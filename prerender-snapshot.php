<?php
if ( ! defined( 'ABSPATH' ) ) exit;

error_log( 'HS DEBUG: prerender-snapshot.php wurde geladen.' );

/**
 * HEIM:SPIEL Prerender Snapshot Writeback -- v1.4
 *
 * Nimmt den von scripts/snapshot-pages.mjs (Puppeteer) erzeugten,
 * fertig gerenderten HTML-Inhalt entgegen und schreibt ihn GEZIELT nur
 * in den Bereich <div id="hs-root">...</div> der jeweiligen Seite --
 * der Rest des Seiteninhalts (WP-Bloecke, das <script src=".../hs-
 * landing.js">-Tag) bleibt unangetastet.
 *
 * v1.1 FIX: Der bisherige Code hat $after bei Beginn des oeffnenden
 * <script>-Tags angesetzt und dabei den ORIGINAL-</script>-Schliesstag
 * nach dem Script-Code stehen lassen. WordPress/Gutenberg normalisierte
 * dadurch die resultierende ungueltige Struktur und der Script-Anker ging
 * nach dem ersten erfolgreichen Writeback verloren. Jetzt wird der ganze
 * Script-Block <script ...>...</script> explizit erkannt, unveraendert
 * beibehalten und nur der Bereich VOR dem oeffnenden <script> ersetzt.
 *
 * v1.2 FIX: WPML-sprachbewusste Post-Aufloesung plus Sprachabgleich vor dem
 * Schreiben. Vorher wurden DE-Snapshots von Seiten mit identischem DE/EN-Slug
 * (biathlon, skeleton, snowboard) in den englischen Post geschrieben.
 *
 * v1.3 FIX: Der <script>-Anker als Endmarke ist entfallen. WordPress entfernt
 * beim Speichern via wp_update_post() im REST-Kontext alle <script>-Tags
 * (KSES, kein "unfiltered_html"), wodurch jede Seite nach dem ERSTEN Writeback
 * dauerhaft mit HTTP 422 blockiert war. Das schliessende </div> wird jetzt per
 * Tiefenzaehlung ermittelt -- ankerfrei und beliebig oft wiederholbar.
 *
 * v1.4 FIX: Dieselbe KSES-Ursache traf auch die Formularelemente. Die
 * WordPress-Standardliste erlaubter Tags fuer post_content enthaelt <form>,
 * <input>, <textarea> und <select> nicht -- <button> dagegen schon. Im
 * gespeicherten Snapshot ueberlebte deshalb der Submit-Button des
 * Kontaktformulars, aber keines der Eingabefelder, und ebenso fehlte das
 * Suchfeld der Wettbewerbsliste. Die erlaubten Tags werden nun GENAU fuer
 * den einen wp_update_post()-Aufruf erweitert.
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
 * Ermittelt den WPML-Sprachcode aus dem URL-Pfad.
 *
 *   "/de/wintersport/biathlon/"  -> "de"
 *   "/winter-sports/biathlon/"   -> Standardsprache (en)
 *
 * Der Vergleich laeuft gegen die aktiv konfigurierten WPML-Sprachen, damit
 * spaeter ergaenzte Sprachen (fr, es, it) automatisch mitgreifen und ein
 * Pfadsegment wie "de" nicht mit einem echten Seiten-Slug verwechselt wird.
 */
function hs_prerender_lang_from_url( $url ) {
$parsed = wp_parse_url( $url );
$path   = ( $parsed && isset( $parsed['path'] ) ) ? trim( $parsed['path'], '/' ) : '';
$parts  = ( $path !== '' ) ? explode( '/', $path ) : [];
$first  = isset( $parts[0] ) ? strtolower( $parts[0] ) : '';

$languages = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
if ( is_array( $languages ) && $first !== '' && isset( $languages[ $first ] ) ) {
return $first;
}

$default = apply_filters( 'wpml_default_language', null );
return $default ? (string) $default : '';
}

/**
 * Loest eine vollstaendige URL zuverlaessig auf eine Post-ID auf.
 * url_to_postid() allein scheitert manchmal an Trailing-Slash- oder
 * Query-String-Abweichungen -- daher mehrere Normalisierungsversuche.
 *
 * v1.2 FIX (WPML): url_to_postid() ist NICHT sprachbewusst. Bei Seiten, deren
 * Slug in DE und EN identisch ist -- biathlon, skeleton, snowboard -- lieferte
 * die Funktion immer den Post der Standardsprache (EN). Folge: Der DE-Snapshot
 * wurde in den EN-Post geschrieben. Die EN-Seiten enthielten danach deutschen
 * Inhalt, die DE-Seiten gar keinen. Seiten mit unterschiedlichen Slugs
 * (ski-alpin/alpine-skiing, bob/bobsleigh, ...) waren nicht betroffen, weil
 * der Slug dort ohnehin eindeutig ist.
 *
 * Zwei Massnahmen:
 *   1. WPML VOR der Aufloesung in die Zielsprache schalten.
 *   2. Das Ergebnis ueber wpml_object_id auf die Uebersetzung in der
 *      Zielsprache mappen -- ohne Fallback auf das Original, damit ein
 *      fehlendes Uebersetzungspaar nicht stillschweigend die falsche
 *      Sprachversion trifft.
 */
