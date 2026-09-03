<?php
/**
 * Lokaler Logiktest fuer das Event-Template (kein WordPress noetig).
 * Baut die WP-Abhaengigkeiten als Stubs nach und faehrt hs_build_event_coverage()
 * gegen synthetische Daten, die den echten Sheet-Tabs nachempfunden sind
 * (Wintersport mit sport-Spalte, Basketball ohne).
 */

// ── WordPress-Stubs ──────────────────────────────────────────────────────────
class WP_Error {
	public $code; public $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t ) { return true; }

$GLOBALS['TEST_INDEX'] = [
	[ 'type'=>'cluster', 'bundleName'=>'Wintersport', 'discipline_key'=>'winter-sports', 'displayName'=>'Wintersport', 'gid'=>'1008552869', 'clusterTemplate'=>'multisport', 'nameFilter'=>'' ],
	[ 'type'=>'cluster', 'bundleName'=>'Basketball',  'discipline_key'=>'basketball',    'displayName'=>'Basketball',  'gid'=>'222',        'clusterTemplate'=>'',           'nameFilter'=>'' ],
	[ 'type'=>'cluster', 'bundleName'=>'Olympische Winterspiele', 'discipline_key'=>'olympia-winter', 'displayName'=>'Olympische Winterspiele', 'gid'=>'', 'clusterTemplate'=>'event', 'nameFilter'=>'Olympische Winterspiele' ],
	[ 'type'=>'cluster', 'bundleName'=>'Olympische Spiele', 'discipline_key'=>'olympia-sommer', 'displayName'=>'Olympische Spiele', 'gid'=>'', 'clusterTemplate'=>'event', 'nameFilter'=>'Olympische Spiele' ],
];

function hs_fetch_index() { return $GLOBALS['TEST_INDEX']; }

function hs_fetch_csv( $gid ) {
	// PHP castet numerische Array-Keys zu int -- der Aufrufer uebergibt den
	// gid daher als Integer. In Produktion unkritisch (String-Konkatenation),
	// hier explizit casten.
	$gid = (string) $gid;
	$future = date( 'Y-m-d', strtotime( '+120 days' ) );
	$recent = date( 'Y-m-d', strtotime( '-400 days' ) );
	$old    = date( 'Y-m-d', strtotime( '-9 years' ) );

	if ( $gid === '1008552869' ) {
		return [
			// Ski Alpin: drei Saisons desselben Wettbewerbs -> EIN Event
			[ 'country'=>'', 'sport'=>'Ski Alpin', 'season_end'=>$future, 'competition_id'=>'1303', 'name'=>'Olympische Winterspiele - Slalom', 'number_matches'=>'3', 'gender'=>'male', 'result_list'=>'1. Durchgang,Gesamt,Startliste' ],
			[ 'country'=>'', 'sport'=>'Ski Alpin', 'season_end'=>$recent, 'competition_id'=>'1303', 'name'=>'Olympische Winterspiele - Slalom', 'number_matches'=>'3', 'gender'=>'male', 'result_list'=>'Gesamt,Startliste' ],
			[ 'country'=>'', 'sport'=>'Ski Alpin', 'season_end'=>$future, 'competition_id'=>'1301', 'name'=>'Olympische Winterspiele - Super-G', 'number_matches'=>'2', 'gender'=>'male' ],
			[ 'country'=>'', 'sport'=>'Ski Alpin', 'season_end'=>$future, 'competition_id'=>'1302', 'name'=>'Olympische Winterspiele - Riesenslalom', 'number_matches'=>'2', 'gender'=>'male' ],
			// Biathlon
			[ 'country'=>'', 'sport'=>'Biathlon', 'season_end'=>$future, 'competition_id'=>'9001', 'name'=>'Olympische Winterspiele - Sprint', 'number_matches'=>'2', 'gender'=>'female' ],
			// Nicht-Olympia-Zeile: darf NICHT auftauchen
			[ 'country'=>'', 'sport'=>'Ski Alpin', 'season_end'=>$future, 'competition_id'=>'2066', 'name'=>'Weltcup Finale', 'number_matches'=>'5', 'gender'=>'male' ],
			// Eingestellte Disziplin (>4 Jahre her, keine kommende Saison): faellt raus
			[ 'country'=>'', 'sport'=>'Ski Alpin', 'season_end'=>$old, 'competition_id'=>'1304', 'name'=>'Olympische Winterspiele - Super-Kombination', 'number_matches'=>'1', 'gender'=>'male' ],
		];
	}
	if ( $gid === '222' ) {
		return [
			// Basketball-Tab hat KEINE sport-Spalte -> Gruppe = Tab-Label
			[ 'country'=>'', 'season_end'=>$future, 'competition_id'=>'162', 'name'=>'Olympische Spiele', 'number_matches'=>'26', 'gender'=>'female' ],
			[ 'country'=>'', 'season_end'=>$future, 'competition_id'=>'149', 'name'=>'Olympische Spiele', 'number_matches'=>'26', 'gender'=>'male' ],
			[ 'country'=>'Deutschland', 'season_end'=>$future, 'competition_id'=>'888', 'name'=>'Bundesliga', 'number_matches'=>'306', 'gender'=>'male' ],
		];
	}
	return [];
}

