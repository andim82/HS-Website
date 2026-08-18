<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * HEIM:SPIEL Prerender Snapshot Writeback -- v1
 *
 * Nimmt den von scripts/snapshot-pages.mjs (Puppeteer) erzeugten,
 * fertig gerenderten HTML-Inhalt entgegen und schreibt ihn GEZIELT nur
 * in den Bereich <div id="hs-root">...</div> der jeweiligen Seite --
 * der Rest des Seiteninhalts (WP-Bloecke, das <script src=".../hs-
 * landing.js">-Tag) bleibt unangetastet.
 *
 * WARUM NICHT den kompletten post_content ueberschreiben:
 * hs-landing.js rendert bei jedem Seitenaufruf per JS in #hs-root neu.
 * Der Snapshot dient NUR als statischer Erstladungs-Zustand fuer
 * Suchmaschinen-Crawler und schnelleres LCP -- sobald ein echter Browser
 * die Seite laedt, ueberschreibt hs-landing.js den Inhalt ohnehin wieder
 * mit den aktuellsten Live-Daten. Die Seite "heilt" sich also selbst,
 * falls ein Snapshot mal veraltet sein sollte.
 *
 * WARUM KEIN Regex bis zur schliessenden </div>: Der gerenderte Inhalt
 * enthaelt selbst verschachtelte <div>-Tags, wodurch ein naives Regex-
 * Matching auf die "erste" schliessende </div> IMMER an der falschen
 * Stelle abbrechen wuerde. Stattdessen wird der feste Anker des NAECHSTEN
 * <script>-Tags (hs-landing.js) genutzt, um das Ende des zu ersetzenden
 * Bereichs zu bestimmen -- das ist robust unabhaengig von der Verschachtelung.
 *
 * VORAUSSETZUNG: In wp-config.php muss definiert sein:
 *   define( 'HS_SNAPSHOT_API_KEY', 'ein-langes-zufaelliges-secret' );
 * Derselbe Wert muss als GitHub Actions Repository-Secret
 * HS_SNAPSHOT_API_KEY hinterlegt werden (siehe scripts/push-snapshots.mjs).
 */

add_action( 'rest_api_init', function () {
	register_rest_route( 'hs-prerender/v1', '/snapshot', [
		'methods'             => 'POST',
		'callback'            => 'hs_prerender_write_snapshot',
		'permission_callback' => 'hs_prerender_check_snapshot_key',
		'args'                => [
			'url'  => [ 'required' => true, 'type' => 'string' ],
			'html' => [ 'required' => true, 'type' => 'string' ],
		],
	] );
} );

function hs_prerender_check_snapshot_key( WP_REST_Request $req ) {
	if ( ! defined( 'HS_SNAPSHOT_API_KEY' ) || ! HS_SNAPSHOT_API_KEY ) {
		return new WP_Error( 'no_key_configured', 'HS_SNAPSHOT_API_KEY ist nicht in wp-config.php definiert.', [ 'status' => 500 ] );
	}
	$provided = $req->get_header( 'x-hs-snapshot-key' );
	if ( ! $provided || ! hash_equals( HS_SNAPSHOT_API_KEY, $provided ) ) {
		return new WP_Error( 'forbidden', 'Ungueltiger oder fehlender X-HS-Snapshot-Key Header.', [ 'status' => 403 ] );
	}
	return true;
}

/**
 * Loest eine vollstaendige URL zuverlaessig auf eine Post-ID auf.
 * url_to_postid() allein scheitert manchmal an Trailing-Slash- oder
 * Query-String-Abweichungen -- daher mehrere Normalisierungsversuche.
 */
function hs_prerender_resolve_post_id( $url ) {
	$candidates = [ $url ];

	$parsed = wp_parse_url( $url );
	if ( $parsed && isset( $parsed['path'] ) ) {
		$path = $parsed['path'];
		$candidates[] = home_url( $path );
		$candidates[] = home_url( untrailingslashit( $path ) );
		$candidates[] = home_url( trailingslashit( $path ) );
	}

	foreach ( array_unique( $candidates ) as $candidate ) {
		$id = url_to_postid( $candidate );
		if ( $id ) return $id;
	}

	return 0;
}

function hs_prerender_write_snapshot( WP_REST_Request $req ) {
	$url  = esc_url_raw( $req->get_param( 'url' ) );
	$html = (string) $req->get_param( 'html' );

	if ( trim( $html ) === '' ) {
		return new WP_Error( 'empty_html', 'Leerer HTML-Inhalt uebergeben.', [ 'status' => 400 ] );
	}

	$post_id = hs_prerender_resolve_post_id( $url );
	if ( ! $post_id ) {
		return new WP_Error( 'not_found', 'Keine WP-Seite fuer URL "' . $url . '" gefunden.', [ 'status' => 404 ] );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'not_found', 'Post-ID ' . $post_id . ' existiert nicht.', [ 'status' => 404 ] );
	}

	$content = $post->post_content;

	// Oeffnendes hs-root-Tag finden (Attribute wie data-type/data-bundle erhalten).
	if ( ! preg_match( '/<div\s+id=["\']hs-root["\']([^>]*)>/i', $content, $open_match, PREG_OFFSET_CAPTURE ) ) {
		return new WP_Error( 'no_hs_root', 'Kein <div id="hs-root"> in dieser Seite gefunden -- Provisioner-Shell fehlt.', [ 'status' => 422 ] );
	}

	$open_tag_full  = $open_match[0][0];
	$open_tag_start = $open_match[0][1];
	$open_tag_end   = $open_tag_start + strlen( $open_tag_full );

	// Naechstes <script -- Tag NACH dem hs-root-Div als festen Endanker suchen.
	$rest_of_content = substr( $content, $open_tag_end );
	if ( ! preg_match( '/<script[^>]*>/i', $rest_of_content, $script_match, PREG_OFFSET_CAPTURE ) ) {
		return new WP_Error( 'no_script_anchor', 'Kein <script>-Tag nach #hs-root gefunden -- Ersetzung abgebrochen (Sicherheitsnetz).', [ 'status' => 422 ] );
	}
	$script_start_relative = $script_match[0][1];
	$script_start_absolute = $open_tag_end + $script_start_relative;

	// Alles zwischen Ende des oeffnenden hs-root-Tags und Beginn des <script>-Tags
	// (das ist der aktuelle #hs-root-Inhalt inkl. seiner schliessenden </div>)
	// wird durch: neuer Inhalt + </div> ersetzt.
	$before = substr( $content, 0, $open_tag_end );
	$after  = substr( $content, $script_start_absolute );

	$new_content = $before . $html . '</div>' . $after;

	$update = wp_update_post( [
		'ID'           => $post_id,
		'post_content' => $new_content,
	], true );

	if ( is_wp_error( $update ) ) {
		return new WP_Error( 'update_failed', $update->get_error_message(), [ 'status' => 500 ] );
	}

	update_post_meta( $post_id, '_hs_last_snapshot_at', current_time( 'mysql' ) );
	update_post_meta( $post_id, '_hs_last_snapshot_url', $url );

	return [
		'ok'      => true,
		'post_id' => $post_id,
		'url'     => $url,
		'title'   => get_the_title( $post_id ),
	];
}
