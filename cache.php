<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PHP-Aequivalent der Frontend-slugify()-Funktion aus hs-landing.js.
 * Transliteriert deutsche Sonderzeichen (insb. "ß") zu reinem ASCII.
 */
function hs_slugify( $str ) {
	if ( $str === null ) return '';
	$str = trim( (string) $str );
	$str = mb_strtolower( $str, 'UTF-8' );
	$str = str_replace(
		[ "\u{00e4}", "\u{00f6}", "\u{00fc}", "\u{00df}" ],
		[ 'ae', 'oe', 'ue', 'ss' ],
		$str
	);
	if ( function_exists( 'remove_accents' ) ) {
		$str = remove_accents( $str );
	}
	$str = preg_replace( '/[^a-z0-9]+/', '-', $str );
	$str = trim( $str, '-' );
	return $str;
}

/**
 * NEU (v2.0): Retry-Wrapper fuer externe Google-Apps-Script-Aufrufe.
 * Google Apps Script Web-Apps koennen nach laengerer Inaktivitaet einen
 * "Kaltstart" haben (Timeout oder einzelner Fehlschlag beim ersten Aufruf).
 * Statt beim ersten Fehler sofort aufzugeben und den alten Cache stehen zu
 * lassen, wird der Abruf bis zu HS_FETCH_MAX_RETRIES mal wiederholt, mit
 * steigender Wartezeit zwischen den Versuchen (einfaches Backoff).
 *
 * @param callable $fetch_fn Funktion ohne Argumente, die ein Array oder
 *                            WP_Error zurueckgibt (z.B. 'hs_fetch_index_de').
 * @param string   $label    Bezeichner fuer Logging/Fehlermeldungen.
 * @return array|WP_Error
 */
if ( ! defined( 'HS_FETCH_MAX_RETRIES' ) ) {
	define( 'HS_FETCH_MAX_RETRIES', 3 );
}
if ( ! defined( 'HS_FETCH_RETRY_DELAY_SECONDS' ) ) {
	define( 'HS_FETCH_RETRY_DELAY_SECONDS', 2 );
}

function hs_fetch_with_retry( callable $fetch_fn, $label ) {
	$last_error = null;

	for ( $attempt = 1; $attempt <= HS_FETCH_MAX_RETRIES; $attempt++ ) {
		$result = call_user_func( $fetch_fn );

		if ( ! is_wp_error( $result ) && is_array( $result ) && ! empty( $result ) ) {
			return $result;
		}

		$last_error = is_wp_error( $result )
			? $result->get_error_message()
			: ( is_array( $result ) ? 'Leeres Ergebnis (0 Zeilen)' : 'Unerwartetes Antwortformat' );

		if ( $attempt < HS_FETCH_MAX_RETRIES ) {
			// Backoff: 2s, 4s, 6s ... vor dem naechsten Versuch.
			sleep( HS_FETCH_RETRY_DELAY_SECONDS * $attempt );
		}
	}

	return new WP_Error(
		'fetch_failed_after_retries',
		$label . ': fehlgeschlagen nach ' . HS_FETCH_MAX_RETRIES . ' Versuchen (letzter Fehler: ' . $last_error . ')'
	);
}

/**
 * NEU (v2.0): Ersetzt die alte hs_refresh_all_cache() als Kernlogik.
 * Baut fuer JEDE einzelne Datenquelle einen eigenen Erfolgs-/Fehlerstatus
 * auf (statt nur einem globalen last_error-String), inkl. automatischer
 * Retries pro Quelle. Die Admin-Seite zeigt diese Detail-Ergebnisse jetzt
 * pro Zeile an, damit nie wieder unbemerkt eine Quelle stale bleibt.
 *
 * @return array{
 *   overall_success: bool,
 *   sources: array<string, array{success: bool, count: int, error: string|null}>
 * }
 */