function hs_prerender_resolve_post_id( $url ) {
$lang = hs_prerender_lang_from_url( $url );

if ( $lang ) {
do_action( 'wpml_switch_language', $lang );
}

$candidates = [ $url ];

$parsed = wp_parse_url( $url );
if ( $parsed && isset( $parsed['path'] ) ) {
$path = $parsed['path'];
$candidates[] = home_url( $path );
$candidates[] = home_url( untrailingslashit( $path ) );
$candidates[] = home_url( trailingslashit( $path ) );
}

$id = 0;
foreach ( array_unique( $candidates ) as $candidate ) {
$id = url_to_postid( $candidate );
if ( $id ) break;
}

if ( ! $id ) return 0;

if ( $lang ) {
// 3. Parameter false = KEIN Fallback auf das Original, wenn keine
// Uebersetzung existiert. Der Sprachabgleich in
// hs_prerender_write_snapshot() bricht dann sauber mit Fehler ab.
$translated = apply_filters( 'wpml_object_id', $id, get_post_type( $id ), false, $lang );
if ( $translated ) {
$id = (int) $translated;
}
}

return (int) $id;
}

/**
 * Findet zum oeffnenden <div id="hs-root"> das passende schliessende </div>
 * per Tiefenzaehlung und liefert dessen Startposition zurueck.
 *
 * Ersetzt das fruehere <script>-Anker-Verfahren, das nach dem ersten
 * Writeback nicht mehr funktionierte (siehe Kommentar in
 * hs_prerender_write_snapshot). Diese Variante ist unbegrenzt wiederholbar.
 *
 * @param  string $content    Kompletter post_content.
 * @param  int    $start      Position direkt NACH dem oeffnenden hs-root-Tag.
 * @return int|null           Startposition des passenden </div> oder null.
 */
