<?php
/**
 * Plugin Name: HEIM:SPIEL Performance
 * Description: Entfernt messbar unnoetige Frontend-Ressourcen. Bewusst klein
 *              gehalten -- kein pauschales "defer" auf alle Skripte, weil
 *              Flatsome und WPML synchrone Inline-Skripte einsetzen, die auf
 *              bereits geladenem jQuery aufbauen. Ein globales Verzoegern
 *              bricht diese Seite.
 * Version:     1.0.0
 * Author:      HEIM:SPIEL
 *
 * Ablage: wp-content/mu-plugins/hs-perf.php
 *
 * ---------------------------------------------------------------------------
 * Messgrundlage (heimspiel.de/de/fussball/, 01.09.2026)
 * ---------------------------------------------------------------------------
 * 11 externe Stylesheets, alle im <head> und damit renderblockierend.
 * 18 externe Skripte, davon 8 blockierend im <head> und 8 blockierend im Body.
 *
 * Was diese Datei erledigt:
 *   1. jquery-migrate            13,3 KB entpackt, blockierend im <head>.
 *                                Nur fuer jQuery-Code vor Version 3 noetig.
 *   2. tf-footer-style.css       liefert HTTP 200 mit 0 Byte -- ein Roundtrip
 *                                im <head> ohne jeden Inhalt.
 *   3. wp-statistics tracker.js  8,7 KB, blockierend im Body, fuer die
 *                                Darstellung ohne Bedeutung -> defer.
 *
 * NICHT anfassen: das OMGF-Stylesheet
 *   //heimspiel.de/wp-content/uploads/omgf/flatsome-googlefonts/...
 * In einer fruehen Fassung dieser Datei stand es als "HTTP 404" auf der
 * Streichliste. Das war ein Messfehler: Der Verweis ist protokoll-relativ und
 * beginnt mit "//", das Pruefskript hatte "https://heimspiel.de" davorgesetzt
 * und damit den Host verdoppelt. Nachgeprueft liefert die Datei HTTP 200 mit
 * 377 Byte, die zugehoerige Lato-Schrift 23.580 Byte. OMGF ersetzt damit den
 * externen Google-Fonts-Aufruf durch eine lokale Kopie -- das ist ein Vorteil
 * fuer die Ladezeit und darf keinesfalls entfernt werden.
 *
 * Was diese Datei NICHT erledigt, obwohl es messbar teuer ist:
 *   - Das doppelte Google-Tag. Es steht als fest eingetragenes Markup in der
 *     Seite, nicht in der Skript-Warteschlange, und ist daher hier nicht
 *     greifbar. Siehe Hinweis am Dateiende.
 *   - Zusammenfassen und Minifizieren der 11 CSS- und 18 JS-Dateien. Das
 *     gehoert in ein Caching-Plugin, nicht hierher.
 *
 * @package HEIMSPIEL
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'HS_Perf' ) ) {

	final class HS_Perf {

		/**
		 * Stylesheets, deren <link> im Frontend entfernt wird. Abgleich per
		 * Teilstring gegen die href, damit die Handles der Fremd-Plugins nicht
		 * bekannt sein muessen.
		 */
		const STYLE_DROP = array(
			'tf-footer-style',   // HTTP 200 mit 0 Byte, nachgeprueft
		);

		/**
		 * Skripte, die "defer" erhalten. Nur Dinge ohne Einfluss auf die
		 * Darstellung -- alles andere bleibt unangetastet.
		 */
		const SCRIPT_DEFER = array(
			'wp-statistics/assets/js/tracker.js',
		);

		public static function init() {
			// jquery-migrate aus den Abhaengigkeiten von jQuery loesen.
			add_action( 'wp_default_scripts', array( __CLASS__, 'drop_jquery_migrate' ) );

			// Leere und fehlerhafte Stylesheets nicht ausgeben.
			add_filter( 'style_loader_tag', array( __CLASS__, 'filter_style_tag' ), 10, 2 );

			// Tracking verzoegern.
			add_filter( 'script_loader_tag', array( __CLASS__, 'filter_script_tag' ), 10, 2 );
		}

		/**
		 * Entfernt jquery-migrate, ohne jQuery selbst anzutasten.
		 *
		 * Der Weg ueber wp_default_scripts ist der einzige zuverlaessige:
		 * Ein spaeteres wp_dequeue_script() greift nicht, weil jQuery
		 * jquery-migrate als Abhaengigkeit fuehrt und WordPress es dann erneut
		 * einreiht.
		 *
		 * @param WP_Scripts $scripts
		 */
		public static function drop_jquery_migrate( $scripts ) {
			if ( is_admin() ) {
				return;
			}
			if ( ! isset( $scripts->registered['jquery'] ) ) {
				return;
			}

			$deps = $scripts->registered['jquery']->deps;
			if ( ! is_array( $deps ) ) {
				return;
			}

			$scripts->registered['jquery']->deps = array_values(
				array_diff( $deps, array( 'jquery-migrate' ) )
			);
		}

		/**
		 * @param  string $tag    Fertiges <link>-Markup.
		 * @param  string $handle Handle des Stylesheets.
		 * @return string         Leerer String unterdrueckt die Ausgabe.
		 */
		public static function filter_style_tag( $tag, $handle ) {
			if ( is_admin() ) {
				return $tag;
			}

			foreach ( self::STYLE_DROP as $needle ) {
				if ( false !== strpos( $tag, $needle ) ) {
					return '';
				}
			}

			return $tag;
		}

		/**
		 * @param  string $tag    Fertiges <script>-Markup.
		 * @param  string $handle Handle des Skripts.
		 * @return string
		 */
		public static function filter_script_tag( $tag, $handle ) {
			if ( is_admin() ) {
				return $tag;
			}
			// Nichts anfassen, was schon defer oder async traegt.
			if ( false !== strpos( $tag, ' defer' ) || false !== strpos( $tag, ' async' ) ) {
				return $tag;
			}

			foreach ( self::SCRIPT_DEFER as $needle ) {
				if ( false !== strpos( $tag, $needle ) ) {
					return str_replace( '<script ', '<script defer ', $tag );
				}
			}

			return $tag;
		}
	}

	HS_Perf::init();
}

