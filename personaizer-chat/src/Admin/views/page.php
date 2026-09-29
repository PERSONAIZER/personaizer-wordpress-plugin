<?php
/**
 * The admin page. $model comes from Personaizer\Admin\Page::model(). Markup only — no decisions here.
 *
 * @var array $model
 */
use Personaizer\Admin\Page;
use Personaizer\Connect\Flow;
use Personaizer\Options;
use Personaizer\Sync\State;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The page is one function so its working variables are locals, not globals of the request.
( static function ( array $model ) {

	$state       = $model['state'];
	$persona     = $state['persona'] ?? null;
	$plan        = $model['plan'];
	$sync_status = $state['status'] ?? '';
	// Null when the API can't be reached and no earlier answer is stored.
	$brand_name = $state['brand']['name'] ?? '';
	$name       = $persona ? $persona['name'] : 'your persona';
	// Rows of a stream that is off are not being sent — they wait for the owner to switch it on. Count them apart.
	$queued   = 0;
	$parked   = 0;
	$deferred = 0;
	$failed   = 0;
	foreach ( $model['streams'] as $stream ) {
		if ( $stream['enabled'] ) {
			$queued += $stream['queue']['queued'];
		} else {
			$parked += $stream['queue']['queued'];
		}
		$deferred += $stream['queue']['deferred'];
		$failed   += $stream['queue']['failed'];
	}

	// Fixed icon markup, never data.
	$icons = array(
		'products'  => '<path d="M3 12V4h8l9 9-8 8z"/><circle cx="7.5" cy="7.5" r="1.3"/>',
		'pages'     => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h4"/>',
		'posts'     => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13 7l4 4"/>',
		'web'       => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3.5 3.5 3.5 14.5 0 18M12 3c-3.5 3.5-3.5 14.5 0 18"/>',
		'messenger' => '<path d="M12 3C7 3 3 6.7 3 11.3c0 2.6 1.3 4.9 3.3 6.4V21l3-1.7c.9.3 1.8.4 2.7.4 5 0 9-3.7 9-8.4S17 3 12 3z"/><path d="M7.5 13.5l3-3 2.5 2 3.5-3"/>',
		'instagram' => '<rect x="3.5" y="3.5" width="17" height="17" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r="0.6"/>',
		'whatsapp'  => '<path d="M3.5 20.5l1.4-4.2A8.5 8.5 0 1 1 8 19.3z"/><path d="M9 8.5c-.4 3 3.4 6.8 6.5 6.5l.8-1.6-2-1-1 .9c-1.1-.5-2-1.4-2.6-2.5l.9-1-1-2z"/>',
		'api'       => '<path d="M8 7l-5 5 5 5M16 7l5 5-5 5"/>',
		'stack'     => '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/><path d="M3 17.5l9 5 9-5"/>',
	);
	$icon  = static function ( $key, $size ) use ( $icons ) {
		return '<svg width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icons[ $key ] . '</svg>';
	};
	$coin  = static function ( $size ) {
		return '<svg width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="#e8a317"/><circle cx="12" cy="12" r="8.2" fill="#fbc93d"/><circle cx="12" cy="12" r="5.6" fill="none" stroke="#e8a317" stroke-width="1.4"/><path d="M8.5 8.2a5 5 0 0 1 4-1.6" stroke="#fff3c4" stroke-width="1.4" fill="none" stroke-linecap="round"/></svg>';
	};
	$allowed_svg = array(
		'svg'    => array(
			'width'           => true,
			'height'          => true,
			'viewbox'         => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'aria-hidden'     => true,
		),
		'path'   => array(
			'd'              => true,
			'fill'           => true,
			'stroke'         => true,
			'stroke-width'   => true,
			'stroke-linecap' => true,
		),
		'circle' => array(
			'cx'           => true,
			'cy'           => true,
			'r'            => true,
			'fill'         => true,
			'stroke'       => true,
			'stroke-width' => true,
		),
		'rect'   => array(
			'x'      => true,
			'y'      => true,
			'width'  => true,
			'height' => true,
			'rx'     => true,
		),
	);
	$status_words = array(
		'queued'      => 'Waiting',
		'reading'     => 'Reading',
		'writing'     => 'Writing',
		'indexing'    => 'Indexing',
		'mapping'     => 'Mapping',
		'summarizing' => 'Summarizing',
	);
	?>
<div class="wrap pz-wrap">
	<div class="pz-head">
		<h1 class="pz-title"><img class="pz-logo" src="<?php echo esc_url( $model['logo'] ); ?>" alt="" width="28" height="28">PERSONAIZER
			<?php if ( $model['connected'] && $model['reachable'] && $sync_status === 'active' && $persona ) : ?>
				<span class="pz-pill pz-pill--on"><span class="pz-pill__dot"></span>Live on your site</span>
			<?php elseif ( $model['connected'] && $model['reachable'] && $sync_status === 'active' ) : ?>
				<span class="pz-pill pz-pill--warn"><span class="pz-pill__dot"></span>Not on your site yet</span>
			<?php elseif ( $model['connected'] && $sync_status === 'disconnected' ) : ?>
				<span class="pz-pill pz-pill--off">Frozen</span>
			<?php elseif ( $model['connected'] && $sync_status === State::GONE ) : ?>
				<span class="pz-pill pz-pill--off">Removed on personaizer.com</span>
			<?php elseif ( $model['connected'] ) : ?>
				<span class="pz-pill pz-pill--off">Can't reach PERSONAIZER</span>
			<?php else : ?>
				<span class="pz-pill">Not connected</span>
			<?php endif; ?>
		</h1>
		<?php if ( $model['connected'] && $sync_status !== State::GONE ) : ?>
			<div class="pz-head__persona">
				<?php if ( $persona ) : ?>
					<div class="pz-avatar">
					<?php
					if ( $persona['avatar_url'] !== '' ) :
						?>
						<img src="<?php echo esc_url( $persona['avatar_url'] ); ?>" alt=""><?php endif; ?></div>
					<div class="pz-head__text">
						<strong><?php echo esc_html( $persona['name'] ); ?></strong>
						<?php if ( $persona['building'] ) : ?>
							<span class="pz-muted"><span class="spinner is-active pz-spinner"></span>Being built</span>
						<?php else : ?>
							<span class="pz-muted">Answers for <?php echo esc_html( $brand_name ?: get_bloginfo( 'name' ) ); ?></span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<a class="button button-primary" href="<?php echo esc_url( $model['dashboard'] ); ?>" target="_blank" rel="noopener">Open dashboard</a>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( $model['notice'] ) : ?>
		<div class="notice notice-<?php echo esc_attr( $model['notice']['kind'] ); ?> is-dismissible"><p><?php echo esc_html( $model['notice']['text'] ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! $model['connected'] ) : ?>
		<div class="pz-card pz-card--connect">
			<img class="pz-logo pz-logo--hero" src="<?php echo esc_url( $model['logo'] ); ?>" alt="" width="72" height="72">
			<h2>Connect this site to PERSONAIZER</h2>
			<p>Your pages, posts and products become what your AI persona knows, and the chat widget answers your visitors from them. You'll pick the brand, the persona and what to sync on personaizer.com — the form is filled in from this site.</p>
			<a class="button button-primary button-hero" data-pz-connect href="<?php echo esc_url( Page::action_url( Flow::ACTION_CONNECT ) ); ?>" target="_blank">Connect to PERSONAIZER</a>
		</div>
	<?php else : ?>

		<?php if ( $sync_status === State::GONE ) : ?>
			<div class="notice notice-warning inline"><p>This site's connection was removed on personaizer.com, so nothing syncs and the widget is off. <a href="<?php echo esc_url( Page::action_url( Flow::ACTION_CONNECT ) ); ?>" data-pz-connect target="_blank">Connect</a> again to start over, or <a href="<?php echo esc_url( Page::action_url( Flow::ACTION_DISCONNECT ) ); ?>" data-pz-confirm="Clear this site's PERSONAIZER connection? Nothing on personaizer.com is touched.">clear the connection</a> here.</p></div>
		<?php elseif ( $sync_status === 'disconnected' ) : ?>
			<div class="notice notice-warning inline"><p>This site was disconnected on personaizer.com. Nothing was deleted; <a href="<?php echo esc_url( Page::action_url( Flow::ACTION_CONNECT ) ); ?>">connect</a> again to resume.</p></div>
		<?php elseif ( ! $model['reachable'] ) : ?>
			<div class="notice notice-error inline"><p>PERSONAIZER can't be reached right now
			<?php
			if ( $model['error'] ) :
				?>
				: <?php echo esc_html( $model['error']['message'] ); ?><?php endif; ?>. Changes are kept and sent when it's back.</p></div>
		<?php elseif ( ! $persona ) : ?>
			<div class="pz-banner">
				<div class="pz-banner__text">
					<strong>Turn on the chat widget</strong>
					<span>Your content syncs into <?php echo esc_html( $brand_name ?: 'your brand' ); ?>, but visitors see no chat yet. Pick the persona that answers on this site on personaizer.com.</span>
				</div>
				<a class="button button-primary" href="<?php echo esc_url( $model['dashboard'] ); ?>" target="_blank" rel="noopener">Pick a persona</a>
			</div>
		<?php endif; ?>

		<?php if ( $plan ) : ?>
			<div class="pz-card pz-plan">
				<div class="pz-plan__head">
					<span><strong><?php echo esc_html( $plan['name'] ); ?> plan</strong>
					<?php
					if ( $plan['gives'] !== '' ) :
						?>
						<span class="pz-muted"> · <?php echo esc_html( $plan['gives'] ); ?></span><?php endif; ?></span>
					<a href="<?php echo esc_url( $model['app_url'] . '/subscription' ); ?>" target="_blank" rel="noopener">Change plan</a>
				</div>
				<div class="pz-meters">
					<?php
					foreach ( array(
						'Conversations' => array( 'conversations', 'credits', 'How credits are spent' ),
						'Knowledge'     => array( 'knowledge', 'knowledge', 'What a knowledge unit is' ),
					) as $label => list( $key, $pop, $pop_label ) ) :
						$meter = $plan[ $key ];
						?>
						<?php if ( $key === 'knowledge' ) : ?>
							<span class="pz-meters__divider"></span>
						<?php endif; ?>
						<div class="pz-meter">
							<div class="pz-meter__head">
								<span class="pz-muted"><?php echo esc_html( $label ); ?></span>
								<span class="pz-meter__value"><strong class="<?php echo $meter['over'] ? 'pz-over' : ''; ?>"><?php echo esc_html( $meter['value'] ); ?></strong>
								<?php
								if ( $meter['of'] !== '' ) :
									?>
									<span class="pz-muted"><?php echo esc_html( $meter['of'] ); ?></span><?php endif; ?>
									<button type="button" class="pz-info" data-pz-pop="<?php echo esc_attr( $pop ); ?>" aria-expanded="false" aria-label="<?php echo esc_attr( $pop_label ); ?>">i</button>
								</span>
							</div>
							<?php if ( $meter['bar'] !== null ) : ?>
								<div class="pz-bar"><div class="pz-bar__fill" style="width: <?php echo esc_attr( number_format( $meter['bar']['fill'], 1, '.', '' ) ); ?>%"></div>
								<?php
								if ( $meter['bar']['at'] !== null ) :
									?>
									<div class="pz-bar__over"></div><?php endif; ?></div>
								<?php if ( $meter['bar']['at'] !== null ) : ?>
									<?php $pz_at = number_format( $meter['bar']['at'], 1, '.', '' ); ?>
									<div class="pz-bar__limit"><span class="pz-bar__tick" style="left: <?php echo esc_attr( $pz_at ); ?>%"></span><span class="pz-bar__label<?php echo $meter['bar']['at'] > 85 ? ' pz-bar__label--end' : ( $meter['bar']['at'] < 15 ? ' pz-bar__label--start' : '' ); ?>" style="left: <?php echo esc_attr( $pz_at ); ?>%"><?php echo esc_html( $meter['bar']['limit'] ); ?></span></div>
								<?php endif; ?>
							<?php endif; ?>
							<?php foreach ( $meter['lines'] as list( $text, $over ) ) : ?>
								<span class="pz-meter__sub<?php echo $over ? ' pz-over' : ''; ?>"><?php echo esc_html( $text ); ?></span>
							<?php endforeach; ?>
						</div>
					<?php endforeach; ?>
				</div>
				<div class="pz-pop pz-pop--credits" data-pz-popover="credits" role="dialog" aria-label="Credits" hidden>
					<div class="pz-pop__head"><?php echo wp_kses( $coin( 18 ), $allowed_svg ); ?><strong>Credits</strong></div>
					<div class="pz-pop__row"><span>Your team's reply during a takeover</span>
						<span class="pz-pop__amount">1<?php echo wp_kses( $coin( 14 ), $allowed_svg ); ?></span></div>
					<div class="pz-pop__row"><span><?php echo esc_html( ucfirst( $name ) ); ?> answers a message</span>
						<span class="pz-pop__channels">
						<?php
						foreach ( array(
							'web'       => 'Website',
							'messenger' => 'Messenger',
							'instagram' => 'Instagram',
							'whatsapp'  => 'WhatsApp',
							'api'       => 'API',
						) as $channel => $title ) :
							?>
							<span title="<?php echo esc_attr( $title ); ?>"><?php echo wp_kses( $icon( $channel, 14 ), $allowed_svg ); ?></span><?php endforeach; ?></span>
						<span class="pz-pop__amount">10<?php echo wp_kses( $coin( 14 ), $allowed_svg ); ?></span></div>
					<div class="pz-pop__row"><span><?php echo esc_html( ucfirst( $name ) ); ?> answers in a live call</span><span class="pz-muted">voice or 3D</span><span class="pz-beta">BETA</span>
						<span class="pz-pop__amount">20<?php echo wp_kses( $coin( 14 ), $allowed_svg ); ?></span></div>
					<div class="pz-pop__row"><span>New persona from one-click setup</span>
						<span class="pz-pop__amount">100<?php echo wp_kses( $coin( 14 ), $allowed_svg ); ?></span></div>
					<p class="pz-muted">A conversation is usually 4 answers ≈ 40 credits.<?php echo $plan['resets'] !== '' ? ' Resets on ' . esc_html( $plan['resets'] ) . '.' : ''; ?></p>
				</div>
				<div class="pz-pop pz-pop--knowledge" data-pz-popover="knowledge" role="dialog" aria-label="Knowledge units" hidden>
					<div class="pz-pop__head"><span class="pz-pop__icon"><?php echo wp_kses( $icon( 'stack', 18 ), $allowed_svg ); ?></span><strong>Knowledge units</strong></div>
					<div class="pz-pop__row"><span>A product</span><span class="pz-muted">long descriptions count more</span><span class="pz-pop__amount">≈ 1</span></div>
					<div class="pz-pop__row"><span>A page or post</span><span class="pz-pop__amount">1 per ~1,800 characters</span></div>
					<div class="pz-pop__row"><span>A PDF or document</span><span class="pz-pop__amount">1 per ~1.5 pages</span></div>
					<div class="pz-pop__row"><span>An image</span><span class="pz-pop__amount">1</span></div>
					<p class="pz-muted">Units count what <?php echo esc_html( $name ); ?> knows now. They don't reset each month; removing content frees them.</p>
				</div>
			</div>
		<?php endif; ?>

		<div class="pz-columns">
			<div class="pz-card">
				<div class="pz-knows__head">
					<h2>What <?php echo esc_html( $name ); ?> knows about your site</h2>
					<?php if ( $model['any_full'] ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( $model['app_url'] . '/subscription' ); ?>" target="_blank" rel="noopener">Upgrade for room</a>
					<?php endif; ?>
				</div>
				<p class="pz-muted">Changes on your site reach <?php echo esc_html( $name ); ?> within seconds<?php echo $model['any_full'] ? ' — while the plan has room' : ''; ?>. Switch sources on and off on <a href="<?php echo esc_url( $model['dashboard'] ); ?>" target="_blank" rel="noopener">personaizer.com</a>.</p>
				<div class="pz-sources">
					<div class="pz-source pz-source--head"><span>Source</span><span>Known to <?php echo esc_html( $name ); ?></span><span>Status</span><span class="pz-right">Last checked</span></div>
					<?php foreach ( $model['streams'] as $stream ) : ?>
						<?php
						$q = $stream['queue'];
						$c = $stream['check'];
						?>
						<div class="pz-source">
							<div class="pz-source__name">
								<span class="pz-source__icon"><?php echo wp_kses( $icon( $stream['icon'], 18 ), $allowed_svg ); ?></span>
								<div>
									<strong><?php echo esc_html( $stream['label'] ); ?></strong>
									<?php if ( $stream['enabled'] && $q['queued'] ) : ?>
										<span class="pz-tag"><?php echo (int) $q['queued']; ?> queued</span>
									<?php elseif ( $q['queued'] ) : ?>
										<span class="pz-tag" title="Waiting for the source to be switched on"><?php echo (int) $q['queued']; ?> waiting (off)</span>
									<?php endif; ?>
									<?php if ( $q['failed'] ) : ?>
										<span class="pz-tag pz-tag--err"><?php echo (int) $q['failed']; ?> refused</span>
									<?php endif; ?>
									<?php if ( $stream['failed'] ) : ?>
										<span class="pz-tag pz-tag--err"><?php echo (int) $stream['failed']; ?> failed to index</span>
									<?php endif; ?>
								</div>
							</div>
							<?php if ( $stream['enabled'] ) : ?>
								<?php
								$total = max( $stream['local'], (int) $stream['synced'] );
								$share = $total > 0 ? max( (int) $stream['synced'] > 0 ? 1.5 : 0, (int) $stream['synced'] / $total * 100 ) : 0;
								?>
								<div class="pz-known">
									<span><strong><?php echo esc_html( number_format_i18n( (int) $stream['synced'] ) ); ?></strong> <span class="pz-muted">of <?php echo esc_html( number_format_i18n( $total ) ); ?> known</span></span>
									<div class="pz-known__bar"><div class="pz-known__fill<?php echo $stream['full'] ? ' pz-known__fill--full' : ''; ?>" style="width: <?php echo esc_attr( number_format( $share, 1, '.', '' ) ); ?>%"></div></div>
								</div>
							<?php else : ?>
								<span class="pz-muted"><?php echo esc_html( number_format_i18n( (int) $stream['local'] ) ); ?> on your site</span>
							<?php endif; ?>
							<span>
								<?php if ( ! $stream['offered'] ) : ?>
									<span class="pz-muted">Not switched on</span>
								<?php elseif ( ! $stream['enabled'] ) : ?>
									<span class="pz-badge">Off</span>
								<?php elseif ( $stream['full'] ) : ?>
									<span class="pz-badge pz-badge--full">Plan full</span>
								<?php elseif ( $stream['status'] === 'ready' ) : ?>
									<span class="pz-badge pz-badge--on">Ready</span>
								<?php else : ?>
									<span class="pz-badge"><span class="spinner is-active pz-spinner"></span><?php echo esc_html( $status_words[ $stream['status'] ] ?? 'Syncing' ); ?></span>
								<?php endif; ?>
							</span>
							<span class="pz-right pz-muted">
								<?php if ( $c && isset( $c['error'] ) ) : ?>
									Failed: <?php echo esc_html( $c['error'] ); ?>
								<?php elseif ( $c ) : ?>
									<?php echo esc_html( Page::ago( (int) $c['at'] ) ); ?>
								<?php elseif ( $stream['enabled'] ) : ?>
									never
								<?php endif; ?>
							</span>
						</div>
					<?php endforeach; ?>
				</div>
				<p class="pz-muted pz-status-line">
					<?php
					if ( $model['backfill']['running'] ) :
						?>
						<span class="spinner is-active pz-spinner"></span> Queuing everything that already exists (<?php echo (int) $model['backfill']['enqueued']; ?> so far)…
						<?php
					elseif ( $model['reconcile']['running'] ) :
						?>
						<span class="spinner is-active pz-spinner"></span> Checking <?php echo esc_html( $model['reconcile']['stream'] ?: 'sources' ); ?>…
						<?php
					elseif ( $queued > 0 ) :
						?>
						<span class="spinner is-active pz-spinner"></span> Sending <?php echo (int) $queued; ?> records…
						<?php
					else :
						?>
						Last sent <?php echo esc_html( Page::ago( $model['last_push'] ) ); ?>.<?php endif; ?>
					<?php
					if ( $deferred > 0 ) :
						?>
						<strong><?php echo (int) $deferred; ?> records are waiting for room</strong> — <a href="<?php echo esc_url( $model['app_url'] . '/subscription' ); ?>" target="_blank" rel="noopener">upgrade</a>.<?php endif; ?>
					<?php
					if ( $model['cron_off'] ) :
						?>
						<br><em>WP-Cron is disabled on this site — make sure a system cron calls wp-cron.php, or syncing only happens while someone is in wp-admin.</em><?php endif; ?>
				</p>
				<?php if ( $model['failures'] ) : ?>
					<details class="pz-failures"><summary><?php echo (int) $failed; ?> records were refused — retried daily</summary>
						<ul>
						<?php
						foreach ( $model['failures'] as $f ) :
							?>
							<li><code><?php echo esc_html( $f->external_id ); ?></code> — <?php echo esc_html( $f->last_error ); ?></li><?php endforeach; ?></ul>
					</details>
				<?php endif; ?>
				<?php if ( $sync_status !== State::GONE ) : ?>
					<p class="pz-actions"><a class="button" href="<?php echo esc_url( Page::action_url( Flow::ACTION_SYNC_NOW ) ); ?>">Sync now</a></p>
				<?php endif; ?>
			</div>

			<div class="pz-rail">
				<div class="pz-card">
					<form method="post" action="options.php">
						<?php settings_fields( 'personaizer' ); ?>
						<h2>Visitors</h2>
						<label class="pz-check"><input type="checkbox" name="<?php echo esc_attr( Options::IDENTIFY_USERS ); ?>" value="1" <?php checked( Options::identify_users() ); ?>>
							<span><strong>Recognise signed-in customers</strong>
							<span class="pz-muted">They skip the contact form: name, email and phone come from their account.</span></span>
						</label>
						<?php submit_button( 'Save', 'secondary', 'submit', false ); ?>
					</form>
				</div>

				<div class="pz-card">
					<?php if ( $persona ) : ?>
						<h2>Chat widget <span class="pz-badge pz-badge--on">On</span></h2>
						<p class="pz-muted">The chat bubble shows on every page of your site.</p>
						<a class="button pz-block" href="<?php echo esc_url( $model['app_url'] . '/persona/' . $persona['id'] ); ?>" target="_blank" rel="noopener">Widget appearance</a>
					<?php else : ?>
						<h2>Chat widget <span class="pz-badge">Off</span></h2>
						<p class="pz-muted">Visitors don't see the chat yet.</p>
						<a class="button button-primary pz-block" href="<?php echo esc_url( $model['dashboard'] ); ?>" target="_blank" rel="noopener">Pick a persona</a>
					<?php endif; ?>
				</div>

				<p class="pz-disconnect"><a href="<?php echo esc_url( Page::action_url( Flow::ACTION_DISCONNECT ) ); ?>" data-pz-confirm="Disconnect this site? The widget and syncing stop. Nothing is deleted on PERSONAIZER; connecting again resumes where you left off.">Disconnect this site</a></p>
			</div>
		</div>

		<details class="pz-sysinfo"><summary>System info</summary>
			<textarea readonly rows="8">
			<?php
				echo esc_textarea(
					implode(
						"\n",
						array(
							'Plugin ' . PERSONAIZER_VERSION,
							'API ' . PERSONAIZER_API_URL,
							'App ' . PERSONAIZER_APP_URL,
							'WordPress ' . get_bloginfo( 'version' ) . ' · PHP ' . PHP_VERSION . ( class_exists( 'WooCommerce' ) ? ' · WooCommerce ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '' ) : '' ),
							'Integration ' . get_option( Options::INTEGRATION_ID, '' ) . ' · brand ' . get_option( Options::BRAND_ID, '' ) . ' · persona ' . Options::persona_id(),
							'Status ' . $sync_status . ' · reachable ' . ( $model['reachable'] ? 'yes' : 'no' ) . ' · WP-Cron ' . ( $model['cron_off'] ? 'disabled' : 'on' ),
							'Outbox queued ' . $queued . ' · waiting (stream off) ' . $parked . ' · plan full ' . $deferred . ' · failed ' . $failed,
							$model['error'] ? 'Last error ' . $model['error']['message'] . ' (' . $model['error']['code'] . ', ' . Page::ago( (int) $model['error']['at'] ) . ')' : 'Last error none',
						)
					)
				);
			?>
			</textarea>
		</details>
	<?php endif; ?>
</div>
	<?php
} )( $model );
