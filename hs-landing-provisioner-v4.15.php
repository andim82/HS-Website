<?php
/**
 * Plugin Name: HS Landing Provisioner
 * Description: Legt automatisch Cluster- und Detailseiten fuer HEIM:SPIEL Landing Pages an.
 * Version: 4.15
 * Author: HEIM:SPIEL
 * Changelog:
 *   v4.15: FIX (wichtigster Fix): Dropdown-Wert, getApiSlug()-Lookup, Cluster-
 *          Metadaten-Lookup und data-bundle-Attribut nutzen jetzt durchgehend
 *          discipline_key statt dem rohen "bundle"-Spaltenwert. URSACHE: Bei
 *          Bundle-Cluster-Zeilen (z.B. "US Sports") enthaelt die Spalte
 *          "bundle" eine kommagetrennte Liste ("Basketball,American_Football,
 *          Eishockey,Fußball") statt eines echten Slugs -- dieser rohe Wert
 *          wurde bisher 1:1 in das data-bundle-Attribut der neu angelegten
 *          Seite geschrieben, wodurch hs-landing.js permanent eine falsche,
 *          nicht existierende Coverage-URL abfragte. Die Zuordnung von
 *          Detail-Unterseiten (Schritt 2, ueber die "bundle"-Spalte der
 *          Kind-Zeilen) bleibt unveraendert ueber den ROHEN "bundle"-Wert der
 *          gewaehlten Cluster-Zeile (currentBundleColumn) -- NICHT ueber
 *          discipline_key -- da Kind-Disziplinen (z.B. bei Wintersport) ihre
 *          eigene "bundle"-Spalte weiterhin gegen den "bundle"-Wert der
 *          Eltern-Zeile matchen, nicht gegen deren discipline_key.
 *   v4.14: Fix hs_find_parent(): Direkter DB-Query statt wpml_switch_language.
 *          Findet DE-Seiten zuverlaessig; wpml_element_language_code zur Verifikation.
 *   v4.13: Fix checkExistingPages(): DE-Tag wurde nicht angezeigt.
 *          deJ.id !== enJ.id Filter entfernt (WPML liefert korrekte separate IDs).
 *          DE-Slug wird aus IndexDE detailUrl abgeleitet wenn verfuegbar.
 *   v4.12: Existing-Page-Check mit EN/DE-Tags (klickbar) auf Disc-Items.
 *          Loeschen einzelner Seiten per Trash-Icon; "Alle loeschen"-Button.
 *   v4.11: DE-Cluster-Slug aus IndexDe detailUrl abgeleitet.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Registriert _hs_no_auto_translate als REST-fähiges Post-Meta fuer
 * Seiten. Dadurch kann der Provisioner dieses Flag direkt im selben
 * POST-Request an wp/v2/pages mitschicken (kein separater PHP-Hook
 * "nach dem Insert" noetig, da die Provisioner-Seitenerstellung
 * ausschliesslich ueber den WordPress-Core-REST-Endpunkt laeuft, nicht
 * ueber einen eigenen wp_insert_post()-Aufruf in diesem Plugin).
 *
 * Zweck: Markiert Provisioner-generierte Landingpages, damit WPMLs
 * "Translate Everything Automatically" sie ausschliesst (siehe Filter
 * wpml_exclude_post_from_auto_translate in hs-wpml-exclusions.php).
 */
add_action( 'init', function () {
    register_post_meta( 'page', '_hs_no_auto_translate', [
        'show_in_rest'      => true,
        'single'            => true,
        'type'              => 'boolean',
        'default'           => false,
        'auth_callback'     => function () {
            return current_user_can( 'manage_options' );
        },
    ] );
} );

add_action( 'admin_menu', function() {
    add_menu_page(
        'HS Provisioner', 'HS Provisioner', 'manage_options',
        'hs-provisioner', 'hs_provisioner_page',
        'dashicons-networking', 80
    );
});

add_action( 'rest_api_init', function() {

    register_rest_route( 'hs-prov/v1', '/link-translation', [
        'methods'             => 'POST',
        'callback'            => 'hs_link_wpml_translation',
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'args' => [
            'en_id' => [ 'required' => true, 'type' => 'integer' ],
            'de_id' => [ 'required' => true, 'type' => 'integer' ],
        ],
    ]);

    register_rest_route( 'hs-prov/v1', '/bulk-publish', [
        'methods'             => 'POST',
        'callback'            => 'hs_bulk_publish',
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'args' => [
            'ids' => [ 'required' => true, 'type' => 'array' ],
        ],
    ]);

    register_rest_route( 'hs-prov/v1', '/find-parent', [
        'methods'             => 'GET',
        'callback'            => 'hs_find_parent',
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'args' => [
            'slug' => [ 'required' => true,  'type' => 'string' ],
            'lang' => [ 'required' => false, 'type' => 'string', 'default' => 'en' ],
        ],
    ]);

    register_rest_route( 'hs-prov/v1', '/rename-slug', [
        'methods'             => 'POST',
        'callback'            => 'hs_rename_slug',
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'args' => [
            'id'   => [ 'required' => true, 'type' => 'integer' ],
            'slug' => [ 'required' => true, 'type' => 'string'  ],
        ],
    ]);
});

/**
 * Setzt WPMLs "Translation Priority" automatisch auf "Nicht benoetigt"
 * fuer JEDE Seite, die ueber den HS Provisioner angelegt wird.
 *
 * Da der Provisioner ueber den STANDARD wp/v2/pages-Endpunkt erstellt
 * (kein eigener hs-prov-Create-Endpunkt existiert), gibt es keinen
 * direkten Zugriff auf "das war der Provisioner". Als zuverlaessiges
 * Erkennungsmerkmal dient daher der Provisioner-Shell-Marker
 * <div id="hs-root" ...>, den buildContent()/buildClusterContent() im
 * JS-Teil in JEDE neu angelegte Seite schreibt.
 *
 * Slug "nicht-benoetigt" wurde am 24.08.2026 manuell in
 * WPML -> Sprachen -> Translation Priorities geprueft (deutsche
 * Sprachversion der Taxonomie translation_priority).
 */
add_action( 'rest_after_insert_page', function ( $post, $request ) {
    if ( stripos( (string) $post->post_content, 'id="hs-root"' ) === false ) {
        return; // Keine Provisioner-Seite -- WPML-Standardverhalten unangetastet lassen.
    }

    if ( ! taxonomy_exists( 'translation_priority' ) ) {
        return; // WPML (noch) nicht aktiv/verfuegbar.
    }

    $term = get_term_by( 'slug', 'nicht-benoetigt', 'translation_priority' );
    if ( $term ) {
        wp_set_object_terms( $post->ID, [ $term->term_id ], 'translation_priority' );
    }
}, 10, 2 );

function hs_link_wpml_translation( WP_REST_Request $req ) {
    $en_id = intval( $req['en_id'] );
    $de_id = intval( $req['de_id'] );
    if ( ! has_action( 'wpml_set_element_language_details' ) ) {
        return new WP_Error( 'no_wpml', 'WPML nicht aktiv.', [ 'status' => 400 ] );
    }
    do_action( 'wpml_set_element_language_details', [
        'element_id'           => $en_id,
        'element_type'         => 'post_page',
        'trid'                 => false,
        'language_code'        => 'en',
        'source_language_code' => null,
    ]);
    $trid = apply_filters( 'wpml_element_trid', null, $en_id, 'post_page' );
    if ( ! $trid ) {
        return new WP_Error( 'no_trid', 'trid konnte nicht ermittelt werden.', [ 'status' => 500 ] );
    }
    do_action( 'wpml_set_element_language_details', [
        'element_id'           => $de_id,
        'element_type'         => 'post_page',
        'trid'                 => $trid,
        'language_code'        => 'de',
        'source_language_code' => 'en',
    ]);
    return [ 'ok' => true, 'trid' => $trid, 'en_id' => $en_id, 'de_id' => $de_id ];
}

function hs_bulk_publish( WP_REST_Request $req ) {
    $ids    = array_map( 'intval', (array) $req['ids'] );
    $done   = [];
    $failed = [];
    foreach ( $ids as $id ) {
        $result = wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ], true );
        is_wp_error( $result ) ? $failed[] = $id : $done[] = $id;
    }
    return [ 'published' => $done, 'failed' => $failed ];
}

