<?php
if ( ! defined( 'ABSPATH' ) ) exit;

error_log( 'HS DEBUG: prerender-snapshot.php wurde geladen.' );

/**
 * HEIM:SPIEL Prerender Snapshot Writeback -- v1.3
 *
 * Nimmt den von scripts/snapshot-pages.mjs (Puppeteer) erzeugten,
 * fertig gerenderten HTML-Inhalt entgegen und schreibt ihn GEZIELT nur
 * in den Bereich <div id="hs-root">...</div> der jeweiligen Seite.
 *
 * v1.3 FIX (entscheidend): Der bisherige Code berechnete zwar einen
 * robusteren Ersetzungs-Anker (ueber den letzten <!-- /wp:html -->
 * Kommentar bzw. als Fallback ueber den <script>-Tag), verwendete
 * anschliessend aber versehentlich eine ALTE, aus einer Vorversion
 * uebrig gebliebene Variable ($script_start_absolute statt
 * $anchor_start_absolute) fuer den Schnittpunkt von $after. Dadurch
 * wurde -- immer wenn der neue /wp:html-Zweig griff -- $script_match
 * NIE gesetzt, PHP werte $script_match[0][1] als 0, und $after
 * enthielt dadurch praktisch den KOMPLETTEN alten #hs-root-Inhalt statt
 * nur den Teil nach dem echten Ende. Der neue Snapshot wurde also vor
 * den kompletten alten Inhalt gesetzt, statt ihn zu ersetzen -- genau
 * das fuehrte zu der fortlaufenden Duplizierung bei jedem Lauf.
 *
 * v1.3 NEU: Zusaetzlich wird das #hs-root-Ende jetzt primaer ueber eine
 * robuste Div-Tiefenzaehlung ermittelt (hs_prerender_find_hs_root_close).
 * Das ist unabhaengig von <!-- /wp:html --> Kommentaren oder <script>-
 * Tags, die durch WPML-Uebersetzungs-Sync, Editor-Saves oder fruehere
 * fehlerhafte Snapshot-Laeufe veraendert, entfernt oder dupliziert
 * worden sein koennen. Die Kommentar-/Script-basierte Suche bleibt nur
 * noch als letzter Fallback erhalten, falls die Tiefenzaehlung aus
 * irgendeinem Grund kein ausgeglichenes Ergebnis liefert.
 *
 * TEMP DEBUG: Der spezielle Body <p>__HS_DEBUG_ONLY__</p> prueft nur die
 * URL-/Post-Aufloesung und schreibt NICHT in post_content. Nach Abschluss
 * der Diagnose kann dieser Block entfernt werden.
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

/** Ermittelt die erwartete Sprache aus dem URL-Pfad. */
function hs_prerender_lang_from_url( $url ) {
    $parsed = wp_parse_url( $url );
    $path = isset( $parsed['path'] ) ? trim( $parsed['path'], '/' ) : '';
    $segments = $path === '' ? [] : explode( '/', $path );

    if ( ! empty( $segments ) && strtolower( $segments[0] ) === 'de' ) {
        return 'de';
    }

    return 'en';
}

/** Prueft die WPML-Sprache eines Posts, sofern WPML verfuegbar ist. */
function hs_prerender_post_lang_matches( $post_id, $target_lang ) {
    if ( ! has_filter( 'wpml_element_language_code' ) ) {
        return true;
    }

    $lang = apply_filters( 'wpml_element_language_code', null, [
        'element_id'   => $post_id,
        'element_type' => 'post_page',
    ] );

    // Fehlt eine WPML-Zuordnung, nicht vorschnell verwerfen. Der normale
    // Post-/Status-Check bleibt trotzdem zwingend.
    if ( ! $lang ) {
        return true;
    }

    return strtolower( $lang ) === strtolower( $target_lang );
}

/** Liefert nur veroeffentlichte WordPress-Seiten, nie Attachments/Revisions. */
function hs_prerender_valid_page( $post_id ) {
    $post = get_post( $post_id );

    if ( $post && $post->post_type === 'page' && $post->post_status === 'publish' ) {
        return $post;
    }

    return null;
}

