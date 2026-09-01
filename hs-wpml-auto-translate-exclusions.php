<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Schliesst per HS Provisioner erzeugte Landingpages von WPMLs
 * automatischer Uebersetzung aus.
 *
 * Der Provisioner setzt beim Erstellen:
 * _hs_no_auto_translate = true
 *
 * WPML >= 4.6.7 stellt dafuer den offiziellen Filter
 * wpml_exclude_post_from_auto_translate bereit.
 */
add_filter( 'wpml_exclude_post_from_auto_translate', function( $exclude, $post_id ) {
    if ( get_post_meta( $post_id, '_hs_no_auto_translate', true ) ) {
        return true;
    }

    return $exclude;
}, 10, 2 );