function hs_refresh_all_cache_v2() {
	$report = [
		'overall_success' => true,
		'sources'         => [],
	];

	// ── 1. Index EN (kritisch: ohne Index kann nichts anderes gebaut werden) ──
	$index = hs_fetch_with_retry( 'hs_fetch_index', 'Index' );
	if ( is_wp_error( $index ) ) {
		$report['sources']['index'] = [
			'success' => false,
			'count'   => 0,
			'error'   => $index->get_error_message(),
		];
		$report['overall_success'] = false;
		update_option( 'hs_cache_refresh_report', $report );
		update_option( 'hs_cache_last_run', current_time( 'mysql' ) );
		update_option( 'hs_cache_last_error', hs_summarize_refresh_errors( $report ) );
		return $report;
	}
	set_transient( 'hs_index_data', $index, HS_CACHE_TTL );
	update_option( 'hs_cache_index_count', count( $index ) );
	$report['sources']['index'] = [ 'success' => true, 'count' => count( $index ), 'error' => null ];

	// ── 2. General Index EN ──────────────────────────────────────────────
	$general = hs_fetch_with_retry( 'hs_fetch_general_index', 'GeneralIndex' );
	if ( is_wp_error( $general ) ) {
		$report['sources']['generalIndex'] = [ 'success' => false, 'count' => 0, 'error' => $general->get_error_message() ];
		$report['overall_success'] = false;
	} else {
		set_transient( 'hs_general_index_data', $general, HS_CACHE_TTL );
		update_option( 'hs_cache_general_count', count( $general ) );
		$report['sources']['generalIndex'] = [ 'success' => true, 'count' => count( $general ), 'error' => null ];
	}

	// ── 3. Index DE ──────────────────────────────────────────────────────
	$index_de = hs_fetch_with_retry( 'hs_fetch_index_de', 'IndexDE' );
	if ( is_wp_error( $index_de ) ) {
		$report['sources']['indexDe'] = [ 'success' => false, 'count' => 0, 'error' => $index_de->get_error_message() ];
		$report['overall_success'] = false;
		// WICHTIG: Alten Cache NICHT loeschen, aber auch NICHT als aktuell
		// markieren -- die Admin-Seite zeigt separat an, wann Index DE
		// zuletzt ERFOLGREICH aktualisiert wurde (hs_cache_indexde_last_success).
	} else {
		set_transient( 'hs_index_de_data', $index_de, HS_CACHE_TTL );
		update_option( 'hs_cache_index_de_count', count( $index_de ) );
		update_option( 'hs_cache_indexde_last_success', current_time( 'mysql' ) );
		$report['sources']['indexDe'] = [ 'success' => true, 'count' => count( $index_de ), 'error' => null ];
	}

	// ── 4. General Index DE ──────────────────────────────────────────────
	$general_de = hs_fetch_with_retry( 'hs_fetch_general_index_de', 'GeneralIndexDE' );
	if ( is_wp_error( $general_de ) ) {
		$report['sources']['generalIndexDe'] = [ 'success' => false, 'count' => 0, 'error' => $general_de->get_error_message() ];
		$report['overall_success'] = false;
	} else {
		set_transient( 'hs_general_index_de_data', $general_de, HS_CACHE_TTL );
		update_option( 'hs_cache_general_de_count', count( $general_de ) );
		update_option( 'hs_cache_generalindexde_last_success', current_time( 'mysql' ) );
		$report['sources']['generalIndexDe'] = [ 'success' => true, 'count' => count( $general_de ), 'error' => null ];
	}

	// ── 5. CSVs pro Disziplin (gid aus EN-Index) ─────────────────────────
	$csv_errors  = [];
	$csv_success = 0;
	foreach ( $index as $disc ) {
		$gid = isset( $disc['gid'] ) ? $disc['gid'] : ( isset( $disc['Gid'] ) ? $disc['Gid'] : null );
		if ( ! $gid ) continue;

		$csv = hs_fetch_with_retry( function() use ( $gid ) { return hs_fetch_csv( $gid ); }, 'CSV gid ' . $gid );
		if ( is_wp_error( $csv ) ) {
			$csv_errors[] = 'GID ' . $gid . ': ' . $csv->get_error_message();
			continue;
		}
		set_transient( 'hs_csv_' . $gid, $csv, HS_CACHE_TTL );
		$csv_success++;
	}
	$report['sources']['csv'] = [
		'success' => empty( $csv_errors ),
		'count'   => $csv_success,
		'error'   => empty( $csv_errors ) ? null : implode( ' | ', $csv_errors ),
	];
	if ( ! empty( $csv_errors ) ) $report['overall_success'] = false;

	// ── 6. Coverage-Aggregation fuer alle Cluster-Bundles neu berechnen. ──
	// FIX (v1.9): discipline_key MIT Unterstrich (array_change_key_case()
	// entfernt keine Unterstriche -- der Key bleibt "discipline_key").
	$coverage_errors  = [];
	$coverage_success = 0;
	foreach ( $index as $row ) {
		$row_lc = array_change_key_case( $row, CASE_LOWER );
		$type   = isset( $row_lc['type'] ) ? strtolower( trim( $row_lc['type'] ) ) : '';

		$dk     = isset( $row_lc['discipline_key'] ) ? trim( $row_lc['discipline_key'] ) : '';
		$bn     = isset( $row_lc['bundlename'] ) ? trim( $row_lc['bundlename'] ) : '';
		$bundle = hs_slugify( $dk !== '' ? $dk : $bn );

		if ( $type === 'cluster' && $bundle !== '' ) {
			$agg = hs_build_coverage_for_sport( $bundle );
			if ( is_wp_error( $agg ) ) {
				$coverage_errors[] = $bundle . ': ' . $agg->get_error_message();
				continue;
			}
			set_transient( 'hs_coverage_' . $bundle, $agg, HS_CACHE_TTL );
			$coverage_success++;
		}
	}
	$report['sources']['coverage'] = [
		'success' => empty( $coverage_errors ),
		'count'   => $coverage_success,
		'error'   => empty( $coverage_errors ) ? null : implode( ' | ', $coverage_errors ),
	];
	if ( ! empty( $coverage_errors ) ) $report['overall_success'] = false;

	// ── 7. Bundle-Totals fuer alle Cluster-Bundles neu berechnen. ─────────
	$totals_errors  = [];
	$totals_success = 0;
	foreach ( $index as $row ) {
		$row_lc = array_change_key_case( $row, CASE_LOWER );
		$type   = isset( $row_lc['type'] ) ? strtolower( trim( $row_lc['type'] ) ) : '';

		$dk = isset( $row_lc['discipline_key'] ) ? trim( $row_lc['discipline_key'] ) : '';
		$bn = isset( $row_lc['bundlename'] ) ? trim( $row_lc['bundlename'] ) : '';
		$bundleSlug = hs_slugify( $dk !== '' ? $dk : $bn );

		if ( $type === 'cluster' && $bundleSlug !== '' ) {
			$totals = hs_build_bundle_totals( $bundleSlug );
			if ( is_wp_error( $totals ) ) {
				$totals_errors[] = $bundleSlug . ': ' . $totals->get_error_message();
				continue;
			}
			set_transient( 'hs_bundle_totals_' . $bundleSlug, $totals, HS_CACHE_TTL );
			$totals_success++;
		}
	}
	$report['sources']['bundleTotals'] = [
		'success' => empty( $totals_errors ),
		'count'   => $totals_success,
		'error'   => empty( $totals_errors ) ? null : implode( ' | ', $totals_errors ),
	];
	if ( ! empty( $totals_errors ) ) $report['overall_success'] = false;

	update_option( 'hs_cache_refresh_report', $report );
	update_option( 'hs_cache_last_run', current_time( 'mysql' ) );
	update_option( 'hs_cache_last_error', $report['overall_success'] ? '' : hs_summarize_refresh_errors( $report ) );

	return $report;
}

/** Baut einen kompakten Fehler-Uebersichtstext aus dem Refresh-Report. */
function hs_summarize_refresh_errors( array $report ) {
	$lines = [];
	foreach ( $report['sources'] as $key => $s ) {
		if ( ! $s['success'] ) {
			$lines[] = $key . ': ' . $s['error'];
		}
	}
	return implode( ' || ', $lines );
}

/**
 * BEIBEHALTEN fuer Abwaertskompatibilitaet (Cron-Hook in cron.php ruft
 * weiterhin 'hs_refresh_all_cache' auf). Ruft intern die neue v2-Funktion
 * auf und gibt weiterhin ein einfaches bool zurueck.
 *
 * @return bool true bei Erfolg (ALLE Quellen ok), false wenn mindestens
 *              eine Quelle fehlgeschlagen ist.
 */
function hs_refresh_all_cache() {
	$report = hs_refresh_all_cache_v2();
	return $report['overall_success'];
}

/** Generische Hilfsfunktion: Holt JSON von der Google Sheets Web App. */
function hs_fetch_gsheet_rows( $url, $preferred_keys = [], $label = 'Google Sheets Web App' ) {
	$response = wp_remote_get( $url, [
		'timeout'     => 30,
		'user-agent'  => 'HEIMSPIEL-WP-Cache/' . HS_CACHE_VERSION,
		'redirection' => 5,
	] );
	if ( is_wp_error( $response ) ) return $response;

	$code = wp_remote_retrieve_response_code( $response );
	if ( $code !== 200 ) {
		return new WP_Error( 'gsheet_error', $label . ' HTTP ' . $code );
	}

	$body = wp_remote_retrieve_body( $response );
	$json = json_decode( $body, true );
	if ( ! is_array( $json ) ) {
		return new WP_Error( 'json_error', 'Ungueltiges JSON von ' . $label );
	}

	foreach ( $preferred_keys as $key ) {
		if ( isset( $json[ $key ] ) && is_array( $json[ $key ] ) ) {
			return array_values( $json[ $key ] );
		}
	}

	$first = reset( $json );
	return is_array( $first ) ? array_values( $first ) : [];
}

function hs_fetch_index() {
	return hs_fetch_gsheet_rows( HS_GSHEET_INDEX_URL, [ 'Index', 'index' ], 'Google Sheets Web App (Index)' );
}

function hs_fetch_index_de() {
	return hs_fetch_gsheet_rows( HS_GSHEET_INDEX_DE_URL, [ 'Index_DE', 'indexDe', 'index_de' ], 'Google Sheets Web App (Index_DE)' );
}

function hs_fetch_general_index() {
	return hs_fetch_gsheet_rows( HS_GSHEET_GENERAL_URL, [ 'General_Index', 'generalIndex', 'general_index' ], 'Google Sheets Web App (General_Index)' );
}