/**
 * Loest eine vollstaendige URL robust auf eine veroeffentlichte Seite auf.
 * 1. url_to_postid() als Kandidat.
 * 2. Treffer nur bei page + publish + passender WPML-Sprache akzeptieren.
 * 3. Fallback auf Slug-Suche, Sprache und bei Mehrdeutigkeit Parent-Slug.
 * 4. Letzte Instanz: hoechste ID (neueste Seite).
 */
function hs_prerender_resolve_post_id( $url ) {
    $target_lang = hs_prerender_lang_from_url( $url );
    $parsed = wp_parse_url( $url );

    // Schritt 1: WordPress-Standardresolver, aber nur als gepruefter Kandidat.
    $url_candidates = [ $url ];
    if ( $parsed && isset( $parsed['path'] ) ) {
        $path = $parsed['path'];
        $url_candidates[] = home_url( $path );
        $url_candidates[] = home_url( untrailingslashit( $path ) );
        $url_candidates[] = home_url( trailingslashit( $path ) );
    }

    foreach ( array_unique( $url_candidates ) as $candidate ) {
        $id = url_to_postid( $candidate );
        if ( ! $id ) {
            continue;
        }

        $post = hs_prerender_valid_page( $id );
        if ( ! $post ) {
            continue;
        }

        if ( hs_prerender_post_lang_matches( $id, $target_lang ) ) {
            return $id;
        }
    }

    // Schritt 2: Robuster Fallback ueber Slug und bei Bedarf Parent-Slug.
    if ( ! $parsed || ! isset( $parsed['path'] ) ) {
        return 0;
    }

    $segments = array_values( array_filter( explode( '/', trim( $parsed['path'], '/' ) ) ) );
    if ( empty( $segments ) ) {
        return 0;
    }

    $slug = sanitize_title( end( $segments ) );
    if ( $slug === '' ) {
        return 0;
    }

    $parent_slug = null;
    if ( count( $segments ) >= 2 ) {
        $maybe_parent = sanitize_title( $segments[ count( $segments ) - 2 ] );
        if ( $maybe_parent !== '' && $maybe_parent !== 'de' ) {
            $parent_slug = $maybe_parent;
        }
    }

    $matches = get_posts( [
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'name'           => $slug,
        'posts_per_page' => -1,
        'no_found_rows'  => true,
    ] );

    if ( empty( $matches ) ) {
        return 0;
    }

    // Zuerst die passende WPML-Sprache bevorzugen.
    $lang_filtered = array_values( array_filter( $matches, function( $match ) use ( $target_lang ) {
        return hs_prerender_post_lang_matches( $match->ID, $target_lang );
    } ) );
    $final_candidates = ! empty( $lang_filtered ) ? $lang_filtered : $matches;

    // Wenn mehrere Seiten denselben Slug tragen, den Parent-Slug abgleichen.
    if ( count( $final_candidates ) > 1 && $parent_slug ) {
        $parent_filtered = array_values( array_filter( $final_candidates, function( $match ) use ( $parent_slug ) {
            $parent_id = wp_get_post_parent_id( $match->ID );
            return $parent_id && get_post_field( 'post_name', $parent_id ) === $parent_slug;
        } ) );

        if ( ! empty( $parent_filtered ) ) {
            $final_candidates = $parent_filtered;
        }
    }

    // Falls danach noch mehrere Kandidaten uebrig sind, die neueste Seite nehmen.
    usort( $final_candidates, function( $a, $b ) {
        return $b->ID <=> $a->ID;
    } );

    return $final_candidates[0]->ID;
}

/**
 * Findet die Position DIREKT NACH dem echten, strukturell passenden
 * schliessenden </div> zum #hs-root-Element -- durch Zaehlen der
 * verschachtelten <div>-Tiefe ab der oeffnenden Stelle.
 *
 * Robuster als jede Suche nach <!-- /wp:html --> oder <script>-Tags,
 * weil diese durch WPML-Uebersetzungs-Sync, Editor-Saves oder fruehere
 * fehlerhafte Snapshot-Laeufe veraendert, entfernt oder dupliziert
 * worden sein koennen. Die reine Div-Tiefenzaehlung ist unabhaengig
 * davon, WAS im Content spaeter folgt -- auch wenn dort noch
 * wohlgeformte HTML-Fragmente als Altlast angehaengt sind.
 *
 * @param string $content       Der komplette post_content.
 * @param int    $open_tag_end  Position direkt NACH dem oeffnenden
 *                               <div id="hs-root" ...> Tag.
 * @return int|false Position direkt nach dem passenden </div>, oder
 *                    false, wenn keine ausgeglichene Verschachtelung
 *                    gefunden werden konnte.
 */
