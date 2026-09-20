/**
 * PERSONAIZER admin page. window.PersonaizerAdminPage is set inline by PHP: { autoReload: true } while something
 * is in flight (the persona building, the backfill, a check, records queued), so the numbers move on their own.
 * One reload for the whole page. On a quiet site the reload is also what fires WP-Cron.
 */
( function () {
    if ( window.PersonaizerAdminPage && window.PersonaizerAdminPage.autoReload ) {
        setTimeout( function () { location.reload(); }, 6000 );
    }
} )();
