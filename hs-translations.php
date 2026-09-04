<?php
/**
 * hs-translations.php -- HEIM:SPIEL Server-seitige Uebersetzungs-Cache-Schicht
 * v4 -- August 2026
 *
 * ZWECK:
 * Ersetzt die bisherige Client-seitige OpenAI-Anbindung (translateEvents() /
 * translateCompetitions() in hs-landing.js), bei der der OpenAI API-Key im
 * Frontend-Quelltext sichtbar war UND bei jedem Website-Besucher (mit neuer
 * Session) ein neuer, kostenpflichtiger API-Call ausgeloest wurde.
 *
 * NEUES VERHALTEN:
 * 1. Der OpenAI-Key liegt NUR NOCH serverseitig (Konstante HS_OPENAI_API_KEY
 *    in wp-config.php -- niemals im Git-Repo, niemals im JS-Bundle).
 * 2. Uebersetzungen werden dauerhaft in der WordPress-Datenbank gespeichert
 *    (wp_options, autoload=no) -- KEIN sessionStorage-Cache mehr noetig.
 * 3. Der OpenAI-Call wird nur EINMALIG pro Sprache+Bundle ausgefuehrt, und
 *    zwar im Hintergrund per WP-Cron (wp_schedule_single_event), NICHT
 *    synchron waehrend ein Besucher auf der Seite ist. Ein Lock-Transient
 *    verhindert parallele Doppel-Anfragen, falls mehrere Besucher gleich-
 *    zeitig eine Uebersetzungsluecke ausloesen.
 * 4. Da sich Wettbewerbs-/Event-Namen praktisch nie aendern, werden bereits
 *    uebersetzte Begriffe NIE erneut angefragt -- nur neue, noch unbekannte
 *    Strings werden nachtraeglich per Cron uebersetzt.
 * 5. Admin-Werkzeug: Buttons im WP-Backend (auf der bestehenden "HEIM:SPIEL
 *    Cache"-Einstellungsseite), um den Uebersetzungs- bzw. Glossar-Cache
 *    manuell zurueckzusetzen (z.B. nach Aenderungen im Sheet).
 *
 * NEU (v2 -- August 2026): Manuelles Glossar zur Korrektur falscher
 * Automatik-Uebersetzungen (z.B. "Monobob" -> faelschlich "Einbeinbob").
 * Siehe hs_fetch_glossary() und die erweiterte hs_openai_translate_batch().
 *
 * NEU (v3 -- August 2026): Admin-UI fuer die beiden Reset-Aktionen ergaenzt
 * (vorher gab es nur die Backend-Handler ohne zugehoerigen Button).
 *
 * NEU (v4 -- August 2026): Glossar-Cache-Laufzeit vereinheitlicht mit den
 * uebrigen Sheet-Daten (HS_CACHE_TTL, aktuell 31 Tage statt vorher 1h) UND
 * automatische Auffrischung beim bestehenden monatlichen WP-Cron-Lauf
 * (HS_CRON_HOOK) -- das Glossar verhaelt sich damit identisch zu Index/
 * GeneralIndex/CSV/Coverage. Der manuelle "Glossar-Cache leeren"-Button
 * bleibt zusaetzlich bestehen, fuer den Fall, dass eine Aenderung im Sheet
 * SOFORT (statt erst beim naechsten monatlichen Refresh) greifen soll.
 *
 * SETUP:
 * - In wp-config.php ergaenzen: define( 'HS_OPENAI_API_KEY', 'sk-...' );
 * - Diese Datei per require_once im Haupt-Plugin einbinden (z.B. in
 *   heimspiel-data-cache.php neben dem require von rest-api.php).
 * - Google-Sheet "Translations" (gid=1129563872): Spalte A = Ausgangsbegriff
 *   (so wie er in der Roh-Quelle steht), jede weitere Spalte traegt als
 *   Ueberschrift den Sprachcode (z.B. "de", "en", "fr") mit der jeweils
 *   verbindlichen Uebersetzung.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── REST-Routen ──────────────────────────────────────────────────────────

add_action( 'rest_api_init', function () {

	// GET /wp-json/hs-cache/v1/translations/{lang}/{cacheKey}
	// Liefert ausschliesslich bereits gespeicherte Uebersetzungen (kein API-Call!)
	register_rest_route( 'hs-cache/v1', '/translations/(?P<lang>[a-z]{2})/(?P<cacheKey>[a-zA-Z0-9_-]+)', [
		'methods'             => 'GET',
		'callback'            => 'hs_rest_get_translations',
		'permission_callback' => '__return_true',
		'args'                => [
			'lang'     => [ 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
			'cacheKey' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
		],
	] );

	// POST /wp-json/hs-cache/v1/translations/{lang}/{cacheKey}
	register_rest_route( 'hs-cache/v1', '/translations/(?P<lang>[a-z]{2})/(?P<cacheKey>[a-zA-Z0-9_-]+)', [
		'methods'             => 'POST',
		'callback'            => 'hs_rest_queue_translations',
		'permission_callback' => '__return_true',
		'args'                => [
			'lang'     => [ 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
			'cacheKey' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
		],
	] );

} );

// ── Hilfsfunktionen ──────────────────────────────────────────────────────

if ( ! defined( 'HS_GLOSSARY_GID' ) ) {
	define( 'HS_GLOSSARY_GID', '1129563872' );
}

// NEU (v4): Gleiche Cache-Laufzeit wie Index/GeneralIndex/CSV/Coverage
// (HS_CACHE_TTL, definiert in heimspiel-data-cache.php, aktuell 31 Tage).
// Fallback auf 31 Tage, falls diese Datei vor heimspiel-data-cache.php
// geladen werden sollte (Konstante dann noch nicht definiert).
if ( ! defined( 'HS_GLOSSARY_TTL' ) ) {
	define( 'HS_GLOSSARY_TTL', defined( 'HS_CACHE_TTL' ) ? HS_CACHE_TTL : 31 * DAY_IN_SECONDS );
}

/**
 * Holt die rohen Glossar-Zeilen frisch von Google Sheets und schreibt sie
 * in den Transient. Wird sowohl vom lazy-Read in hs_fetch_glossary() als
 * auch proaktiv beim monatlichen WP-Cron-Lauf (siehe HS_CRON_HOOK weiter
 * unten) sowie vom manuellen Admin-Button aufgerufen.
 */