function hs_fetch_general_index_de() {
	return hs_fetch_gsheet_rows( HS_GSHEET_GENERAL_DE_URL, [ 'General_Index_DE', 'generalIndexDe', 'general_index_de' ], 'Google Sheets Web App (General_Index_DE)' );
}

function hs_fetch_csv( $gid ) {
	$url = HS_GSHEET_CSV_BASE . $gid;
	$response = wp_remote_get( $url, [
		'timeout'    => 30,
		'user-agent' => 'HEIMSPIEL-WP-Cache/' . HS_CACHE_VERSION,
	] );
	if ( is_wp_error( $response ) ) return $response;

	$code = wp_remote_retrieve_response_code( $response );
	if ( $code !== 200 ) {
		return new WP_Error( 'csv_error', 'CSV HTTP ' . $code . ' (GID ' . $gid . ')' );
	}

	$text = wp_remote_retrieve_body( $response );
	return hs_parse_csv( $text );
}

function hs_parse_csv( $text ) {
	$text = str_replace( "\r\n", "\n", $text );
	$text = str_replace( "\r", "\n", $text );
	$lines = explode( "\n", trim( $text ) );
	if ( count( $lines ) < 2 ) return [];

	$headers = str_getcsv( $lines[0] );
	$headers = array_map( 'trim', $headers );
	$rows = [];

	foreach ( array_slice( $lines, 1 ) as $line ) {
		if ( trim( $line ) === '' ) continue;
		$vals = str_getcsv( $line );
		$obj = [];
		foreach ( $headers as $i => $h ) {
			$obj[ $h ] = isset( $vals[ $i ] ) ? trim( $vals[ $i ] ) : '';
		}
		$rows[] = $obj;
	}
	return $rows;
}

function hs_country_iso_map() {
	static $map = null;
	if ( $map === null ) {
		$map = [
			'Deutschland' => 'DE', 'Schweden' => 'SE', 'Brasilien' => 'BR', 'Italien' => 'IT',
			'Russland' => 'RU', 'Schweiz' => 'CH', 'Spanien' => 'ES', 'Frankreich' => 'FR',
			'Österreich' => 'AT', 'England' => 'GB', 'USA' => 'US', 'Argentinien' => 'AR',
			'Portugal' => 'PT', 'Niederlande' => 'NL', 'Belgien' => 'BE', 'Türkei' => 'TR',
			'Griechenland' => 'GR', 'Polen' => 'PL', 'Kroatien' => 'HR', 'Serbien' => 'RS',
			'Ukraine' => 'UA', 'Dänemark' => 'DK', 'Norwegen' => 'NO', 'Finnland' => 'FI',
			'Schottland' => 'GB-SCT', 'Wales' => 'GB-WLS', 'Irland' => 'IE', 'Tschechien' => 'CZ',
			'Slowakei' => 'SK', 'Ungarn' => 'HU', 'Rumänien' => 'RO', 'Bulgarien' => 'BG',
			'Mexiko' => 'MX', 'Kolumbien' => 'CO', 'Chile' => 'CL', 'Uruguay' => 'UY',
			'Peru' => 'PE', 'Ecuador' => 'EC', 'Venezuela' => 'VE', 'Paraguay' => 'PY',
			'Bolivien' => 'BO', 'Japan' => 'JP', 'Südkorea' => 'KR', 'China' => 'CN',
			'Australien' => 'AU', 'Kanada' => 'CA', 'Saudi-Arabien' => 'SA', 'Katar' => 'QA',
			'VAE' => 'AE', 'Ägypten' => 'EG', 'Marokko' => 'MA', 'Algerien' => 'DZ',
			'Tunesien' => 'TN', 'Nigeria' => 'NG', 'Südafrika' => 'ZA', 'Ghana' => 'GH',
			'Senegal' => 'SN', 'Kamerun' => 'CM', 'Elfenbeinküste' => 'CI', 'Israel' => 'IL',
			'Indien' => 'IN', 'Indonesien' => 'ID', 'Thailand' => 'TH', 'Vietnam' => 'VN',
			'Malaysia' => 'MY', 'Singapur' => 'SG', 'Philippinen' => 'PH', 'Neuseeland' => 'NZ',
			'Island' => 'IS', 'Slowenien' => 'SI', 'Bosnien' => 'BA', 'Nordmazedonien' => 'MK',
			'Albanien' => 'AL', 'Montenegro' => 'ME', 'Zypern' => 'CY', 'Malta' => 'MT',
			'Luxemburg' => 'LU', 'Weißrussland' => 'BY', 'Litauen' => 'LT', 'Lettland' => 'LV',
			'Estland' => 'EE', 'Georgien' => 'GE', 'Armenien' => 'AM', 'Aserbaidschan' => 'AZ',
			'Kasachstan' => 'KZ', 'Usbekistan' => 'UZ', 'Iran' => 'IR', 'Irak' => 'IQ',
			'Jordanien' => 'JO', 'Libanon' => 'LB', 'Kuwait' => 'KW', 'Bahrain' => 'BH',
			'Oman' => 'OM', 'Jemen' => 'YE', 'Costa Rica' => 'CR', 'Panama' => 'PA',
			'Honduras' => 'HN', 'Guatemala' => 'GT', 'Jamaika' => 'JM', 'Trinidad und Tobago' => 'TT',
			'Haiti' => 'HT', 'Kuba' => 'CU',
		];
	}
	return $map;
}

function hs_country_to_iso( $country_name ) {
	$map = hs_country_iso_map();
	return isset( $map[ $country_name ] ) ? $map[ $country_name ] : '';
}

/**
 * Ermittelt die gid der Cluster-Zeile fuer $sport und aggregiert deren CSV-Tab.
 * Findet die Cluster-Zeile ueber discipline_key ODER bundleName.
 *
 * @param string $sport Bundle-Name (bereits normalisiert/slugified erwartet)
 * @return array|WP_Error
 */
