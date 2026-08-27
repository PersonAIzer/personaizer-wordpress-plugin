/**
 * PERSONAIZER admin settings page.
 *
 * Registered/enqueued via admin_enqueue_scripts (not a raw <script> echo) so it plays by WordPress's
 * own dependency and caching rules — see the personaizer_chat_page() docblock in personaizer-chat.php.
 *
 * window.PersonaizerAdminPage is optional and set inline by PHP only when the page needs to poll:
 * { autoReload: true } while the persona is still building / content is still syncing.
 */
( function () {
    // Reveal/hide a lane's "keep up to date" sub-toggle the instant its on/off switch flips — before
    // Save. The server only renders the SAVED state; this mirrors the switch's live checked state onto
    // .pz-lane-off, and the CSS shows/hides the sub-toggle + notes from that class. (The real value is
    // still decided server-side on Save by personaizer_apply_lane_settings.) A no-op when the page has
    // no .pz-lane rows (not yet connected).
    document.querySelectorAll( '.pz-lane' ).forEach( function ( lane ) {
        var sw = lane.querySelector( '.pz-lane-head .pz-switch input[type=checkbox]' );
        if ( ! sw ) { return; }
        sw.addEventListener( 'change', function () {
            lane.classList.toggle( 'pz-lane-off', ! sw.checked );
        } );
    } );

    // ONE refresh for the whole page, decided once. Three things can be in flight — the persona
    // building (ours), the content backfill (this site's cron), and the backend still processing
    // already-pushed docs — each could otherwise arm its own timer, so this keeps it to a single reload.
    if ( window.PersonaizerAdminPage && window.PersonaizerAdminPage.autoReload ) {
        setTimeout( function () { location.reload(); }, 6000 );
    }
} )();
