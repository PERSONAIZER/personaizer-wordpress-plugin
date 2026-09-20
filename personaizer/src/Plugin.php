<?php
namespace Personaizer;

use Personaizer\Admin\Page;
use Personaizer\Connect\Flow;
use Personaizer\Sync\Backfill;
use Personaizer\Sync\Daily;
use Personaizer\Sync\Hooks;
use Personaizer\Sync\Outbox;
use Personaizer\Sync\Reconcile;
use Personaizer\Sync\Worker;
use Personaizer\Widget\Embed;
use Personaizer\Widget\IdentityToken;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Wires the plugin together. Nothing here does work: every part registers its hooks and waits.
 *
 * What the plugin is, in one paragraph: the site's pages, posts, products (and public custom types) are STREAMS.
 * Every change on the site drops a row into the outbox (Sync\Hooks → Sync\Outbox); a worker drains the outbox
 * to PERSONAIZER in batches (Sync\Worker), after asking once a minute which streams the owner has switched on
 * (Sync\State ← POST /v1/integration/sync). Once a day each stream's full list is reconciled so nothing drifts
 * (Sync\Reconcile). The connection itself is made once, from the admin page (Connect\Flow), and the chat widget
 * rides on every page (Widget\Embed). Everything the owner configures lives on personaizer.com.
 */
final class Plugin {

    public static function boot() {
        Flow::boot();
        Hooks::boot();
        Worker::boot();
        Backfill::boot();
        Reconcile::boot();
        Daily::boot();
        Page::boot();
        Embed::boot();
        IdentityToken::boot();
        // The self-hosted update channel ships in prod zips only (build-zip.sh strips it for --dev and --org).
        if ( is_file( PERSONAIZER_PLUGIN_DIR . '/src/Updater.php' ) ) {
            Updater::boot();
        }
    }

    public static function activate() {
        Outbox::install();
        Daily::schedule();
    }

    /** A deactivated plugin never keeps walking: every schedule goes; the connection and the outbox stay for reactivation. */
    public static function deactivate() {
        Daily::unschedule();
        Worker::disarm();
        Backfill::disarm();
        Reconcile::disarm();
    }
}