function hs_build_coverage_for_sport( $sport ) {
	$sport = hs_slugify( $sport );

	$index = get_transient( 'hs_index_data' );
	if ( $index === false ) {
		$index = hs_fetch_index();
		if ( is_wp_error( $index ) ) {
			return $index;
		}
	}

	$gid = null;
	$curated_ids = [];
	$bundleRaw = '';

	foreach ( $index as $row ) {
		$row_lc = array_change_key_case( $row, CASE_LOWER );
		$type   = isset( $row_lc['type'] ) ? strtolower( trim( $row_lc['type'] ) ) : '';
		if ( $type !== 'cluster' ) continue;

		$bundleNameSlug = isset( $row_lc['bundlename'] ) ? hs_slugify( $row_lc['bundlename'] ) : '';
		$disciplineKeySlug = isset( $row_lc['discipline_key'] ) ? hs_slugify( $row_lc['discipline_key'] ) : '';

		if ( $bundleNameSlug === $sport || $disciplineKeySlug === $sport ) {
			$gid = isset( $row_lc['gid'] ) ? trim( $row_lc['gid'] ) : null;
			$bundleRaw = isset( $row_lc['bundle'] ) ? trim( $row_lc['bundle'] ) : '';

			if ( isset( $row_lc['topcompetitions'] ) && trim( $row_lc['topcompetitions'] ) !== '' ) {
				$curated_ids = array_map( 'trim', explode( ',', $row_lc['topcompetitions'] ) );
				$curated_ids = array_filter( $curated_ids, function( $v ) { return $v !== ''; } );
			}
			break;
		}
	}

	if ( $gid === null && $bundleRaw === '' ) {
		return new WP_Error( 'not_found', 'Kein gid/bundle fuer Cluster-Bundle "' . $sport . '" im Index-Sheet gefunden.' );
	}

	// ── Fall A: Einzelner Sport-Tab mit eigener gid ─────────────────────────
	// FIX: _hs_sport_key auch in Fall A auf jede Zeile schreiben, sonst bleibt
	// c.sport im Frontend leer und die Sport-Pille verschwindet komplett fuer
	// alle Bundles, die ueber eine eigene gid direkt aufgeloest werden.
	if ( $gid !== null && $gid !== '' ) {
		$rows = hs_fetch_csv( $gid );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		foreach ( $rows as &$r ) {
			$r['_hs_sport_key'] = $sport;
		}
		unset( $r );
		return hs_aggregate_coverage( $rows, $curated_ids );
	}

	// ── Fall B: Bundle ohne eigene gid -- mehrere Sport-Tabs zusammenfuehren ─
	$memberNames = array_values( array_filter( array_map( 'trim', explode( ',', $bundleRaw ) ), function( $v ) { return $v !== ''; } ) );

	if ( empty( $memberNames ) ) {
		return new WP_Error( 'not_found', 'Bundle "' . $sport . '" hat weder eigene gid noch Mitglieder in Spalte "bundle".' );
	}

	$mergedRows = [];
	$memberErrors = [];

	foreach ( $memberNames as $memberName ) {
		$memberSlug = hs_slugify( $memberName );
		$memberGid = null;
		$memberDisplayName = $memberName;

		foreach ( $index as $row ) {
			$row_lc = array_change_key_case( $row, CASE_LOWER );
			$type   = isset( $row_lc['type'] ) ? strtolower( trim( $row_lc['type'] ) ) : '';
			if ( $type !== 'cluster' ) continue;

			$bn = isset( $row_lc['bundlename'] ) ? hs_slugify( $row_lc['bundlename'] ) : '';
			$dk = isset( $row_lc['discipline_key'] ) ? hs_slugify( $row_lc['discipline_key'] ) : '';

			if ( $bn === $memberSlug || $dk === $memberSlug ) {
				$memberGid = isset( $row_lc['gid'] ) ? trim( $row_lc['gid'] ) : null;
				$memberDisplayName = trim( (string) (
					$row_lc['displayname'] ?? $row_lc['name'] ?? $row_lc['bundlename'] ?? $memberName
				) );
				// FIX: memberSlug auf den tatsaechlichen disciplinekey der gefundenen
				// Cluster-Zeile normalisieren (falls die Bundle-Spalte einen leicht
				// abweichenden Namen enthaelt als der disciplinekey selbst). Dadurch
				// matcht der spaeter gesetzte _hs_sport_key IMMER exakt den Schluessel,
				// den das Frontend aus index/indexDe (disciplinekey) aufbaut.
				if ( $dk !== '' ) {
					$memberSlug = $dk;
				} elseif ( $bn !== '' ) {
					$memberSlug = $bn;
				}
				break;
			}
		}

		if ( ! $memberGid ) {
			$memberErrors[] = $memberName . ': keine gid gefunden';
			continue;
		}

		$memberRows = hs_fetch_csv( $memberGid );
		if ( is_wp_error( $memberRows ) ) {
			$memberErrors[] = $memberName . ' (gid ' . $memberGid . '): ' . $memberRows->get_error_message();
			continue;
		}

		foreach ( $memberRows as &$mr ) {
			$mr['_hs_sport_key'] = $memberSlug;
		}
		unset( $mr );

		$mergedRows = array_merge( $mergedRows, $memberRows );
	}

	if ( empty( $mergedRows ) ) {
		return new WP_Error(
			'no_member_data',
			'Bundle "' . $sport . '": keine Daten aus den Mitglieds-Tabs geladen (' . implode( ' | ', $memberErrors ) . ')'
		);
	}

	if ( ! empty( $curated_ids ) ) {
		$curatedSet = array_flip( $curated_ids );
		$mergedRows = array_values( array_filter( $mergedRows, function( $row ) use ( $curatedSet ) {
			$row_lc = array_change_key_case( $row, CASE_LOWER );
			$cid = trim( (string) ( $row_lc['competition_id'] ?? '' ) );
			return $cid !== '' && isset( $curatedSet[ $cid ] );
		} ) );
	}

	return hs_aggregate_coverage( $mergedRows, $curated_ids );
}

/**
 * Loest ein Bundle-Cluster (clusterTemplate="bundle") auf.
 * HINWEIS: Wird aktuell fuer Bundles ohne eigene gid NICHT durchlaufen
 * (dort greift Fall B in hs_build_coverage_for_sport()) -- bleibt fuer
 * moegliche kuenftige Verwendung erhalten.
 */
function hs_build_bundle_coverage( array $cluster_row, array $curated_ids, array $index ) {
	if ( empty( $curated_ids ) ) {
		return new WP_Error( 'no_competitions', 'Keine topCompetitions fuer dieses Bundle im Index-Sheet gepflegt.' );
	}

	$sport_keys = isset( $cluster_row['bundle'] ) ? array_map( 'trim', explode( ',', $cluster_row['bundle'] ) ) : [];
	$sport_keys = array_filter( $sport_keys, function( $v ) { return $v !== ''; } );

	if ( empty( $sport_keys ) ) {
		return new WP_Error( 'no_sports', 'Keine Sportarten im "bundle"-Feld der Bundle-Zeile gepflegt.' );
	}
	$sport_keys_lc = array_map( 'strtolower', $sport_keys );

	$gids = [];
	$gidToSportName = [];

	foreach ( $index as $row ) {
		$row_lc = array_change_key_case( $row, CASE_LOWER );
		$type = isset( $row_lc['type'] ) ? strtolower( trim( $row_lc['type'] ) ) : '';
		if ( $type !== 'cluster' ) continue;
		$disc_key = isset( $row_lc['discipline_key'] ) ? strtolower( trim( $row_lc['discipline_key'] ) ) : '';
		if ( $disc_key === '' || ! in_array( $disc_key, $sport_keys_lc, true ) ) continue;
		$row_gid = isset( $row_lc['gid'] ) ? trim( $row_lc['gid'] ) : '';
		if ( $row_gid === '' ) continue;

		if ( ! in_array( $row_gid, $gids, true ) ) {
			$gids[] = $row_gid;
		}
		if ( ! isset( $gidToSportName[ $row_gid ] ) ) {
			$gidToSportName[ $row_gid ] = trim( (string) (
				$row_lc['displayname'] ?? $row_lc['name'] ?? $row_lc['bundlename'] ?? ''
			) );
		}
	}

	if ( empty( $gids ) ) {
		return new WP_Error( 'no_gids', 'Keine gueltigen gids fuer die im Bundle referenzierten Sportarten gefunden.' );
	}

	$compLookup = [];
	foreach ( $gids as $gid ) {
		$rows = hs_fetch_csv( $gid );
		if ( is_wp_error( $rows ) || empty( $rows ) ) continue;

		$sportName = $gidToSportName[ $gid ] ?? '';
		$memberLookup = hs_build_comp_lookup_from_rows( $rows );

		foreach ( $memberLookup as $compId => $entry ) {
			$entry['sport'] = $sportName;
			$compLookup[ $compId ] = $entry;
		}
	}

	$normalized_curated_ids = array_values( array_filter( array_map( function( $v ) {
		return trim( (string) $v );
	}, $curated_ids ), function( $v ) { return $v !== ''; } ) );

	$bundle_top = [];
	foreach ( $normalized_curated_ids as $cid ) {
		if ( isset( $compLookup[ $cid ] ) ) {
			$bundle_top[] = $compLookup[ $cid ];
		}
	}

	return [
		'totalCompetitions' => count( $bundle_top ),
		'totalCountries'    => 0,
		'totalMatches'      => (int) array_sum( array_column( $bundle_top, 'matches' ) ),
		'countries'         => [],
		'international'     => [],
		'topCompetitions'   => $bundle_top,
	];
}