// ── Zu testender Produktionscode ─────────────────────────────────────────────
// cache.php enthaelt ausser den Funktionsdefinitionen nur ABSPATH-Guard und
// zwei define()-Blocke, laesst sich also mit Stubs komplett einbinden.
define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'HS_CACHE_TTL' ) ) define( 'HS_CACHE_TTL', 3600 );
function wp_remote_get( $url, $args = [] ) { return new WP_Error( 'stub', 'nicht im Test' ); }
function wp_remote_retrieve_body( $r ) { return ''; }
function wp_remote_retrieve_response_code( $r ) { return 0; }
function sanitize_text_field( $s ) { return $s; }
// mbstring ist in dieser CLI-Installation nicht aktiv, auf dem Server aber
// vorhanden. Polyfill nur fuer den Test -- fuer die hier genutzten deutschen
// Umlaute/ASCII ist die Ersatzfunktion ausreichend.
if ( ! function_exists( 'mb_strtolower' ) ) {
	function mb_strtolower( $s, $enc = null ) {
		return strtr( strtolower( $s ), [ 'Ä'=>'ä', 'Ö'=>'ö', 'Ü'=>'ü' ] );
	}
}
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }

// hs_fetch_index()/hs_fetch_csv() werden im Test durch die Stubs oben ersetzt:
// dafuer die Original-Definitionen in einer Arbeitskopie umbenennen, die
// Aufrufstellen in hs_build_event_coverage() greifen dann auf die Stubs zu.
$src = file_get_contents( __DIR__ . '/cache.php' );
$src = str_replace(
	[ 'function hs_fetch_index()', 'function hs_fetch_csv(' ],
	[ 'function hs_fetch_index__orig()', 'function hs_fetch_csv__orig(' ],
	$src
);
$tmp = __DIR__ . '/.test-cache-copy.php';
file_put_contents( $tmp, $src );
require_once $tmp;
@unlink( $tmp );

// ── Tests ────────────────────────────────────────────────────────────────────
$fails = 0;
function check( $label, $actual, $expected ) {
	global $fails;
	$ok = ( $actual === $expected );
	if ( ! $ok ) $fails++;
	printf( "%s  %-52s ist=%s soll=%s\n", $ok ? 'OK  ' : 'FAIL', $label,
		var_export( $actual, true ), var_export( $expected, true ) );
}

echo "--- hs_event_short_name ---\n";
check( 'Praefix mit Bindestrich', hs_event_short_name( 'Olympische Winterspiele - Slalom', 'Olympische Winterspiele' ), 'Slalom' );
check( 'Super-G bleibt intakt',   hs_event_short_name( 'Olympische Winterspiele - Super-G', 'Olympische Winterspiele' ), 'Super-G' );
check( 'Rest leer -> Vollname',   hs_event_short_name( 'Olympische Spiele', 'Olympische Spiele' ), 'Olympische Spiele' );

echo "\n--- Winterspiele (sport-Spalte) ---\n";
$w = hs_build_event_coverage( 'olympia-winter' );
if ( is_wp_error( $w ) ) { echo "FAIL  WP_Error: " . $w->get_error_message() . "\n"; $fails++; }
else {
	check( 'Sportarten',        $w['totalSports'], 2 );
	check( 'Events gesamt',     $w['totalEvents'], 4 );
	$names = array_map( function( $s ) { return $s['name'] . ':' . $s['eventCount']; }, $w['sports'] );
	check( 'Gruppen',           implode( ',', $names ), 'Ski Alpin:3,Biathlon:1' );
	$alpin = $w['sports'][0]['events'];
	check( 'Events alphabet.',  implode( '|', array_column( $alpin, 'shortName' ) ), 'Riesenslalom|Slalom|Super-G' );
	check( 'Vollname erhalten', $alpin[1]['name'], 'Olympische Winterspiele - Slalom' );
	check( 'Saisons dedupliziert', count( $alpin ), 3 );
	// result_list muss wie stats_list behandelt werden (Wintersport-Tab).
	$slalom = null;
	foreach ( $alpin as $ev ) { if ( $ev['shortName'] === 'Slalom' ) $slalom = $ev; }
	check( 'result_list als statsList', $slalom ? $slalom['statsList'] : '(kein Slalom)', '1. Durchgang,Gesamt,Startliste' );
}

echo "\n--- Sommerspiele (ohne sport-Spalte, tab-uebergreifend) ---\n";
$s = hs_build_event_coverage( 'olympia-sommer' );
if ( is_wp_error( $s ) ) { echo "FAIL  WP_Error: " . $s->get_error_message() . "\n"; $fails++; }
else {
	check( 'Gruppe = Tab-Label', $s['sports'][0]['name'], 'Basketball' );
	check( 'Events (m/w)',       $s['sports'][0]['eventCount'], 2 );
	check( 'Bundesliga raus',    strpos( json_encode( $s ), 'Bundesliga' ), false );
}

echo "\n--- Fehlerfaelle ---\n";
$e = hs_build_event_coverage( 'basketball' );
check( 'ohne nameFilter -> Fehler', is_wp_error( $e ) ? $e->get_error_code() : 'kein Fehler', 'missing_name_filter' );
$e2 = hs_build_event_coverage( 'gibt-es-nicht' );
check( 'unbekannter Slug -> Fehler', is_wp_error( $e2 ) ? $e2->get_error_code() : 'kein Fehler', 'not_found' );

echo "\n" . ( $fails === 0 ? "ALLE TESTS BESTANDEN\n" : "$fails TEST(S) FEHLGESCHLAGEN\n" );
exit( $fails === 0 ? 0 : 1 );
