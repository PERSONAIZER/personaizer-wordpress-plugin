# Releasing

The plugin is listed on WordPress.org as **`personaizer-chat`** (approved 2026-09-28; the slug can never change).
WordPress.org is the only update channel: sites install and update from it. Each release also goes to this
repo's GitHub Releases as an archive of the same zip, for anyone installing by hand.

| | |
|---|---|
| Listing | https://wordpress.org/plugins/personaizer-chat/ (hidden until the first SVN commit; search finds it 6–14 days later) |
| SVN | https://plugins.svn.wordpress.org/personaizer-chat, username `personaizer` |
| Display name | PERSONAIZER (the plugin header and `readme.txt` title; only the slug is fixed) |

## One-time setup

- **SVN password:** WordPress.org → Profile → Account & Security. It is separate from the login password.
- **SVN client:** e.g. TortoiseSVN with "command line client tools", so `svn` is on PATH.
- **gh:** authenticated (`gh auth status`).
- **Email:** keep `plugins@wordpress.org` whitelisted; the team closes plugins it can't reach.

## Every release

1. **Bump the version in three places**: the `personaizer-chat.php` header `Version:`, the
   `PERSONAIZER_VERSION` `define()`, and `readme.txt`'s `Stable tag:`. `build-zip.sh` refuses to build if they
   disagree. `Stable tag` is what tells WordPress.org which tag sites get.
2. Add a `== Changelog ==` entry in `readme.txt`. If the backend's contract fixtures changed, run
   `tools/sync-fixtures.sh` and make the tests green first (`php tools/phpunit.phar`).
3. Commit and push, then:
   ```bash
   ./release.sh --dry-run   # optional: stage the SVN changes and show them, commit nothing
   ./release.sh
   ```
   It builds the zip; makes SVN `trunk/` exactly that build (removed files are removed too); copies
   `.wordpress-org/` into `assets/`; copies `trunk` to `tags/<version>`; commits; then creates the GitHub release
   with the zip and this version's changelog. It refuses a version that already exists on either side.

Every SVN commit rebuilds the served download (up to ~6 hours to show), so commit only finished versions.
SVN is a release system, not git: never `.git*`, zips, `node_modules` or vendor folders (the build has none).

## Listing images (`.wordpress-org/` → SVN `assets/`)

| File | Size | Status |
|---|---|---|
| `icon-128x128.png`, `icon-256x256.png` | 128×128, 256×256 | ✅ in the repo |
| `banner-772x250.png`, `banner-1544x500.png` | 772×250, 1544×500 (≤ 4 MB) | ⬜ missing: the page shows no header until added |
| `screenshot-1.png`, `screenshot-2.png`, … | any (≤ 10 MB), lowercase names | ⬜ optional; each needs a caption line under `== Screenshots ==` in `readme.txt` |

Images are served through a CDN and can take a few hours to change after a commit.

## Why the plugin passes review

- **GPLv2** license in header + readme + `LICENSE`.
- **External service disclosed** with Terms + Privacy links (`readme.txt`'s `== External Services ==`).
- **No update channel of its own**: `build-zip.sh` fails if update-hook code appears in the build.
- **Opt-in for personal data**: name/email/phone recognition is OFF until the owner enables it.
- **Security**: prepared SQL, escaped output, sanitized input, nonces + capability checks, `ABSPATH` guards on
  every file, no bundled/minified code, no hardcoded secrets (Connect fetches them at runtime). CSS/JS are
  registered via `wp_enqueue_style` / `wp_enqueue_script`, never raw `<style>` / `<script>` echoes.
- **No admin nags / no trialware**: the free plan is fully functional.

`.phpcs.xml.dist` runs the sniffs Plugin Check runs, and CI fails on a finding. Before a release, also run the
Plugin Check plugin on a clean WordPress with `WP_DEBUG` on; the reviewers do.
