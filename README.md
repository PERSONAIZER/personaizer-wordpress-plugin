# PERSONAIZER — WordPress Plugin

Connect a WordPress / WooCommerce site to a [PERSONAIZER](https://personaizer.com) AI persona in one
click: a floating chat widget that answers visitors from the site's own pages, posts and WooCommerce
products, and can keep that content synced to the persona so answers stay current.

PERSONAIZER is an external SaaS. This plugin is a client for it — chat runs against the PERSONAIZER API
and the widget script is served from PERSONAIZER's CDN. A free plan exists, and connecting creates a
persona automatically — no paid account is needed to try it.

## Install

- **From WordPress.org** ([personaizer-chat](https://wordpress.org/plugins/personaizer-chat/)): Plugins →
  Add New → search "PERSONAIZER" → Install → Activate. Updates arrive the same way.
- **From a release zip**: grab the latest from [Releases](../../releases), then in WordPress admin go to
  Plugins → Add New → Upload Plugin. It is the same plugin, so it also updates from WordPress.org.

## Use it

1. Open the **PERSONAIZER** menu in wp-admin and click **Connect**.
2. Approve on the PERSONAIZER consent screen: the brand (the form is filled in from your site), the persona
   (built for you if you have none), and what to sync — pages, posts, products, custom types, all on by default.
3. Back in wp-admin the page shows the persona building and each stream syncing; the chat widget appears on the
   site's front end as soon as the persona is ready.
4. Optionally "recognise signed-in customers" so the AI greets logged-in visitors by name.

## Develop

```bash
tools/sync-fixtures.sh          # copy the backend's recorded API exchanges into fixtures/
php tools/phpunit.phar          # unit tests, no WordPress needed (curl -L -o tools/phpunit.phar https://phar.phpunit.de/phpunit-11.phar)
./build-zip.sh                  # the package → dist/; test it on a site with testing/dev-override.php
```

Everything the owner configures beyond that — the widget's look, greeting, FAQ, and the persona itself —
lives on [personaizer.com](https://personaizer.com); this plugin is the bridge to it, not a second copy of
those settings.

## Docs

- **[ARCHITECTURE.md](ARCHITECTURE.md)** — how the plugin is put together: the file map, the two backend
  auth modes, and how content sync/backfill/reconciliation fit together.
- **[RELEASING.md](RELEASING.md)** — the pipeline (change → test on dev → publish) and the WordPress.org listing.
- The plugin's own `readme.txt` (inside `personaizer-chat/`, bundled in every release zip) is the
  WordPress.org-facing feature list, FAQ, and changelog.
- **[testing/](testing/)** — a turnkey kit for validating the plugin against a real public WordPress site
  (LocalWP can't exercise image sync, Connect's PKCE callback, WP-Cron, or CORS — see
  `testing/README.md`).

## Repo layout

```
personaizer-chat/  the plugin itself (the WordPress.org slug) — its contents are exactly what ships
.wordpress-org/     listing images (icons, banners) that release.sh puts in SVN assets/
build-zip.sh        packages personaizer-chat/ into the installable zip
release.sh          releases a version: WordPress.org SVN, then a GitHub release as the archive
testing/            kit for testing against a real public site + the WooCommerce sample catalog
```

## License

GPLv2 or later — see [LICENSE](LICENSE).
