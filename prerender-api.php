<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * HEIM:SPIEL Prerender API
 * Zwei neue, AUTH-geschuetzte REST-Endpunkte (im Gegensatz zu den bisherigen
 * oeffentlichen GET-Routen in rest-api.php) fuer den automatisierten
 * SEO-Prerendering-Workflow (GitHub Actions + Puppeteer):
 *
 *   POST /wp-json/hs-cache/v1/prerender/media  -- Bild-Upload in die Mediathek
 *   POST /wp-json/hs-cache/v1/prerender/page   -- Statisches HTML in eine Seite schreiben
 *
 * Auth: WordPress Application Password (Basic Auth), Capability-Check via
 * permission_callback -- KEIN __return_true wie bei den Lese-Endpunkten.
 *
 * WICHTIG (lokal, nicht nur im Repo): Diese Datei gehoert in den lokalen
 * includes/-Ordner des Plugins (analog zu cache.php, rest-api.php, etc.),
 * bevor das Plugin neu gezippt und in WordPress hochgeladen wird.
 */

add_action( 'rest_api_init', 'hs_register_prerender_routes' );

function hs_register_prerender_routes() {

	register_rest_route( 'hs-cache/v1', '/prerender/media', [
		'methods'             => 'POST',
		'callback'            => 'hs_rest_prerender_upload_media',
		'permission_callback' => function() {
			return current_user_can( 'upload_files' );
		},
		'args' => [
			'filename'    => [ 'required' => true, 'sanitize_callback' => 'sanitize_file_name' ],
			'mime_type'   => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
			'data_base64' => [ 'required' => true ],
			'alt_text'    => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
			'title'       => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
		],
	] );

	register_rest_route( 'hs-cache/v1', '/prerender/page', [
		'methods'             => 'POST',
		'callback'            => 'hs_rest_prerender_write_page',
		'permission_callback' => function() {
			return current_user_can( 'edit_pages' ) && current_user_can( 'unfiltered_html' );
		},
		'args' => [
			'page_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
			'html'    => [ 'required' => true ],
		],
	] );
}

/**
 * Nimmt Base64-Bilddaten entgegen (bereits als WebP konvertiert, das passiert
 * im Node-Skript VOR dem Upload), speichert sie in der Mediathek und setzt
 * automatisch Alt-Text und Titel -- wichtig fuer Bild-SEO.
 */
function hs_rest_prerender_upload_media( WP_REST_Request $request ) {
	$filename   = $request->get_param( 'filename' );
	$mime_type  = $request->get_param( 'mime_type' );
	$b64        = $request->get_param( 'data_base64' );
	$alt_text   = $request->get_param( 'alt_text' ) ?: '';
	$title      = $request->get_param( 'title' ) ?: $filename;

	$binary = base64_decode( $b64, true );
	if ( $binary === false ) {
		return new WP_REST_Response( [ 'error' => 'Ungueltige Base64-Daten.' ], 400 );
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$upload = wp_upload_bits( $filename, null, $binary );
	if ( ! empty( $upload['error'] ) ) {
		return new WP_REST_Response( [ 'error' => $upload['error'] ], 500 );
	}

	$attachment = [
		'post_mime_type' => $mime_type,
		'post_title'     => $title,
		'post_content'   => '',
		'post_status'    => 'inherit',
	];
	$attach_id = wp_insert_attachment( $attachment, $upload['file'] );
	if ( is_wp_error( $attach_id ) ) {
		return new WP_REST_Response( [ 'error' => $attach_id->get_error_message() ], 500 );
	}

	$attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
	wp_update_attachment_metadata( $attach_id, $attach_data );

	if ( $alt_text ) {
		update_post_meta( $attach_id, '_wp_attachment_image_alt', $alt_text );
	}

	return new WP_REST_Response( [
		'id'  => $attach_id,
		'url' => $upload['url'],
	], 200 );
}

/**
 * Ersetzt den post_content einer bestehenden Seite durch das per Puppeteer
 * erzeugte, vollstaendig gerenderte HTML. unfiltered_html-Capability-Check
 * im permission_callback stellt sicher, dass <script>-Tags (fuer die
 * weiterhin eingebundene hs-landing.js, Progressive Enhancement) NICHT von
 * wp_kses beim Speichern entfernt werden.
 */
function hs_rest_prerender_write_page( WP_REST_Request $request ) {
	$page_id = $request->get_param( 'page_id' );
	$html    = $request->get_param( 'html' );

	$post = get_post( $page_id );
	if ( ! $post || $post->post_type !== 'page' ) {
		return new WP_REST_Response( [ 'error' => 'Seite ID ' . $page_id . ' nicht gefunden.' ], 404 );
	}

	$result = wp_update_post( [
		'ID'           => $page_id,
		'post_content' => $html,
	], true );

	if ( is_wp_error( $result ) ) {
		return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 500 );
	}

	return new WP_REST_Response( [
		'ok'      => true,
		'page_id' => $page_id,
		'link'    => get_permalink( $page_id ),
	], 200 );
}
