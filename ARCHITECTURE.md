# Architecture

How this plugin is put together, for anyone picking it up cold.

## In one paragraph

The site's pages, posts, products and public custom types are **streams**. Every change on the site drops a row
into an **outbox** table; one **worker** drains the outbox to PERSONAIZER in batches, after asking once a minute
which streams the owner has switched on. Once a day each stream's **full list** is reconciled so nothing drifts.
The connection is made once from the admin page; the chat widget rides on every page. Everything the owner
configures lives on personaizer.com — this plugin has one setting of its own (recognise signed-in customers).

## File map

```
personaizer.php                bootstrap: header, the three URL constants, PSR-4 loader, activation hooks
src/Plugin.php                 wiring — every part registers its hooks here
src/Options.php                every wp_option the plugin keeps, named once
src/Data.php                   what Disconnect and uninstall.php remove (options, outbox, schedules, transients)

src/Api/Client.php             the HTTP client: connect start + token, and the four sync calls (ik_ key)
src/Api/Contracts.php          the ONLY file that knows the API's JSON — readers tested against fixtures/

src/Site/Streams.php           the streams this site has: key, label, post type, count, type (files | catalog)
src/Site/Profile.php           this site described by itself, in the website-extractor's result shape
src/Site/Languages.php         every language the site publishes in, primary first (WPML / Polylang / TranslatePress / locale)

src/Content/PostPayload.php    a post → a files record { id, fingerprint, title, content (markdown), links, images }
src/Content/ProductPayload.php a WooCommerce product → a catalog record { …, categories, attributes, variants, … }
src/Content/Markdown.php       rendered HTML → markdown (headings, lists, links, tables), pure PHP
src/Content/Fingerprint.php    md5 of the canonical record — what the full-list check compares

src/Sync/Outbox.php            the table: enqueue / claim / ack / fail / defer / release
src/Sync/Hooks.php             WordPress + WooCommerce events → outbox rows (never an HTTP call in a hook)
src/Sync/State.php             what PERSONAIZER last answered to sync (which streams are on…), cached a minute
src/Sync/Worker.php            drains the outbox: one items call per batch of ≤100, acts on the answer
src/Sync/Backfill.php          after a connect: every published record of every stream that is on → outbox
src/Sync/Reconcile.php         daily: each stream's full list of { id, fingerprint } → missing/stale → outbox
src/Sync/Daily.php             the one recurring WP-Cron tick everything hands-off rides

src/Connect/Flow.php           Connect (start → consent screen → callback → token), Disconnect, Sync now
src/Admin/Page.php + views/    the status page
src/Widget/Embed.php           chat.js on every page, with the persona's public id
src/Widget/IdentityToken.php   the signed-in-customer token endpoint (HS256 with the account's identity secret)
src/Updater.php                self-hosted "update available" channel — stripped from --dev and --org builds

fixtures/v1-integration/       the backend's recorded exchanges, copied by tools/sync-fixtures.sh
tests/                         PHPUnit, no WordPress: Contracts against the fixtures, Markdown, Fingerprint, Languages
```

## The four calls

Everything this site says to PERSONAIZER after it is connected goes through four calls, with its integration key
(`ik_…`, `X-Api-Key`, server-side only — it must never reach the browser):

| Call | When | What |
|---|---|---|
| `POST /v1/integration/sync` | before a drain (cached 60 s), after any `integration.closed`, daily | reports every stream with a count and its type; answers status, brand, persona, plan, and every stream that has a source — on/off, type, counts, last check |
| `PUT /v1/integration/streams/{stream}/items` | the worker, per batch | `{ upserts: [record…], deletes: [id…] }` → `{ written, deferred, rejected, deleted, deletes_busy }` |
| `PUT /v1/integration/streams/{stream}/reconcile` | daily, after a backfill, Sync now | `{ generation, items: [{ id, fingerprint }] }` → `{ missing, stale, orphans_deleted, orphans_held, busy }` |
| `DELETE /v1/integration` | Disconnect | freezes the integration there; nothing is deleted |

One refusal matters: `409 integration.closed` — the site is disconnected, or the stream is off or was never on.
The worker drops the stream's rows and forgets the cached state; the next sync says what is on. `402
limits.quota_exceeded` parks rows as *deferred* until a sync shows headroom. A per-record `rejected` keeps the row
as *failed* with its reason (shown on the admin page, retried daily).

The wire shapes are the backend's `IntegrationSyncContracts`; every exchange is recorded by the backend's contract
test into `fixtures/v1-integration/*.json`, copied here by `tools/sync-fixtures.sh`, and read by
`tests/ContractsTest.php`. When the backend changes a shape, this repo's tests fail before an installed site does.

## Connect

OAuth Authorization-Code + PKCE, PERSONAIZER being the authorization server. **Connect** in the admin: this server
calls `POST /api/integrations/connect/start` with the site's profile (`Site\Profile`), its inventory
(`Site\Streams`), its callback and a PKCE challenge, gets a `connect_id`, and sends the browser to
`{APP_URL}/connect?c=<id>&state=<csrf>`. The owner picks brand, persona and streams there and approves; the browser
returns to `admin-post.php?action=personaizer_connect_callback&code=…&state=…`; this server redeems the code with
the verifier at `POST /api/integrations/connect/token` and stores the credential, brand, persona and identity
secret. Then: first sync, outbox cleared, backfill. Connecting again after a Disconnect is the same flow; the backend resumes the
integration and the consent screen opens on what is true now.

## Two credentials

1. **Integration key** (`ik_…`) — server-side only, reaches only `/v1/integration/*`.
2. **Persona ID** (a GUID) — printed into the page for chat.js; the backend binds it to the site's registered
   origin (an Origin check a browser cannot forge), so a copied id does not work elsewhere. chat.js calls
   PERSONAIZER directly from the visitor's browser; no WordPress round-trip per message.

## Why an outbox, not option arrays

Hooks fire from concurrent requests, the worker from cron, the admin page from a third place; an array
read-modify-written from all of them loses rows. A table with `UNIQUE (stream, external_id)` gives one row per
record, idempotent enqueues (ten edits of a page collapse into one push), a state per row, and honest counts for
the admin page. Payloads are never stored — a row is "push this record", and the record is built from the live
post at drain time, so a post unpublished in the meantime becomes a delete.

## Distribution

Prod builds carry `src/Updater.php`, which hooks WordPress's own update-transient mechanism and polls a static
manifest at `github.com/…/releases/latest/download/personaizer.json`; `--dev` builds (dev URLs) and `--org` builds
(wordpress.org serves updates) strip it. `build-zip.sh` guards that the source defaults to production and that
the header, `PERSONAIZER_VERSION` and readme `Stable tag` agree; `release.sh` cuts the GitHub release.