function hs_prerender_find_matching_div_close( $content, $start ) {
$depth  = 1;
$offset = $start;
$length = strlen( $content );

while ( $offset < $length ) {
if ( ! preg_match( '/<\s*(\/?)div\b[^>]*>/i', $content, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
return null;
}

$is_closing = ( $m[1][0] === '/' );
$tag_start  = $m[0][1];
$tag_end    = $tag_start + strlen( $m[0][0] );

if ( $is_closing ) {
$depth--;
if ( $depth === 0 ) {
return $tag_start;
}
} else {
$depth++;
}

$offset = $tag_end;
}

return null;
}

/**
 * Erweitert die von KSES erlaubten HTML-Tags um Formularelemente.
 *
 * Wird ausschliesslich waehrend des wp_update_post()-Aufrufs im
 * Snapshot-Writeback registriert und unmittelbar danach wieder entfernt.
 * Der Inhalt stammt aus der eigenen, serverseitig gerenderten Seite --
 * es werden keine Fremdinhalte durchgelassen. Alle uebrigen Inhalte der
 * Website werden weiterhin unveraendert gefiltert.
 *
 * @param  array  $tags    Erlaubte Tags samt Attributen.
 * @param  string $context KSES-Kontext.
 * @return array
 */
function hs_prerender_allow_form_tags( $tags, $context ) {
if ( 'post' !== $context || ! is_array( $tags ) ) {
return $tags;
}

$tags['form'] = [
'action'     => true,
'method'     => true,
'id'         => true,
'class'      => true,
'style'      => true,
'novalidate' => true,
// onsubmit="return false;" verhindert im Original, dass ein Absenden ohne
// JavaScript die Seite neu laedt. Falls KSES das Attribut dennoch
// entfernt, bleibt das Formular funktionsfaehig -- die serverseitig
// gesetzte Canonical verhindert, dass eine etwaige Query-URL indexiert wird.
'onsubmit'   => true,
'aria-label' => true,
'data-*'     => true,
];

$tags['input'] = [
'type'         => true,
'name'         => true,
'value'        => true,
'placeholder'  => true,
'id'           => true,
'class'        => true,
'style'        => true,
'required'     => true,
'readonly'     => true,
'disabled'     => true,
'checked'      => true,
'maxlength'    => true,
'minlength'    => true,
'min'          => true,
'max'          => true,
'step'         => true,
'pattern'      => true,
'autocomplete' => true,
'inputmode'    => true,
'aria-label'   => true,
'data-*'       => true,
];

$tags['textarea'] = [
'name'         => true,
'placeholder'  => true,
'rows'         => true,
'cols'         => true,
'id'           => true,
'class'        => true,
'style'        => true,
'required'     => true,
'readonly'     => true,
'disabled'     => true,
'maxlength'    => true,
'autocomplete' => true,
'aria-label'   => true,
'data-*'       => true,
];

$tags['select'] = [
'name'       => true,
'id'         => true,
'class'      => true,
'style'      => true,
'required'   => true,
'multiple'   => true,
'disabled'   => true,
'aria-label' => true,
'data-*'     => true,
];

$tags['option'] = [
'value'    => true,
'selected' => true,
'disabled' => true,
'label'    => true,
];

$tags['label'] = [
'for'   => true,
'id'    => true,
'class' => true,
'style' => true,
];

return $tags;
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

// SICHERHEITSNETZ (v1.2): Die Sprache des Ziel-Posts MUSS zur Sprache der URL
// passen. Ohne diese Pruefung meldet der Writeback Erfolg, waehrend er die
// falsche Sprachversion ueberschreibt -- genau das ist bei biathlon, skeleton
// und snowboard passiert. Lieber ein sichtbarer Fehler im Action-Log als ein
// stiller Datenverlust.
$expected_lang = hs_prerender_lang_from_url( $url );
if ( $expected_lang ) {
$details     = apply_filters( 'wpml_post_language_details', null, $post_id );
$actual_lang = ( is_array( $details ) && ! empty( $details['language_code'] ) )
? (string) $details['language_code']
: '';

if ( $actual_lang && $actual_lang !== $expected_lang ) {
return new WP_Error(
'lang_mismatch',
sprintf(
'Sprachkonflikt: URL "%s" erwartet Sprache "%s", aufgeloeste Post-ID %d hat aber "%s". Abbruch, um das Ueberschreiben der falschen Sprachversion zu verhindern.',
$url,
$expected_lang,
$post_id,
$actual_lang
),
[ 'status' => 409 ]
);
}
}

$content = $post->post_content;

// Oeffnendes hs-root-Tag finden (Attribute wie data-type/data-bundle erhalten).
if ( ! preg_match( '/<div\s+id=["\']hs-root["\']([^>]*)>/i', $content, $open_match, PREG_OFFSET_CAPTURE ) ) {
return new WP_Error( 'no_hs_root', 'Kein <div id="hs-root"> in dieser Seite gefunden -- Provisioner-Shell fehlt.', [ 'status' => 422 ] );
}

$open_tag_full = $open_match[0][0];
$open_tag_end  = $open_match[0][1] + strlen( $open_tag_full );

// v1.3: Das schliessende </div> von #hs-root per Tiefenzaehlung bestimmen,
// statt einen <script>-Block als Endmarke zu benoetigen.
//
// WARUM: Der bisherige Anker <script></script> war nach dem ERSTEN
// erfolgreichen Writeback verschwunden. Ursache ist nicht die Ersetzung
// selbst, sondern wp_update_post(): Der REST-Aufruf laeuft ohne
// angemeldeten Benutzer, damit ohne die Faehigkeit "unfiltered_html" --
// WordPress entfernt beim Speichern per KSES alle <script>-Tags aus dem
// post_content. Der Anker konnte also gar nicht ueberleben, und jede Seite
// war nach dem ersten Durchlauf dauerhaft blockiert ("HTTP 422: Kein
// vollstaendiger <script>-Tag nach #hs-root gefunden").
//
// Die Tiefenzaehlung braucht ueberhaupt keinen Anker: Sie findet das zum
// oeffnenden <div id="hs-root"> gehoerende </div>, indem sie oeffnende und
// schliessende div-Tags mitzaehlt. Das funktioniert beliebig oft
// wiederholbar, weil der Snapshot vom Browser serialisiert und damit
// garantiert ausbalanciert ist.
$close_offset = hs_prerender_find_matching_div_close( $content, $open_tag_end );
if ( $close_offset === null ) {
return new WP_Error(
'unbalanced_markup',
'Kein passendes </div> fuer #hs-root gefunden (unausbalanciertes Markup) -- Ersetzung abgebrochen (Sicherheitsnetz).',
[ 'status' => 422 ]
);
}

// Alles VOR dem Inhalt und alles NACH dem schliessenden </div> bleibt
// unveraendert -- inklusive weiterer WP-Bloecke hinter dem Container.
$before      = substr( $content, 0, $open_tag_end );
$after       = substr( $content, $close_offset );
$new_content = $before . $html . $after;

// v1.4: Formular-Tags nur fuer diesen einen Speichervorgang zulassen.
add_filter( 'wp_kses_allowed_html', 'hs_prerender_allow_form_tags', 10, 2 );

$update = wp_update_post( [
'ID'           => $post_id,
'post_content' => $new_content,
], true );

// Filter sofort wieder entfernen -- auch im Fehlerfall, deshalb VOR der
// Fehlerauswertung.
remove_filter( 'wp_kses_allowed_html', 'hs_prerender_allow_form_tags', 10 );

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
