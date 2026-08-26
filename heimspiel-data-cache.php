<?php
/**
 * Plugin Name: HEIMSPIEL Data Cache
 * Description: Cached WP-REST-API Layer fuer die HEIMSPIEL Landingpages (hs-landing.js). Holt Index-/CSV-Daten aus Google Sheets (via Google Apps Script Web App), cached sie als Transients und stellt sie unter /wp-json/hs-cache/v1/... performant zur Verfuegung.
 * Version: 1.4
 * Author: HEIMSPIEL
 * Text Domain: heimspiel-data-cache
 *
 * Changelog:
 * v1.4 -- August 2026: Neu: includes/prerender-sync.php in $hs_includes
 *         aufgenommen. Ersetzt die fruehere Push-Variante aus
 *         includes/prerender-api.php (POST /prerender/media von GitHub
 *         Actions aus), die von einer Hosting-/Cloud-IP-Firewall blockiert
 *         wurde (401 rest_forbidden bei authentifizierten POST-Requests aus
 *         GitHub-Actions-IP-Bereichen -- AIOS und alle anderen WP-Plugins
 *         waren dabei nicht die Ursache, siehe Debugging-Historie).
 *         Neuer Ablauf: GitHub Actions committet fertige WebP-Bilder +
 *         image-mapping.json direkt ins Repo (dist/hero-images/), und
 *         WordPress HOLT sich diese Dateien selbst ab (ausgehender Request,
 *         wird von Inbound-Firewalls nicht geblockt) -- per taeglichem
 *         WP-Cron (hs_prerender_sync_cron) ODER manuellem Button auf der
 *         Admin-Seite "HEIMSPIEL Cache". includes/prerender-api.php bleibt
 *         vorerst im Plugin (fuer /prerender/page -- Puppeteer-HTML-Writeback
 *         aus Subtask 6, dort ist noch offen, ob dieselbe Firewall-Blockade
 *         zuschlaegt oder nicht, da /prerender/page bisher nicht getestet
 *         wurde).
 * v1.3 -- Juli 2026: FIX: HS_GSHEET_INDEX_URL etc. zeigten faelschlicherweise
 *         auf veraltete Sheety-URLs (api.sheety.co/...), obwohl das Projekt
 *         laengst auf eine Google Apps Script Web App umgestellt wurde
 *         (script.google.com/macros/s/.../exec?sheet=...). Das verursachte
 *         den "Google Sheets Web App (Index) HTTP 500"-Fehler beim
 *         Cache-Refresh, da die Sheety-URL nicht mehr existiert/nicht mehr
 *         das erwartete JSON liefert. Jetzt korrekt auf HS_GSHEET_APP_BASE_URL
 *         (Apps-Script-Deployment-URL) + ?sheet=... umgestellt, passend zum
 *         doGet(e)-Code im Apps Script (ALLOWED_SHEETS: Index, Index_DE,
 *         General_Index, General_Index_DE).
 * v1.2 -- Juli 2026: Neu: includes/hs-coverage-endpoint.php in $hs_includes
 *         aufgenommen -- HINWEIS: dieser Eintrag wurde in v1.3 wieder entfernt,
 *         da die Coverage-Logik bereits direkt in includes/cache.php und
 *         includes/rest-api.php integriert ist (siehe dortiges Changelog).
 *         Ein zusaetzlicher Include fuehrte zu doppelt deklarierten Funktionen
 *         und einem Fatal Error beim Aktivieren.
 * v1.1 -- Juli 2026: Neu: /wp-json/hs-cache/v1/coverage/{sport} Endpoint.
 * v1.0 -- 2026: Initiale Version (Index/GeneralIndex/CSV Caching, monatlicher
 *         Cron-Refresh).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'HS_CACHE_VERSION', '1.4' );
define( 'HS_CACHE_TTL', 31 * DAY_IN_SECONDS ); // Cache-Laufzeit (Transients)
define( 'HS_CRON_HOOK', 'hs_refresh_all_cache_event' );

// ── Google Apps Script Web App -- Basis-URL ─────────────────────────────────
// WICHTIG: Diese URL aktualisieren, sobald im Apps-Script-Editor eine neue
// Bereitstellung (Deployment) erstellt wird ("Bereitstellen -> Neue
// Bereitstellung verwalten"). Die /exec-URL aendert sich bei jedem neuen
// Deployment, sofern nicht "Gleiche URL beibehalten" gewaehlt wird.
if ( ! defined( 'HS_GSHEET_APP_BASE_URL' ) ) {
    define( 'HS_GSHEET_APP_BASE_URL', 'https://script.google.com/macros/s/AKfycbzUNQBiEtqWhgjYAGcSDmozQnrqdAQT72paZrNfMv5ryXxmu2VWStLO8lrMw2LVAYYs/exec' );
}

// Einzel-Endpoints (Sheet-Name als Parameter, passend zu ALLOWED_SHEETS im Apps Script)
if ( ! defined( 'HS_GSHEET_INDEX_URL' ) ) {
    define( 'HS_GSHEET_INDEX_URL', HS_GSHEET_APP_BASE_URL . '?sheet=Index' );
}
if ( ! defined( 'HS_GSHEET_INDEX_DE_URL' ) ) {
    define( 'HS_GSHEET_INDEX_DE_URL', HS_GSHEET_APP_BASE_URL . '?sheet=Index_DE' );
}
if ( ! defined( 'HS_GSHEET_GENERAL_URL' ) ) {
    define( 'HS_GSHEET_GENERAL_URL', HS_GSHEET_APP_BASE_URL . '?sheet=General_Index' );
}
if ( ! defined( 'HS_GSHEET_GENERAL_DE_URL' ) ) {
    define( 'HS_GSHEET_GENERAL_DE_URL', HS_GSHEET_APP_BASE_URL . '?sheet=General_Index_DE' );
}

// Google Sheets CSV Export Basis-URL (fuer die einzelnen Sport-Tabs per GID -- unveraendert, kein Apps Script)
if ( ! defined( 'HS_GSHEET_CSV_BASE' ) ) {
    define( 'HS_GSHEET_CSV_BASE', 'https://docs.google.com/spreadsheets/d/1EZYrk7msUG7jus30sHc0p_5Ql7M6808xM1ADN0RrMq0/export?format=csv&gid=' );
}

// Includes defensiv laden (file_exists-Check verhindert einen Fatal Error,
// falls beim Hochladen eine Datei fehlen sollte -- das Plugin degradiert dann
// kontrolliert statt die ganze Seite mit critical error lahmzulegen).
$hs_includes = [
    'includes/cache.php',
    'includes/rest-api.php',
    'includes/prerender-api.php',
    'includes/prerender-sync.php',
    'includes/prerender-snapshot.php',
    'includes/hs-wpml-auto-translate-exclusions.php',
    'includes/cron.php',
    'includes/admin.php',
];

foreach ( $hs_includes as $hs_inc ) {
    $hs_path = plugin_dir_path( __FILE__ ) . $hs_inc;
    if ( file_exists( $hs_path ) ) {
        require_once $hs_path;
    } else {
        add_action( 'admin_notices', function() use ( $hs_inc ) {
            echo '<div class="notice notice-error"><p>HEIM:SPIEL Data Cache: Datei "' . esc_html( $hs_inc ) . '" fehlt im Plugin-Ordner.</p></div>';
        } );
    }
}

if ( function_exists( 'hs_schedule_cron' ) ) {
    register_activation_hook( __FILE__, 'hs_schedule_cron' );
}
if ( function_exists( 'hs_unschedule_cron' ) ) {
    register_deactivation_hook( __FILE__, 'hs_unschedule_cron' );
}
