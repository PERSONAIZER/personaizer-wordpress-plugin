/**
 * PERSONAIZER admin page. window.PersonaizerAdminPage is set inline by PHP: { autoReload: true } while something
 * is in flight (the persona building, the backfill, a check, records queued), so the numbers move on their own.
 * One reload for the whole page. On a quiet site the reload is also what fires WP-Cron.
 *
 * Connect opens personaizer.com in a new tab; once the owner comes back to this one it reloads, so the page
 * shows "connected" without a manual refresh. (The new tab ends on this page too — the callback lands here.)
 */
( function () {
    // The notice flags (?pz_connected=1 …) are for the page they land on. Every reload below re-requests the same
    // URL, so drop them now or the one-line notice outlives what it announced.
    if ( window.history && window.history.replaceState && /[?&]pz_/.test( location.search ) ) {
        var params = new URLSearchParams( location.search );
        Array.from( params.keys() ).forEach( function ( k ) { if ( k.indexOf( 'pz_' ) === 0 ) params.delete( k ); } );
        window.history.replaceState( null, '', location.pathname + '?' + params.toString() + location.hash );
    }

    if ( window.PersonaizerAdminPage && window.PersonaizerAdminPage.autoReload ) {
        setTimeout( function () { location.reload(); }, 6000 );
    }

    // Disconnect asks first. The question sits on the button (data-pz-confirm) so the markup stays free of inline handlers.
    document.querySelectorAll( '[data-pz-confirm]' ).forEach( function ( el ) {
        el.addEventListener( 'click', function ( e ) {
            if ( ! window.confirm( el.getAttribute( 'data-pz-confirm' ) ) ) e.preventDefault();
        } );
    } );

    // The ⓘ on the plan opens what each action costs in credits; a click elsewhere or Escape closes it.
    var info = document.querySelector( '[data-pz-credits]' );
    var credits = document.querySelector( '.pz-credits' );
    if ( info && credits ) {
        var showCredits = function ( open ) {
            credits.hidden = ! open;
            info.setAttribute( 'aria-expanded', String( open ) );
        };
        info.addEventListener( 'click', function ( e ) { e.stopPropagation(); showCredits( credits.hidden ); } );
        document.addEventListener( 'click', function ( e ) { if ( ! credits.contains( e.target ) ) showCredits( false ); } );
        document.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Escape' ) showCredits( false ); } );
    }

    var connect = document.querySelector( '[data-pz-connect]' );
    if ( ! connect ) return;
    var away = false;
    connect.addEventListener( 'click', function () { away = true; } );
    document.addEventListener( 'visibilitychange', function () {
        if ( away && document.visibilityState === 'visible' ) location.reload();
    } );
} )();
