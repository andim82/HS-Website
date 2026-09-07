<?php
/**
 * Lokaler Logiktest fuer das Bundle-Template (kein WordPress noetig).
 *
 * Prueft die Umstellung, dass clusterTemplate="bundle" seine kuratierten
 * competition_ids ueber ALLE Sport-Tabs sucht statt nur ueber die in Spalte
 * "bundle" gelisteten Mitglieder -- und dass genau das die uebrigen Templates
 * NICHT betrifft.
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

// Zwei echte Sport-Tabs mit gid, dazu drei Cluster ohne gid: ein Bundle mit
// LEERER bundle-Spalte (neues Verhalten), ein Bundle mit der alten
// Mitglieder-Liste, und ein clubBundle, das auf seinen Tab beschraenkt bleibt.
$GLOBALS['TEST_INDEX'] = [
	[ 'type'=>'cluster', 'bundleName'=>'Basketball', 'discipline_key'=>'basketball', 'displayName'=>'Basketball', 'gid'=>'111', 'clusterTemplate'=>'general-purpose', 'bundle'=>'Basketball', 'topCompetitions'=>'148' ],
	[ 'type'=>'cluster', 'bundleName'=>'Eishockey', 'discipline_key'=>'ice-hockey', 'displayName'=>'Ice Hockey', 'gid'=>'222', 'clusterTemplate'=>'general-purpose', 'bundle'=>'Eishockey', 'topCompetitions'=>'487' ],
	[ 'type'=>'cluster', 'bundleName'=>'US Sports', 'discipline_key'=>'us-sports', 'displayName'=>'US Sports', 'gid'=>'', 'clusterTemplate'=>'bundle', 'bundle'=>'', 'topCompetitions'=>'148,487' ],
	[ 'type'=>'cluster', 'bundleName'=>'Basketball,Eishockey', 'discipline_key'=>'us-sports-alt', 'displayName'=>'US Sports', 'gid'=>'', 'clusterTemplate'=>'bundle', 'bundle'=>'Basketball,Eishockey', 'topCompetitions'=>'148,487' ],
	[ 'type'=>'cluster', 'bundleName'=>'Club Paket', 'discipline_key'=>'club-package', 'displayName'=>'Club Paket', 'gid'=>'', 'clusterTemplate'=>'clubBundle', 'bundle'=>'Basketball', 'topCompetitions'=>'148,487' ],
];

function hs_fetch_index() { return $GLOBALS['TEST_INDEX']; }

function hs_fetch_csv( $gid ) {
	// PHP castet numerische Array-Keys zu int -- der Aufrufer uebergibt den
	// gid daher als Integer. In Produktion unkritisch (String-Konkatenation),
	// hier explizit casten.
	$gid = (string) $gid;
	// hs_build_last_season_family_stats() liefert die Zahlen der letzten
	// ABGESCHLOSSENEN Saison -- eine rein zukuenftige Saison ergibt 0 Events.
	// Deshalb liegt season_end hier in der Vergangenheit (und weit genug
	// innerhalb der 4-Jahres-Grenze, ab der Wettbewerbe als eingestellt gelten).
	$done = date( 'Y-m-d', strtotime( '-100 days' ) );

	if ( $gid === '111' ) {
		return [
			[ 'country'=>'USA', 'federation'=>'', 'season_end'=>$done, 'competition_id'=>'148', 'name'=>'NBA', 'number_matches'=>'1230', 'gender'=>'male', 'stats_list'=>'points_scored' ],
			[ 'country'=>'Deutschland', 'federation'=>'', 'season_end'=>$done, 'competition_id'=>'900', 'name'=>'BBL', 'number_matches'=>'306', 'gender'=>'male', 'stats_list'=>'points_scored' ],
		];
	}
	if ( $gid === '222' ) {
		return [
			[ 'country'=>'USA', 'federation'=>'', 'season_end'=>$done, 'competition_id'=>'487', 'name'=>'NHL', 'number_matches'=>'1312', 'gender'=>'male', 'stats_list'=>'goals' ],
			[ 'country'=>'Deutschland', 'federation'=>'', 'season_end'=>$done, 'competition_id'=>'901', 'name'=>'DEL', 'number_matches'=>'400', 'gender'=>'male', 'stats_list'=>'goals' ],
		];
	}
	return [];
}

// ── Zu testender Produktionscode ─────────────────────────────────────────────
define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'HS_CACHE_TTL' ) ) define( 'HS_CACHE_TTL', 3600 );
function wp_remote_get( $url, $args = [] ) { return new WP_Error( 'stub', 'nicht im Test' ); }
function wp_remote_retrieve_body( $r ) { return ''; }
function wp_remote_retrieve_response_code( $r ) { return 0; }
function sanitize_text_field( $s ) { return $s; }
if ( ! function_exists( 'mb_strtolower' ) ) {
	function mb_strtolower( $s, $enc = null ) { return strtolower( $s ); }
}
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }

// hs_fetch_index()/hs_fetch_csv() werden im Test durch die Stubs oben ersetzt:
// dafuer die Original-Definitionen in einer Arbeitskopie umbenennen.
$src = file_get_contents( __DIR__ . '/cache.php' );
$src = str_replace(
	[ 'function hs_fetch_index()', 'function hs_fetch_csv(' ],
	[ 'function hs_fetch_index__orig()', 'function hs_fetch_csv__orig(' ],
	$src
);
$tmp = __DIR__ . '/.test-cache-copy-bundle.php';
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

function comp_names( $agg ) {
	$out = [];
	foreach ( [ 'countries', 'international' ] as $bucket ) {
		foreach ( (array) ( $agg[ $bucket ] ?? [] ) as $grp ) {
			foreach ( (array) ( $grp['topCompetitions'] ?? [] ) as $c ) {
				$out[] = (string) ( $c['name'] ?? '' );
			}
		}
	}
	sort( $out );
	return implode( '|', $out );
}

function sport_keys( $agg ) {
	$out = [];
	foreach ( [ 'countries', 'international' ] as $bucket ) {
		foreach ( (array) ( $agg[ $bucket ] ?? [] ) as $grp ) {
			foreach ( (array) ( $grp['topCompetitions'] ?? [] ) as $c ) {
				$k = (string) ( $c['sport'] ?? '' );
				if ( $k !== '' && ! in_array( $k, $out, true ) ) $out[] = $k;
			}
		}
	}
	sort( $out );
	return implode( '|', $out );
}

echo "--- Bundle mit LEERER bundle-Spalte (neues Verhalten) ---\n";
$a = hs_build_coverage_for_sport( 'us-sports' );
if ( is_wp_error( $a ) ) { echo 'FAIL  WP_Error: ' . $a->get_error_message() . "\n"; $fails++; }
else {
	check( 'beide kuratierten Wettbewerbe',   comp_names( $a ), 'NBA|NHL' );
	check( 'nicht kuratierte Zeilen draussen', strpos( json_encode( $a ), 'BBL' ), false );
	check( 'sport-Key je Herkunfts-Tab',      sport_keys( $a ), 'basketball|ice-hockey' );
	check( 'Wettbewerbe gesamt',              $a['totalCompetitions'], 2 );
}

echo "\n--- Bundle mit alter Mitglieder-Liste (rueckwaertskompatibel) ---\n";
$b = hs_build_coverage_for_sport( 'us-sports-alt' );
if ( is_wp_error( $b ) ) { echo 'FAIL  WP_Error: ' . $b->get_error_message() . "\n"; $fails++; }
else {
	check( 'identisches Ergebnis',   comp_names( $b ), 'NBA|NHL' );
	check( 'identische sport-Keys',  sport_keys( $b ), 'basketball|ice-hockey' );
}

echo "\n--- clubBundle bleibt auf seinen Tab beschraenkt ---\n";
$c = hs_build_coverage_for_sport( 'club-package' );
if ( is_wp_error( $c ) ) { echo 'FAIL  WP_Error: ' . $c->get_error_message() . "\n"; $fails++; }
else {
	check( 'nur der Basketball-Tab', comp_names( $c ), 'NBA' );
	check( 'NHL nicht eingesammelt', strpos( json_encode( $c ), 'NHL' ), false );
}

echo "\n--- Einzelsport mit eigener gid unveraendert (Fall A) ---\n";
// Fall A liefert bewusst den GANZEN Tab: topCompetitions steuert dort nur die
// Rangfolge, nicht die Auswahl. Nur die Bundles filtern hart auf die
// kuratierten IDs. Der Test haelt genau diesen Unterschied fest.
$d = hs_build_coverage_for_sport( 'basketball' );
if ( is_wp_error( $d ) ) { echo 'FAIL  WP_Error: ' . $d->get_error_message() . "\n"; $fails++; }
else {
	check( 'ganzer Tab, nicht nur kuratiert', comp_names( $d ), 'BBL|NBA' );
	check( 'sport-Key = eigener Slug',        sport_keys( $d ), 'basketball' );
}

echo "\n--- Bundle-Totals ---\n";
$t1 = hs_build_bundle_totals( 'us-sports' );
if ( is_wp_error( $t1 ) ) { echo 'FAIL  WP_Error: ' . $t1->get_error_message() . "\n"; $fails++; }
else { check( 'Summe ueber alle Tabs', $t1['totalEvents'], 1230 + 1312 ); }

$t2 = hs_build_bundle_totals( 'us-sports-alt' );
if ( is_wp_error( $t2 ) ) { echo 'FAIL  WP_Error: ' . $t2->get_error_message() . "\n"; $fails++; }
else { check( 'alte Liste, gleiche Summe', $t2['totalEvents'], 1230 + 1312 ); }

$t3 = hs_build_bundle_totals( 'club-package' );
if ( is_wp_error( $t3 ) ) { echo 'FAIL  WP_Error: ' . $t3->get_error_message() . "\n"; $fails++; }
else { check( 'clubBundle nur eigener Tab', $t3['totalEvents'], 1230 ); }

echo "\n--- Fehlerfaelle ---\n";
$e = hs_build_coverage_for_sport( 'gibt-es-nicht' );
check( 'unbekannter Slug -> Fehler', is_wp_error( $e ) ? $e->get_error_code() : 'kein Fehler', 'not_found' );

echo "\n" . ( $fails === 0 ? "ALLE TESTS BESTANDEN\n" : "$fails TEST(S) FEHLGESCHLAGEN\n" );
exit( $fails === 0 ? 0 : 1 );