/**
 * Baut aus rohen CSV-Zeilen EINES Sport-Tabs ein compLookup-Array.
 */
function hs_build_comp_lookup_from_rows( array $rows ) {
	if ( empty( $rows ) ) return [];

	$sample = array_change_key_case( $rows[0], CASE_LOWER );
	if ( ! array_key_exists( 'competition_id', $sample ) ) return [];

	$hasCountry   = array_key_exists( 'country', $sample );
	$hasFed       = array_key_exists( 'federation', $sample );
	$hasMatches   = array_key_exists( 'number_matches', $sample );
	$hasCompOrder = array_key_exists( 'competition_order', $sample );
	$hasAge       = array_key_exists( 'age', $sample );
	$hasGender    = array_key_exists( 'gender', $sample );

	$compNameKey = null;
	foreach ( [ 'competition_name', 'competitionname', 'competition', 'name' ] as $candidate ) {
		if ( array_key_exists( $candidate, $sample ) ) { $compNameKey = $candidate; break; }
	}

	$lastSeasonStats = hs_build_last_season_family_stats( $rows );

	$compLookup = [];
	foreach ( $rows as $row ) {
		$row = array_change_key_case( $row, CASE_LOWER );
		$compId = trim( (string) ( $row['competition_id'] ?? '' ) );
		if ( $compId === '' || isset( $compLookup[ $compId ] ) ) continue;

		$country = $hasCountry ? trim( (string) ( $row['country'] ?? '' ) ) : '';
		$fed     = $hasFed ? trim( (string) ( $row['federation'] ?? '' ) ) : '';
		$matches = $hasMatches ? floatval( $row['number_matches'] ?? 0 ) : 0;

		$compName = $compNameKey ? trim( (string) ( $row[ $compNameKey ] ?? '' ) ) : '';
		if ( $compName === '' ) $compName = $compId;

		$lsStats = $lastSeasonStats[ $compId ] ?? [
			'matches' => 0,
			'liveScores' => 0,
			'liveTicker' => 0,
			'statsList' => '',
		];

		$compLookup[ $compId ] = [
			'competition_id'   => $compId,
			'competition_name' => $compName,
			'country'          => $country,
			'country_iso'      => $country !== '' ? hs_country_to_iso( $country ) : '',
			'federation'       => $fed,
			'matches'          => $matches,
			'label'            => $country !== '' ? ( $country . ' - ' . $compName ) : trim( $fed . ' ' . $compName ),
			'seasonMatches'    => $lsStats['matches'],
			'liveScores'       => $lsStats['liveScores'],
			'liveTicker'       => $lsStats['liveTicker'],
			'statsList'        => $lsStats['statsList'] ?? '',
			'competition_order'=> $hasCompOrder ? trim( (string) ( $row['competition_order'] ?? '' ) ) : '',
			'age'              => $hasAge ? trim( (string) ( $row['age'] ?? '' ) ) : '',
			'gender'           => $hasGender ? trim( (string) ( $row['gender'] ?? '' ) ) : '',
		];
	}

	return $compLookup;
}

function hs_pick_last_completed_season_end( array $rows ) {
	$now = time();
	$bestEndByComp = [];

	foreach ( $rows as $row ) {
		$compId = trim( (string) ( $row['competition_id'] ?? '' ) );
		if ( $compId === '' ) continue;

		$seasonEndRaw = trim( (string) ( $row['season_end'] ?? '' ) );
		if ( $seasonEndRaw === '' ) continue;

		$seasonEndTs = strtotime( $seasonEndRaw );
		if ( $seasonEndTs === false ) continue;

		if ( $seasonEndTs > $now ) continue;

		if ( ! isset( $bestEndByComp[ $compId ] ) || $seasonEndTs > $bestEndByComp[ $compId ] ) {
			$bestEndByComp[ $compId ] = $seasonEndTs;
		}
	}

	return $bestEndByComp;
}

/** (LEGACY -- nicht mehr aktiv aufgerufen) */
function hs_build_last_season_stats( array $rows ) {
	$bestEndByComp = hs_pick_last_completed_season_end( $rows );
	$stats = [];

	foreach ( $rows as $row ) {
		$compId = trim( (string) ( $row['competition_id'] ?? '' ) );
		if ( $compId === '' || ! isset( $bestEndByComp[ $compId ] ) ) continue;

		$seasonEndRaw = trim( (string) ( $row['season_end'] ?? '' ) );
		$seasonEndTs = strtotime( $seasonEndRaw );
		if ( $seasonEndTs === false || $seasonEndTs !== $bestEndByComp[ $compId ] ) continue;

		if ( ! isset( $stats[ $compId ] ) ) {
			$stats[ $compId ] = [
				'matches' => 0,
				'liveScores' => 0,
				'liveTicker' => 0,
				'statsList' => '',
			];
		}

		$matches = floatval( $row['number_matches'] ?? 0 );
		$lsFull = floatval( $row['live_status_full'] ?? 0 );
		$lsData = floatval( $row['live_status_data'] ?? 0 );
		$lsGoals = floatval( $row['live_status_goals'] ?? 0 );
		$lsResult = floatval( $row['live_status_result'] ?? 0 );

		$stats[ $compId ]['matches'] += $matches;
		$stats[ $compId ]['liveScores'] += ( $lsFull + $lsData + $lsGoals + $lsResult );
		$stats[ $compId ]['liveTicker'] += $lsFull;

		if ( $stats[ $compId ]['statsList'] === '' ) {
			$sl = trim( (string) ( $row['stats_list'] ?? '' ) );
			if ( $sl !== '' ) {
				$stats[ $compId ]['statsList'] = $sl;
			}
		}
	}

	foreach ( $stats as $compId => $s ) {
		$stats[ $compId ]['matches'] = (int) $s['matches'];
		$stats[ $compId ]['liveScores'] = (int) $s['liveScores'];
		$stats[ $compId ]['liveTicker'] = (int) $s['liveTicker'];
	}

	return $stats;
}

/**
 * Saison-Familien-Aggregation (Playoff/Play-In/regulaer werden zusammengezaehlt).
 */