/* ---------------------------------------------------------------------------
 * Manuell zu erledigen -- nicht per Code loesbar
 * ---------------------------------------------------------------------------
 *
 * DAS DOPPELTE GOOGLE-TAG
 *
 * Die Seite enthaelt die Messung G-B5NL70Q8FN zweimal:
 *
 *   1) korrekt gesperrt, wird erst nach Einwilligung ausgefuehrt:
 *      <script type="text/plain" data-usercentrics="Google Analytics" async
 *              src="//www.googletagmanager.com/gtag/js?id=G-B5NL70Q8FN">
 *
 *   2) UNGESPERRT, laeuft immer:
 *      <!-- Google tag (gtag.js) -->
 *      <script async src="https://www.googletagmanager.com/gtag/js?id=G-B5NL70Q8FN">
 *      <script> window.dataLayer = ... gtag('config', 'G-B5NL70Q8FN') </script>
 *
 * Folgen:
 *   - gtag.js ist entpackt 491 KB und wird ohne Einwilligung geladen,
 *     ausgewertet und ausgefuehrt.
 *   - Nach Einwilligung laufen zwei gtag('config')-Aufrufe fuer dieselbe
 *     Mess-ID. Das erzeugt sehr wahrscheinlich doppelte Seitenaufrufe in GA4.
 *   - Der zweite Block umgeht die Einwilligungsloesung vollstaendig.
 *
 * Der zweite Block gehoert entfernt. Zu suchen ist er unter:
 *   Flatsome -> Advanced -> Global Settings -> Header Scripts
 *   Theme-Editor -> header.php
 *   ein Snippet-Plugin (Code Snippets, WPCode o. ae.)
 *   Google Site Kit, falls parallel aktiv
 * ------------------------------------------------------------------------- */
