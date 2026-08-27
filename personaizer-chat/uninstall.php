<?php
/**
 * Fires when the user deletes the plugin from WordPress.
 *
 * Removes every trace of the account from this site. The list itself lives in Personaizer_Data —
 * the same one the in-admin "Disconnect" uses — so the two can never drift apart and strand a
 * credential on the site after the plugin that owned it is gone.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-personaizer-data.php';

Personaizer_Data::clear();