function hs_find_parent( WP_REST_Request $req ) {
    $slug = sanitize_title( $req['slug'] );
    $lang = sanitize_text_field( $req['lang'] );

    // v4.14: Query pages by slug directly via DB, language-agnostic.
    // Finds the page regardless of language, then returns the correct translation ID.
    global $wpdb;

    // Search by post_name (slug) across all statuses and languages
    $results = $wpdb->get_results( $wpdb->prepare(
        "SELECT ID, post_title, post_name, post_status FROM {$wpdb->posts}
         WHERE post_name = %s
           AND post_type = 'page'
           AND post_status IN ('publish','draft')
         LIMIT 10",
        $slug
    ) );

    if ( empty( $results ) ) {
        return [ 'found' => false, 'id' => 0 ];
    }

    // If WPML is active: for each result, find the version in the requested language
    foreach ( $results as $candidate ) {
        $target_id = $candidate->ID;

        // Try to get the translation in $lang
        if ( function_exists( 'wpml_object_id' ) ) {
            $translated = apply_filters( 'wpml_object_id', $candidate->ID, 'page', false, $lang );
            if ( $translated ) $target_id = $translated;
        } elseif ( function_exists( 'icl_object_id' ) ) {
            $translated = icl_object_id( $candidate->ID, 'page', false, $lang );
            if ( $translated ) $target_id = $translated;
        }

        // Check if the resolved page actually has the right language
        $page = get_post( $target_id );
        if ( ! $page ) continue;

        // Verify language if WPML active
        if ( function_exists( 'wpml_object_id' ) ) {
            $page_lang = apply_filters( 'wpml_element_language_code', null, [ 'element_id' => $target_id, 'element_type' => 'post_page' ] );
            if ( $page_lang && $page_lang !== $lang ) {
                // Translation not found in target language — skip
                continue;
            }
        }

        return [
            'found' => true,
            'id'    => $page->ID,
            'title' => $page->post_title,
            'slug'  => $page->post_name,
        ];
    }

    // Fallback: return first result without language check
    $fallback = get_post( $results[0]->ID );
    if ( $fallback ) {
        return [ 'found' => true, 'id' => $fallback->ID, 'title' => $fallback->post_title, 'slug' => $fallback->post_name ];
    }
    return [ 'found' => false, 'id' => 0 ];
}

function hs_rename_slug( WP_REST_Request $req ) {
    $id       = intval( $req['id'] );
    $new_slug = sanitize_title( $req['slug'] );
    if ( ! $id || ! $new_slug ) {
        return new WP_Error( 'bad_params', 'id und slug erforderlich.', [ 'status' => 400 ] );
    }
    $post = get_post( $id );
    if ( ! $post ) {
        return new WP_Error( 'not_found', 'Seite ID ' . $id . ' nicht gefunden.', [ 'status' => 404 ] );
    }
    $result = wp_update_post([ 'ID' => $id, 'post_name' => $new_slug ], true);
    if ( is_wp_error( $result ) ) {
        return new WP_Error( 'update_failed', $result->get_error_message(), [ 'status' => 500 ] );
    }
    return [ 'ok' => true, 'id' => $id, 'slug' => $new_slug, 'old_slug' => $post->post_name, 'title' => $post->post_title ];
}

