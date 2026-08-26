<?php
/**
 * Plugin Name: HEIM:SPIEL REST gzip
 * Description: Komprimiert die Antworten der hs-cache-REST-Endpunkte mit gzip.
 *              Der Webserver liefert application/json aktuell unkomprimiert aus,
 *              wodurch hs-landing.js pro Seitenaufruf rund 580 KB JSON laedt
 *              (coverage 444,6 KB + indexDe 126,0 KB + generalIndexDe 9,5 KB).
 *              Mit gzip sind es zusammen etwa 43 KB -- rund 93 Prozent weniger.
 *
 *              Wirkt ausschliesslich auf Routen unter /hs-cache/v1/. Alle
 *              anderen REST-Endpunkte, das WordPress-Backend und normale
 *              Seitenaufrufe bleiben unberuehrt.
 * Version:     1.0.0
 * Author:      HEIM:SPIEL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'rest_pre_serve_request', 'hs_rest_gzip_serve', 10, 4 );

/**
 * Uebernimmt das Ausliefern der Antwort selbst, um sie gzip-komprimiert zu
 * senden. Der Filter laeuft an genau der Stelle, an der der REST-Server sonst
 * json_encode() aufrufen und ausgeben wuerde -- Content-Type und die uebrigen
 * Header sind zu diesem Zeitpunkt bereits gesetzt, wir ergaenzen nur
 * Content-Encoding, Vary und Content-Length.
 *
 * Rueckgabe true bedeutet: "bereits ausgeliefert, nichts weiter tun".
 * Bei jeder Unsicherheit wird $served unveraendert zurueckgegeben, sodass der
 * normale, unkomprimierte Weg greift -- die Antwort kommt also in jedem Fall an.
 *
 * @param  bool             $served  Wurde die Anfrage schon ausgeliefert?
 * @param  WP_HTTP_Response $result  Das Ergebnisobjekt.
 * @param  WP_REST_Request  $request Die Anfrage.
 * @param  WP_REST_Server   $server  Der REST-Server.
 * @return bool
 */
function hs_rest_gzip_serve( $served, $result, $request, $server ) {

	// Ein anderer Filter war schneller -> nicht eingreifen.
	if ( $served ) {
		return $served;
	}

	// Nur unsere eigenen Endpunkte.
	if ( ! $request instanceof WP_REST_Request ) {
		return $served;
	}
	if ( 0 !== strpos( (string) $request->get_route(), '/hs-cache/v1/' ) ) {
		return $served;
	}

	// Nur lesende Anfragen.
	if ( 'GET' !== strtoupper( (string) $request->get_method() ) ) {
		return $served;
	}

	// JSONP wuerde ein anderes Ausgabeformat brauchen -> nicht anfassen.
	if ( isset( $_GET['_jsonp'] ) ) {
		return $served;
	}

	// Client muss gzip akzeptieren.
	$accept = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] )
		? strtolower( (string) $_SERVER['HTTP_ACCEPT_ENCODING'] )
		: '';
	if ( false === strpos( $accept, 'gzip' ) ) {
		return $served;
	}

	// Voraussetzungen pruefen. Wenn PHP bereits selbst komprimiert, wuerde ein
	// zweiter Durchgang die Antwort zerstoeren.
	if ( ! function_exists( 'gzencode' ) ) {
		return $served;
	}
	if ( ini_get( 'zlib.output_compression' ) ) {
		return $served;
	}
	if ( headers_sent() ) {
		return $served;
	}
	// Falls schon ein Encoding gesetzt wurde (z.B. durch mod_deflate oder ein
	// Cache-Plugin), nicht zusaetzlich komprimieren.
	foreach ( headers_list() as $sent ) {
		if ( 0 === stripos( $sent, 'content-encoding:' ) ) {
			return $served;
		}
	}

	// Daten so aufbereiten, wie der REST-Server es auch tun wuerde.
	$embed = isset( $_GET['_embed'] ) ? $_GET['_embed'] : false;
	$data  = $server->response_to_data( $result, $embed );

	$json = wp_json_encode( $data );
	if ( false === $json || '' === $json ) {
		return $served;
	}

	$gz = gzencode( $json, 6 );
	if ( false === $gz || strlen( $gz ) >= strlen( $json ) ) {
		// Kompression hat nichts gebracht (sehr kleine Antworten) -> normal ausliefern.
		return $served;
	}

	header( 'Content-Encoding: gzip' );
	header( 'Vary: Accept-Encoding', false );
	header( 'Content-Length: ' . strlen( $gz ) );
	header( 'X-HS-Gzip: ' . strlen( $json ) . '->' . strlen( $gz ) );

	echo $gz; // phpcs:ignore WordPress.Security.EscapeOutput -- bereits kodiertes JSON.

	return true;
}
