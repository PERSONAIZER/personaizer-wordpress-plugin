# Architecture

How this plugin is put together, for anyone picking it up cold.

## File map

```
personaizer.php                          bootstrap, admin menu + settings page, widget injection
includes/
  class-personaizer-api.php                    HTTP client for the PERSONAIZER integration API (ik_ key)
  class-personaizer-site-profile.php           describes this WordPress site to PERSONAIZER (no scraping needed)
  class-personaizer-daily.php                  the one recurring WP-Cron tick everything hands-off rides
  class-personaizer-content-sync.php           Pages/Posts/CPTs → their streams, via WP hooks
  class-personaizer-woocommerce-sync.php       WooCommerce Products → the products stream, via WC hooks
  class-personaizer-backfill.php               one-time "sync everything that already exists" (WP-Cron)
  class-personaizer-manifest.php               the stream manifest: proves each stream 1:1 with the site (WP-Cron)
  class-personaizer-updater.php                self-hosted "update available" channel (zip distribution only)
  class-personaizer-data.php                   single source of truth for "what did we store", for
                                                Disconnect + uninstall.php
uninstall.php                                  removes every option/credential/scheduled task on delete
assets/admin-page.{css,js}                     the settings page's styling + JS, registered via
                                                admin_enqueue_scripts (not raw <style>/<script> echoes —
                                                WordPress.org review requires this)
```

## Two ways the plugin authenticates to PERSONAIZER

The backend (`api.personaizer.com`) accepts two different credentials, and this plugin uses both,
deliberately, for different calls:

1. **Integration key — `X-Api-Key: ik_…`.** Server-side only, read via `Personaizer_Api::integration_key()`
   (`includes/class-personaizer-api.php`) from the option the plugin stores after Connect. It is the
   credential of this site's *integration* on personaizer.com — the thing that owns the knowledge streams the
   site syncs — and reaches only `/v1/integration/*`: reading the integration (which streams are on), reporting
   inventory, pushing/deleting docs in a stream, sending a stream manifest, and checking subscription limits.
   This key must never reach the browser. Every call also sends `X-Personaizer-Plugin-Version`.

2. **Public Persona ID — `X-Persona-Id: <GUID>`.** Safe to print into the page (it's the Intercom
   `app_id` model). Used by the chat widget script (`chat.js`, injected on `wp_footer`) to call the
   backend directly from the visitor's browser — no WordPress round-trip per chat message. The backend
   binds this credential to the persona's *registered domains* (an Origin-header check a browser cannot
   forge), so a Persona ID copied to another site simply won't authenticate from there.

Only ONE anonymous, unauthenticated call exists: `GET /v1/persona/profile`, used by the admin settings
page to show the connected persona's name/avatar.

## Which streams sync is not a plugin setting

The owner switches streams on and off on personaizer.com (the integration's page, beside every other source of
the brand). The plugin reads that back from `GET /v1/integration` — cached a minute, refreshed after anything
that could change it, and backed by the last good answer when the API is unreachable — and pushes only
into streams that are on. A push into a stream that was switched off answers `409 integration.stream_disabled`,
which the sync layer treats as "drop it, don't retry". Nothing about streams is stored locally as truth.

## Content sync: three independent mechanisms, not one

- **`Personaizer_Content_Sync`** and **`Personaizer_WooCommerce_Sync`** are the steady state: WordPress /
  WooCommerce hooks (`wp_after_insert_post`, `woocommerce_update_product`, stock-change hooks, trash/delete
  hooks) push one item at a time, the moment it changes. This is what "syncing" means day to day.
- **`Personaizer_Backfill`** exists only because hooks can't retroactively fire for content that already
  existed before Connect. It walks the site a batch at a time on WP-Cron (one HTTP round-trip per post
  inline would blow `max_execution_time` on any real site) and is also the source of the admin page's
  "syncing 40/120" progress.
- **`Personaizer_Manifest`** exists because an event-driven design can silently drift — a missed WP-Cron
  tick, a timeout, a product trashed while the plugin was inactive — and "we attempted a send" is not the
  same claim as "the AI actually holds this." Daily (and after a backfill, and on demand) it walks each
  stream that is on, fingerprints every published item (the hash of the exact payload it would push — the
  same hash sent with every push), and hands the list to `PUT /v1/integration/streams/{stream}/manifest`. The
  backend answers what it is missing or holds stale (queued for the retry tick to push) and removes what
  the site no longer lists — behind rails of its own: a manifest that would orphan more than a quarter of a
  stream is held until the next one agrees. On this side a stream's manifest is sent only when the site can
  enumerate it fully right now (a post type that isn't registered — WooCommerce deactivated — is skipped,
  never reported empty).

Each stream (Pages, Posts, each public CPT, Products) is switched on/off independently on personaizer.com.
Turning a stream off doesn't delete anything already synced; removals made while off are queued and
re-verified once the stream resumes.

## Distribution: two update channels, mutually exclusive per build

- **WordPress.org SVN** (the `--org` build): the directory's own update mechanism handles "new version
  available" for every site that installed via the directory. Shipping a second, self-hosted updater
  alongside this is a wordpress.org rejection reason, so the `--org` build strips
  `class-personaizer-updater.php` entirely (see `build-zip.sh`).
- **Self-hosted zip** (prod builds, this repo's GitHub Releases via `release.sh`): for anyone who
  installs from a downloaded zip instead of the directory, `class-personaizer-updater.php` hooks into
  WordPress's own update-transient mechanism (`pre_set_site_transient_update_plugins` /
  `plugins_api`) so the native "update available" row, one-click Update, and background auto-updates all
  work unchanged — polling a static JSON manifest, not a live API endpoint (every install polls it twice
  a day forever; a static file is the right shape for that, an API endpoint is not).

  That manifest is a release ASSET, reached through `/releases/latest/download/personaizer.json` — a
  permalink that always resolves to the newest release. So the page clients download from and the file
  installed sites poll are the same release, and cutting one is the entire publishing step. Before 1.2.3
  both lived on our own blob container, which meant a release was only real once someone remembered to
  run a second upload script; the two drifted, and a version published here but never uploaded there was
  invisible to every installed site. The updater's same-host rule
  (`Personaizer_Updater::trusted_package`) is satisfied because the manifest and the zip it advertises
  are assets of the same release on github.com.

  `--dev` builds ship WITHOUT the updater: there is one release line now, so a dev build that polled it
  would offer the tester a prod package and quietly replace the dev URLs it was installed for.

A given zip ships with exactly one of these active, decided at build time by `build-zip.sh`'s flag.

## Admin settings page

`personaizer_chat_page()` in `personaizer.php` is deliberately *not* a WordPress Settings API page —
nothing the owner configures there is stored as a WordPress "setting" in the traditional sense beyond one
on/off toggle (whether to recognize signed-in customers). Everything else —
widget appearance, greeting, FAQ, the persona itself — lives on personaizer.com and is fetched for
display, not edited here. The page's CSS/JS live in `assets/admin-page.{css,js}` and are registered via
`admin_enqueue_scripts` scoped to just this page (`toplevel_page_personaizer`); the one piece of
per-request dynamic behavior (whether to auto-reload while a sync is still running) is passed to the
static JS via `wp_add_inline_script` with a small JSON config object, not by echoing a `<script>` tag.