function hs_refresh_glossary_cache() {
	$rows = hs_fetch_csv( HS_GLOSSARY_GID );
	if ( is_wp_error( $rows ) || empty( $rows ) ) {
		return false;
	}
	set_transient( 'hs_glossary_data', $rows, HS_GLOSSARY_TTL );
	return true;
}

// Haengt die Glossar-Auffrischung an denselben monatlichen Cron-Hook, den
// auch hs_refresh_all_cache() (cache.php) fuer Index/GeneralIndex/CSV/
// Coverage nutzt -- ohne cache.php selbst anfassen zu muessen. Der manuelle
// "Cache jetzt aktualisieren"-Button in admin.php ruft hs_refresh_all_cache()
// zwar direkt auf (nicht ueber den Hook), das Glossar wird dort aber ueber
// den separaten Button unten trotzdem sofort mit aktualisiert.
if ( defined( 'HS_CRON_HOOK' ) ) {
	add_action( HS_CRON_HOOK, 'hs_refresh_glossary_cache' );
}

/**
 * Laedt das Glossar-Sheet (via hs_fetch_csv() aus cache.php) und liefert ein
 * Array [ 'Monobob' => 'Monobob', ... ] fuer die angefragte Zielsprache.
 *
 * Spalte A = Quellbegriff, so wie er in den Roh-Event-/Wettbewerbsnamen
 * vorkommt -- IMMER die erste Spalte, unabhaengig vom Text der
 * Spaltenueberschrift (z.B. "Zu uebersetzender Begriff"). Alle weiteren
 * Spalten werden per Sprachcode-Ueberschrift (z.B. "de", "en", "fr")
 * zugeordnet -- Reihenfolge und Anzahl dieser Spalten ist frei waehlbar.
 *
 * @param string $target_lang Sprachcode, z.B. "en"
 * @return array [ Ausgangsbegriff => Uebersetzung ]
 */
