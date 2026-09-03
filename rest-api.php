<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// HINWEIS (Fix Fatal Error): hs_slugify() wird zentral in includes/cache.php
// definiert (dort auch von hs_build_coverage_for_sport() genutzt) und
// VOR dieser Datei geladen (siehe $hs_includes-Reihenfolge in der
// Haupt-Plugin-Datei: cache.php -> rest-api.php -> cron.php -> admin.php).
// Eine zweite Deklaration hier wuerde "Cannot redeclare hs_slugify()"
// verursachen und die Plugin-Aktivierung mit einem Fatal Error blockieren.
// Defensive Absicherung falls doch mal die Ladereihenfolge geaendert wird:
if ( ! function_exists( 'hs_slugify' ) ) {
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
}

add_action( 'rest_api_init', 'hs_register_rest_routes' );

function hs_register_rest_routes() {

	// GET /wp-json/hs-cache/v1/index
	register_rest_route( 'hs-cache/v1', '/index', [
		'methods'             => 'GET',
		'callback'            => 'hs_rest_get_index',
		'permission_callback' => '__return_true',
	] );

	// GET /wp-json/hs-cache/v1/generalIndex
	register_rest_route( 'hs-cache/v1', '/generalIndex', [
		'methods'             => 'GET',
		'callback'            => 'hs_rest_get_general_index',
		'permission_callback' => '__return_true',
	] );

	// GET /wp-json/hs-cache/v1/indexDe
	register_rest_route( 'hs-cache/v1', '/indexDe', [
		'methods'             => 'GET',
		'callback'            => 'hs_rest_get_index_de',
		'permission_callback' => '__return_true',
	] );

	// GET /wp-json/hs-cache/v1/generalIndexDe
	register_rest_route( 'hs-cache/v1', '/generalIndexDe', [
		'methods'             => 'GET',
		'callback'            => 'hs_rest_get_general_index_de',
		'permission_callback' => '__return_true',
	] );

	// GET /wp-json/hs-cache/v1/csv/{gid}
	register_rest_route( 'hs-cache/v1', '/csv/(?P<gid>[a-zA-Z0-9_-]+)', [
		'methods'             => 'GET',
		'callback'            => 'hs_rest_get_csv',
		'permission_callback' => '__return_true',
		'args'                => [
			'gid' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
		],
	] );

	// GET /wp-json/hs-cache/v1/coverage/{sport}
	// Automatische Laender-/Foederations-Aggregation fuer Sportarten
	// mit vielen Wettbewerben (z.B. Fussball). Liest den bestehenden Sport-Tab
	// per gid aus der Cluster-Zeile im Index-Sheet (Spalte "gid").
	register_rest_route( 'hs-cache/v1', '/coverage/(?P<sport>[a-zA-Z0-9_-]+)', [
		'methods'             => 'GET',
		'callback'            => 'hs_rest_get_coverage',
		'permission_callback' => '__return_true',
		'args'                => [
			'sport' => [ 'required' => true, 'sanitize_callback' => 'sanitize_title' ],
		],
	] );


	// GET /wp-json/hs-cache/v1/event-coverage/{slug}
	// NEU (Event-Template): Multi-Sport-Events wie Olympische Spiele.
	// Auswahl per Namensfilter (Index-Spalte "nameFilter") statt kuratierter
	// competition_ids, Gruppierung nach Sportart statt nach Land/Foederation.
	// Logik in cache.php (hs_build_event_coverage()).
	register_rest_route( 'hs-cache/v1', '/event-coverage/(?P<slug>[a-zA-Z0-9_-]+)', [
		'methods'             => 'GET',
		'callback'            => 'hs_rest_get_event_coverage',
		'permission_callback' => '__return_true',
		'args'                => [
			'slug' => [ 'required' => true, 'sanitize_callback' => 'sanitize_title' ],
		],
	] );

	// GET /wp-json/hs-cache/v1/bundle-totals/{bundle}
	// Liefert totalEvents / livetickCount / liveCompetitions fuer eine
	// Bundle-Cluster-Zeile (z.B. "us-sports"), die mehrere Einzelsport-Tabs
	// referenziert (Spalte "bundle" der Cluster-Zeile, z.B.
	// "Basketball,American_Football,Eishockey,Fußball"). Aggregations-Logik
	// liegt in cache.php (hs_build_bundle_totals()).
	register_rest_route( 'hs-cache/v1', '/bundle-totals/(?P<bundle>[a-zA-Z0-9_-]+)', [
		'methods'             => 'GET',
		'callback'            => 'hs_rest_get_bundle_totals',
		'permission_callback' => '__return_true',
		'args'                => [
			'bundle' => [ 'required' => true, 'sanitize_callback' => 'sanitize_title' ],
		],
	] );

	// GET /wp-json/hs-cache/v1/coverage-debug/{sport}?id={competition_id}
	// NEU (v1.4): Diagnose-Endpoint fuer die Top-Competitions-Berechnung.
	// Zeigt exakt, welche kuratierten IDs aus dem Index-Sheet (Spalte
	// "topCompetitions" der Cluster-Zeile) gematcht wurden, welche NICHT
	// gematcht wurden, und liefert per optionalem Query-Parameter ?id=...
	// die vollen Rohdaten (inkl. aller CSV-Zeilen) fuer eine einzelne
	// competition_id -- damit nachvollziehbar wird, WARUM ein bestimmter
	// Wettbewerb (z.B. "United States - NCAA", ID 19513) in den Top
	// Competitions auftaucht, obwohl er dort nicht erwartet wird.
	// Beispiel-Aufruf:
	//   /wp-json/hs-cache/v1/coverage-debug/fussball?id=19513
	register_rest_route( 'hs-cache/v1', '/coverage-debug/(?P<sport>[a-zA-Z0-9_-]+)', [
		'methods'             => 'GET',
		'callback'            => 'hs_rest_get_coverage_debug',
		'permission_callback' => '__return_true',
		'args'                => [
			'sport' => [ 'required' => true, 'sanitize_callback' => 'sanitize_title' ],
			'id'    => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
		],
	] );
}