function hs_build_last_season_family_stats( array $rows ) {
$now = time();

// FIX (Task 4): Statt nach dem ersten Wort von season_name zu gruppieren
// (unzuverlaessig, da Ligen uneinheitliche Namenskonventionen nutzen, z.B.
// "2024" + "2024 Playoffs" vs. "2024/2025" + "2024 Playoffs" vs.
// "2020/2021 Playoffs" + "2020"), gruppieren wir jetzt anhand der zeitlichen
// Naehe des season_end-Datums. Regular Season + Playoffs derselben Saison
// enden immer nur wenige Wochen/Monate auseinander, waehrend verschiedene
// Jahres-Saisons ca. 11-12 Monate auseinander liegen.
$CLUSTER_GAP_SECONDS = 200 * 86400;

$grouped = [];
foreach ( $rows as $row ) {
$row = array_change_key_case( $row, CASE_LOWER );
$compId = trim( (string) ( $row['competition_id'] ?? '' ) );
if ( $compId === '' ) continue;

$seasonEndRaw = trim( (string) ( $row['season_end'] ?? '' ) );
if ( $seasonEndRaw === '' ) continue;
$seasonEndTs = strtotime( $seasonEndRaw );
if ( $seasonEndTs === false ) continue;

if ( ! isset( $grouped[ $compId ] ) ) $grouped[ $compId ] = [];
$grouped[ $compId ][] = [
'row'         => $row,
'seasonEndTs' => $seasonEndTs,
];
}

$result = [];
foreach ( $grouped as $compId => $entries ) {
usort( $entries, function( $a, $b ) { return $a['seasonEndTs'] <=> $b['seasonEndTs']; } );

// Zeitlich zusammenhaengende Cluster bilden (Luecke > 200 Tage = neue Saison).
$clusters = [];
$current  = [];
$prevEnd  = null;
foreach ( $entries as $entry ) {
if ( $prevEnd !== null && ( $entry['seasonEndTs'] - $prevEnd ) > $CLUSTER_GAP_SECONDS ) {
$clusters[] = $current;
$current = [];
}
$current[] = $entry;
$prevEnd = $entry['seasonEndTs'];
}
if ( ! empty( $current ) ) $clusters[] = $current;

// Letzten ABGESCHLOSSENEN Cluster waehlen (max end <= now).
$bestCluster       = null;
$bestClusterMaxEnd = null;
// NEU (Task 5): merken, ob es ueberhaupt einen Cluster mit Saisonende in
// der Zukunft gibt (= angekuendigte/laufende Saison) -- unabhaengig davon,
// wie lange die letzte ABGESCHLOSSENE Saison zurueckliegt. Wichtig fuer
// zyklische Wettbewerbe wie Weltmeisterschaften (alle 4 Jahre).
$hasFutureCluster  = false;
foreach ( $clusters as $cluster ) {
$ends   = array_column( $cluster, 'seasonEndTs' );
$maxEnd = max( $ends );
if ( $maxEnd > $now ) {
$hasFutureCluster = true;
continue;
}

if ( $bestClusterMaxEnd === null || $maxEnd > $bestClusterMaxEnd ) {
$bestClusterMaxEnd = $maxEnd;
$bestCluster        = $cluster;
}
}

if ( $bestCluster === null ) continue;

$matches    = 0;
$liveTicker = 0;
$liveScores = 0;
$statsList  = '';
foreach ( $bestCluster as $entry ) {
$row      = $entry['row'];
$m        = floatval( $row['number_matches'] ?? 0 );
$lsFull   = floatval( $row['live_status_full'] ?? 0 );
$lsData   = floatval( $row['live_status_data'] ?? 0 );
$lsGoals  = floatval( $row['live_status_goals'] ?? 0 );
$lsResult = floatval( $row['live_status_result'] ?? 0 );

$matches    += $m;
$liveTicker += $lsFull;
$liveScores += ( $lsFull + $lsData + $lsGoals + $lsResult );

if ( $statsList === '' ) {
$sl = trim( (string) ( $row['stats_list'] ?? '' ) );
if ( $sl !== '' ) $statsList = $sl;
}
}

$result[ $compId ] = [
'matches'          => (int) $matches,
'liveTicker'       => (int) $liveTicker,
'liveScores'       => (int) $liveScores,
'statsList'        => $statsList,
'seasonEnd'        => date( 'Y-m-d', $bestClusterMaxEnd ),
// NEU (Task 5): Basis fuer den "Wettbewerb ist veraltet"-Check in
// hs_aggregate_coverage().
'lastCompletedEnd' => $bestClusterMaxEnd,
'hasCurrentSeason' => $hasFutureCluster,
];
}

return $result;
}

/**
 * Baut totalEvents/livetickCount/liveCompetitions fuer eine Bundle-Cluster-Zeile.
 */
function hs_build_bundle_totals( $bundle_slug ) {
	$bundle_slug = hs_slugify( $bundle_slug );

	$index = get_transient( 'hs_index_data' );
	if ( $index === false ) {
		$index = hs_fetch_index();
		if ( is_wp_error( $index ) ) return $index;
	}

	$bundleRow = null;
	foreach ( $index as $row ) {
		$row_lc = array_change_key_case( $row, CASE_LOWER );
		$type   = isset( $row_lc['type'] ) ? strtolower( trim( $row_lc['type'] ) ) : '';
		if ( $type !== 'cluster' ) continue;

		$bn = isset( $row_lc['bundlename'] ) ? hs_slugify( $row_lc['bundlename'] ) : '';
		$dk = isset( $row_lc['discipline_key'] ) ? hs_slugify( $row_lc['discipline_key'] ) : '';

		if ( $bn === $bundle_slug || $dk === $bundle_slug ) { $bundleRow = $row_lc; break; }
	}
	if ( ! $bundleRow ) {
		return new WP_Error( 'not_found', 'Bundle-Cluster-Zeile fuer "' . $bundle_slug . '" nicht im Index-Sheet gefunden.' );
	}

	$memberNamesRaw = trim( (string) ( $bundleRow['bundle'] ?? '' ) );
	$memberNames    = array_values( array_filter( array_map( 'trim', explode( ',', $memberNamesRaw ) ), function( $v ) { return $v !== ''; } ) );

	$curatedRaw = trim( (string) ( $bundleRow['topcompetitions'] ?? '' ) );
	$curatedIds = array_values( array_filter( array_map( 'trim', explode( ',', $curatedRaw ) ), function( $v ) { return $v !== ''; } ) );
	$curatedSet = array_flip( $curatedIds );

	if ( empty( $memberNames ) ) {
		return new WP_Error( 'no_members', 'Bundle "' . $bundle_slug . '" hat keine Mitglieder in Spalte "bundle".' );
	}

	$totalEvents      = 0;
	$livetickCount    = 0;
	$liveCompetitions = 0;
	$perCompetition   = [];
	$debugMembers     = [];

	foreach ( $memberNames as $memberName ) {
		$memberSlug = hs_slugify( $memberName );

		$memberGid = null;
		foreach ( $index as $row ) {
			$row_lc = array_change_key_case( $row, CASE_LOWER );
			$type   = isset( $row_lc['type'] ) ? strtolower( trim( $row_lc['type'] ) ) : '';
			if ( $type !== 'cluster' ) continue;

			$bn = isset( $row_lc['bundlename'] ) ? hs_slugify( $row_lc['bundlename'] ) : '';
			$dk = isset( $row_lc['discipline_key'] ) ? hs_slugify( $row_lc['discipline_key'] ) : '';

			if ( $bn === $memberSlug || $dk === $memberSlug ) {
				$memberGid = isset( $row_lc['gid'] ) ? trim( $row_lc['gid'] ) : null;
				break;
			}
		}

		if ( ! $memberGid ) {
			$debugMembers[] = [ 'member' => $memberName, 'error' => 'keine gid gefunden' ];
			continue;
		}

		$csvRows = hs_fetch_csv( $memberGid );
		if ( is_wp_error( $csvRows ) ) {
			$debugMembers[] = [ 'member' => $memberName, 'gid' => $memberGid, 'error' => $csvRows->get_error_message() ];
			continue;
		}

		$statsByCompId = hs_build_last_season_family_stats( $csvRows );

		$memberMatched = [];
		foreach ( $statsByCompId as $compId => $stats ) {
			if ( ! empty( $curatedIds ) && ! isset( $curatedSet[ $compId ] ) ) continue;

			$totalEvents      += $stats['matches'];
			$livetickCount    += $stats['liveTicker'];
			$liveCompetitions += $stats['liveScores'];

			$perCompetition[ $compId ] = $stats;
			$memberMatched[] = $compId;
		}

		$debugMembers[] = [
			'member'                => $memberName,
			'gid'                   => $memberGid,
			'matchedCompetitionIds' => $memberMatched,
		];
	}

	return [
		'totalEvents'      => $totalEvents,
		'livetickCount'    => $livetickCount,
		'liveCompetitions' => $liveCompetitions,
		'perCompetition'   => $perCompetition,
		'debug'            => [
			'bundleSlug'  => $bundle_slug,
			'memberNames' => $memberNames,
			'curatedIds'  => $curatedIds,
			'members'     => $debugMembers,
		],
	];
}