function hs_fetch_glossary( $target_lang ) {
	$rows = get_transient( 'hs_glossary_data' );
	if ( $rows === false ) {
		if ( ! hs_refresh_glossary_cache() ) {
			return [];
		}
		$rows = get_transient( 'hs_glossary_data' );
	}

	if ( empty( $rows ) ) return [];

	$header_keys = array_keys( $rows[0] );
	if ( empty( $header_keys ) ) return [];

	$source_key = $header_keys[0];
	$target_key = null;
	foreach ( $header_keys as $k ) {
		if ( $k === $source_key ) continue;
		if ( strtolower( trim( $k ) ) === strtolower( trim( $target_lang ) ) ) {
			$target_key = $k;
			break;
		}
	}
	if ( ! $target_key ) return [];

	$glossary = [];
	foreach ( $rows as $row ) {
		$source   = trim( (string) ( $row[ $source_key ] ?? '' ) );
		$override = trim( (string) ( $row[ $target_key ] ?? '' ) );
		if ( $source !== '' && $override !== '' ) {
			$glossary[ $source ] = $override;
		}
	}
	return $glossary;
}

function hs_translations_option_key( $lang, $cache_key ) {
	return 'hs_trans_' . $lang . '_' . md5( $cache_key );
}

function hs_translations_lock_key( $lang, $cache_key ) {
	return 'hs_trans_lock_' . $lang . '_' . md5( $cache_key );
}

// ── GET: nur lesen, niemals OpenAI aufrufen ──────────────────────────────

function hs_rest_get_translations( WP_REST_Request $request ) {
	$lang      = $request->get_param( 'lang' );
	$cache_key = $request->get_param( 'cacheKey' );

	$stored = get_option( hs_translations_option_key( $lang, $cache_key ), [] );

	$response = new WP_REST_Response( [ 'translations' => $stored ], 200 );
	$response->header( 'Cache-Control', 'public, max-age=86400' );
	$response->header( 'Access-Control-Allow-Origin', '*' );
	return $response;
}

// ── POST: liefert Cache-Treffer sofort, stoesst Hintergrund-Job fuer den Rest an ──

function hs_rest_queue_translations( WP_REST_Request $request ) {
	$lang      = $request->get_param( 'lang' );
	$cache_key = $request->get_param( 'cacheKey' );
	$body      = $request->get_json_params();
	$strings   = isset( $body['strings'] ) && is_array( $body['strings'] ) ? $body['strings'] : [];
	$strings   = array_values( array_unique( array_filter( array_map( 'trim', $strings ) ) ) );

	$option_key = hs_translations_option_key( $lang, $cache_key );
	$stored     = get_option( $option_key, [] );

	$missing = array_values( array_diff( $strings, array_keys( $stored ) ) );

	if ( ! empty( $missing ) ) {
		$lock_key = hs_translations_lock_key( $lang, $cache_key );
		if ( false === get_transient( $lock_key ) ) {
			set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );
			wp_schedule_single_event( time() + 5, 'hs_translate_batch_cron', [ $lang, $cache_key, $missing ] );
		}
	}

	$response = new WP_REST_Response( [
		'translations' => $stored,
		'pending'      => count( $missing ),
	], 200 );
	$response->header( 'Access-Control-Allow-Origin', '*' );
	return $response;
}

// ── WP-Cron: der EINZIGE Ort, an dem OpenAI tatsaechlich aufgerufen wird ──

add_action( 'hs_translate_batch_cron', 'hs_translate_batch_cron_handler', 10, 3 );

// Wie viele Strings gehen in EINEN OpenAI-Aufruf, und wie viele Aufrufe darf
// ein einzelner Cron-Lauf machen? Beides wird notwendig, seit nicht mehr nur
// vier Top-Wettbewerbe uebersetzt werden, sondern der gesamte Bestand
// (Fussball allein: 743 eindeutige Namen, ueber alle Sportarten 1.071).
//
// CHUNK: gpt-4o-mini liefert maximal 16.384 Ausgabe-Tokens. Ein einziger
// Aufruf ueber 1.071 Namen erzeugt rund 17.000 -- das JSON waere mitten drin
// abgeschnitten, json_decode() liefert null, es wuerde NICHTS gespeichert, und
// weil der Lock am Ende freigegeben wird, loeste der naechste Besucher denselben
// kostenpflichtigen Aufruf erneut aus. Eine Endlosschleife ohne Ergebnis.
//
// CHUNKS_PER_RUN: begrenzt die Laufzeit eines Cron-Durchlaufs, damit PHPs
// max_execution_time nicht zuschlaegt. Bleiben Strings uebrig, plant sich der
// Job selbst erneut ein und behaelt dabei den Lock -- so stossen parallele
// POSTs keine Doppelarbeit an.
if ( ! defined( 'HS_TRANSLATE_CHUNK' ) ) {
define( 'HS_TRANSLATE_CHUNK', 80 );
}
if ( ! defined( 'HS_TRANSLATE_CHUNKS_PER_RUN' ) ) {
define( 'HS_TRANSLATE_CHUNKS_PER_RUN', 4 );
}