function hs_prerender_find_hs_root_close( $content, $open_tag_end ) {
    $depth  = 1; // Das oeffnende hs-root-Tag selbst zaehlt als Tiefe 1.
    $offset = $open_tag_end;
    $len    = strlen( $content );

    while ( $offset < $len && $depth > 0 ) {
        if ( ! preg_match(
            '/<div\b[^>]*>|<\/div\s*>/i',
            $content,
            $tag_match,
            PREG_OFFSET_CAPTURE,
            $offset
        ) ) {
            return false; // Kein weiteres div-Tag gefunden -- unausgeglichen.
        }

        $tag    = $tag_match[0][0];
        $offset = $tag_match[0][1] + strlen( $tag );

        if ( stripos( $tag, '</div' ) === 0 ) {
            $depth--;
        } else {
            $depth++;
        }
    }

    return $depth === 0 ? $offset : false;
}

/**
 * Fallback-Ermittlung des Ersetzungs-Ankers ueber den letzten
 * <!-- /wp:html --> Kommentar bzw. notfalls einen <script>-Tag direkt
 * nach #hs-root. Wird nur genutzt, wenn die primaere Div-Tiefenzaehlung
 * (hs_prerender_find_hs_root_close) kein ausgeglichenes Ergebnis liefert.
 *
 * @return int|false
 */
function hs_prerender_find_fallback_anchor( $content, $open_tag_end ) {
    // Letztes Vorkommen von "/wp:html" im GESAMTEN content suchen, nicht
    // nur das erste ab hs-root -- verhindert Treffer mitten in bereits
    // dupliziertem Altbestand.
    if ( preg_match_all( '/<!--\s*\/wp:html\s*-->/i', $content, $all_matches, PREG_OFFSET_CAPTURE ) ) {
        $all_offsets = $all_matches[0];
        $last = end( $all_offsets );
        if ( $last[1] > $open_tag_end ) {
            return $last[1] + strlen( $last[0] );
        }
    }

    // Letzte Instanz: kompletten <script>-Block direkt nach hs-root suchen.
    $rest_of_content = substr( $content, $open_tag_end );
    if ( preg_match( '/<script\b[^>]*>.*?<\/script\s*>/is', $rest_of_content, $script_match, PREG_OFFSET_CAPTURE ) ) {
        return $open_tag_end + $script_match[0][1] + strlen( $script_match[0][0] );
    }

    return false;
}

