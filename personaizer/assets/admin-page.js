/**
 * PERSONAIZER admin settings page.
 *
 * Registered/enqueued via admin_enqueue_scripts (not a raw <script> echo) so it plays by WordPress's
 * own dependency and caching rules — see the personaizer_chat_page() docblock in personaizer.php.
 *
 * window.PersonaizerAdminPage is optional and set inline by PHP only when the page needs to poll:
 * { autoReload: true } while the persona is still building / content is still syncing.
 */
( function () {
    // ONE refresh for the whole page, decided once. Four things can be in flight — the persona
    // building (ours), the content backfill and the stream check (this site's cron), and the backend still
    // processing already-pushed docs — each could otherwise arm its own timer, so this keeps it to a
    // single reload.
    if ( window.PersonaizerAdminPage && window.PersonaizerAdminPage.autoReload ) {
        setTimeout( function () { location.reload(); }, 6000 );
    }
} )();