function hs_translate_batch_cron_handler( $lang, $cache_key, $strings ) {
$lock_key   = hs_translations_lock_key( $lang, $cache_key );
$option_key = hs_translations_option_key( $lang, $cache_key );

if ( empty( $strings ) || ! defined( 'HS_OPENAI_API_KEY' ) || ! HS_OPENAI_API_KEY ) {
delete_transient( $lock_key );
return;
}

// Bereits Gespeichertes erneut abziehen: Zwischen Anmeldung und Ausfuehrung
// kann ein anderer Lauf Teile schon uebersetzt haben.
$stored  = get_option( $option_key, [] );
$strings = array_values( array_diff( $strings, array_keys( $stored ) ) );

if ( empty( $strings ) ) {
delete_transient( $lock_key );
return;
}

$chunks    = array_chunk( $strings, HS_TRANSLATE_CHUNK );
$processed = 0;

foreach ( $chunks as $chunk ) {
if ( $processed >= HS_TRANSLATE_CHUNKS_PER_RUN ) {
break;
}

$translated = hs_openai_translate_batch( $chunk, $lang, $cache_key );
$processed++;

if ( empty( $translated ) ) {
// Aufruf fehlgeschlagen -- Lauf beenden und Lock freigeben, damit ein
// spaeterer Seitenaufruf es erneut versucht. Kein sofortiger Retry.
delete_transient( $lock_key );
return;
}

// Nach JEDEM Chunk speichern, damit ein Abbruch keinen Fortschritt kostet.
$stored = get_option( $option_key, [] );
$stored = array_merge( $stored, $translated );
update_option( $option_key, $stored, false );
}

$remaining = array_slice( $strings, $processed * HS_TRANSLATE_CHUNK );

if ( ! empty( $remaining ) ) {
set_transient( $lock_key, 1, 10 * MINUTE_IN_SECONDS );
wp_schedule_single_event( time() + 30, 'hs_translate_batch_cron', [ $lang, $cache_key, $remaining ] );
return;
}

delete_transient( $lock_key );
}

// ── OpenAI-Call (serverseitig, Key nie im Frontend sichtbar) ─────────────