function hs_provisioner_page() {
    $nonce = wp_create_nonce( 'wp_rest' );
    $rest_base = trailingslashit( get_rest_url( null, '/' ) );
    $wpml_langs = function_exists( 'icl_get_languages' )
        ? array_keys( icl_get_languages( 'skip_missing=0' ) )
        : [ 'de', 'en', 'fr', 'es', 'it' ];
    foreach ( $wpml_langs as $_lang ) {
        $rest_base = preg_replace( '#/' . preg_quote( $_lang, '#' ) . '/wp-json/#', '/wp-json/', $rest_base );
    }
    $api      = $rest_base . 'wp/v2/pages';
    $cache    = $rest_base . 'hs-cache/v1/index';
    $cache_de = $rest_base . 'hs-cache/v1/indexDe';
    $prov     = rtrim( $rest_base . 'hs-prov/v1', '/' );
?>
<div class="wrap">
<style>
*{box-sizing:border-box;}
#hsp{max-width:960px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;}
#hsp .hsp-header{background:#061d3e;color:#fff;padding:18px 26px;border-radius:6px;display:flex;align-items:center;gap:12px;margin-bottom:22px;}
#hsp .hsp-header h1{font-size:1.1rem;font-weight:700;color:#fff;margin:0;padding:0;border:none;}
#hsp .hsp-badge{background:#e75519;color:#fff;font-size:.65rem;font-weight:900;padding:3px 9px;border-radius:3px;letter-spacing:.08em;}
#hsp .hsp-card{background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:22px 26px;margin-bottom:18px;}
#hsp .hsp-card h2{font-size:.95rem;font-weight:700;color:#061d3e;margin:0 0 14px;padding-bottom:8px;border-bottom:2px solid #e75519;display:flex;align-items:center;gap:10px;}
#hsp .hsp-step{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;background:#e75519;color:#fff;font-size:.72rem;font-weight:900;border-radius:50%;flex-shrink:0;}
#hsp .hsp-card.locked{opacity:.5;pointer-events:none;position:relative;}
#hsp .hsp-card.locked::after{content:'Schritt 1 zuerst abschliessen';position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:.85rem;font-weight:700;color:#6b7280;background:rgba(255,255,255,.6);border-radius:6px;}
#hsp .hsp-info{background:#f0f6ff;border-left:3px solid #3b82f6;padding:9px 13px;border-radius:4px;font-size:.8rem;line-height:1.6;margin-bottom:14px;}
#hsp .hsp-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
#hsp label{display:block;font-size:.78rem;font-weight:700;color:#374151;margin-bottom:4px;margin-top:10px;}
#hsp input,#hsp select{width:100%;padding:7px 11px;border:1.5px solid #d1d5db;border-radius:4px;font-size:.86rem;}
#hsp input:focus,#hsp select:focus{border-color:#e75519;outline:none;}
#hsp .hsp-hint{font-size:.72rem;color:#6b7280;margin-top:4px;line-height:1.5;background:#f8f9fb;border-left:2px solid #cbd5e1;padding:5px 9px;border-radius:3px;}
#hsp .hsp-parent-status{font-size:.78rem;margin-top:6px;min-height:20px;line-height:1.6;}
#hsp .ok{color:#16a34a;font-weight:700;}
#hsp .warn{color:#d97706;}
#hsp .err{color:#dc2626;}
#hsp .hsp-toggle-row{display:flex;align-items:flex-start;gap:10px;margin-top:14px;padding:12px;background:#f8f9fb;border-radius:5px;border:1.5px solid #e2e6ec;}
#hsp .hsp-toggle-row input[type=checkbox]{width:auto;margin:3px 0 0;flex-shrink:0;}
#hsp .hsp-tlabel strong{display:block;font-size:.84rem;font-weight:700;color:#374151;margin-bottom:2px;}
#hsp .hsp-tlabel span{font-size:.75rem;color:#6b7280;line-height:1.5;}
#hsp .hsp-lang-opts{display:none;margin-top:10px;padding:12px;background:#f0f6ff;border-radius:5px;border:1.5px solid #bfdbfe;}
#hsp .hsp-lang-opts.visible{display:block;}
#hsp .hsp-btn{background:#e75519;color:#fff;font-weight:700;font-size:.88rem;padding:9px 22px;border:none;border-radius:4px;cursor:pointer;margin-top:12px;}
#hsp .hsp-btn:hover{background:#b84010;}
#hsp .hsp-btn:disabled{background:#9ca3af;cursor:not-allowed;}
#hsp .hsp-btn-sec{background:#fff;color:#374151;border:1.5px solid #d1d5db;font-weight:700;font-size:.83rem;padding:7px 16px;border-radius:4px;cursor:pointer;margin-left:6px;margin-top:12px;}
#hsp .hsp-btn-sec:hover{background:#f3f4f6;}
#hsp .hsp-btn-green{background:#16a34a;color:#fff;font-weight:700;font-size:.83rem;padding:7px 16px;border:none;border-radius:4px;cursor:pointer;margin-left:6px;margin-top:12px;display:none;}
#hsp .hsp-btn-green:hover{background:#15803d;}
#hsp .hsp-btn-green.on{display:inline-block;}
#hsp .hsp-cluster-result{display:none;margin-top:12px;padding:12px 16px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:5px;font-size:.82rem;line-height:1.8;}
#hsp .hsp-cluster-result.on{display:block;}
#hsp .disc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:7px;margin-top:10px;}
#hsp .disc-item{display:flex;align-items:center;gap:7px;background:#f8f9fb;border:1.5px solid #e2e6ec;border-radius:4px;padding:7px 10px;font-size:.78rem;font-weight:600;color:#374151;transition:opacity .15s;}
.disc-item:has(.disc-cb:not(:checked)){opacity:.45;}
.disc-cb{flex:0 0 15px;width:15px;height:15px;accent-color:#e75519;cursor:pointer;}
.disc-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.disc-dot{flex:0 0 8px;}
.disc-status{flex:0 0 auto;margin-left:auto;font-size:.7rem;font-weight:400;color:#6b7280;}
#hsp .disc-item.done{border-color:#86efac;background:#f0fdf4;}
#hsp .disc-item.error{border-color:#fca5a5;background:#fff5f5;}
#hsp .disc-item.running{border-color:#fdba74;background:#fff7ed;}
#hsp .disc-item.skip{border-color:#fde68a;background:#fffbeb;}
#hsp .disc-dot{width:8px;height:8px;border-radius:50%;background:#9ca3af;flex-shrink:0;}
#hsp .disc-item.done .disc-dot{background:#16a34a;}
#hsp .disc-item.error .disc-dot{background:#dc2626;}
#hsp .disc-item.running .disc-dot{background:#e75519;animation:hsPulse .8s infinite;}
#hsp .disc-item.skip .disc-dot{background:#f59e0b;}
@keyframes hsPulse{0%,100%{opacity:1}50%{opacity:.25}}
#hsp .disc-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
#hsp .disc-status{font-size:.68rem;color:#9ca3af;white-space:nowrap;}
#hsp .disc-item.done .disc-status{color:#16a34a;}
#hsp .disc-item.error .disc-status{color:#dc2626;}
#hsp .disc-item.skip .disc-status{color:#d97706;}
#hsp .disc-tags{display:flex;gap:3px;align-items:center;flex-shrink:0;}
#hsp .disc-tag{display:inline-flex;align-items:center;font-size:.63rem;font-weight:700;padding:2px 5px;border-radius:3px;text-decoration:none;color:#fff;white-space:nowrap;line-height:1.4;}
#hsp .disc-tag.en{background:#2563eb;}
#hsp .disc-tag.de{background:#16a34a;}
#hsp .disc-tag:hover{opacity:.82;}
#hsp .disc-del{background:none;border:none;cursor:pointer;color:#9ca3af;font-size:.8rem;padding:1px 4px;line-height:1;border-radius:3px;flex-shrink:0;margin-left:2px;}
#hsp .disc-del:hover{color:#dc2626;background:#fee2e2;}
#hsp .hsp-btn-red{background:#dc2626;color:#fff;border:none;padding:7px 14px;border-radius:4px;font-size:.8rem;font-weight:700;cursor:pointer;transition:background .15s;}
#hsp .hsp-btn-red:hover{background:#b91c1c;}
#hsp .hsp-btn-red:disabled{background:#fca5a5;cursor:not-allowed;}
#hsp .disc-single:hover{background:#b84010;}
#hsp .disc-item.done .disc-single,#hsp .disc-item.running .disc-single{display:none;}
#hsp .hsp-log{background:#0f172a;color:#94a3b8;font-family:monospace;font-size:.74rem;padding:12px;border-radius:5px;max-height:260px;overflow-y:auto;margin-top:12px;line-height:1.7;}
#hsp .log-ok{color:#4ade80;}
#hsp .log-err{color:#f87171;}
#hsp .log-inf{color:#60a5fa;}
#hsp .log-warn{color:#fbbf24;}
#hsp .hsp-ibtn{background:#e75519;color:#fff;font-size:.73rem;font-weight:700;padding:2px 9px;border:none;border-radius:3px;cursor:pointer;margin-left:5px;vertical-align:middle;}
#hsp .hsp-ibtn:hover{background:#b84010;}
#hsp .hsp-ibtn.sec{background:#fff;color:#374151;border:1.5px solid #d1d5db;}
#hsp .hsp-ibtn.sec:hover{background:#f3f4f6;}
#hsp .hsp-ibtn:disabled{background:#9ca3af;cursor:not-allowed;}
#hsp code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:.76rem;}
</style>

<div id="hsp">
  <div class="hsp-header">
    <h1>HS Landing Provisioner</h1>
    <span class="hsp-badge">v4.15</span>
  </div>

  <!-- ═══ GLOBAL SETTINGS ════════════════════════════════════════════ -->
  <div class="hsp-card" id="cardSettings">
    <h2>Grundeinstellungen</h2>
    <div class="hsp-info" id="hspCacheInfo">Cache-Endpoint wird ermittelt...</div>
    <div class="hsp-grid">
      <div>
        <label>Cluster / Bundle</label>
        <select id="hspCluster"><option value="">-- Cluster waehlen --</option></select>
        <p class="hsp-hint">Alle Bundles aus dem Cache-Index. Auswahl gilt fuer Schritt 1 und 2.</p>
      </div>
      <div>
        <label>Seiten-Status beim Anlegen</label>
        <select id="hspStatus">
          <option value="draft">Entwurf (empfohlen)</option>
          <option value="publish">Direkt veroeffentlichen</option>
        </select>
        <p class="hsp-hint">Entwurf = nicht sichtbar, erst pruefen. Direkt = sofort live.</p>
      </div>
    </div>
    <div class="hsp-toggle-row">
      <input type="checkbox" id="hspBilingual">
      <div class="hsp-tlabel">
        <strong>Zweisprachig anlegen (EN + DE via WPML)</strong>
        <span>Jede Seite wird als EN- und DE-Version angelegt und in WPML verknuepft. Seitentitel erhaelt automatisch [EN] / [DE] Suffix.</span>
      </div>
    </div>
    <div class="hsp-lang-opts" id="hspLangOpts">
      <label>DE URL-Praefix</label>
      <input type="text" id="hspLangPrefix" value="/de/" style="max-width:130px;">
      <p class="hsp-hint">URL-Pfad fuer deutsche Seiten laut WPML-Konfiguration. Standard: /de/</p>
    </div>
  </div>

  <!-- ═══ SCHRITT 1: CLUSTER ═════════════════════════════════════════ -->
  <div class="hsp-card" id="cardCluster">
    <h2><span class="hsp-step">1</span> Cluster-Seite anlegen</h2>
    <div class="hsp-info">
      Legt die uebergeordnete Bundle-Seite an (z.B. <strong>Wintersport</strong>).
      Diese Seite muss existieren, <em>bevor</em> Detailseiten als Unterseiten zugeordnet werden koennen.
      Nach dem Anlegen wird die Seite automatisch als Parent fuer Schritt&nbsp;2 uebernommen.
    </div>

    <div id="hspClusterParentInfo" style="display:none;">
      <div class="hsp-parent-status" id="hspParentEnStatus"></div>
      <div class="hsp-parent-status" id="hspParentDeStatus" style="display:none;"></div>
    </div>

    <div style="display:flex;align-items:center;flex-wrap:wrap;gap:4px;">
      <button class="hsp-btn" id="hspClusterBtn" disabled>Cluster-Seite anlegen</button>
      <button class="hsp-btn-green on" id="hspClusterSkipBtn" style="display:none;" disabled>
        Cluster existiert bereits &rarr; Schritt 2 freischalten
      </button>
    </div>

    <div class="hsp-log" id="hspLogCluster">Bereit.</div>
    <div class="hsp-cluster-result" id="hspClusterResult"></div>
  </div>

  <!-- ═══ SCHRITT 2: DETAILSEITEN ════════════════════════════════════ -->
  <div class="hsp-card locked" id="cardDetail">
    <h2><span class="hsp-step">2</span> Detailseiten anlegen <span id="hspDiscCount" style="font-weight:400;font-size:.8rem;color:#6b7280;"></span></h2>
    <div class="hsp-info">
      Jede Disziplin wird als eigene WP-Seite unterhalb der Cluster-Seite angelegt.
      Bereits vorhandene Seiten werden uebersprungen.
    </div>
    <div class="disc-grid" id="hspDiscList"><div style="color:#9ca3af;font-size:.82rem;padding:12px;">Zuerst Schritt 1 abschliessen</div></div>
    <div style="display:flex;align-items:center;gap:10px;margin-top:8px;padding:8px 10px;background:#f9fafb;border-radius:6px;border:1px solid #e5e7eb;">
      <input type="checkbox" id="hspSelectAll" disabled style="width:16px;height:16px;accent-color:#e75519;cursor:pointer;">
      <label for="hspSelectAll" style="font-size:.85rem;font-weight:600;color:#374151;cursor:pointer;user-select:none;">Alle ausw&auml;hlen</label>
    </div>
    <div style="display:flex;align-items:center;flex-wrap:wrap;gap:4px;margin-top:8px;">
      <button class="hsp-btn" id="hspAllBtn" disabled>Detailseiten anlegen</button>
      <button class="hsp-btn-red" id="hspDeleteAllBtn" disabled title="Alle Seiten dieses Clusters l&ouml;schen">
        &#128465; Alle l&ouml;schen
      </button>
      <button class="hsp-btn-green" id="hspPublishBtn">Entw&uuml;rfe ver&ouml;ffentlichen</button>
    </div>
    <p class="hsp-hint" style="margin-top:8px;">Ausgew&auml;hlte Disziplinen werden als WP-Seiten angelegt. &bdquo;Entw&uuml;rfe ver&ouml;ffentlichen&ldquo; schaltet alle angelegten Seiten dieser Session live.</p>
    <div class="hsp-log" id="hspLogDetail">Warte auf Schritt 1...</div>
    <div class="hsp-cluster-result" id="hspDetailResult"></div>
  </div>
</div>
<script>
(function(){
  var NONCE = <?php echo json_encode( $nonce ); ?>;
  var API   = <?php echo json_encode( $api ); ?>;
  var CACHE    = <?php echo json_encode( $cache ); ?>;
  var CACHE_DE = <?php echo json_encode( $cache_de ); ?>;
  var PROV     = <?php echo json_encode( $prov ); ?>;

  var indexData    = [];
  var currentDiscs = [];
  var createdIds   = [];
  var parentIdEn   = 0;
  var parentIdDe   = 0;
  var clusterReady = false;
  // NEU (v4.15): rohe "bundle"-Spalte der aktuell gewaehlten Cluster-Zeile --
  // wird fuer die Zuordnung von Detail-Unterseiten (Schritt 2) benoetigt,
  // NICHT fuer den eindeutigen Cluster-Identifier (dafuer: discipline_key).
  var currentBundleColumn = '';

  function log(targetId, msg, cls) {
    var el = document.getElementById(targetId);
    var d  = document.createElement('div');
    if (cls) d.className = 'log-' + cls;
    d.textContent = msg;
    el.appendChild(d);
    el.scrollTop = el.scrollHeight;
  }
  function logC(msg, cls){ log('hspLogCluster', msg, cls); }
  function logD(msg, cls){ log('hspLogDetail',  msg, cls); }

  // ── parseRows: JSON-Array extrahieren + snake_case → camelCase normalisieren ──
  function parseRows(j) {
    var raw;
    if (Array.isArray(j))              raw = j;
    else if (Array.isArray(j.index))   raw = j.index;
    else if (Array.isArray(j.indexDe)) raw = j.indexDe;
    else if (Array.isArray(j.rows))    raw = j.rows;
    else {
      var k = Object.keys(j);
      for (var i=0; i<k.length; i++) if (Array.isArray(j[k[i]])) { raw = j[k[i]]; break; }
    }
    if (!raw) return [];
    // Google Apps Script liefert snake_case – normalisieren auf camelCase
    return raw.map(function(d) {
      // snake_case → camelCase (Google Apps Script Kompatibilitaet)
      if (d.discipline_key !== undefined && d.disciplineKey === undefined) d.disciplineKey = d.discipline_key;
      if (d.detail_url     !== undefined && d.detailUrl     === undefined) d.detailUrl     = d.detail_url;
      if (d.hero_headline  !== undefined && d.heroHeadline  === undefined) d.heroHeadline  = d.hero_headline;
      if (d.hero_bg_url    !== undefined && d.heroBgUrl     === undefined) d.heroBgUrl     = d.hero_bg_url;
      if (d.bundle_name    !== undefined && d.bundleName    === undefined) d.bundleName    = d.bundle_name;
      if (d.display_name   !== undefined && d.displayName   === undefined) d.displayName   = d.display_name;
      if (d.sport_eyebrow  !== undefined && d.sportEyebrow  === undefined) d.sportEyebrow  = d.sport_eyebrow;
      // Generische Fallbacks fuer alternative Key-Namen
      if (!d.disciplineKey && d.key)   d.disciplineKey = d.key;
      if (!d.detailUrl     && d.url)   d.detailUrl     = d.url;
      if (!d.displayName   && d.label) d.displayName   = d.label;
      // Letzter Fallback: disciplineKey aus slug oder name ableiten
      if (!d.disciplineKey && d.slug)  d.disciplineKey = d.slug;
      if (!d.disciplineKey && d.name && (d.type||'').toLowerCase() !== 'cluster')
        d.disciplineKey = d.name.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '');
      return d;
    });
  }

  function sleep(ms){ return new Promise(function(r){ setTimeout(r, ms); }); }

  document.getElementById('hspCacheInfo').innerHTML =
    'REST-API: <code>' + API + '</code> &nbsp;|&nbsp; Cache: <code>' + CACHE + '</code>';
  logC('REST-API Basis: ' + API, 'inf');
  logC('Lade Index: ' + CACHE, 'inf');

  fetch(CACHE, { headers: { Accept: 'application/json' } })
    .then(function(r){ logC('HTTP ' + r.status, r.ok?'ok':'err'); if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
    .then(function(j){
      var rows = parseRows(j);
      logC('Eintraege: ' + rows.length, rows.length?'ok':'warn');
      if (rows.length) logC('Keys im ersten Eintrag: ' + Object.keys(rows[0]).join(', '), 'inf');
      if (!rows.length) return;
      indexData = rows;
      buildDropdown(rows);
    })
    .catch(function(e){ logC('Fehler: ' + e.message, 'err'); });

  // ── buildDropdown (v4.15 FIX) ────────────────────────────────────────────
  // Dropdown-"value" ist jetzt discipline_key (Fallback: bundleName-Slug,
  // falls discipline_key im Index-Sheet mal fehlen sollte) statt dem rohen
  // "bundle"-Spaltenwert. discipline_key ist die eindeutige, technische
  // Kennung, die auch das Backend (hs_build_coverage_for_sport() etc.) und
  // das Frontend (hs-landing.js data-bundle-Attribut) erwarten. Der rohe
  // "bundle"-Spaltenwert (z.B. "Basketball,American_Football,Eishockey,
  // Fußball" bei Bundle-Zeilen wie "US Sports") ist dafuer NICHT geeignet --
  // er wird separat als currentBundleColumn fuer die Zuordnung von Detail-
  // Unterseiten (Schritt 2) weiterverwendet.
  function buildDropdown(rows) {
    var seen = {}, keys = [];
    rows.forEach(function(d) {
      var type = (d.type || '').toLowerCase();
      if (type !== 'cluster') return;
      var key = (d.disciplineKey || d.bundleName || '').toLowerCase().trim();
      if (key && !seen[key]) { seen[key] = true; keys.push(key); }
    });

    var sel = document.getElementById('hspCluster');
    sel.innerHTML = '';
    var def = document.createElement('option');
    def.value = '';
    def.textContent = '-- Cluster waehlen --';
    sel.appendChild(def);

    keys.forEach(function(key) {
      var meta = rows.find(function(r) {
        var type = (r.type || '').toLowerCase();
        var rKey = (r.disciplineKey || r.bundleName || '').toLowerCase().trim();
        return type === 'cluster' && rKey === key;
      });

      var o = document.createElement('option');
      o.value = key; // FIX (v4.15): discipline_key statt rohem "bundle"-Wert

      // Label: "<name> (<discipline_key>)" -- z.B. "US Sports (us-sports)".
      var displayLabel = (meta && (meta.name || meta.displayName || meta.bundleName)) || key;
      o.textContent = displayLabel + ' (' + key + ')';

      sel.appendChild(o);
    });

    sel.addEventListener('change', onClusterSelect);
  }

  // ── getApiSlug (v4.15 FIX): Lookup ueber discipline_key statt "bundle" ──
  function getApiSlug(key) {
    var meta = indexData.find(function(d){
      return (d.disciplineKey||'').toLowerCase()===key && (d.type||'').toLowerCase()==='cluster';
    });
    if (!meta) return key;
    var u = meta.detailUrl || meta.detail_url || '';
    return u ? u.replace(/\/$/, '').split('/').pop() : key;
  }

  function onClusterSelect() {
    var key = document.getElementById('hspCluster').value; // discipline_key
    clusterReady = false;
    parentIdEn = 0; parentIdDe = 0;
    createdIds = [];
    currentBundleColumn = '';
    document.getElementById('cardDetail').classList.add('locked');
    document.getElementById('hspAllBtn').disabled = true;
    document.getElementById('hspDeleteAllBtn').disabled = true;
    document.getElementById('hspDiscList').innerHTML = '<div style="color:#9ca3af;font-size:.82rem;padding:12px;">Zuerst Schritt 1 abschliessen</div>';
    document.getElementById('hspDiscCount').textContent = '';
    document.getElementById('hspClusterResult').classList.remove('on');
    document.getElementById('hspDetailResult').classList.remove('on');
    document.getElementById('hspPublishBtn').classList.remove('on');

    var clBtn      = document.getElementById('hspClusterBtn');
    var skipBtn    = document.getElementById('hspClusterSkipBtn');
    var parentInfo = document.getElementById('hspClusterParentInfo');

    if (!key) {
      clBtn.disabled = true;
      skipBtn.style.display = 'none';
      parentInfo.style.display = 'none';
      return;
    }

    clBtn.disabled = false;
    clBtn.textContent = 'Cluster-Seite anlegen';
    skipBtn.style.display = '';
    skipBtn.disabled = false;
    skipBtn.textContent = 'Cluster existiert bereits \u2192 Schritt 2 freischalten';
    parentInfo.style.display = 'block';

    var bilingual = document.getElementById('hspBilingual').checked;
    var apiSlug   = getApiSlug(key);
    logC('Cluster: ' + key + ' | API-Slug: ' + apiSlug, 'inf');

    var stEn = document.getElementById('hspParentEnStatus');
    var stDe = document.getElementById('hspParentDeStatus');
    resolveParent(key, apiSlug, 'en', stEn, function(id){ parentIdEn = id; }, logC);
    if (bilingual) {
      stDe.style.display = 'block';
      resolveParent(key, apiSlug, 'de', stDe, function(id){ parentIdDe = id; }, logC);
    } else {
      stDe.style.display = 'none';
    }

    // FIX (v4.15): Cluster-Metadaten ueber discipline_key finden, um daraus
    // die ROHE "bundle"-Spalte (currentBundleColumn) zu ermitteln -- diese
    // wird fuer die Zuordnung der Detail-Unterseiten (Schritt 2) benoetigt,
    // NICHT der discipline_key selbst. Fallback auf key, falls meta fehlt.
    var clusterMeta = indexData.find(function(d){
      return (d.disciplineKey||'').toLowerCase()===key && (d.type||'').toLowerCase()==='cluster';
    });
    currentBundleColumn = (clusterMeta && clusterMeta.bundle) ? clusterMeta.bundle : key;

    currentDiscs = indexData.filter(function(d){
      return (d.bundle||'').toLowerCase()===currentBundleColumn.toLowerCase()
          && (d.type||'').toLowerCase()!=='cluster';
      // Hinweis: disciplineKey-Pruefung entfernt (v4.10) –
      // parseRows() stellt per Normalisierung sicher dass der Key gesetzt ist.
    });
    buildDiscList(currentDiscs);
    document.getElementById('hspDiscCount').textContent = '(' + currentDiscs.length + ' Disziplinen)';
    // v4.13: Existierende Seiten prüfen — DE-Slugs aus IndexDE laden
    var _bilingual = document.getElementById('hspBilingual').checked;
    (async function() {
      var _deRowsMap = {};
      if (_bilingual) {
        try {
          var _deRes = await fetch(CACHE_DE, { headers: { Accept: 'application/json' } });
          if (_deRes.ok) {
            var _deJson = await _deRes.json();
            // FIX (v4.15): gegen currentBundleColumn matchen statt gegen den
            // discipline_key-Dropdown-Wert.
            parseRows(_deJson)
              .filter(function(r){ return (r.bundle||'').toLowerCase() === currentBundleColumn.toLowerCase() && (r.type||'').toLowerCase() !== 'cluster'; })
              .forEach(function(r){ if (r.disciplineKey) _deRowsMap[r.disciplineKey] = r; });
          }
        } catch(e) {}
      }
      checkExistingPages(currentDiscs, _bilingual, _deRowsMap).catch(function(){});
    })();
  }

  function lookupParent(slug, lang) {
    return fetch(PROV + '/find-parent?slug=' + encodeURIComponent(slug) + '&lang=' + lang, {
      credentials: 'same-origin', headers: { 'X-WP-Nonce': NONCE }
    }).then(function(r){ return r.json(); }).catch(function(){ return { found:false, id:0 }; });
  }

  function renameSlug(pageId, newSlug) {
    return fetch(PROV + '/rename-slug', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
      body: JSON.stringify({ id: pageId, slug: newSlug })
    }).then(function(r){ return r.json(); }).catch(function(e){ return { ok:false, message:e.message }; });
  }

  async function resolveParent(key, apiSlug, lang, el, setter, logFn) {
    el.innerHTML = '<span>Suche Parent ' + lang.toUpperCase() + '...</span>';
    var res = await lookupParent(key, lang);
    if (res.found) {
      setter(res.id);
      el.innerHTML = '<span class="ok">\u2713 Parent ' + lang.toUpperCase() + ': &ldquo;' + res.title + '&rdquo; (ID&nbsp;' + res.id + ')</span>';
      logFn('Parent ' + lang.toUpperCase() + ': "' + res.title + '" ID ' + res.id, 'ok');
      return;
    }
    if (apiSlug && apiSlug !== key) {
      res = await lookupParent(apiSlug, lang);
    }
    if (res.found) {
      var pid = res.id; var ptitle = res.title;
      setter(pid);
      el.innerHTML = '<span class="ok">\u2713 Parent ' + lang.toUpperCase() + ': &ldquo;' + ptitle + '&rdquo; (ID&nbsp;' + pid + ')</span>';
      logFn('Parent ' + lang.toUpperCase() + ' (Slug: ' + apiSlug + ') -> "' + ptitle + '" ID ' + pid, 'ok');
      return;
    }
    setter(0);
    var tried = key + (apiSlug && apiSlug!==key ? ' / ' + apiSlug : '');
    el.innerHTML =
      '<span class="warn">\u26a0 Kein Parent ' + lang.toUpperCase() + ' (gesucht: <code>' + tried + '</code>) &mdash; nach Cluster-Anlegen automatisch gesetzt<br>'
      + 'Oder manuell: <input type="number" class="hsp-manual-id" placeholder="0" style="width:70px;padding:2px 6px;font-size:.78rem;border:1.5px solid #d1d5db;border-radius:3px;">'
      + ' <button class="hsp-ibtn" data-a="setid">Setzen</button></span>';
    el.querySelector('[data-a="setid"]').addEventListener('click', function(){
      var v = parseInt(el.querySelector('.hsp-manual-id').value,10)||0;
      setter(v);
      el.innerHTML = v
        ? '<span class="ok">\u2713 Parent ' + lang.toUpperCase() + ' manuell: ID&nbsp;' + v + '</span>'
        : '<span class="warn">Top-Level (ID 0)</span>';
      logFn('Parent ' + lang.toUpperCase() + ' manuell: ID ' + v, v?'ok':'warn');
    });
    logFn('Parent ' + lang.toUpperCase() + ' nicht gefunden (' + tried + ') - wird nach Cluster-Anlegen gesetzt', 'warn');
  }

  document.getElementById('hspClusterBtn').addEventListener('click', async function(){
    var key       = document.getElementById('hspCluster').value; // discipline_key
    var status    = document.getElementById('hspStatus').value;
    var bilingual = document.getElementById('hspBilingual').checked;
    if (!key) return;

    var btn     = this;
    var skipBtn = document.getElementById('hspClusterSkipBtn');
    btn.disabled = true; btn.textContent = 'Wird angelegt...';
    skipBtn.disabled = true;
    document.getElementById('hspClusterResult').classList.remove('on');

    // FIX (v4.15): Lookup ueber discipline_key statt "bundle".
    var meta     = indexData.find(function(d){ return (d.disciplineKey||'').toLowerCase()===key && (d.type||'').toLowerCase()==='cluster'; });
    var clNameEn = meta ? (meta.eyebrow || meta.bundleName || meta.displayName || key) : key;
    var clNameDe = clNameEn;
    var deMeta;
    if (bilingual) {
      try {
        var deIndexRes = await fetch(CACHE_DE, { headers: { Accept: 'application/json' } });
        if (deIndexRes.ok) {
          var deIndexJson = await deIndexRes.json();
          var deRows = parseRows(deIndexJson);
          // FIX (v4.15): Lookup ueber discipline_key statt "bundle".
          deMeta = deRows.find(function(d){
            return (d.disciplineKey||'').toLowerCase()===key && (d.type||'').toLowerCase()==='cluster';
          });
          if (deMeta && (deMeta.eyebrow || deMeta.bundleName)) {
            clNameDe = deMeta.eyebrow || deMeta.bundleName;
          }
        }
      } catch(e) { logC('DE Index nicht geladen, verwende EN Titel: ' + e.message, 'warn'); }
    }
    var apiSlug = getApiSlug(key);

    // v4.11: DE-Cluster-Slug aus IndexDe detailUrl ableiten (Fallback: apiSlug)
    var deClusterSlug = apiSlug;
    if (bilingual && typeof deMeta !== 'undefined' && deMeta) {
      var deCUrl = deMeta.detailUrl || deMeta.detail_url || deMeta.detailurl || '';
      if (deCUrl) {
        deClusterSlug = deCUrl.replace(/\/$/, '').split('/').filter(Boolean).pop() || apiSlug;
      }
    }

    logC('-- Lege Cluster-Seite an: "' + clNameEn + '" / "' + clNameDe + '" (EN-Slug: ' + apiSlug + ', DE-Slug: ' + deClusterSlug + ') --', 'inf');

    // FIX (v4.15, entscheidend): buildClusterContent(key) schreibt jetzt
    // data-bundle="<discipline_key>" statt data-bundle="<roher bundle-Wert>"
    // in die neu angelegte Seite -- z.B. data-bundle="us-sports" statt
    // data-bundle="basketball,american_football,eishockey,fußball".
    var enRes = await createPage(bilingual ? clNameEn : clNameDe, apiSlug, buildClusterContent(key), status, 0, bilingual?'en':'');
    if (!enRes.ok) {
      var isConf = enRes.status===409 || (enRes.json.code && enRes.json.code.indexOf('exists')>-1);
      if (isConf) {
        logC('Cluster-Seite existiert bereits (Slug: ' + apiSlug + ')', 'warn');
        await resolveAndSetParent(key, apiSlug, bilingual);
        unlockStep2(key, bilingual);
        btn.textContent = 'Bereits vorhanden';
        return;
      }
      logC('FEHLER: ' + (enRes.json.message||enRes.status), 'err');
      btn.disabled=false; btn.textContent='Erneut versuchen';
      skipBtn.disabled=false;
      return;
    }
    var enId = enRes.json.id;
    logC('Cluster EN angelegt: ID ' + enId + ' | Slug: ' + enRes.json.slug, 'ok');
    parentIdEn = enId;

    var html = '<strong>Cluster-Seite angelegt:</strong><br>'
      + (bilingual?'[EN] ':'') + '"' + (bilingual?clNameEn:clNameDe) + '" &mdash; ID&nbsp;' + enId
      + (enRes.json.link ? ' &mdash; <a href="' + enRes.json.link + '" target="_blank">Vorschau</a>' : '');

    if (bilingual) {
      var prefix = document.getElementById('hspLangPrefix').value.trim() || '/de/';
      var deRes  = await createPage(clNameDe, deClusterSlug, buildClusterContent(key), status, 0, 'de');
      if (deRes.ok) {
        var deId = deRes.json.id;
        logC('Cluster DE angelegt: ID ' + deId, 'ok');
        parentIdDe = deId;
        createdIds.push(deId);
        html += '<br>[DE] "' + clNameDe + '" &mdash; ID&nbsp;' + deId
          + (deRes.json.link ? ' &mdash; <a href="' + deRes.json.link + '" target="_blank">Vorschau</a>' : '');
        var lnk = await linkTranslation(enId, deId);
        if (lnk.ok && lnk.json.ok) {
          logC('WPML verknuepft (trid ' + lnk.json.trid + ')', 'ok');
          html += '<br><span class="ok">WPML-Verknuepfung: trid ' + lnk.json.trid + '</span>';
        } else {
          logC('WARN WPML: ' + JSON.stringify(lnk.json), 'warn');
        }
      } else {
        logC('FEHLER Cluster DE: ' + (deRes.json.message||deRes.status), 'err');
      }
    }

    createdIds.push(enId);
    var res = document.getElementById('hspClusterResult');
    res.innerHTML = html; res.classList.add('on');

    document.getElementById('hspParentEnStatus').innerHTML =
      '<span class="ok">\u2713 Parent EN gesetzt: "' + (bilingual?clNameEn:clNameDe) + '" (ID&nbsp;' + enId + ')</span>';
    if (bilingual && parentIdDe) {
      document.getElementById('hspParentDeStatus').innerHTML =
        '<span class="ok">\u2713 Parent DE gesetzt: "' + clNameDe + '" (ID&nbsp;' + parentIdDe + ')</span>';
    }

    unlockStep2(key, bilingual);
    btn.textContent = 'Angelegt \u2713';
  });

  document.getElementById('hspClusterSkipBtn').addEventListener('click', async function(){
    var key       = document.getElementById('hspCluster').value; // discipline_key
    var bilingual = document.getElementById('hspBilingual').checked;
    var apiSlug   = getApiSlug(key);
    if (!key) return;
    this.disabled = true;
    document.getElementById('hspClusterBtn').disabled = true;
    logC('Parent-Suche fuer bestehenden Cluster...', 'inf');
    await resolveAndSetParent(key, apiSlug, bilingual);
    unlockStep2(key, bilingual);
  });

  async function resolveAndSetParent(key, apiSlug, bilingual) {
    var stEn = document.getElementById('hspParentEnStatus');
    await resolveParent(key, apiSlug, 'en', stEn, function(id){ parentIdEn=id; }, logC);
    if (bilingual) {
      var stDe = document.getElementById('hspParentDeStatus');
      stDe.style.display='block';
      await resolveParent(key, apiSlug, 'de', stDe, function(id){ parentIdDe=id; }, logC);
    }
  }

  function unlockStep2(key, bilingual) {
    clusterReady = true;
    document.getElementById('cardDetail').classList.remove('locked');
    document.getElementById('hspAllBtn').disabled = currentDiscs.length===0;
    var sa = document.getElementById('hspSelectAll');
    if (sa) { sa.disabled = false; sa.checked = true; sa.indeterminate = false; }
    logD('Schritt 2 freigeschaltet. Parent EN: ID ' + parentIdEn + (bilingual ? ' | Parent DE: ID ' + parentIdDe : ''), 'ok');
    logD('Bereit: ' + currentDiscs.length + ' Detailseiten fuer "' + key + '"', 'inf');
  }

  function buildDiscList(discs) {
    var list = document.getElementById('hspDiscList');
    list.innerHTML = '';
    if (!discs.length) {
      list.innerHTML = '<div style="color:#9ca3af;font-size:.82rem;padding:12px;">Keine Disziplinen gefunden</div>';
      return;
    }
    discs.forEach(function(d){
      var item = document.createElement('div');
      item.className = 'disc-item';
      item.id = 'hsd-' + d.disciplineKey;

      var cb = document.createElement('input');
      cb.type = 'checkbox'; cb.className = 'disc-cb'; cb.value = d.disciplineKey;
      cb.checked = true;
      cb.addEventListener('change', syncSelectAll);

      var dot  = document.createElement('span'); dot.className = 'disc-dot';
      var name = document.createElement('span'); name.className = 'disc-name';
      name.textContent = d.displayName || d.name || d.disciplineKey;
      var st     = document.createElement('span'); st.className = 'disc-status'; st.textContent = '';
      var tags   = document.createElement('span'); tags.className = 'disc-tags'; tags.id = 'hsd-tags-' + d.disciplineKey;
      var delBtn = document.createElement('button');
      delBtn.className = 'disc-del'; delBtn.title = 'Seite(n) l\u00f6schen'; delBtn.textContent = '\u{1F5D1}';
      delBtn.style.display = 'none';
      (function(key){ delBtn.addEventListener('click', function(e){ e.stopPropagation(); deleteDisc(key); }); })(d.disciplineKey);

      item.appendChild(cb); item.appendChild(dot); item.appendChild(name); item.appendChild(st);
      item.appendChild(tags); item.appendChild(delBtn);
      list.appendChild(item);
    });

    syncSelectAll();
  }

  // ── v4.13: Check existing WP pages per disc and show EN/DE tags ─────────────
  // deRowsMap: optional { disciplineKey -> deRow } for DE slug lookup
  async function checkExistingPages(discs, bilingual, deRowsMap) {
    var siteUrl = window.location.origin;
    for (var i = 0; i < discs.length; i++) {
      var d      = discs[i];
      var key    = d.disciplineKey;
      var enUrl  = d.detailUrl || d.detail_url || '';
      var enSlug = enUrl ? enUrl.replace(/\/$/, '').split('/').pop() : key;

      // DE slug: prefer IndexDE detailUrl if available, fallback to EN slug
      var deRow  = deRowsMap ? deRowsMap[key] : null;
      var deUrl  = deRow ? (deRow.detailUrl || deRow.detail_url || '') : '';
      var deSlug = deUrl ? deUrl.replace(/\/$/, '').split('/').pop() : enSlug;

      var tags = document.getElementById('hsd-tags-' + key);
      var del  = document.querySelector('#hsd-' + key + ' .disc-del');
      if (!tags) continue;
      try {
        // EN check
        var enR = await fetch(PROV + '/find-parent?slug=' + encodeURIComponent(enSlug) + '&lang=en', { headers: { 'X-WP-Nonce': NONCE } });
        var enJ = enR.ok ? await enR.json() : { found: false };
        if (enJ.found && enJ.id) {
          var enTag = document.createElement('a');
          enTag.className = 'disc-tag en';
          enTag.href = siteUrl + '/wp-admin/post.php?post=' + enJ.id + '&action=edit';
          enTag.target = '_blank'; enTag.textContent = 'EN \u2713';
          enTag.dataset.pageId = enJ.id;
          tags.appendChild(enTag);
          if (del) { del.style.display = ''; del.dataset.enId = enJ.id; }
          document.getElementById('hspDeleteAllBtn').disabled = false;
        }
        // DE check — v4.13: kein id !== enJ.id Filter mehr, WPML liefert separate IDs
        if (bilingual) {
          var deR = await fetch(PROV + '/find-parent?slug=' + encodeURIComponent(deSlug) + '&lang=de', { headers: { 'X-WP-Nonce': NONCE } });
          var deJ = deR.ok ? await deR.json() : { found: false };
          if (deJ.found && deJ.id) {
            var deTag = document.createElement('a');
            deTag.className = 'disc-tag de';
            deTag.href = siteUrl + '/wp-admin/post.php?post=' + deJ.id + '&action=edit';
            deTag.target = '_blank'; deTag.textContent = 'DE \u2713';
            deTag.dataset.pageId = deJ.id;
            tags.appendChild(deTag);
            if (del) { del.style.display = ''; del.dataset.deId = deJ.id; }
            document.getElementById('hspDeleteAllBtn').disabled = false;
          }
        }
      } catch(e) { /* silent per disc */ }
    }
  }

  // ── v4.12: Delete single disc pages ──────────────────────────────────────────
  async function deleteDisc(key) {
    var del   = document.querySelector('#hsd-' + key + ' .disc-del');
    var enId  = del && del.dataset.enId ? parseInt(del.dataset.enId) : 0;
    var deId  = del && del.dataset.deId ? parseInt(del.dataset.deId) : 0;
    var ids   = [enId, deId].filter(Boolean);
    if (!ids.length) { logD('Keine bekannten Seiten-IDs für ' + key, 'warn'); return; }
    var label = (enId ? 'EN ' : '') + (deId ? 'DE' : '');
    if (!confirm('Seite(n) für "' + key + '" wirklich löschen? (' + label.trim() + ')')) return;
    logD('🗑 Lösche ' + key + ' (' + label.trim() + ')...', 'warn');
    for (var i = 0; i < ids.length; i++) {
      try {
        await fetch(API + '/' + ids[i] + '?force=true', {
          method: 'DELETE', credentials: 'same-origin',
          headers: { 'X-WP-Nonce': NONCE }
        });
      } catch(e) {}
    }
    var tags = document.getElementById('hsd-tags-' + key);
    if (tags) tags.innerHTML = '';
    if (del) { del.style.display = 'none'; delete del.dataset.enId; delete del.dataset.deId; }
    setDisc(key, '', '');
    logD('🗑 Gelöscht: ' + key + ' (IDs: ' + ids.join(', ') + ')', 'warn');
    // disable delete-all if no tags left
    if (!document.querySelectorAll('.disc-tag').length) {
      document.getElementById('hspDeleteAllBtn').disabled = true;
    }
  }

  function setDisc(key, state, txt){
    var el = document.getElementById('hsd-'+key);
    if (!el) return;
    el.className = 'disc-item ' + state;
    el.querySelector('.disc-status').textContent = txt;
  }

  function buildContent(key, clusterUrl) {
    var t = 'script';
    return '<!-- wp:html -->\n<div id="hs-root"\n  data-type="detail"\n  data-discipline="' + key + '"\n  data-cluster-url="' + clusterUrl + '">\n</div>\n<' + t + '></' + t + '>\n<!-- /wp:html -->';
  }
  // FIX (v4.15): "bundle"-Parameter ist jetzt discipline_key -- schreibt
  // z.B. data-bundle="us-sports" statt data-bundle="basketball,american_
  // football,eishockey,fußball" in die neu angelegte Cluster-Seite.
  function buildClusterContent(disciplineKey) {
    var t = 'script';
    return '<!-- wp:html -->\n<div id="hs-root"\n  data-type="cluster"\n  data-bundle="' + disciplineKey + '">\n</div>\n<' + t + '></' + t + '>\n<!-- /wp:html -->';
  }

function createPage(title, slug, content, status, parentId, lang) {
    var url  = API + (lang ? '?lang=' + lang : '');
    var body = {
      title:title,
      slug:slug,
      content:content,
      status:status,
      parent:parentId,
      // Markiert die Seite serverseitig, damit WPMLs Automatic
      // Translation sie ausschliesst (siehe wpml_exclude_post_from_auto_translate
      // Filter in hs-wpml-exclusions.php).
      meta: { _hs_no_auto_translate: true }
    };
    if (lang) body.lang = lang;
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
      body: JSON.stringify(body)
    }).then(function(r){ return r.json().then(function(j){ return {ok:r.ok,status:r.status,json:j}; }); });
}

  function linkTranslation(enId, deId) {
    return fetch(PROV + '/link-translation', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
      body: JSON.stringify({ en_id:enId, de_id:deId })
    }).then(function(r){ return r.json().then(function(j){ return {ok:r.ok,json:j}; }); });
  }

  async function processDisc(d, discIdx, clusterKey, status, bilingual, langPrefix, deRows) {
    var key        = d.disciplineKey;
    var name       = d.displayName || d.name || key;
    var url        = d.detailUrl || d.detail_url || '';
    var slug       = url ? url.replace(/\/$/, '').split('/').pop() : key;
    // clusterKey (discipline_key) wird nur fuer die URL-Anzeige verwendet --
    // die eigentliche Parent-Zuordnung erfolgt ueber parentIdEn/parentIdDe.
    var clUrl      = '/' + clusterKey + '/';
    var clUrlDe    = langPrefix + clusterKey + '/';
    var deRow      = (deRows && deRows[discIdx]) || null;
    var deKey      = deRow ? (deRow.disciplineKey || key) : key;
    var deUrl      = deRow ? (deRow.detailUrl || deRow.detail_url || '') : '';
    var deSlug     = deUrl ? deUrl.replace(/\/$/, '').split('/').pop() : deKey;
    var titleEn    = name;
    var titleDe    = deRow ? (deRow.displayName || deRow.name || name) : name;

    setDisc(key, 'running', '...');
    logD('> ' + name + ' [' + slug + ']' + (bilingual?' EN+DE':''), '');

    var enRes = await createPage(titleEn, slug, buildContent(key, clUrl), status, parentIdEn, bilingual?'en':'');
    if (!enRes.ok) {
      var isConf = enRes.status===409 || (enRes.json.code && enRes.json.code.indexOf('exists')>-1);
      if (isConf){ setDisc(key,'skip','existiert'); logD('  SKIP ' + name,'warn'); return {result:'skip'}; }
      setDisc(key,'error','ERR '+enRes.status);
      logD('  FEHLER EN ' + name + ' – ' + (enRes.json.message||enRes.status),'err');
      return {result:'fail'};
    }
    var enId = enRes.json.id;
    logD('  OK EN ID ' + enId,'ok');
    createdIds.push(enId);

    if (!bilingual) {
      setDisc(key,'done','');
      (function(k, eId){
        var tags = document.getElementById('hsd-tags-' + k);
        var del  = document.querySelector('#hsd-' + k + ' .disc-del');
        if (tags) {
          tags.innerHTML = '';
          var et = document.createElement('a'); et.className = 'disc-tag en';
          et.href = window.location.origin + '/wp-admin/post.php?post=' + eId + '&action=edit';
          et.target = '_blank'; et.textContent = 'EN \u2713'; et.dataset.pageId = eId;
          tags.appendChild(et);
        }
        if (del) { del.style.display = ''; del.dataset.enId = eId; }
        document.getElementById('hspDeleteAllBtn').disabled = false;
      })(key, enId);
      return {result:'ok',id:enId,name:name,link:enRes.json.link||''};
    }

    var deRes = await createPage(titleDe, deSlug, buildContent(deKey, clUrlDe), status, parentIdDe, 'de');
    if (!deRes.ok) {
      setDisc(key,'error','ERR DE '+deRes.status);
      logD('  FEHLER DE ' + name + ' – ' + (deRes.json.message||deRes.status),'err');
      return {result:'fail'};
    }
    var deId = deRes.json.id;
    logD('  OK DE ID ' + deId,'ok');
    createdIds.push(deId);

    var lnk = await linkTranslation(enId, deId);
    if (lnk.ok && lnk.json.ok) {
      logD('  WPML trid ' + lnk.json.trid,'ok');
      setDisc(key,'done','');
      // v4.12: Tags mit klickbaren Links aktualisieren
      (function(k, eId, dId){
        var tags = document.getElementById('hsd-tags-' + k);
        var del  = document.querySelector('#hsd-' + k + ' .disc-del');
        if (tags) {
          tags.innerHTML = '';
          var siteUrl = window.location.origin;
          if (eId) {
            var et = document.createElement('a'); et.className = 'disc-tag en';
            et.href = siteUrl + '/wp-admin/post.php?post=' + eId + '&action=edit';
            et.target = '_blank'; et.textContent = 'EN \u2713'; et.dataset.pageId = eId;
            tags.appendChild(et);
          }
          if (dId) {
            var dt = document.createElement('a'); dt.className = 'disc-tag de';
            dt.href = siteUrl + '/wp-admin/post.php?post=' + dId + '&action=edit';
            dt.target = '_blank'; dt.textContent = 'DE \u2713'; dt.dataset.pageId = dId;
            tags.appendChild(dt);
          }
        }
        if (del) { del.style.display = ''; del.dataset.enId = eId; if (dId) del.dataset.deId = dId; }
        document.getElementById('hspDeleteAllBtn').disabled = false;
      })(key, enId, deId);
    } else {
      logD('  WARN WPML: ' + (lnk.json.message||JSON.stringify(lnk.json)),'warn');
      setDisc(key,'done','ID '+enId+' (WPML pruefen)');
    }
    return {result:'ok',id:enId,deId:deId,name:name,link:enRes.json.link||'',deLink:deRes.json.link||''};
  }

  function syncSelectAll() {
    var all  = Array.from(document.querySelectorAll('.disc-cb'));
    var chk  = all.filter(function(cb){ return cb.checked; });
    var sa   = document.getElementById('hspSelectAll');
    if (!sa) return;
    sa.checked       = all.length > 0 && chk.length === all.length;
    sa.indeterminate = chk.length > 0 && chk.length < all.length;
    var btn = document.getElementById('hspAllBtn');
    if (btn && clusterReady) btn.disabled = chk.length === 0;
  }

  document.getElementById('hspAllBtn').addEventListener('click', async function(){
    if (!clusterReady) return;
    var btn       = this;
    var key       = document.getElementById('hspCluster').value; // discipline_key
    var status    = document.getElementById('hspStatus').value;
    var bilingual = document.getElementById('hspBilingual').checked;
    var prefix    = document.getElementById('hspLangPrefix').value.trim()||'/de/';

    var checked   = Array.from(document.querySelectorAll('.disc-cb:checked')).map(function(cb){ return cb.value; });
    var toProcess = currentDiscs.filter(function(d){ return checked.indexOf(d.disciplineKey) !== -1; });
    if (!toProcess.length) { logD('Keine Disziplinen ausgewaehlt.','warn'); return; }

    btn.disabled=true; btn.textContent='Laeuft...';
    document.getElementById('hspDetailResult').classList.remove('on');
    logD('-- Start: ' + key + ' | ' + toProcess.length + ' ausgewaehlt' + (bilingual?' | EN+DE':'') + ' --','inf');
    logD('   Parent EN: ' + parentIdEn + (bilingual?' | Parent DE: '+parentIdDe:''),'inf');

    var deRows = [];
    if (bilingual) {
      try {
        var deIdxRes = await fetch(CACHE_DE, { headers: { Accept: 'application/json' } });
        if (deIdxRes.ok) {
          var deIdxJson = await deIdxRes.json();
          var allDeRows = parseRows(deIdxJson);
          // FIX (v4.15): gegen currentBundleColumn matchen statt gegen den
          // discipline_key-Dropdown-Wert -- Kind-Disziplinen (z.B. bei
          // Wintersport) tragen weiterhin die ROHE "bundle"-Spalte.
          deRows = allDeRows.filter(function(r){
            return (r.bundle||'').toLowerCase() === currentBundleColumn.toLowerCase()
                && (r.type||'').toLowerCase() !== 'cluster';
          });
          logD('DE-Index geladen: ' + deRows.length + ' Detail-Zeilen', 'ok');
        }
      } catch(e) { logD('DE-Index nicht geladen: ' + e.message, 'warn'); }
    }

    var ok=0, fail=0, skip=0, created=[];
    for (var i=0; i<toProcess.length; i++){
      var fullIdx = currentDiscs.indexOf(toProcess[i]);
      var res = await processDisc(toProcess[i],fullIdx,key,status,bilingual,prefix,deRows);
      if (res.result==='ok')  { ok++;   created.push(res); }
      if (res.result==='fail') fail++;
      if (res.result==='skip') skip++;
      await sleep(350);
    }

    var r = document.getElementById('hspDetailResult');
    var html = '<strong>' + ok + ' Detailseiten angelegt</strong>' + (bilingual?' (je EN+DE, WPML-verknuepft)':'');
    if (skip) html += ' &nbsp;<span style="color:#d97706">'+skip+' uebersprungen</span>';
    if (fail) html += ' &nbsp;<span style="color:#dc2626">'+fail+' Fehler</span>';
    if (created.length) {
      html += '<br><br>' + created.map(function(p){
        var links = '';
        if (p.link)   links += ' &mdash; <a href="'+p.link+'" target="_blank">EN</a>';
        if (p.deLink) links += ' &mdash; <a href="'+p.deLink+'" target="_blank">DE</a>';
        if (!links && p.link) links = ' &mdash; <a href="'+p.link+'" target="_blank">Vorschau</a>';
        return '&bull; ' + p.name
          + (p.deId ? ' &mdash; EN:&nbsp;' + p.id + ' / DE:&nbsp;' + p.deId : ' &mdash; ID&nbsp;' + p.id)
          + links;
      }).join('<br>');
    }
    r.innerHTML = html; r.classList.add('on');
    logD('-- Fertig: '+ok+' OK / '+skip+' skip / '+fail+' err --','inf');
    btn.disabled=false; btn.textContent='Abgeschlossen';
    if (createdIds.length && status==='draft') document.getElementById('hspPublishBtn').classList.add('on');
  });

  document.getElementById('hspSelectAll').addEventListener('change', function(){
    document.querySelectorAll('.disc-cb').forEach(function(cb){ cb.checked = this.checked; }, this);
    syncSelectAll();
  });

  document.getElementById('hspPublishBtn').addEventListener('click', async function(){
    var btn = this;
    if (!createdIds.length){ logD('Keine angelegten Seiten.','warn'); return; }
    btn.disabled=true; btn.textContent='Veroeffentliche...';
    logD('-- Bulk Publish: ' + createdIds.length + ' Seiten --','inf');
    try {
      var r = await fetch(PROV+'/bulk-publish',{
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-WP-Nonce':NONCE},
        body: JSON.stringify({ids:createdIds})
      });
      var j = await r.json();
      logD('Veroeffentlicht: '+(j.published||[]).length+' | Fehler: '+(j.failed||[]).length,
        (j.failed||[]).length?'warn':'ok');
      btn.textContent='Veroeffentlicht \u2713';
    } catch(e) {
      logD('Fehler: '+e.message,'err');
      btn.disabled=false; btn.textContent='Entw\u00fcrfe ver\u00f6ffentlichen';
    }
  });

  // ── v4.12: Delete-all handler ─────────────────────────────────────────────────
  document.getElementById('hspDeleteAllBtn').addEventListener('click', async function(){
    var allTags = Array.from(document.querySelectorAll('.disc-tag'));
    if (!allTags.length) { logD('Keine bekannten Seiten zum Löschen.', 'warn'); return; }
    var ids = allTags.map(function(t){ return parseInt(t.dataset.pageId); }).filter(Boolean);
    var unique = ids.filter(function(v, i, a){ return a.indexOf(v) === i; });
    if (!unique.length) return;
    if (!confirm('Alle ' + unique.length + ' Seiten dieses Clusters wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.')) return;
    var btn = this;
    btn.disabled = true; btn.textContent = 'Löscht...';
    logD('🗑 Lösche ' + unique.length + ' Seiten...', 'warn');
    var ok = 0, fail = 0;
    for (var i = 0; i < unique.length; i++) {
      try {
        var r = await fetch(API + '/' + unique[i] + '?force=true', {
          method: 'DELETE', credentials: 'same-origin',
          headers: { 'X-WP-Nonce': NONCE }
        });
        r.ok ? ok++ : fail++;
      } catch(e) { fail++; }
    }
    logD('🗑 Gelöscht: ' + ok + '/' + unique.length + (fail ? ' | Fehler: ' + fail : ''), ok === unique.length ? 'warn' : 'err');
    // Reset all disc UI
    document.querySelectorAll('.disc-tags').forEach(function(t){ t.innerHTML = ''; });
    document.querySelectorAll('.disc-del').forEach(function(b){ b.style.display = 'none'; delete b.dataset.enId; delete b.dataset.deId; });
    document.querySelectorAll('.disc-item').forEach(function(el){ el.className = 'disc-item'; el.querySelector('.disc-status').textContent = ''; });
    btn.disabled = true; btn.textContent = '🗑 Alle löschen';
  });

  document.getElementById('hspBilingual').addEventListener('change', function(){
    document.getElementById('hspLangOpts').classList.toggle('visible', this.checked);
    var key = document.getElementById('hspCluster').value;
    if (key) onClusterSelect();
  });

})();
</script>
<?php }
