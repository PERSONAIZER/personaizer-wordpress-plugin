<?php
/**
 * The admin page. $model comes from Personaizer\Admin\Page::model(). Markup only — no decisions here.
 *
 * @var array $model
 */
use Personaizer\Admin\Page;
use Personaizer\Connect\Flow;
use Personaizer\Options;

if ( ! defined( 'ABSPATH' ) ) exit;

$m        = $model;
$state    = $m['state'];
$persona  = $state['persona'] ?? null;
$plan     = $state['plan'] ?? null;
$status   = $state['status'] ?? '';
$queued   = 0; $deferred = 0; $failed = 0;
foreach ( $m['streams'] as $s ) { $queued += $s['queue']['queued']; $deferred += $s['queue']['deferred']; $failed += $s['queue']['failed']; }
?>
<div class="wrap pz-wrap">
    <h1 class="pz-title">PERSONAIZER
        <?php if ( $m['connected'] && $m['reachable'] && $status === 'active' ) : ?>
            <span class="pz-pill pz-pill--on"><?php echo $persona ? 'Live on your site' : 'Connected'; ?></span>
        <?php elseif ( $m['connected'] && $status === 'disconnected' ) : ?>
            <span class="pz-pill pz-pill--off">Frozen</span>
        <?php elseif ( $m['connected'] ) : ?>
            <span class="pz-pill pz-pill--off">Can't reach PERSONAIZER</span>
        <?php else : ?>
            <span class="pz-pill">Not connected</span>
        <?php endif; ?>
    </h1>

    <?php if ( $m['notice'] ) : ?>
        <div class="notice notice-<?php echo esc_attr( $m['notice']['kind'] ); ?> is-dismissible"><p><?php echo esc_html( $m['notice']['text'] ); ?></p></div>
    <?php endif; ?>

    <?php if ( ! $m['connected'] ) : ?>
        <div class="pz-card pz-card--hero">
            <h2>Connect this site to PERSONAIZER</h2>
            <p>Your pages, posts and products become what your AI persona knows, and the chat widget answers your visitors from them. You'll pick the brand, the persona and what to sync on personaizer.com — the form is filled in from this site.</p>
            <a class="button button-primary button-hero" href="<?php echo esc_url( Page::action_url( Flow::ACTION_CONNECT ) ); ?>">Connect to PERSONAIZER</a>
        </div>
    <?php else : ?>

        <div class="pz-card pz-card--hero">
            <div class="pz-hero">
                <?php if ( $persona ) : ?>
                    <div class="pz-avatar"><?php if ( $persona['avatar_url'] !== '' ) : ?><img src="<?php echo esc_url( $persona['avatar_url'] ); ?>" alt=""><?php endif; ?></div>
                <?php endif; ?>
                <div class="pz-hero__text">
                    <h2><?php echo $persona ? esc_html( $persona['name'] ) : esc_html( $state['brand']['name'] ?: 'Your brand' ); ?></h2>
                    <?php if ( $persona && $persona['building'] ) : ?>
                        <p class="pz-muted"><span class="spinner is-active pz-spinner"></span> Being built — the widget shows a "coming soon" state until it's ready.</p>
                    <?php elseif ( $persona ) : ?>
                        <p class="pz-muted">Answering on this site for <strong><?php echo esc_html( $state['brand']['name'] ); ?></strong>.</p>
                    <?php else : ?>
                        <p class="pz-muted">Content syncs into <strong><?php echo esc_html( $state['brand']['name'] ?: 'your brand' ); ?></strong>; no chat widget is on the site yet — pick a persona on personaizer.com.</p>
                    <?php endif; ?>
                    <?php if ( $plan && $plan['name'] !== '' ) : ?>
                        <p class="pz-muted pz-plan">
                            <?php echo esc_html( $plan['name'] ); ?> plan ·
                            <?php if ( $plan['knowledge_units_limit'] === null ) : ?>unlimited knowledge<?php else : ?>
                                <?php echo esc_html( number_format_i18n( $plan['knowledge_units_used'] ) ); ?> of <?php echo esc_html( number_format_i18n( $plan['knowledge_units_limit'] ) ); ?> knowledge units used
                                <?php if ( $deferred > 0 ) : ?> — <strong><?php echo (int) $deferred; ?> records are waiting for room</strong>; <a href="<?php echo esc_url( $m['app_url'] . '/billing' ); ?>" target="_blank" rel="noopener">upgrade</a><?php endif; ?>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="pz-hero__actions">
                    <a class="button button-primary" href="<?php echo esc_url( $m['app_url'] . ( $persona ? '/persona/' . $persona['id'] : '/knowledge' ) ); ?>" target="_blank" rel="noopener">Open in PERSONAIZER</a>
                </div>
            </div>
            <?php if ( $status === 'disconnected' ) : ?>
                <div class="notice notice-warning inline"><p>This site was disconnected on personaizer.com. Nothing was deleted; <a href="<?php echo esc_url( Page::action_url( Flow::ACTION_CONNECT ) ); ?>">reconnect</a> to resume.</p></div>
            <?php elseif ( ! $m['reachable'] ) : ?>
                <div class="notice notice-error inline"><p>PERSONAIZER can't be reached right now<?php if ( $m['error'] ) : ?>: <?php echo esc_html( $m['error']['message'] ); ?><?php endif; ?>. Changes are kept and sent when it's back.</p></div>
            <?php endif; ?>
        </div>

        <div class="pz-card">
            <h2>What it learns from this site</h2>
            <p class="pz-muted">Switch streams on and off on <a href="<?php echo esc_url( $m['app_url'] . '/knowledge' ); ?>" target="_blank" rel="noopener">personaizer.com</a>. New and edited content keeps syncing by itself.</p>
            <table class="widefat striped pz-streams">
                <thead><tr><th>Stream</th><th>Status</th><th>Synced</th><th>Waiting</th><th>Last check</th></tr></thead>
                <tbody>
                <?php foreach ( $m['streams'] as $key => $s ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $s['label'] ); ?></strong> <span class="pz-muted"><?php echo (int) $s['local']; ?> published</span></td>
                        <td><?php if ( $s['enabled'] ) : ?><span class="pz-dot pz-dot--on"></span> On<?php elseif ( $s['offered'] ) : ?><span class="pz-dot"></span> Off<?php else : ?><span class="pz-muted">Not switched on</span><?php endif; ?></td>
                        <td><?php if ( $s['synced'] !== null ) : ?><?php echo (int) $s['synced']; ?> of <?php echo (int) $s['local']; ?><?php if ( $s['ready'] !== null && $s['ready'] < $s['synced'] ) : ?> <span class="pz-muted">(<?php echo (int) $s['ready']; ?> ready)</span><?php endif; ?><?php else : ?>—<?php endif; ?></td>
                        <td>
                            <?php $q = $s['queue']; ?>
                            <?php if ( $q['queued'] ) : ?><span class="pz-tag"><?php echo (int) $q['queued']; ?> queued</span><?php endif; ?>
                            <?php if ( $q['deferred'] ) : ?><span class="pz-tag pz-tag--warn"><?php echo (int) $q['deferred']; ?> plan full</span><?php endif; ?>
                            <?php if ( $q['failed'] ) : ?><span class="pz-tag pz-tag--err"><?php echo (int) $q['failed']; ?> failed</span><?php endif; ?>
                            <?php if ( ! $q['queued'] && ! $q['deferred'] && ! $q['failed'] ) : ?><span class="pz-muted">—</span><?php endif; ?>
                        </td>
                        <td class="pz-muted">
                            <?php $c = $s['check']; ?>
                            <?php if ( $c && isset( $c['error'] ) ) : ?>Failed: <?php echo esc_html( $c['error'] ); ?>
                            <?php elseif ( $c ) : ?><?php echo esc_html( Page::ago( (int) $c['at'] ) ); ?><?php if ( $c['missing'] || $c['stale'] ) : ?> · <?php echo (int) ( $c['missing'] + $c['stale'] ); ?> to send<?php endif; ?><?php if ( $c['orphans_held'] ) : ?> · <?php echo (int) $c['orphans_held']; ?> awaiting removal<?php endif; ?>
                            <?php else : ?>never<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="pz-muted pz-status-line">
                <?php if ( $m['backfill']['running'] ) : ?><span class="spinner is-active pz-spinner"></span> Queuing everything that already exists (<?php echo (int) $m['backfill']['enqueued']; ?> so far)…
                <?php elseif ( $m['reconcile']['running'] ) : ?><span class="spinner is-active pz-spinner"></span> Checking <?php echo esc_html( $m['reconcile']['stream'] ?: 'streams' ); ?>…
                <?php elseif ( $queued > 0 ) : ?><span class="spinner is-active pz-spinner"></span> Sending <?php echo (int) $queued; ?> records…
                <?php else : ?>Last sent <?php echo esc_html( Page::ago( $m['last_push'] ) ); ?>.<?php endif; ?>
                <?php if ( $m['cron_off'] ) : ?><br><em>WP-Cron is disabled on this site — make sure a system cron calls wp-cron.php, or syncing only happens while someone is in wp-admin.</em><?php endif; ?>
            </p>
            <?php if ( $m['failures'] ) : ?>
                <details class="pz-failures"><summary><?php echo (int) $failed; ?> records were refused — retried daily</summary>
                    <ul><?php foreach ( $m['failures'] as $f ) : ?><li><code><?php echo esc_html( $f->external_id ); ?></code> — <?php echo esc_html( $f->last_error ); ?></li><?php endforeach; ?></ul>
                </details>
            <?php endif; ?>
            <p class="pz-actions">
                <a class="button" href="<?php echo esc_url( Page::action_url( Flow::ACTION_SYNC_NOW ) ); ?>">Sync now</a>
                <a class="button" href="<?php echo esc_url( Page::action_url( Flow::ACTION_CONNECT ) ); ?>">Reconnect</a>
                <a class="button pz-danger" href="<?php echo esc_url( Page::action_url( Flow::ACTION_DISCONNECT ) ); ?>" onclick="return confirm('Disconnect this site? Nothing is deleted on PERSONAIZER; you can reconnect any time.');">Disconnect</a>
            </p>
        </div>

        <div class="pz-card">
            <form method="post" action="options.php">
                <?php settings_fields( 'personaizer' ); ?>
                <h2>Visitors</h2>
                <label><input type="checkbox" name="<?php echo esc_attr( Options::IDENTIFY_USERS ); ?>" value="1" <?php checked( Options::identify_users() ); ?>> Recognise signed-in customers in the chat, so the persona knows who it's talking to.</label>
                <p class="pz-muted">Widget appearance, greeting and behaviour are edited on <a href="<?php echo esc_url( $m['app_url'] . ( $persona ? '/persona/' . $persona['id'] : '' ) ); ?>" target="_blank" rel="noopener">personaizer.com</a>.</p>
                <?php submit_button( 'Save', 'secondary', 'submit', false ); ?>
            </form>
        </div>

        <details class="pz-sysinfo"><summary>System info</summary>
            <textarea readonly rows="8"><?php
                echo esc_textarea( implode( "\n", array(
                    'Plugin ' . PERSONAIZER_VERSION, 'API ' . PERSONAIZER_API_URL, 'App ' . PERSONAIZER_APP_URL,
                    'WordPress ' . get_bloginfo( 'version' ) . ' · PHP ' . PHP_VERSION . ( class_exists( 'WooCommerce' ) ? ' · WooCommerce ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '' ) : '' ),
                    'Integration ' . get_option( Options::INTEGRATION_ID, '' ) . ' · brand ' . get_option( Options::BRAND_ID, '' ) . ' · persona ' . Options::persona_id(),
                    'Status ' . $status . ' · reachable ' . ( $m['reachable'] ? 'yes' : 'no' ) . ' · WP-Cron ' . ( $m['cron_off'] ? 'disabled' : 'on' ),
                    'Outbox queued ' . $queued . ' · plan full ' . $deferred . ' · failed ' . $failed,
                    $m['error'] ? 'Last error ' . $m['error']['message'] . ' (' . $m['error']['code'] . ', ' . Page::ago( (int) $m['error']['at'] ) . ')' : 'Last error none',
                ) ) );
            ?></textarea>
        </details>
    <?php endif; ?>
</div>