/**
 * GET /wp-json/hs-cache/v1/bundle-totals/{bundle}
 * Liefert totalEvents / livetickCount / liveCompetitions fuer eine
 * Bundle-Cluster-Zeile (z.B. "us-sports"). Aggregations-Logik liegt in
 * cache.php (hs_build_bundle_totals()). Ergebnis wird -- analog zu
 * /coverage/{sport} -- als Transient gecacht.
 */
function hs_rest_get_bundle_totals( WP_REST_Request $request ) {
	$bundle    = $request->get_param( 'bundle' );
	$cache_key = 'hs_bundle_totals_' . hs_slugify( $bundle );
	$data      = get_transient( $cache_key );

	if ( $data === false ) {
		$data = hs_build_bundle_totals( $bundle );
		if ( is_wp_error( $data ) ) {
			$status = ( $data->get_error_code() === 'not_found' ) ? 404 : 502;
			return new WP_REST_Response( [ 'error' => $data->get_error_message() ], $status );
		}
		set_transient( $cache_key, $data, HS_CACHE_TTL );
	}

	$response = new WP_REST_Response( $data, 200 );
	$response->header( 'Cache-Control', 'public, max-age=3600' );
	$response->header( 'Access-Control-Allow-Origin', '*' );
	return $response;
}

function hs_rest_get_index( WP_REST_Request $request ) {
	$data = get_transient( 'hs_index_data' );
	if ( $data === false ) {
		$data = hs_fetch_index();
		if ( is_wp_error( $data ) ) {
			return new WP_REST_Response( [ 'error' => $data->get_error_message() ], 502 );
		}
		set_transient( 'hs_index_data', $data, HS_CACHE_TTL );
	}
	$response = new WP_REST_Response( [ 'index' => $data ], 200 );
	$response->header( 'Cache-Control', 'public, max-age=3600' );
	$response->header( 'Access-Control-Allow-Origin', '*' );
	return $response;
}

