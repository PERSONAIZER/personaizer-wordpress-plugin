/**
 * PERSONAIZER admin page. window.PersonaizerAdminPage is set inline by PHP: { autoReload: true } while something
 * is in flight (the persona building, the backfill, a check, records queued), so the numbers move on their own.
 * One reload for the whole page. On a quiet site the reload is also what fires WP-Cron.
 *
 * Connect opens personaizer.com in a new tab; once the owner comes back to this one it reloads, so the page
 * shows "connected" without a manual refresh. (The new tab ends on this page too — the callback lands here.)
 */
( function () {
    if ( window.PersonaizerAdminPage && window.PersonaizerAdminPage.autoReload ) {
        setTimeout( function () { location.reload(); }, 6000 );
    }

    var connect = document.querySelector( '[data-pz-connect]' );
    if ( ! connect ) return;
    var away = false;
    connect.addEventListener( 'click', function () { away = true; } );
    document.addEventListener( 'visibilitychange', function () {
        if ( away && document.visibilityState === 'visible' ) location.reload();
    } );
} )();