function hs_prerender_write_snapshot( WP_REST_Request $req ) {
    $url  = esc_url_raw( $req->get_param( 'url' ) );
    $html = (string) $req->get_param( 'html' );

    if ( trim( $html ) === '' ) {
        return new WP_Error( 'empty_html', 'Leerer HTML-Inhalt uebergeben.', [ 'status' => 400 ] );
    }

    $post_id = hs_prerender_resolve_post_id( $url );
    if ( ! $post_id ) {
        $debug_parsed = wp_parse_url( $url );
        $debug_path = isset( $debug_parsed['path'] ) ? $debug_parsed['path'] : '(kein Pfad)';

        error_log(
            'HS SNAPSHOT DEBUG'
            . ' | request_url=' . $url
            . ' | post_id=0'
            . ' | resolved_path=' . $debug_path
            . ' | result=not_found'
        );

        return new WP_Error(
            'not_found',
            'Keine WP-Seite fuer URL "' . $url . '" gefunden.',
            [ 'status' => 404 ]
        );
    }

    $post = get_post( $post_id );
    if ( ! $post ) {
        return new WP_Error(
            'not_found',
            'Post-ID ' . $post_id . ' existiert nicht.',
            [ 'status' => 404 ]
        );
    }

    // TEMP DEBUG-ONLY: prueft Resolver und Metadaten, schreibt nichts.
    if ( $html === '<p>__HS_DEBUG_ONLY__</p>' ) {
        error_log(
            'HS SNAPSHOT DEBUG-ONLY'
            . ' | request_url=' . $url
            . ' | post_id=' . $post_id
            . ' | title=' . $post->post_title
            . ' | slug=' . $post->post_name
            . ' | status=' . $post->post_status
        );

        return [
            'ok'         => true,
            'debug_only' => true,
            'post_id'    => $post_id,
            'title'      => $post->post_title,
            'slug'       => $post->post_name,
            'status'     => $post->post_status,
        ];
    }

    $content = $post->post_content;

    // TEMP DEBUG: nur zur Diagnose; nach Abschluss der Tests entfernen.
    $debug_lang = has_filter( 'wpml_element_language_code' )
        ? apply_filters( 'wpml_element_language_code', null, [
            'element_id'   => $post_id,
            'element_type' => 'post_page',
        ] )
        : '(WPML nicht verfuegbar)';

    $debug_has_root = stripos( $content, 'id="hs-root"' ) !== false
        || stripos( $content, "id='hs-root'" ) !== false;
    $debug_has_script = preg_match( '/<script\b[^>]*>.*?<\/script\s*>/is', $content );
    $debug_hs_root_count = preg_match_all( '/id=["\']hs-root["\']/i', $content );

    error_log(
        'HS SNAPSHOT DEBUG'
        . ' | request_url=' . $url
        . ' | post_id=' . $post_id
        . ' | title=' . $post->post_title
        . ' | slug=' . $post->post_name
        . ' | status=' . $post->post_status
        . ' | wpml_lang=' . ( $debug_lang ?: '(leer)' )
        . ' | has_hs_root=' . ( $debug_has_root ? 'yes' : 'no' )
        . ' | hs_root_count=' . $debug_hs_root_count
        . ' | has_complete_script=' . ( $debug_has_script ? 'yes' : 'no' )
    );

    // Oeffnendes hs-root-Tag finden (Attribute wie data-type/data-bundle erhalten).
    if ( ! preg_match( '/<div\s+id=["\']hs-root["\']([^>]*)>/i', $content, $open_match, PREG_OFFSET_CAPTURE ) ) {
        return new WP_Error( 'no_hs_root', 'Kein <div id="hs-root"> in dieser Seite gefunden -- Provisioner-Shell fehlt.', [ 'status' => 422 ] );
    }

    $open_tag_full  = $open_match[0][0];
    $open_tag_start = $open_match[0][1];
    $open_tag_end   = $open_tag_start + strlen( $open_tag_full );

    // v1.3 FIX: primaer robuste Div-Tiefenzaehlung nutzen, um das
    // tatsaechliche Ende von #hs-root zu finden -- unabhaengig von
    // wp:html-Kommentaren oder <script>-Tags. Nur wenn das aus
    // irgendeinem Grund kein ausgeglichenes Ergebnis liefert, auf die
    // Kommentar-/Script-basierte Fallback-Suche zurueckfallen.
    $anchor_start_absolute = hs_prerender_find_hs_root_close( $content, $open_tag_end );

    if ( $anchor_start_absolute === false ) {
        $anchor_start_absolute = hs_prerender_find_fallback_anchor( $content, $open_tag_end );
    }

    if ( $anchor_start_absolute === false ) {
        return new WP_Error(
            'no_safety_anchor',
            'Konnte das Ende von #hs-root weder ueber Div-Tiefenzaehlung noch ueber wp:html-/<script>-Fallback ermitteln -- Ersetzung abgebrochen (Sicherheitsnetz).',
            [ 'status' => 422 ]
        );
    }

    // Nur den bisherigen #hs-root-Inhalt ersetzen. Alles ab dem
    // ermittelten Anker (Script-Block, Folgeinhalte etc.) bleibt erhalten.
    $before = substr( $content, 0, $open_tag_end );
    $after  = substr( $content, $anchor_start_absolute );
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