function hs_rest_get_general_index( WP_REST_Request $request ) {
	$data = get_transient( 'hs_general_index_data' );
	if ( $data === false ) {
		$data = hs_fetch_general_index();
		if ( is_wp_error( $data ) ) {
			return new WP_REST_Response( [ 'error' => $data->get_error_message() ], 502 );
		}
		set_transient( 'hs_general_index_data', $data, HS_CACHE_TTL );
	}
	$response = new WP_REST_Response( [ 'generalIndex' => $data ], 200 );
	$response->header( 'Cache-Control', 'public, max-age=3600' );
	$response->header( 'Access-Control-Allow-Origin', '*' );
	return $response;
}

function hs_rest_get_index_de( WP_REST_Request $request ) {
	$data = get_transient( 'hs_index_de_data' );
	if ( $data === false ) {
		$data = hs_fetch_index_de();
		if ( is_wp_error( $data ) ) {
			return new WP_REST_Response( [ 'error' => $data->get_error_message() ], 502 );
		}
		set_transient( 'hs_index_de_data', $data, HS_CACHE_TTL );
	}
	$response = new WP_REST_Response( [ 'indexDe' => $data ], 200 );
	$response->header( 'Cache-Control', 'public, max-age=3600' );
	$response->header( 'Access-Control-Allow-Origin', '*' );
	return $response;
}

function hs_rest_get_general_index_de( WP_REST_Request $request ) {
	$data = get_transient( 'hs_general_index_de_data' );
	if ( $data === false ) {
		$data = hs_fetch_general_index_de();
		if ( is_wp_error( $data ) ) {
			return new WP_REST_Response( [ 'error' => $data->get_error_message() ], 502 );
		}
		set_transient( 'hs_general_index_de_data', $data, HS_CACHE_TTL );
	}
	$response = new WP_REST_Response( [ 'generalIndexDe' => $data ], 200 );
	$response->header( 'Cache-Control', 'public, max-age=3600' );
	$response->header( 'Access-Control-Allow-Origin', '*' );
	return $response;
}

function hs_rest_get_csv( WP_REST_Request $request ) {
	$gid  = $request->get_param( 'gid' );
	$data = get_transient( 'hs_csv_' . $gid );
	if ( $data === false ) {
		$data = hs_fetch_csv( $gid );
		if ( is_wp_error( $data ) ) {
			return new WP_REST_Response( [ 'error' => $data->get_error_message() ], 502 );
		}
		set_transient( 'hs_csv_' . $gid, $data, HS_CACHE_TTL );
	}
	$response = new WP_REST_Response( $data, 200 );
	$response->header( 'Cache-Control', 'public, max-age=3600' );
	$response->header( 'Access-Control-Allow-Origin', '*' );
	return $response;
}

/**
 * GET /wp-json/hs-cache/v1/coverage/{sport}
 * Liefert automatisch aggregierte Coverage-Daten (Laender + International)
 * fuer Sportarten mit vielen Wettbewerben (z.B. Fussball: 1.271 Wettbewerbe
 * in 104 Laendern). Aggregations-Logik liegt in cache.php (hs_aggregate_coverage).
 */
function hs_rest_get_coverage( WP_REST_Request $request ) {
    $sport = $request->get_param( 'sport' );
    $cache_key = 'hs_coverage_' . $sport;
    $data = get_transient( $cache_key );

    if ( $data === false ) {
        $data = hs_build_coverage_for_sport( $sport );
        if ( is_wp_error( $data ) ) {
            $status = ( $data->get_error_code() === 'not_found' ) ? 404 : 502;
            return new WP_REST_Response( [ 'error' => $data->get_error_message() ], $status );
        }
        set_transient( $cache_key, $data, HS_CACHE_TTL );
    }

    $response = new WP_REST_Response( $data, 200 );
    $response->header( 'Cache-Control', 'public, max-age=3600' );
    $response->header( 'Access-Control-Allow-Origin', '*' );
    return $response;
}