function hs_openai_translate_batch( $strings, $target_lang, $cache_key = '' ) {
	$glossary = hs_fetch_glossary( $target_lang );

	$result       = [];
	$to_translate = [];

	foreach ( $strings as $s ) {
		if ( isset( $glossary[ $s ] ) ) {
			$result[ $s ] = $glossary[ $s ];
		} else {
			$to_translate[] = $s;
		}
	}

	if ( empty( $to_translate ) ) {
		return $result;
	}

	$relevant_glossary = [];
	foreach ( $glossary as $term => $override ) {
		foreach ( $to_translate as $s ) {
			if ( stripos( $s, $term ) !== false ) {
				$relevant_glossary[ $term ] = $override;
				break;
			}
		}
	}

	$glossary_hint = '';
	if ( ! empty( $relevant_glossary ) ) {
		$pairs = [];
		foreach ( $relevant_glossary as $term => $override ) {
			$pairs[] = $term . ' -> ' . $override;
		}
		$glossary_hint = " MANDATORY GLOSSARY: Use these EXACT translations for the following terms " .
			"wherever they appear, even inside a longer phrase -- do NOT translate them differently: " .
			implode( '; ', $pairs ) . '.';
	}

// Statistik-Beschriftungen (cacheKey-Praefix "stats_", gesetzt von
// translateEventStats() im Event-Template) brauchen einen eigenen Prompt.
// Der Wettbewerbsnamen-Prompt unten laesst Eigennamen bewusst unangetastet
// und uebersetzt nur generische Anteile -- bei Messgroessen wie "Fehler
// liegend", "Nachlader Gesamt" oder "1. Zw.-zeit" ist genau das falsch:
// hier soll ALLES uebersetzt werden, in der Fachsprache der jeweiligen
// Sportart und kurz genug fuer die Pill-Darstellung.
if ( strpos( (string) $cache_key, 'stats_' ) === 0 ) {
$prompt =
"You translate German label texts for sports statistics into " . $target_lang . ".\n" .
"They are column headings for winter- and summer-olympic disciplines " .
"(alpine skiing, biathlon, ski jumping, figure skating, bobsleigh, " .
"speed skating, short track, luge, cross-country, nordic combined, " .
"snowboard, freestyle, skeleton, ski mountaineering, ice hockey, " .
"basketball, football).\n\n" .
"RULES:\n" .
"1. Use the established terminology of the sport, not a literal translation. " .
"Examples: 'Kuer' -> 'Free Skate', 'Kurzprogramm' -> 'Short Program', " .
"'Fehler liegend' -> 'Prone Misses', 'Fehler stehend' -> 'Standing Misses', " .
"'Nachlader' -> 'Spare Rounds', 'Durchgang' -> 'Run', 'Lauf' -> 'Run', " .
"'Weite Springen' -> 'Jump Distance', 'Startliste' -> 'Start List', " .
"'Zwischenzeit' -> 'Split Time', 'Handicap Langlauf' -> " .
"'Cross-Country Handicap'.\n" .
"2. Keep leading ordinals and their position: '1. Durchgang' -> '1st Run', " .
"'3. Wechsel' -> '3rd Exchange'. Never renumber.\n" .
"3. Keep it SHORT -- these render as small badges. If the German is " .
"abbreviated, abbreviate the result too: '1. Zw.-zeit' -> '1st Split', " .
"'Punkte 2. DG' -> 'Points 2nd Run'.\n" .
"4. If a label needs no translation at all, return it EXACTLY as given.\n" .
"5. Never add explanations, units or extra words.\n" .
$glossary_hint . "\n" .
"Return ONLY a JSON object: each key is the original German string, each value " .
"the result.\n" .
"Input: " . wp_json_encode( array_values( $to_translate ) );
} else {
// Der Prompt ist bewusst restriktiv formuliert. Von 1.071 Wettbewerbsnamen
// enthalten nur rund 330 einen generischen deutschen Begriff -- die uebrigen
// sind Eigen- und Markennamen wie "Allsvenskan", "Coppa Italia" oder
// "Primera Division", die unveraendert bleiben MUESSEN. Ein zu freier Prompt
// erzeugt dort Schaden statt Nutzen.
$prompt =
"You normalise German sports competition names for a " . $target_lang . " audience.\n\n" .
"RULES:\n" .
"1. Translate ONLY generic sports terminology. Examples: Pokal -> Cup, " .
"Freundschaft -> Friendlies, Frauen -> Women, Herren -> Men, Jugend -> Youth, " .
"Aufstieg -> Promotion, Abstieg -> Relegation, Meisterschaft -> Championship, " .
"Qualifikation -> Qualification, Olympische Spiele -> Olympic Games, " .
"WM -> World Cup, EM -> European Championship, Testspiel -> Friendly.\n" .
"2. NEVER translate proper nouns, brand names, club names, league brand names, " .
"sponsor names, country names or abbreviations. These must be returned " .
"COMPLETELY UNCHANGED, for example: Bundesliga, Allsvenskan, Superettan, " .
"Serie A, Coppa Italia, Primera Division, Eredivisie, Ligue 1, MLS, NHL, DFB.\n" .
"3. If a name mixes both, translate ONLY the generic part and keep the rest " .
"byte-identical. Example: 'DFB-Pokal' -> 'DFB Cup', 'Bundesliga Frauen' -> " .
"'Bundesliga Women'.\n" .
"4. If a name needs no translation at all, return it EXACTLY as given.\n" .
"5. Never invent, expand, abbreviate or reorder names. Never add explanations.\n" .
$glossary_hint . "\n" .
"Return ONLY a JSON object: each key is the original German string, each value " .
"the result.\n" .
"Input: " . wp_json_encode( array_values( $to_translate ) );
}

	$args = [
		'timeout' => 30,
		'headers' => [
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . HS_OPENAI_API_KEY,
		],
		'body' => wp_json_encode( [
			'model'           => 'gpt-4o-mini',
			'temperature'     => 0,
			'response_format' => [ 'type' => 'json_object' ],
			'messages'        => [ [ 'role' => 'user', 'content' => $prompt ] ],
		] ),
	];

	$res = wp_remote_post( 'https://api.openai.com/v1/chat/completions', $args );

	if ( is_wp_error( $res ) ) {
		error_log( 'HS Translation Error: ' . $res->get_error_message() );
		return $result;
	}

	$code = wp_remote_retrieve_response_code( $res );
	if ( $code !== 200 ) {
		error_log( 'HS Translation Error: OpenAI returned HTTP ' . $code );
		return $result;
	}

	$body    = json_decode( wp_remote_retrieve_body( $res ), true );
	$content = $body['choices'][0]['message']['content'] ?? '';
	$decoded = json_decode( $content, true );

	if ( is_array( $decoded ) ) {
		foreach ( $decoded as $orig => $translated ) {
			foreach ( $relevant_glossary as $term => $override ) {
				if ( stripos( $orig, $term ) !== false ) {
					$translated = str_ireplace( $term, $override, $translated );
				}
			}
			$result[ $orig ] = $translated;
		}
	}

	return $result;
}