if ( ! defined( 'HS_TOP_COMP_ID_CAP' ) ) {
	define( 'HS_TOP_COMP_ID_CAP', 500 );
}

function hs_rank_country_top_competitions( array $list, $n = 5 ) {
	$eligible = array_filter( $list, function( $c ) {
		return trim( (string) ( $c['age'] ?? '' ) ) === '';
	} );

	$toOrderId = function( $c ) {
		$order = isset( $c['competition_order'] ) && $c['competition_order'] !== '' ? (int) $c['competition_order'] : 999999;
		$id = isset( $c['compId'] ) ? (int) $c['compId'] : ( isset( $c['competition_id'] ) ? (int) $c['competition_id'] : 999999999 );
		return [ $order, $id ];
	};

	$capped = array_filter( $eligible, function( $c ) use ( $toOrderId ) {
		$oi = $toOrderId( $c );
		return $oi[1] <= HS_TOP_COMP_ID_CAP;
	} );

	$sortFn = function( $a, $b ) use ( $toOrderId ) {
		$oa = $toOrderId( $a );
		$ob = $toOrderId( $b );
		if ( $oa[0] !== $ob[0] ) return $oa[0] <=> $ob[0];
		return $oa[1] <=> $ob[1];
	};

	$ranked = ! empty( $capped ) ? $capped : $eligible;
	usort( $ranked, $sortFn );

	$top = array_slice( array_values( $ranked ), 0, $n );
	$topCompIds = array_map( function( $c ) {
		return isset( $c['compId'] ) ? $c['compId'] : ( $c['competition_id'] ?? $c['name'] );
	}, $top );

	$rest = array_filter( $list, function( $c ) use ( $topCompIds ) {
		$cid = isset( $c['compId'] ) ? $c['compId'] : ( $c['competition_id'] ?? $c['name'] );
		return ! in_array( $cid, $topCompIds, true );
	} );
	$rest = array_values( $rest );
	usort( $rest, function( $a, $b ) {
		return strcmp( $a['name'], $b['name'] );
	} );

	return [ 'top' => $top, 'rest' => $rest ];
}

/**
 * Aggregiert rohe Coverage-CSV-Zeilen nach Land / Foederation / International.
 * Reicht -- falls beim Zeilen-Merge in Fall A/Fall B gesetzt -- das interne
 * Feld "_hs_sport_key" als 'sport' in jeden Wettbewerbs-Eintrag durch.
 */