/**
 * GET /wp-json/hs-cache/v1/event-coverage/{slug}
 * Liefert die nach Sportart gruppierten Wettbewerbe eines Multi-Sport-Events
 * (Namensfilter aus der Index-Spalte "nameFilter"). Siehe cache.php.
 */
function hs_rest_get_event_coverage( WP_REST_Request $request ) {
	$slug      = $request->get_param( 'slug' );
	$cache_key = 'hs_event_coverage_' . $slug;
	$data      = get_transient( $cache_key );

	if ( $data === false ) {
		$data = hs_build_event_coverage( $slug );
		if ( is_wp_error( $data ) ) {
			$code   = $data->get_error_code();
			$status = in_array( $code, [ 'not_found', 'missing_name_filter', 'no_matches', 'no_current_events' ], true ) ? 404 : 502;
			return new WP_REST_Response( [ 'error' => $data->get_error_message(), 'code' => $code ], $status );
		}
		set_transient( $cache_key, $data, HS_CACHE_TTL );
	}

	$response = new WP_REST_Response( $data, 200 );
	$response->header( 'Cache-Control', 'public, max-age=3600' );
	$response->header( 'Access-Control-Allow-Origin', '*' );
	return $response;
}

/**
 * GET /wp-json/hs-cache/v1/coverage-debug/{sport}?id={competition_id}
 * NEU (v1.4): Diagnose-Endpoint. Baut die Coverage-Aggregation fuer $sport
 * NICHT aus dem Cache, sondern IMMER frisch aus dem aktuellen CSV auf (damit
 * das Ergebnis garantiert den aktuellen Sheet-/CSV-Stand widerspiegelt) und
 * gibt detaillierte Zwischenwerte zurueck:
 *
 * - raw_curated_cell:       Rohtext der Sheet-Zelle "topCompetitions" (Cluster-Zeile)
 * - curated_ids_parsed:     Nach Komma gesplittete IDs, so wie im Sheet eingetragen
 * - curated_ids_normalized: Getrimmte String-Versionen (wie im Produktionscode verglichen)
 * - curated_matched:        Welche kuratierten IDs tatsaechlich im CSV gefunden wurden
 * - curated_unmatched:      Welche kuratierten IDs NICHT im CSV gefunden wurden
 * - decision:               "curated" oder "fallback_by_matches" -- zeigt, welcher
 *                            Pfad in hs_aggregate_coverage() tatsaechlich greift
 * - fallback_top12:         Die Top-12 Wettbewerbe, die der automatische
 *                            Fallback-Pfad (Sortierung nach number_matches) liefern wuerde
 * - lookup_id_detail:       Nur wenn ?id=... uebergeben wird: alle Rohzeilen aus
 *                            dem CSV fuer genau diese competition_id, plus den
 *                            aggregierten compLookup-Eintrag und ob diese ID Teil
 *                            der kuratierten Liste ist
 *
 * Beispiel: /wp-json/hs-cache/v1/coverage-debug/fussball?id=19513
 * (fuer die Recherche zu "United States - NCAA")
 */