// ── Backend-Handler: manueller Reset (z.B. nach Sheet-Aenderungen) ───────

add_action( 'admin_post_hs_reset_translations', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
	check_admin_referer( 'hs_reset_translations' );

	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'hs_trans_%'" );

	wp_redirect( add_query_arg( 'hs_translations_reset', '1', wp_get_referer() ) );
	exit;
} );

// NEU (v4): Statt nur zu loeschen, wird das Glossar SOFORT frisch nachgeladen
// (hs_refresh_glossary_cache()) -- der Admin sieht die Aenderung damit direkt,
// ohne auf den naechsten monatlichen Cron-Lauf warten zu muessen.
add_action( 'admin_post_hs_reset_glossary_cache', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
	check_admin_referer( 'hs_reset_glossary_cache' );

	delete_transient( 'hs_glossary_data' );
	hs_refresh_glossary_cache();

	wp_redirect( add_query_arg( 'hs_glossary_reset', '1', wp_get_referer() ) );
	exit;
} );

// ── Admin-UI fuer die beiden Reset-Aktionen ──────────────────────────────
// Erscheint auf der bestehenden "HEIM:SPIEL Cache"-Einstellungsseite
// (Menuepunkt "Settings -> HEIM:SPIEL Cache", Slug "hs-data-cache").
add_action( 'admin_notices', function () {
	$screen = get_current_screen();
	if ( ! $screen || strpos( $screen->id, 'hs-data-cache' ) === false ) return;

	if ( isset( $_GET['hs_translations_reset'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>Alle gespeicherten Übersetzungen wurden zurückgesetzt. Sie werden bei nächstem Seitenaufruf neu erzeugt (per Cron, über OpenAI, mit Glossar-Vorrang).</p></div>';
	}
	if ( isset( $_GET['hs_glossary_reset'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>Glossar wurde sofort neu geladen. Änderungen im "Translations"-Sheet sind jetzt aktiv (sonst automatisch beim nächsten monatlichen Cache-Refresh).</p></div>';
	}

	echo '<div class="notice notice-info"><p><strong>Übersetzungs-Verwaltung:</strong> Das Glossar wird automatisch beim monatlichen Cache-Refresh aktualisiert (wie Index/CSV/Coverage). Für sofortige Wirkung nach einer Sheet-Änderung:</p><p>';

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:10px;">';
	echo '<input type="hidden" name="action" value="hs_reset_glossary_cache">';
	wp_nonce_field( 'hs_reset_glossary_cache' );
	submit_button( 'Glossar jetzt neu laden', 'secondary', 'submit', false );
	echo '</form>';

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;" onsubmit="return confirm(\'Wirklich ALLE gespeicherten Übersetzungen löschen? Sie werden per Cron neu erzeugt (verursacht neue OpenAI-Calls).\');">';
	echo '<input type="hidden" name="action" value="hs_reset_translations">';
	wp_nonce_field( 'hs_reset_translations' );
	submit_button( 'Alle Übersetzungen zurücksetzen', 'delete', 'submit', false );
	echo '</form>';

	echo '</p></div>';
} );