function hs_aggregate_coverage( array $rows, array $curated_ids = [] ) {
	if ( empty( $rows ) ) {
		return [
			'totalCompetitions' => 0,
			'totalCountries' => 0,
			'totalMatches' => 0,
			'countries' => [],
			'international' => [],
			'topCompetitions' => [],
		];
	}

	$sample = array_change_key_case( $rows[0], CASE_LOWER );
	$hasCountry = array_key_exists( 'country', $sample );
	$hasFed = array_key_exists( 'federation', $sample );
	$hasCompId = array_key_exists( 'competition_id', $sample );
	$hasMatches = array_key_exists( 'number_matches', $sample );

	$compNameKey = null;
	foreach ( [ 'competition_name', 'competitionname', 'competition', 'name' ] as $candidate ) {
		if ( array_key_exists( $candidate, $sample ) ) {
			$compNameKey = $candidate;
			break;
		}
	}

	if ( ! $hasCompId ) {
		return new WP_Error( 'missing_column', 'Spalte "competition_id" nicht im Sport-Tab gefunden.' );
	}

	$countries = [];
	$intl = [];
	$seenCompIds = [];
	$totalMatches = 0;
	$compLookup = [];

$lastSeasonStats = hs_build_last_season_family_stats( $rows );
$STALE_YEARS_THRESHOLD = 4;
$staleCutoffTs = strtotime( '-' . $STALE_YEARS_THRESHOLD . ' years' );
$staleCompIds = [];
foreach ( $lastSeasonStats as $lsCompId => $lsStats ) {
if ( empty( $lsStats['hasCurrentSeason'] ) && ! empty( $lsStats['lastCompletedEnd'] ) && $lsStats['lastCompletedEnd'] < $staleCutoffTs ) {
$staleCompIds[ $lsCompId ] = true;
}
}

$hasCompOrder = array_key_exists( 'competition_order', $sample );
$hasAge = array_key_exists( 'age', $sample );
$hasGender = array_key_exists( 'gender', $sample );

foreach ( $rows as $row ) {
$row = array_change_key_case( $row, CASE_LOWER );

$compId = trim( (string) ( $row['competition_id'] ?? '' ) );
if ( $compId === '' ) continue;
if ( isset( $staleCompIds[ $compId ] ) ) continue;

		$country = $hasCountry ? trim( (string) ( $row['country'] ?? '' ) ) : '';
		$fed = $hasFed ? trim( (string) ( $row['federation'] ?? '' ) ) : '';
		$matches = $hasMatches ? floatval( $row['number_matches'] ?? 0 ) : 0;

		$compName = $compNameKey ? trim( (string) ( $row[ $compNameKey ] ?? '' ) ) : '';
		if ( $compName === '' ) $compName = $compId;

		$compSport = trim( (string) ( $row['_hs_sport_key'] ?? '' ) );

		$lsStatsRow = $lastSeasonStats[ $compId ] ?? [
			'matches' => 0,
			'liveScores' => 0,
			'liveTicker' => 0,
			'statsList' => '',
		];

		$compOrder = $hasCompOrder ? trim( (string) ( $row['competition_order'] ?? '' ) ) : '';
		$compAge = $hasAge ? trim( (string) ( $row['age'] ?? '' ) ) : '';
		$compGender = $hasGender ? trim( (string) ( $row['gender'] ?? '' ) ) : '';

// FIX: totalMatches muss die "letzte abgeschlossene Saison"-Zahl nutzen
// (seasonMatches ueber hs_build_last_season_family_stats), nicht den rohen
// number_matches-Wert der zufaellig zuerst gesehenen CSV-Zeile -- sonst
// zaehlt z.B. NFL nur mit einzelnen Playoff-Spielen statt der vollen Saison.
if ( ! isset( $seenCompIds[ $compId ] ) ) {
$totalMatches += $lsStatsRow['matches'];
}

		if ( $country !== '' ) {
			if ( ! isset( $countries[ $country ] ) ) {
				$countries[ $country ] = [ 'competitions' => [], 'matches' => 0, 'topCompetitions' => [] ];
			}
			$countries[ $country ]['competitions'][ $compId ] = true;
			$countries[ $country ]['matches'] += $matches;
			$countries[ $country ]['topCompetitions'][] = [
				'name' => $compName,
				'matches' => $matches,
				'compId' => $compId,
				'federation' => $fed,
				'sport' => $compSport,
				'seasonMatches' => $lsStatsRow['matches'],
				'liveScores' => $lsStatsRow['liveScores'],
				'liveTicker' => $lsStatsRow['liveTicker'],
				'statsList' => $lsStatsRow['statsList'],
				'competition_order' => $compOrder,
				'age' => $compAge,
				'gender' => $compGender,
			];
		} else {
			$key = $fed !== '' ? $fed : 'Sonstige International';
			if ( ! isset( $intl[ $key ] ) ) {
				$intl[ $key ] = [ 'competitions' => [], 'matches' => 0, 'topCompetitions' => [] ];
			}
			$intl[ $key ]['competitions'][ $compId ] = true;
			$intl[ $key ]['matches'] += $matches;
			$intl[ $key ]['topCompetitions'][] = [
				'name' => $compName,
				'matches' => $matches,
				'compId' => $compId,
				'federation' => $fed,
				'sport' => $compSport,
				'seasonMatches' => $lsStatsRow['matches'],
				'liveScores' => $lsStatsRow['liveScores'],
				'liveTicker' => $lsStatsRow['liveTicker'],
				'statsList' => $lsStatsRow['statsList'],
				'competition_order' => $compOrder,
				'age' => $compAge,
				'gender' => $compGender,
			];
		}

		if ( ! isset( $compLookup[ $compId ] ) ) {
			$lsStats = $lastSeasonStats[ $compId ] ?? [
				'matches' => 0,
				'liveScores' => 0,
				'liveTicker' => 0,
				'statsList' => '',
			];

			$compLookup[ $compId ] = [
				'competition_id' => $compId,
				'competition_name' => $compName,
				'country' => $country,
				'country_iso' => $country !== '' ? hs_country_to_iso( $country ) : '',
				'federation' => $fed,
				'matches' => $matches,
				'sport' => $compSport,
				'label' => $country !== '' ? ( $country . ' - ' . $compName ) : trim( $fed . ' ' . $compName ),
				'seasonMatches' => $lsStats['matches'],
				'liveScores' => $lsStats['liveScores'],
				'liveTicker' => $lsStats['liveTicker'],
				'statsList' => $lsStats['statsList'],
				'competition_order' => $compOrder,
				'age' => $compAge,
				'gender' => $compGender,
			];
		}

		$seenCompIds[ $compId ] = true;
	}

	$dedupeByCompId = function( $list ) {
		$unique = [];
		foreach ( $list as $item ) {
			$id = isset( $item['compId'] ) ? $item['compId'] : ( $item['competition_id'] ?? $item['name'] );
			if ( ! isset( $unique[ $id ] ) || $item['matches'] > $unique[ $id ]['matches'] ) {
				$unique[ $id ] = $item;
			}
		}
		return array_values( $unique );
	};

	$buildFullCompetitionList = function( $list, $n = 5 ) use ( $dedupeByCompId ) {
		$deduped = $dedupeByCompId( $list );
		$ranked = hs_rank_country_top_competitions( $deduped, $n );
		return [
			'competitions' => array_merge( $ranked['top'], $ranked['rest'] ),
			'topCount' => count( $ranked['top'] ),
		];
	};

	$countryList = [];
	foreach ( $countries as $name => $d ) {
		$fullList = $buildFullCompetitionList( $d['topCompetitions'] );
		$countryList[] = [
			'name' => $name,
			'country_iso' => hs_country_to_iso( $name ),
			'competitions' => count( $d['competitions'] ),
			'matches' => (int) $d['matches'],
			'topCompetitions' => $fullList['competitions'],
			'topCompetitionsCount' => $fullList['topCount'],
		];
	}

	usort( $countryList, function( $a, $b ) {
		if ( $a['competitions'] === $b['competitions'] ) {
			return strcmp( $a['name'], $b['name'] );
		}
		return $b['competitions'] <=> $a['competitions'];
	} );

	$intlList = [];
	foreach ( $intl as $fed => $d ) {
		$fullList = $buildFullCompetitionList( $d['topCompetitions'] );
		$intlList[] = [
			'federation' => $fed,
			'competitions' => count( $d['competitions'] ),
			'matches' => (int) $d['matches'],
			'topCompetitions' => $fullList['competitions'],
			'topCompetitionsCount' => $fullList['topCount'],
		];
	}

	usort( $intlList, function( $a, $b ) {
		if ( $a['competitions'] === $b['competitions'] ) {
			return strcmp( $a['federation'], $b['federation'] );
		}
		return $b['competitions'] <=> $a['competitions'];
	} );

	$normalizedCuratedIds = array_values( array_filter( array_map( function( $v ) {
		return trim( (string) $v );
	}, $curated_ids ), function( $v ) { return $v !== ''; } ) );

	$globalTop = [];
	if ( ! empty( $normalizedCuratedIds ) ) {
		foreach ( $normalizedCuratedIds as $cid ) {
			if ( isset( $compLookup[ $cid ] ) ) {
				$globalTop[] = $compLookup[ $cid ];
			}
		}
	}

	if ( empty( $globalTop ) ) {
		$globalTop = array_values( $compLookup );
		usort( $globalTop, function( $a, $b ) {
			if ( $a['matches'] === $b['matches'] ) {
				return strcmp( $a['competition_name'], $b['competition_name'] );
			}
			return $b['matches'] <=> $a['matches'];
		} );
		$globalTop = array_slice( $globalTop, 0, 12 );
	}

	return [
		'totalCompetitions' => count( $seenCompIds ),
		'totalCountries' => count( $countryList ),
		'totalMatches' => (int) $totalMatches,
		'countries' => $countryList,
		'international' => $intlList,
		'topCompetitions' => $globalTop,
	];
}