function hs_rest_get_coverage_debug( WP_REST_Request $request ) {
    $sport = $request->get_param( 'sport' );

    $index = get_transient( 'hs_index_data' );
    if ( $index === false ) {
        $index = hs_fetch_index();
        if ( is_wp_error( $index ) ) {
            return new WP_REST_Response( [ 'error' => $index->get_error_message() ], 502 );
        }
    }

    $gid            = null;
    $rawCuratedCell = null;
    foreach ( $index as $row ) {
        $row_lc = array_change_key_case( $row, CASE_LOWER );
        $type   = isset( $row_lc['type'] ) ? strtolower( trim( $row_lc['type'] ) ) : '';
        $bundle = isset( $row_lc['bundle'] ) ? hs_slugify( $row_lc['bundle'] ) : '';
        if ( $type === 'cluster' && $bundle === hs_slugify( $sport ) ) {
            $gid            = isset( $row_lc['gid'] ) ? trim( $row_lc['gid'] ) : null;
            $rawCuratedCell = isset( $row_lc['topcompetitions'] ) ? $row_lc['topcompetitions'] : null;
            break;
        }
    }

    if ( ! $gid ) {
        // NEU: Statt nur "not found" zu melden, listen wir ALLE Cluster-Zeilen
        // aus dem aktuellen Index-Sheet mit ihrem rohen "bundle"-Wert auf --
        // damit sofort sichtbar wird, ob z.B. Tippfehler, Leerzeichen, Umlaute
        // oder ein umbenanntes Bundle-Feld die Ursache fuer den fehlenden
        // Live-Match sind (waehrend /coverage/{sport} evtl. noch eine alte,
        // laengst veraltete Cache-Antwort aus dem Transient ausliefert).
        $allClusterRows = [];
        foreach ( $index as $row ) {
            $row_lc = array_change_key_case( $row, CASE_LOWER );
            $type   = isset( $row_lc['type'] ) ? strtolower( trim( $row_lc['type'] ) ) : '';
            if ( $type === 'cluster' ) {
                $allClusterRows[] = [
                    'raw_bundle'        => $row_lc['bundle'] ?? null,
                    'bundle_normalized' => isset( $row_lc['bundle'] ) ? hs_slugify( $row_lc['bundle'] ) : null,
                    'gid'               => $row_lc['gid'] ?? null,
                    'bundlename'        => $row_lc['bundlename'] ?? null,
                ];
            }
        }

        $coverageCacheTransient = get_transient( 'hs_coverage_' . $sport );

        return new WP_REST_Response( [
            'error'                 => 'Kein gid fuer Bundle "' . $sport . '" im AKTUELLEN Index-Sheet gefunden (Live-Lookup, kein Cache).',
            'requested_sport_param' => $sport,
            'all_cluster_rows_in_index' => $allClusterRows,
            'stale_coverage_cache_exists' => $coverageCacheTransient !== false,
            'stale_coverage_cache_preview' => $coverageCacheTransient !== false
                ? [
                    'totalCompetitions' => $coverageCacheTransient['totalCompetitions'] ?? null,
                    'totalCountries'    => $coverageCacheTransient['totalCountries'] ?? null,
                    'topCompetitions_names' => array_map( function( $c ) {
                        return $c['competition_name'] ?? ( $c['label'] ?? null );
                    }, $coverageCacheTransient['topCompetitions'] ?? [] ),
                ]
                : null,
        ], 404 );
    }

    $curated_ids = [];
    if ( $rawCuratedCell !== null && trim( $rawCuratedCell ) !== '' ) {
        $curated_ids = array_map( 'trim', explode( ',', $rawCuratedCell ) );
        $curated_ids = array_filter( $curated_ids, function( $v ) { return $v !== ''; } );
    }

    $rows = hs_fetch_csv( $gid );
    if ( is_wp_error( $rows ) ) {
        return new WP_REST_Response( [ 'error' => $rows->get_error_message() ], 502 );
    }

    // compLookup + alle Rohzeilen pro competition_id einsammeln (fuer die Diagnose)
    $compLookup  = [];
    $rawRowsById = [];
    foreach ( $rows as $row ) {
        $row_lc = array_change_key_case( $row, CASE_LOWER );
        $compId = trim( (string) ( $row_lc['competition_id'] ?? '' ) );
        if ( $compId === '' ) continue;

        $rawRowsById[ $compId ][] = $row_lc;

        $matches = floatval( $row_lc['number_matches'] ?? 0 );

        if ( ! isset( $compLookup[ $compId ] ) ) {
            $compLookup[ $compId ] = [
                'competition_id'   => $compId,
                'competition_name' => trim( (string) ( $row_lc['competition_name'] ?? $row_lc['competitionname'] ?? $row_lc['competition'] ?? $row_lc['name'] ?? '' ) ),
                'country'          => trim( (string) ( $row_lc['country'] ?? '' ) ),
                'federation'       => trim( (string) ( $row_lc['federation'] ?? '' ) ),
                'matches'          => $matches,
                'row_count'        => 0,
            ];
        } else {
            // Falls dieselbe competition_id in mehreren CSV-Zeilen vorkommt
            // (z.B. pro Spieltag), wird hier sichtbar, ob sich number_matches
            // fehlerhaft aufsummiert -- KEINE Summierung in dieser Debug-Ansicht,
            // damit du den Rohwert jeder einzelnen Zeile siehst (siehe raw_rows).
        }
        $compLookup[ $compId ]['row_count']++;
    }

    // Normalisierte kuratierte IDs -- identisch zur gefixten Produktionslogik
    // in hs_aggregate_coverage() (String-Cast + trim beidseitig).
    $normalizedCuratedIds = array_values( array_filter( array_map( function( $v ) {
        return trim( (string) $v );
    }, $curated_ids ), function( $v ) { return $v !== ''; } ) );

    $matchedCurated   = [];
    $unmatchedCurated = [];
    foreach ( $normalizedCuratedIds as $cid ) {
        if ( isset( $compLookup[ $cid ] ) ) {
            $matchedCurated[] = $compLookup[ $cid ];
        } else {
            $unmatchedCurated[] = $cid;
        }
    }

    // Fallback-Top-12 exakt wie im gefixten hs_aggregate_coverage (deterministisch
    // sortiert: primaer nach matches, sekundaer alphabetisch nach Name).
    $fallbackTop = array_values( $compLookup );
    usort( $fallbackTop, function( $a, $b ) {
        if ( $a['matches'] === $b['matches'] ) {
            return strcmp( $a['competition_name'], $b['competition_name'] );
        }
        return $b['matches'] <=> $a['matches'];
    } );
    $fallbackTop = array_slice( $fallbackTop, 0, 12 );

    // Gezielte Detailsuche fuer eine einzelne competition_id (Query-Param ?id=...)
    $lookupId     = $request->get_param( 'id' );
    $lookupDetail = null;
    if ( $lookupId !== null && $lookupId !== '' ) {
        $lookupIdNorm = trim( (string) $lookupId );
        if ( isset( $compLookup[ $lookupIdNorm ] ) ) {
            $lookupDetail = [
                'competition_id' => $lookupIdNorm,
                'compLookup'      => $compLookup[ $lookupIdNorm ],
                'raw_rows'        => $rawRowsById[ $lookupIdNorm ] ?? [],
                'is_in_curated_cell' => in_array( $lookupIdNorm, $normalizedCuratedIds, true ),
                'rank_in_fallback_top12' => null,
            ];
            foreach ( $fallbackTop as $i => $item ) {
                if ( $item['competition_id'] === $lookupIdNorm ) {
                    $lookupDetail['rank_in_fallback_top12'] = $i + 1;
                    break;
                }
            }
        } else {
            $lookupDetail = [
                'competition_id' => $lookupIdNorm,
                'error'           => 'ID nicht im CSV-Tab (gid ' . $gid . ') gefunden.',
            ];
        }
    }

    $response = new WP_REST_Response( [
        'sport'                     => $sport,
        'gid'                       => $gid,
        'raw_curated_cell'          => $rawCuratedCell,
        'curated_ids_parsed'        => $curated_ids,
        'curated_ids_normalized'    => $normalizedCuratedIds,
        'curated_matched'           => $matchedCurated,
        'curated_unmatched'         => $unmatchedCurated,
        'decision'                  => ! empty( $matchedCurated ) ? 'curated' : 'fallback_by_matches',
        'fallback_top12'            => $fallbackTop,
        'total_unique_competitions' => count( $compLookup ),
        'lookup_id_detail'          => $lookupDetail,
    ], 200 );
    $response->header( 'Cache-Control', 'no-store' );
    $response->header( 'Access-Control-Allow-Origin', '*' );
    return $response;
}
