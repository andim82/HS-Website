<?php
/**
 * Plugin Name: HEIM:SPIEL SEO Meta
 * Description: Serverseitige SEO-Meta-Ausgabe fuer Provisioner-Landingpages (#hs-root).
 *              Subtask 2: <meta name="description">.
 *              Subtask 3: Open Graph + Twitter Cards (mit korrektem property=-Attribut)
 *              sowie Unterdrueckung der fehlerhaften Meta-Tag-Manager-Ausgabe
 *              -- ausschliesslich auf diesen Seiten, der Rest der Website bleibt unberuehrt.
 *              Subtask 4: JSON-LD (@graph mit Organization, WebSite, WebPage,
 *              SoftwareApplication und FAQPage) serverseitig statt per JavaScript.
 *
 *              v1.1.1: Filter-Prioritaet fuer mtm_head_meta_tags von 10 auf 99
 *              korrigiert -- mit 10 blieben die fehlerhaften MTM-og-Tags erhalten
 *              (Begruendung im Kommentar bei add_filter).
 *              v1.2.0: JSON-LD ergaenzt. FAQPage wird aus dem SICHTBAREN
 *              Snapshot-Markup gelesen, nicht aus den Sheet-Templates neu
 *              berechnet -- dadurch stimmt das Schema garantiert mit dem
 *              ueberein, was Nutzer auf der Seite sehen (Google-Anforderung).
 *              v1.3.0 (Subtask 6): <title> serverseitig ueber
 *              pre_get_document_title -- Quelle seoTitle aus dem Sheet,
 *              sonst heroHeadline plus Markensuffix.
 * Version:     1.7.0
 * Author:      HEIM:SPIEL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HS_Seo_Meta {

	/** Cache-Dauer fuer die berechneten Meta-Daten pro Seite/Sprache. */
	const CACHE_TTL = 21600; // 6 Stunden

	/** Zielmaximum fuer die Description-Laenge in Zeichen. */
	const DESC_MAX = 158;

	/** Sheet-Spalten (normalisiert: kleingeschrieben, ohne "_"). */
	const FIELD_DESC       = 'seodescription';
	const FIELD_TITLE      = 'seotitle';
	const FIELD_DESC_FB    = 'description';
	const FIELD_IMAGE      = 'herobgurlcached';
	const FIELD_IMAGE_FB   = 'herobgurl';
	const FIELD_HEADLINE   = 'heroheadline';

	/** Dateiname des Landing-Stylesheets, erwartet neben diesem MU-Plugin. */
	const CSS_FILE = 'hs-landing.css';

	const SITE_NAME = 'HEIM:SPIEL';
	const SITE_URL  = 'https://heimspiel.de';

	/** Trennzeichen und Obergrenze fuer den <title>. */
	const TITLE_SEPARATOR = ' | ';
	const TITLE_MAX        = 65;

	/**
	 * Sprachabhaengiges Suffix im <title>.
	 *
	 * Enthaelt bewusst den Kategoriebegriff "Sportdaten" / "Sports Data":
	 * heroHeadline liefert nur "<Sportart> API & Widgets" und damit NICHT den
	 * zentralen Suchbegriff der Branche. Eine Zielgruppennennung ("fuer
	 * Medien") waere hier falsch, weil auch Broadcaster, Vereine, Verbaende
	 * und Marken adressiert werden -- der Kategoriebegriff schliesst dagegen
	 * alle ein. Das Sport-Keyword bleibt vorn, was fuer die mobile
	 * Snippet-Anzeige entscheidend ist.
	 */
	const TITLE_SUFFIX_DE = 'HEIM:SPIEL Sportdaten';
	const TITLE_SUFFIX_EN = 'HEIM:SPIEL Sports Data';

	/** Memoisierter Kontext, damit get_root_context() nicht mehrfach parst. */
	private static $ctx_cache = null;
	private static $ctx_done  = false;

	public static function init() {
		// Prioritaet 1: so frueh wie moeglich im <head>.
		add_action( 'wp_head', array( __CLASS__, 'render_head' ), 1 );

		// Subtask 6: <title> serverseitig setzen. pre_get_document_title
		// kurzschliesst wp_get_document_title() komplett, sobald ein
		// nicht-leerer Wert zurueckkommt -- damit greifen weder Theme- noch
		// WordPress-Defaults mehr hinein.
		add_filter( 'pre_get_document_title', array( __CLASS__, 'filter_document_title' ), 10, 1 );

		// Subtask 3: Meta Tag Manager gibt og:*- und twitter:*-Tags mit dem
		// falschen HTML-Attribut aus (name= statt property=) und zusaetzlich
		// einen hartcodierten Tippfehler ("webssite"). Beides sind Bugs IM
		// PLUGIN (classes/open-graph.php), nicht Konfigurationsfehler.
		// Ueber den offiziellen Filter mtm_head_meta_tags entfernen wir diese
		// Tags NUR auf #hs-root-Seiten -- alle anderen Seiten der Website
		// behalten die bisherige MTM-Ausgabe unveraendert (kein Regressionsrisiko).
		//
		// PRIORITAET 99 IST ZWINGEND: Meta_Tag_Manager\Open_Graph::add_tags haengt
		// sich mit Prioritaet 10 an denselben Filter -- registriert aber erst
		// INNERHALB von Meta_Tag_Manager::head() ueber load('open-graph'), also
		// nach diesem MU-Plugin. Bei gleicher Prioritaet entscheidet die
		// Registrierungsreihenfolge, wir wuerden also aufraeumen und MTM haengt
		// die og:*-Tags danach wieder an. Mit 99 laufen wir garantiert zuletzt.
		add_filter( 'mtm_head_meta_tags', array( __CLASS__, 'filter_mtm_tags' ), 99 );

		// Subtask 10 / Stufe 1: Das komplette Landing-CSS entsteht bisher erst
		// zur Laufzeit in hs-landing.js -- vier per JavaScript injizierte
		// <style>-Bloecke, zusammen rund 31 KB mit 152 hs-*-Klassen. Der
		// serverseitige Snapshot verwendet 138 dieser Klassen, hat aber keine
		// einzige davon als CSS-Regel im Dokument und wird deshalb bis zum
		// Ausfuehren des Skripts voellig unformatiert dargestellt.
		//
		// Wir geben dieselben Bloecke mit denselben IDs serverseitig aus. Alle
		// vier JS-Injektionen pruefen vorab per getElementById auf ihre ID und
		// ueberspringen sich dann selbst -- an hs-landing.js muss dafuer NICHTS
		// geaendert werden.
		//
		// PRIORITAET 999 IST WICHTIG: Die JS-Bloecke landen per
		// document.head.appendChild() hinter allen Theme-Stylesheets. Bei
		// gleicher Spezifitaet entscheidet die Reihenfolge, wir muessen also
		// ebenfalls hinter wp_print_styles (wp_head-Prioritaet 8) landen, sonst
		// koennte Flatsome einzelne Regeln ueberschreiben.
		add_action( 'wp_head', array( __CLASS__, 'render_landing_css' ), 999 );

		// Subtask 10 / Stufe 2: Zwei Korrekturen am ausgelieferten Snapshot,
		// damit der vorgerenderte Inhalt auch ohne ausgefuehrtes JavaScript
		// vollstaendig sichtbar ist -- also fuer KI-Crawler, SEO-Werkzeuge und
		// fuer die erste Sekunde eines normalen Seitenaufrufs.
		//
		// 1) hs-landing.js erzeugt die Key-Facts-Zaehler mit HARTCODIERTER 0 als
		//    Text und dem echten Wert in data-target (Zeile 1817). Ohne
		//    JavaScript stehen alle drei Kennzahlen deshalb dauerhaft auf 0.
		//    Wir setzen den Wert aus data-target als Text ein -- die
		//    Zaehleranimation bleibt unberuehrt, weil sie den Text ohnehin
		//    ueberschreibt, sobald sie laeuft.
		//
		// 2) Die Klasse fade-in setzt opacity:0 und wird erst per
		//    IntersectionObserver um "visible" ergaenzt (Zeile 2599). Im
		//    Snapshot sind davon 124 Elemente betroffen, darunter alle
		//    Top-Wettbewerbs-Karten. Wir entfernen die Klasse aus dem
		//    ausgelieferten Markup; das live neu gerenderte Markup von
		//    hs-landing.js bekommt sie weiterhin, die Animation bleibt also
		//    fuer Besucher mit JavaScript vollstaendig erhalten.
		//
		// Prioritaet 20: nach do_blocks (9), wpautop (10) und den Shortcodes (11).
		add_filter( 'the_content', array( __CLASS__, 'filter_snapshot_markup' ), 20 );

		// Cache invalidieren, sobald eine Seite gespeichert wird (z.B. durch
		// den Snapshot-Writeback -- dann kann sich das hs-root-Markup aendern).
		add_action( 'save_post', array( __CLASS__, 'flush_cache_for_post' ), 10, 1 );
	}

	/* ------------------------------------------------------------------ *
	 * Ausgabe
	 * ------------------------------------------------------------------ */

	public static function render_head() {
		$ctx = self::get_root_context();
		if ( ! $ctx ) {
			return; // Keine Provisioner-Landingpage -> nichts anfassen.
		}

		// Zuerst die Preloads: Sie sollen so weit oben im <head> stehen wie
		// moeglich, damit der Browser die Anfragen sofort startet.
		self::render_preloads( $ctx );

		$meta = self::get_meta( $ctx );

		$out = array();

		// --- Subtask 2: Description ---
		if ( '' !== $meta['description'] ) {
			$out[] = '<meta name="description" content="' . esc_attr( $meta['description'] ) . '" />';
		}

		// --- Subtask 3: Open Graph (property=, nicht name=) ---
		$out[] = '<meta property="og:type" content="website" />';
		$out[] = '<meta property="og:site_name" content="' . esc_attr( self::SITE_NAME ) . '" />';
		$out[] = '<meta property="og:locale" content="' . esc_attr( $meta['og_locale'] ) . '" />';

		if ( '' !== $meta['title'] ) {
			$out[] = '<meta property="og:title" content="' . esc_attr( $meta['title'] ) . '" />';
		}
		if ( '' !== $meta['description'] ) {
			$out[] = '<meta property="og:description" content="' . esc_attr( $meta['description'] ) . '" />';
		}
		if ( '' !== $meta['url'] ) {
			$out[] = '<meta property="og:url" content="' . esc_url( $meta['url'] ) . '" />';
		}
		if ( '' !== $meta['image'] ) {
			$out[] = '<meta property="og:image" content="' . esc_url( $meta['image'] ) . '" />';
			$out[] = '<meta property="og:image:alt" content="' . esc_attr( $meta['image_alt'] ) . '" />';
			if ( $meta['image_w'] && $meta['image_h'] ) {
				$out[] = '<meta property="og:image:width" content="' . (int) $meta['image_w'] . '" />';
				$out[] = '<meta property="og:image:height" content="' . (int) $meta['image_h'] . '" />';
			}
		}

		// --- Subtask 3: Twitter Cards (hier ist name= korrekt) ---
		// (JSON-LD folgt weiter unten nach den Meta-Tags.)
		// summary_large_image nur, wenn tatsaechlich ein Bild vorliegt --
		// sonst zeigt X/Twitter eine leere graue Flaeche.
		$out[] = '<meta name="twitter:card" content="'
			. ( '' !== $meta['image'] ? 'summary_large_image' : 'summary' ) . '" />';

		if ( '' !== $meta['title'] ) {
			$out[] = '<meta name="twitter:title" content="' . esc_attr( $meta['title'] ) . '" />';
		}
		if ( '' !== $meta['description'] ) {
			$out[] = '<meta name="twitter:description" content="' . esc_attr( $meta['description'] ) . '" />';
		}
		if ( '' !== $meta['image'] ) {
			$out[] = '<meta name="twitter:image" content="' . esc_url( $meta['image'] ) . '" />';
			$out[] = '<meta name="twitter:image:alt" content="' . esc_attr( $meta['image_alt'] ) . '" />';
		}

		echo "\n" . '<!-- HEIM:SPIEL SEO Meta -->' . "\n";
		echo implode( "\n", $out ) . "\n";

		// --- Subtask 4: JSON-LD ---
		$jsonld = self::build_jsonld( $ctx, $meta );
		if ( $jsonld ) {
			// Wichtig: KEIN JSON_UNESCAPED_SLASHES. Escapte Slashes verhindern,
			// dass ein "</script>" in einem Textfeld den Script-Block vorzeitig
			// beendet -- das ist der Standard-XSS-Vektor bei JSON-LD.
			$json = wp_json_encode( $jsonld, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
			if ( $json ) {
				echo '<script type="application/ld+json" id="hs-seo-jsonld-server">' . "\n";
				echo $json . "\n";
				echo '</script>' . "\n";
			}
		}

		echo '<!-- / HEIM:SPIEL SEO Meta -->' . "\n";
	}

	/**
	 * Subtask 11: Preload-Hints fuer die JSON-Endpunkte, die hs-landing.js
	 * braucht, bevor es den Inhalt rendern kann.
	 *
	 * WARUM: Gemessen an /de/fussball/ laufen die Datenabrufe in zwei Etappen
	 * hintereinander statt parallel. Zeile 117 holt Index und General Index
	 * gemeinsam (rund 1.334 ms), erst danach folgt in renderCluster() Zeile 371
	 * der Coverage-Abruf (weitere rund 1.527 ms). Zusammen etwa 2,9 Sekunden,
	 * in denen der Loader steht.
	 *
	 * Die Reihenfolge im JavaScript ist nachvollziehbar, weil useCoverageMode
	 * vom Index abhaengt -- man braucht ihn, um zu ENTSCHEIDEN, ob Coverage
	 * noetig ist. Zum STARTEN der Anfrage braucht man ihn nicht: Der Sport steht
	 * im data-bundle-Attribut, und serverseitig wissen wir zusaetzlich aus dem
	 * Snapshot, ob die Seite ueberhaupt im Coverage-Modus rendert.
	 *
	 * Mit den Preload-Hints starten alle drei Anfragen beim Parsen des <head> --
	 * parallel und bevor die rund 177 KB Inline-JavaScript geparst sind. Wenn
	 * hs-landing.js sie spaeter anfordert, liegen sie im Browser-Cache.
	 *
	 * WICHTIG -- die URLs muessen ZEICHENGENAU denen im JavaScript entsprechen,
	 * sonst laedt der Browser zweimal statt einmal:
	 *   - window.HS_CACHE_BASE ist nicht gesetzt, die URLs sind also relativ zur
	 *     Domainwurzel und haben KEIN Sprachpraefix (/wp-json/..., nicht
	 *     /de/wp-json/...).
	 *   - Die Sprachweiche entspricht isDE aus dem Skript, das pageLang aus
	 *     document.documentElement.lang liest -- deckungsgleich mit WPML.
	 *   - Der Coverage-Key ist slugify(data-bundle). Fuer /de/fussball/ ist das
	 *     "football", NICHT "fussball" -- beide Endpunkte existieren und liefern
	 *     unterschiedliche Daten.
	 *
	 * Kein crossorigin-Attribut: Die Abrufe sind gleichoriginig, fetch() nutzt
	 * dort credentials "same-origin". Mit crossorigin wuerde der Modus nicht
	 * uebereinstimmen und der Browser die Daten doppelt holen.
	 *
	 * @param array $ctx Kontext aus get_root_context().
	 */
	private static function render_preloads( $ctx ) {
		$base = '/wp-json/hs-cache/v1/';
		$lang = self::current_lang();

		$urls = ( 'de' === $lang )
			? array( $base . 'indexDe', $base . 'generalIndexDe' )
			: array( $base . 'index', $base . 'generalIndex' );

		// Coverage nur dort, wo die Seite tatsaechlich im Coverage-Modus rendert.
		// Belegt wird das am gespeicherten Snapshot: Cluster-Seiten mit
		// Disziplin-Zwischenebene (z.B. Wintersport) und Detailseiten holen
		// diesen Endpunkt nicht -- ein Preload waere dort verschwendet und wuerde
		// in der Konsole als ungenutzt gemeldet.
		$content       = isset( $ctx['content'] ) ? (string) $ctx['content'] : '';
		$uses_coverage = (
			false !== strpos( $content, 'hs-coverage-more-item' ) ||
			false !== strpos( $content, 'hs-top-competitions' )
		);

		if ( $uses_coverage ) {
			$slug = self::slugify( isset( $ctx['bundle'] ) ? $ctx['bundle'] : '' );
			// Sicherheitsnetz: nur ausgeben, wenn der Slug wirklich sauber ist.
			if ( '' !== $slug && preg_match( '/^[a-z0-9_-]+$/', $slug ) ) {
				$urls[] = $base . 'coverage/' . $slug;
			}
		}

		echo "\n<!-- HEIM:SPIEL Preload der Landing-Daten -->\n";
		foreach ( $urls as $url ) {
			echo '<link rel="preload" href="' . esc_url( $url ) . '" as="fetch" />' . "\n";
		}
	}

	/**
	 * Spiegelt slugify() aus hs-landing.js.
	 *
	 * Bewusst ohne iconv-Transliteration: Die Ausgabe muss zeichengenau der des
	 * Skripts entsprechen, und iconv liefert je nach Locale unterschiedliche
	 * Ergebnisse. Die im Sheet vorkommenden Sonderfaelle sind die deutschen
	 * Umlaute und das Eszett -- genau die behandelt das Skript ebenfalls
	 * explizit, bevor es normalisiert.
	 *
	 * @param  string $str Rohwert aus data-bundle.
	 * @return string
	 */
	private static function slugify( $str ) {
		$s = self::lower( trim( (string) $str ) );

		$s = str_replace(
			array( 'ä', 'ö', 'ü', 'ß' ),
			array( 'ae', 'oe', 'ue', 'ss' ),
			$s
		);

		$s = preg_replace( '/[^a-z0-9_-]+/', '-', $s );
		$s = preg_replace( '/-+/', '-', $s );

		return is_string( $s ) ? trim( $s, '-' ) : '';
	}

	/**
	 * Subtask 6: Setzt den <title> serverseitig.
	 *
	 * Vorher kam der Titel aus dem WordPress-Seitentitel ("Fussball Paket –
	 * HEIMSPIEL") -- generisch und ohne Bezug zur eigentlichen Leistung. Der
	 * beschreibende Titel existierte nur clientseitig ueber document.title und
	 * war damit fuer Crawler unzuverlaessig.
	 *
	 * Quelle ist derselbe Wert wie fuer og:title (seoTitle aus dem Sheet, sonst
	 * heroHeadline). Der Markenname wird als Suffix ergaenzt -- aber nur, wenn
	 * er nicht schon enthalten ist und das Ergebnis nicht zu lang wird.
	 *
	 * @param  string $title Der von WordPress vorgesehene Titel.
	 * @return string
	 */
	public static function filter_document_title( $title ) {
		// Rekursionsschutz: get_meta() darf keinen Titel-Filter erneut ausloesen.
		static $running = false;
		if ( $running ) {
			return $title;
		}

		$ctx = self::get_root_context();
		if ( ! $ctx ) {
			return $title; // Keine Landingpage -> WordPress-Standard behalten.
		}

		$running = true;
		$meta    = self::get_meta( $ctx );
		$running = false;

		$base = isset( $meta['title'] ) ? trim( (string) $meta['title'] ) : '';
		if ( '' === $base ) {
			return $title;
		}

		// Markenname nur anhaengen, wenn er noch fehlt. Geprueft wird ohne
		// Sonderzeichen, damit auch die Schreibweise "HEIMSPIEL" (ohne Doppel-
		// punkt, so wie im WordPress-Sitename) als vorhanden erkannt wird.
		$brand_folded = self::fold( self::SITE_NAME );
		if ( false !== strpos( self::fold( $base ), $brand_folded ) ) {
			return $base;
		}

		$suffix = ( isset( $meta['og_locale'] ) && 'de_DE' === $meta['og_locale'] )
			? self::TITLE_SUFFIX_DE
			: self::TITLE_SUFFIX_EN;

		$with_brand = $base . self::TITLE_SEPARATOR . $suffix;

		// Sicherheitsnetz: Wenn der Titel mit Marke zu lang wuerde, hat der
		// inhaltliche Teil Vorrang -- Google schneidet sonst genau dort ab.
		if ( self::len( $with_brand ) > self::TITLE_MAX ) {
			return $base;
		}

		return $with_brand;
	}

	/**
	 * Entfernt og:*- und twitter:*-Tags aus der Meta-Tag-Manager-Ausgabe,
	 * aber ausschliesslich auf #hs-root-Seiten. Alle uebrigen MTM-Tags
	 * (z.B. google-site-verification) bleiben erhalten.
	 *
	 * @param  array $tags Liste von MTM_Tag-Objekten.
	 * @return array
	 */
	public static function filter_mtm_tags( $tags ) {
		if ( ! self::get_root_context() ) {
			return $tags;
		}

		$kept = array();
		foreach ( (array) $tags as $tag ) {
			$value = '';
			if ( is_object( $tag ) && isset( $tag->value ) ) {
				$value = strtolower( trim( (string) $tag->value ) );
			} elseif ( is_array( $tag ) && isset( $tag['value'] ) ) {
				$value = strtolower( trim( (string) $tag['value'] ) );
			}

			if ( 0 === strpos( $value, 'og:' ) || 0 === strpos( $value, 'twitter:' ) ) {
				continue;
			}
			$kept[] = $tag;
		}

		return $kept;
	}

	/* ------------------------------------------------------------------ *
	 * Subtask 4: JSON-LD
	 * ------------------------------------------------------------------ */

	/**
	 * Baut einen zusammenhaengenden @graph statt mehrerer isolierter Bloecke.
	 * Ueber @id-Referenzen weiss Google dadurch, dass Organization, WebSite,
	 * WebPage und das Produkt zur selben Entitaet gehoeren.
	 *
	 * @return array|null
	 */
	/**
	 * Obergrenze fuer die ItemList. Schutz gegen unerwartet grosse Snapshots;
	 * die Fussball-Seite liegt mit 232 Eintraegen klar darunter.
	 */
	const ITEMLIST_MAX = 400;

	private static function build_jsonld( $ctx, $meta ) {
		if ( '' === $meta['url'] ) {
			return null;
		}

		$lang = ( 'de_DE' === $meta['og_locale'] ) ? 'de' : 'en';

		$org_id  = self::SITE_URL . '/#organization';
		$site_id = self::SITE_URL . '/#website';
		$page_id = $meta['url'] . '#webpage';

		$graph = array();

		// --- Organization ---
		$graph[] = array(
			'@type' => 'Organization',
			'@id'   => $org_id,
			'name'  => self::SITE_NAME,
			'url'   => self::SITE_URL . '/',
		);

		// --- WebSite ---
		$graph[] = array(
			'@type'     => 'WebSite',
			'@id'       => $site_id,
			'name'      => self::SITE_NAME,
			'url'       => self::SITE_URL . '/',
			'publisher' => array( '@id' => $org_id ),
		);

		// --- WebPage ---
		$webpage = array(
			'@type'      => 'WebPage',
			'@id'        => $page_id,
			'url'        => $meta['url'],
			'name'       => $meta['title'],
			'isPartOf'   => array( '@id' => $site_id ),
			'inLanguage' => $lang,
		);
		if ( '' !== $meta['description'] ) {
			$webpage['description'] = $meta['description'];
		}
		if ( '' !== $meta['image'] ) {
			$webpage['primaryImageOfPage'] = array(
				'@type' => 'ImageObject',
				'url'   => $meta['image'],
			);
		}

		// --- ItemList der abgedeckten Wettbewerbe ---
		//
		// Quelle ist das Snapshot-Markup, nicht das Sheet: Damit kann die Liste
		// nicht von dem abweichen, was Nutzer auf der Seite sehen.
		$competitions = self::competitions_from_snapshot(
			isset( $ctx['content'] ) ? (string) $ctx['content'] : ''
		);
		$list_id  = $meta['url'] . '#competitions';
		$itemlist = self::build_competition_itemlist( $competitions, $list_id );
		if ( $itemlist ) {
			$webpage['mainEntity'] = array( '@id' => $list_id );
		}

		$graph[] = $webpage;

		if ( $itemlist ) {
			$graph[] = $itemlist;
		}

		// --- SoftwareApplication (Widget / JSON-API-Produkt) ---
		//
		// Bewusst OHNE "offers": Ein Offer ohne "price" ist unvollstaendig und
		// wird von Validatoren als Fehler gemeldet. Die bisherige JS-Variante
		// gab genau so ein Offer aus (nur priceCurrency + availability).
		// Solange keine Preise veroeffentlicht werden, ist Weglassen korrekter
		// als ein unvollstaendiges Preisobjekt.
		$app = array(
			'@type'                  => 'SoftwareApplication',
			'@id'                    => $meta['url'] . '#software',
			'name'                   => $meta['title'],
			'url'                    => $meta['url'],
			'applicationCategory'    => 'BusinessApplication',
			'applicationSubCategory' => 'Sports Data API',
			'operatingSystem'        => 'Web',
			'inLanguage'             => $lang,
			'provider'               => array( '@id' => $org_id ),
			'isPartOf'               => array( '@id' => $page_id ),
		);
		if ( '' !== $meta['description'] ) {
			$app['description'] = $meta['description'];
		}
		if ( '' !== $meta['image'] ) {
			$app['image'] = $meta['image'];
		}
		$graph[] = $app;

		// --- FAQPage (nur echte, sichtbare Fragen) ---
		$faq = isset( $meta['faq'] ) && is_array( $meta['faq'] ) ? $meta['faq'] : array();
		if ( ! empty( $faq ) ) {
			$questions = array();
			foreach ( $faq as $item ) {
				$questions[] = array(
					'@type'          => 'Question',
					'name'           => $item['q'],
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $item['a'],
					),
				);
			}
			$graph[] = array(
				'@type'      => 'FAQPage',
				'@id'        => $meta['url'] . '#faq',
				'isPartOf'   => array( '@id' => $page_id ),
				'inLanguage' => $lang,
				'mainEntity' => $questions,
			);
		}

		return array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);
	}

	/**
	 * Liest Frage/Antwort-Paare aus dem gespeicherten Snapshot-Markup.
	 *
	 * Warum aus dem Markup und nicht aus den Sheet-Templates neu berechnet?
	 * Google verlangt, dass FAQPage-Inhalte auf der Seite SICHTBAR sind. Wuerde
	 * PHP die seoFaqTpl*-Templates eigenstaendig neu befuellen, koennte das
	 * Ergebnis vom tatsaechlich gerenderten Text abweichen (andere Zahlen,
	 * andere Wettbewerbsliste, fehlende Variablen) -- das waere ein
	 * Richtlinienverstoss. Der Snapshot ist die einzige Quelle, die exakt
	 * dem entspricht, was Nutzer sehen.
	 *
	 * Der Accordion-Block enthaelt zwei Arten von Eintraegen:
	 *   Slot 1-9  : USP-Aussagen ("Flexible Integration") -- KEINE Fragen.
	 *   Slot 10+  : echte SEO-Fragen ("Wie viele Wettbewerbe ...?").
	 * Nur letztere duerfen ins FAQPage-Schema. Als Kriterium dient das
	 * Fragezeichen -- geprueft an DE-, EN- und Detailseiten, trennt dort
	 * zuverlaessig.
	 *
	 * @return array Liste von array( 'q' => ..., 'a' => ... )
	 */
	private static function faq_items_from_snapshot( $content ) {
		// Muster: faq-item-Container -> faq-trigger-title (Frage) -> faq-desc (Antwort).
		$pattern = '/<div[^>]*class=["\']faq-item["\'][^>]*>'
			. '.*?class=["\']faq-trigger-title["\'][^>]*>(.*?)<\/span>'
			. '.*?class=["\']faq-desc["\'][^>]*>(.*?)<\/p>/s';

		if ( ! preg_match_all( $pattern, (string) $content, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$items = array();
		foreach ( $matches as $m ) {
			$question = self::clean( $m[1] );
			$answer   = self::clean( $m[2] );

			if ( '' === $question || '' === $answer ) {
				continue;
			}
			if ( false === strpos( $question, '?' ) ) {
				continue; // USP-Aussage, keine Frage -> nicht ins FAQPage-Schema.
			}

			$items[] = array(
				'q' => $question,
				'a' => $answer,
			);
		}

		return $items;
	}

	/* ---------------------------------------------------------------------- *
	 * Wettbewerbe als ItemList
	 * ---------------------------------------------------------------------- */

	/**
	 * Wikidata-Zuordnung fuer eindeutig identifizierbare Wettbewerbe.
	 *
	 * Schluessel ist ABSICHTLICH das Paar "Gruppe|Name" und nicht der Name
	 * allein: "Premier League" steht auf der Fussball-Seite elf Mal (England,
	 * Russland, Ukraine, Wales, Aegypten, Saudi-Arabien ...), "Primera Division"
	 * zehn Mal, "Relegation" sechzehn Mal. Ein Verweis auf den Namen allein
	 * waere in den meisten Faellen die falsche Entitaet.
	 *
	 * Beide Sprachfassungen sind enthalten, weil der Snapshot die Gruppen
	 * uebersetzt ("Deutschland" / "Germany") und bei drei Wettbewerben auch den
	 * Namen ("DFB-Pokal" / "DFB Cup", "EM" / "European Championship",
	 * "WM" / "World Cup").
	 *
	 * Jede ID wurde gegen Wikidata geprueft: Label und Eigenschaft P17 ("Staat")
	 * muessen zum Land der Karte passen. Faelle ohne eindeutige Zuordnung fehlen
	 * bewusst -- ein falscher sameAs-Verweis richtet mehr Schaden an als ein
	 * fehlender.
	 *
	 * @return array
	 */
	private static function competition_wikidata_map() {
		return array(
			'Vereinigtes Königreich|Premier League'   => 'Q9448',
			'United Kingdom|Premier League'           => 'Q9448',
			'Vereinigtes Königreich|Championship'     => 'Q19510',
			'United Kingdom|Championship'             => 'Q19510',
			'Vereinigtes Königreich|League One'       => 'Q19565',
			'United Kingdom|League One'               => 'Q19565',
			'Vereinigtes Königreich|FA Cup'           => 'Q11151',
			'United Kingdom|FA Cup'                   => 'Q11151',
			'Vereinigtes Königreich|League Cup'       => 'Q11152',
			'United Kingdom|League Cup'               => 'Q11152',
			'Deutschland|Bundesliga'                  => 'Q82595',
			'Germany|Bundesliga'                      => 'Q82595',
			'Deutschland|2. Bundesliga'               => 'Q152665',
			'Germany|2. Bundesliga'                   => 'Q152665',
			'Deutschland|3. Liga'                     => 'Q154069',
			'Germany|3. Liga'                         => 'Q154069',
			'Deutschland|DFB-Pokal'                   => 'Q150880',
			'Germany|DFB Cup'                         => 'Q150880',
			'Spanien|Primera División'                => 'Q324867',
			'Spain|Primera División'                  => 'Q324867',
			'Spanien|Segunda División'                => 'Q35615',
			'Spain|Segunda División'                  => 'Q35615',
			'Spanien|Copa del Rey'                    => 'Q483794',
			'Spain|Copa del Rey'                      => 'Q483794',
			'Italien|Serie A'                         => 'Q15804',
			'Italy|Serie A'                           => 'Q15804',
			'Italien|Serie B'                         => 'Q194052',
			'Italy|Serie B'                           => 'Q194052',
			'Italien|Coppa Italia'                    => 'Q169918',
			'Italy|Coppa Italia'                      => 'Q169918',
			'Frankreich|Ligue 1'                      => 'Q13394',
			'France|Ligue 1'                          => 'Q13394',
			'Frankreich|Ligue 2'                      => 'Q217374',
			'France|Ligue 2'                          => 'Q217374',
			'Frankreich|Coupe de France'              => 'Q212412',
			'France|Coupe de France'                  => 'Q212412',
			'Niederlande|Eredivisie'                  => 'Q167541',
			'Netherlands|Eredivisie'                  => 'Q167541',
			'Niederlande|Eerste Divisie'              => 'Q610823',
			'Netherlands|Eerste Divisie'              => 'Q610823',
			'Niederlande|KNVB beker'                  => 'Q216858',
			'Netherlands|KNVB beker'                  => 'Q216858',
			'Portugal|Primeira Liga'                  => 'Q182994',
			'Belgien|Pro League'                      => 'Q216022',
			'Belgium|Pro League'                      => 'Q216022',
			'Türkei|SüperLig'                         => 'Q485568',
			'Türkiye|SüperLig'                        => 'Q485568',
			'Schottland|Premiership'                  => 'Q14377162',
			'Schottland|Championship'                 => 'Q14468438',
			'Österreich|Bundesliga'                   => 'Q219592',
			'Austria|Bundesliga'                      => 'Q219592',
			'Schweiz|Super League'                    => 'Q202699',
			'Switzerland|Super League'                => 'Q202699',
			'Vereinigte Staaten|Major League Soccer'  => 'Q18543',
			'United States|Major League Soccer'       => 'Q18543',
			'Brasilien|Série A'                       => 'Q206813',
			'Brazil|Série A'                          => 'Q206813',
			'Argentinien|Primera División'            => 'Q223170',
			'Argentina|Primera División'              => 'Q223170',
			'Mexiko|Primera División'                 => 'Q764690',
			'Mexico|Primera División'                 => 'Q764690',
			'Japan|J1 League'                         => 'Q276445',
			'Saudi-Arabien|Saudi Pro League'          => 'Q255633',
			'Saudi Arabia|Saudi Pro League'           => 'Q255633',
			'UEFA|Champions League'                   => 'Q18756',
			'UEFA|Europa League'                      => 'Q18760',
			'UEFA|EM'                                 => 'Q260858',
			'UEFA|European Championship'              => 'Q260858',
			'FIFA|WM'                                 => 'Q19317',
			'FIFA|World Cup'                          => 'Q19317',
			'CONMEBOL|Copa América'                   => 'Q178750',
			'CONMEBOL|Copa Libertadores'              => 'Q184795',
			'CONMEBOL|Copa Sudamericana'              => 'Q60585',
			'CONCACAF|Gold Cup'                       => 'Q189327',
			'AFC|Asian Cup'                           => 'Q157894',
			'CAF|Africa Cup'                          => 'Q83145',
		);
	}

	/**
	 * Verbaende. Fuer diese Gruppen wird "memberOf" gesetzt statt "areaServed"
	 * -- ein Verband ist keine geografische Region.
	 *
	 * @return array
	 */
	private static function competition_federations() {
		return array( 'FIFA', 'UEFA', 'CONMEBOL', 'CONCACAF', 'AFC', 'CAF', 'OFC' );
	}

	/**
	 * Liest die Wettbewerbe samt zugehoeriger Gruppe aus dem Snapshot-Markup.
	 *
	 * Quelle ist bewusst das Markup und nicht das Sheet -- wie bei der FAQ. So
	 * kann die ItemList nicht von dem abweichen, was Nutzer sehen.
	 *
	 * Getrennt wird per explode() am Karten-Marker, NICHT per Regex ueber die
	 * div-Ebenen: Die Karte enthaelt verschachtelte divs, ein nicht-greedy
	 * Muster bricht dort zu frueh ab und verliert alle Zeilen.
	 *
	 * Das Muster fuer den Namen endet auf "</span></td>" und nicht auf dem
	 * ersten "</span>". Grund: Bei Zeilen mit Flaggen- oder Globus-Icon steht
	 * dort ein verschachteltes span -- sonst faengt die Klammer nur das Icon und
	 * der Name geht verloren (42 von 236 Zeilen im Test).
	 *
	 * @param  string $content Snapshot-Markup aus dem Post-Content.
	 * @return array           Liste von array( 'group' => ..., 'name' => ... )
	 */
	private static function competitions_from_snapshot( $content ) {
		$content = (string) $content;
		if ( false === strpos( $content, 'hs-tc-card-wrap' ) ) {
			return array();
		}

		$karten = explode( '<div class="hs-tc-card-wrap">', $content );
		array_shift( $karten );

		$row_pattern = '/<td[^>]*class="hs-event-name"[^>]*>\s*'
			. '<span[^>]*class="hs-event-name-inner"[^>]*>(.*?)<\/span>\s*<\/td>/s';
		$grp_pattern = '/class="hs-card-sport"[^>]*>(.*?)<\/span>/s';

		$items = array();
		$seen  = array();

		foreach ( $karten as $karte ) {
			$gruppe = '';
			if ( preg_match( $grp_pattern, $karte, $gm ) ) {
				$gruppe = self::clean( $gm[1] );
			}

			if ( ! preg_match_all( $row_pattern, $karte, $rm ) ) {
				continue;
			}

			foreach ( $rm[1] as $roh ) {
				$name = self::clean( $roh );
				if ( '' === $name ) {
					continue;
				}
				// Schutz gegen unausgewertete JS-Fragmente im Markup.
				if ( false !== strpos( $name, "' +" ) || false !== strpos( $gruppe, "' +" ) ) {
					continue;
				}

				$key = $gruppe . '|' . $name;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;

				$items[] = array(
					'group' => $gruppe,
					'name'  => $name,
				);

				if ( count( $items ) >= self::ITEMLIST_MAX ) {
					return $items;
				}
			}
		}

		return $items;
	}

	/**
	 * Baut den ItemList-Knoten. Ohne Eintraege wird null geliefert, damit auf
	 * Detailseiten (andere Markup-Struktur) kein leerer Knoten entsteht.
	 *
	 * Kein Rich Result zu erwarten -- der Nutzen liegt in der Zuordnung der
	 * Wettbewerbe zu bekannten Entitaeten und darin, dass KI-Suchsysteme
	 * JSON-LD auswerten.
	 *
	 * @param  array  $items   Ergebnis von competitions_from_snapshot().
	 * @param  string $list_id @id des Knotens.
	 * @return array|null
	 */
	private static function build_competition_itemlist( $items, $list_id ) {
		if ( empty( $items ) ) {
			return null;
		}

		$map         = self::competition_wikidata_map();
		$federations = self::competition_federations();

		$elements = array();
		$pos      = 0;

		foreach ( $items as $item ) {
			$pos++;

			$org = array(
				'@type' => 'SportsOrganization',
				'name'  => $item['name'],
			);

			if ( '' !== $item['group'] ) {
				if ( in_array( $item['group'], $federations, true ) ) {
					$org['memberOf'] = array(
						'@type' => 'SportsOrganization',
						'name'  => $item['group'],
					);
				} else {
					$org['areaServed'] = $item['group'];
				}
			}

			$key = $item['group'] . '|' . $item['name'];
			if ( isset( $map[ $key ] ) ) {
				$org['sameAs'] = 'https://www.wikidata.org/wiki/' . $map[ $key ];
			}

			$elements[] = array(
				'@type'    => 'ListItem',
				'position' => $pos,
				'item'     => $org,
			);
		}

		return array(
			'@type'           => 'ItemList',
			'@id'             => $list_id,
			'numberOfItems'   => count( $elements ),
			'itemListOrder'   => 'https://schema.org/ItemListUnordered',
			'itemListElement' => $elements,
		);
	}

	/* ------------------------------------------------------------------ *
	 * Erkennung der Provisioner-Landingpage
	 * ------------------------------------------------------------------ */

	/**
	 * Liest den #hs-root-Container aus dem Post-Content und extrahiert
	 * data-type / data-bundle / data-discipline. Der Snapshot-Writeback
	 * speichert dieses Markup im Post-Content, deshalb ist es serverseitig
	 * ohne JavaScript verfuegbar.
	 *
	 * @return array|null
	 */
	private static function get_root_context() {
		if ( self::$ctx_done ) {
			return self::$ctx_cache;
		}
		self::$ctx_done = true;

		if ( is_admin() || ! is_singular() ) {
			return self::$ctx_cache = null;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return self::$ctx_cache = null;
		}

		$content = (string) $post->post_content;
		if ( false === stripos( $content, 'hs-root' ) ) {
			return self::$ctx_cache = null;
		}

		if ( ! preg_match( '/<div[^>]*id=["\']hs-root["\'][^>]*>/i', $content, $m ) ) {
			return self::$ctx_cache = null;
		}

		$tag = $m[0];

		self::$ctx_cache = array(
			'post_id'    => (int) $post->ID,
			'type'       => strtolower( self::attr( $tag, 'data-type' ) ),
			'bundle'     => self::attr( $tag, 'data-bundle' ),
			'discipline' => self::attr( $tag, 'data-discipline' ),
			'content'    => $content,
		);

		return self::$ctx_cache;
	}

	/**
	 * Liest ein einzelnes Attribut aus einem HTML-Tag-String.
	 */
	private static function attr( $tag, $name ) {
		$pattern = '/\b' . preg_quote( $name, '/' ) . '=["\']([^"\']*)["\']/i';
		return preg_match( $pattern, $tag, $m ) ? trim( $m[1] ) : '';
	}

	/* ------------------------------------------------------------------ *
	 * Meta-Daten ermitteln
	 * ------------------------------------------------------------------ */

	/**
	 * @return array description, title, url, image, image_alt, image_w, image_h, og_locale
	 */
	private static function get_meta( $ctx ) {
		$lang      = self::current_lang();
		// Schluessel v2, weil das gecachte Array in v1.2.0 ein zusaetzliches
		// "faq"-Feld enthaelt -- so werden alte Caches nicht falsch gelesen.
		$cache_key = 'hs_seo_meta2_' . $ctx['post_id'] . '_' . $lang;

		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rows = self::fetch_index_rows( $lang );
		$row  = self::find_row( $rows, $ctx );

		$description = '';
		$title       = '';
		$image       = '';

		if ( $row ) {
			$description = self::val( $row, self::FIELD_DESC );
			if ( '' === $description ) {
				// Fallback aus dem description-Feld -- ueberspringt gezielt den
				// einleitenden Boilerplate-Satz, der in ALLEN Index-Zeilen
				// identisch ist und sonst zu identischen Descriptions auf
				// allen Seiten fuehren wuerde.
				$description = self::build_fallback( $row );
			}

			$title = self::val( $row, self::FIELD_TITLE );
			if ( '' === $title ) {
				$title = self::val( $row, self::FIELD_HEADLINE );
			}

			// Bevorzugt das im WP-Upload gecachte Bild (absolute heimspiel.de-URL).
			// Der Google-Drive-Thumbnail-Link taugt NICHT als og:image, weil
			// Social-Crawler dort keinen stabilen Content-Type erhalten.
			$image = self::val( $row, self::FIELD_IMAGE );
			if ( '' === $image || false === strpos( $image, 'heimspiel.de' ) ) {
				$image = self::hero_image_from_snapshot( $ctx['content'] );
			}
		}

		if ( '' === $title ) {
			// WICHTIG: hier NICHT wp_get_document_title() verwenden. Diese
			// Funktion loest pre_get_document_title aus, unser eigener
			// Title-Filter ruft aber get_meta() auf -- das waere eine
			// Endlosrekursion. get_the_title() umgeht den Filter.
			$title = self::clean( get_the_title( $ctx['post_id'] ) );
		}
		if ( '' === $image ) {
			$image = self::hero_image_from_snapshot( $ctx['content'] );
		}

		$dimensions = self::image_dimensions( $image );

		$meta = array(
			'description' => self::clean( $description ),
			'title'       => self::clean( $title ),
			'url'         => self::canonical_url( $ctx['post_id'] ),
			'image'       => $image,
			'image_alt'   => self::clean( $title ),
			'image_w'     => $dimensions[0],
			'image_h'     => $dimensions[1],
			'og_locale'   => ( 'de' === $lang ) ? 'de_DE' : 'en_US',
			'faq'         => self::faq_items_from_snapshot( $ctx['content'] ),
		);

		set_transient( $cache_key, $meta, self::CACHE_TTL );

		return $meta;
	}

	/**
	 * Liest das Hero-Bild direkt aus dem gespeicherten Snapshot-Markup.
	 * Das ist die verlaesslichste Quelle, weil es exakt das Bild ist, das
	 * Besucher oben auf der Seite sehen.
	 */
	private static function hero_image_from_snapshot( $content ) {
		if ( preg_match( '/<div[^>]*class=["\'][^"\']*hs-hero-bg[^"\']*["\'][^>]*>\s*<img[^>]*src=["\']([^"\']+)["\']/i', (string) $content, $m ) ) {
			return esc_url_raw( $m[1] );
		}
		return '';
	}

	/**
	 * Ermittelt Breite/Hoehe, falls das Bild in der Mediathek liegt.
	 * Facebook und LinkedIn rendern Previews schneller und stabiler, wenn
	 * die Dimensionen mitgeliefert werden.
	 *
	 * @return array [width, height] -- 0/0 wenn nicht ermittelbar.
	 */
	private static function image_dimensions( $url ) {
		if ( '' === $url ) {
			return array( 0, 0 );
		}

		$attachment_id = attachment_url_to_postid( $url );
		if ( $attachment_id ) {
			$src = wp_get_attachment_image_src( $attachment_id, 'full' );
			if ( is_array( $src ) && ! empty( $src[1] ) && ! empty( $src[2] ) ) {
				return array( (int) $src[1], (int) $src[2] );
			}
		}

		return array( 0, 0 );
	}

	private static function canonical_url( $post_id ) {
		$url = wp_get_canonical_url( $post_id );
		if ( ! $url ) {
			$url = get_permalink( $post_id );
		}
		return $url ? $url : '';
	}

	/**
	 * Sprachermittlung ueber WPML, mit Locale-Fallback.
	 */
	private static function current_lang() {
		$lang = apply_filters( 'wpml_current_language', null );

		if ( ! $lang ) {
			$lang = get_locale();
		}

		return strtolower( substr( (string) $lang, 0, 2 ) );
	}

	/**
	 * Holt die Index-Zeilen ueber den internen REST-Aufruf des bestehenden
	 * hs-cache-Plugins. rest_do_request() laeuft im gleichen PHP-Prozess --
	 * kein zusaetzlicher HTTP-Roundtrip, und der vorhandene Cache-Layer des
	 * Plugins wird mitgenutzt.
	 *
	 * @return array Liste von Zeilen mit normalisierten Schluesseln.
	 */
	private static function fetch_index_rows( $lang ) {
		$route = ( 'de' === $lang )
			? '/hs-cache/v1/indexDe'
			: '/hs-cache/v1/index';

		$response = rest_do_request( new WP_REST_Request( 'GET', $route ) );

		if ( is_wp_error( $response ) || $response->is_error() ) {
			return array();
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return array();
		}

		// Der Endpoint liefert { "indexDe": [ ... ] } bzw. { "index": [ ... ] }.
		$rows = array();
		if ( isset( $data[0] ) && is_array( $data[0] ) ) {
			$rows = $data;
		} else {
			foreach ( $data as $value ) {
				if ( is_array( $value ) ) {
					$rows = $value;
					break;
				}
			}
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = self::norm_keys( $row );
			}
		}

		return $out;
	}

	/**
	 * Spiegelt normKeys() aus hs-landing.js: Leerzeichen entfernen,
	 * snake_case aufloesen, dann komplett kleinschreiben. Dadurch treffen
	 * "discipline_key", "disciplineKey" und "disciplinekey" denselben Key.
	 */
	private static function norm_keys( $row ) {
		$out = array();

		foreach ( $row as $key => $value ) {
			$nk = preg_replace( '/\s+/', '', trim( (string) $key ) );
			$nk = str_replace( '_', '', $nk );
			$nk = strtolower( $nk );

			$out[ $nk ] = is_scalar( $value ) ? trim( (string) $value ) : '';
		}

		return $out;
	}

	private static function val( $row, $key ) {
		return isset( $row[ $key ] ) ? trim( (string) $row[ $key ] ) : '';
	}

	/**
	 * Zeilensuche -- spiegelt exakt die Logik aus hs-landing.js:
	 *
	 * detail : Treffer ueber disciplinekey.
	 * cluster: Phase 1 exakter Treffer (bundle / bundlename / disciplinekey),
	 *          Phase 2 Gruppenmitgliedschaft in kommagetrennter bundle-Liste.
	 *
	 * Die Zwei-Phasen-Suche ist wichtig, weil die US-Sports-Bundle-Zeile im
	 * Sheet vor den Einzelsport-Zeilen steht und sonst faelschlich gewinnt.
	 *
	 * @return array|null
	 */
	private static function find_row( $rows, $ctx ) {
		if ( empty( $rows ) ) {
			return null;
		}

		if ( 'detail' === $ctx['type'] ) {
			$needle = strtolower( $ctx['discipline'] );
			if ( '' === $needle ) {
				return null;
			}
			foreach ( $rows as $row ) {
				if ( strtolower( self::val( $row, 'disciplinekey' ) ) === $needle ) {
					return $row;
				}
			}
			return null;
		}

		$needle = strtolower( $ctx['bundle'] );
		if ( '' === $needle ) {
			return null;
		}

		// Phase 1: exakter Treffer.
		foreach ( $rows as $row ) {
			if ( 'cluster' !== strtolower( self::val( $row, 'type' ) ) ) {
				continue;
			}
			if (
				strtolower( self::val( $row, 'bundle' ) ) === $needle ||
				strtolower( self::val( $row, 'bundlename' ) ) === $needle ||
				strtolower( self::val( $row, 'disciplinekey' ) ) === $needle
			) {
				return $row;
			}
		}

		// Phase 2: Mitgliedschaft in kommagetrennter bundle-Liste.
		foreach ( $rows as $row ) {
			if ( 'cluster' !== strtolower( self::val( $row, 'type' ) ) ) {
				continue;
			}
			$parts = array_map( 'trim', explode( ',', strtolower( self::val( $row, 'bundle' ) ) ) );
			if ( in_array( $needle, $parts, true ) ) {
				return $row;
			}
		}

		return null;
	}

	/* ------------------------------------------------------------------ *
	 * Textaufbereitung
	 * ------------------------------------------------------------------ */

	/**
	 * Baut die Fallback-Description aus dem description-Feld.
	 *
	 * Wichtig (durch QA belegt): Satz 1 des description-Feldes ist in ALLEN
	 * 21 Index-Zeilen wortgleich ("HEIM:SPIEL ist ein zuverlaessiger Partner
	 * ..."). Wuerde man einfach von vorne kuerzen, bekaeme JEDE Landingpage
	 * dieselbe Description -- aus SEO-Sicht schlechter als gar keine.
	 *
	 * Deshalb: Einstieg beim ERSTEN Satz, der einen die Seite eindeutig
	 * identifizierenden Begriff enthaelt (bundleName / displayName / name /
	 * disciplineKey). Danach werden weitere ganze Saetze angehaengt, solange
	 * DESC_MAX nicht ueberschritten wird -- es wird also nie mitten im Satz
	 * abgeschnitten.
	 */
	private static function build_fallback( $row ) {
		$text = self::clean( self::val( $row, self::FIELD_DESC_FB ) );
		if ( '' === $text ) {
			return '';
		}

		$sentences = preg_split( '/(?<=[.!?])\s+/u', $text );
		$sentences = array_values( array_filter( array_map( 'trim', (array) $sentences ), 'strlen' ) );
		if ( empty( $sentences ) ) {
			return '';
		}

		$terms = array();
		foreach ( array( 'bundlename', 'displayname', 'name' ) as $key ) {
			$terms[] = self::val( $row, $key );
		}
		$terms[] = str_replace( '-', ' ', self::val( $row, 'disciplinekey' ) );

		$start = 0;
		foreach ( $sentences as $i => $sentence ) {
			$folded = self::fold( $sentence );
			foreach ( $terms as $term ) {
				$ft = self::fold( $term );
				if ( '' !== $ft && false !== strpos( $folded, $ft ) ) {
					$start = (int) $i;
					break 2;
				}
			}
		}

		$out   = '';
		$count = count( $sentences );
		for ( $i = $start; $i < $count; $i++ ) {
			$candidate = trim( $out . ' ' . $sentences[ $i ] );
			if ( self::len( $candidate ) > self::DESC_MAX ) {
				break;
			}
			$out = $candidate;
		}

		// Sonderfall: schon der erste relevante Satz ist laenger als DESC_MAX.
		if ( '' === $out ) {
			$slice = self::sub( $sentences[ $start ], 0, self::DESC_MAX );
			$pos   = self::rpos( $slice, ' ' );
			$out   = ( false !== $pos && $pos >= 80 )
				? trim( self::sub( $slice, 0, $pos ) ) . ' …'
				: trim( $slice ) . ' …';
		}

		return $out;
	}

	/**
	 * Normalisiert einen Begriff fuer den Vergleich: Umlaute/ss aufloesen,
	 * alles ausser a-z0-9 entfernen. Faengt die Schreibweisen-Inkonsistenz
	 * im Sheet ab ("Fußball" im bundleName vs. "Fussball" im Beschreibungstext).
	 */
	private static function fold( $text ) {
		$text = self::lower( (string) $text );
		$text = str_replace(
			array( 'ß', 'ä', 'ö', 'ü' ),
			array( 'ss', 'ae', 'oe', 'ue' ),
			$text
		);
		return preg_replace( '/[^a-z0-9]/', '', $text );
	}

	/**
	 * Entfernt HTML, dekodiert Entities, raeumt unsichtbare Sonderzeichen auf
	 * und normalisiert Whitespace.
	 *
	 * Das Entfernen der Zero-Width-Zeichen ist nicht kosmetisch: Die englische
	 * speed-skating-Beschreibung enthaelt 2x U+200B ("Speed[ZWSP][ZWSP]Skating"),
	 * was sonst sowohl die Begriffserkennung als auch das Snippet verfaelscht.
	 */
	private static function clean( $text ) {
		$text = (string) $text;
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}\x{2028}\x{2029}]/u', '', $text );
		$text = str_replace( "\xC2\xA0", ' ', $text ); // geschuetztes Leerzeichen
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( $text );
	}

	/* Multibyte-sichere Helfer mit Fallback, falls mbstring fehlt. */

	private static function len( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
	}

	private static function sub( $s, $start, $length = null ) {
		if ( function_exists( 'mb_substr' ) ) {
			return null === $length
				? mb_substr( $s, $start, null, 'UTF-8' )
				: mb_substr( $s, $start, $length, 'UTF-8' );
		}
		return null === $length ? substr( $s, $start ) : substr( $s, $start, $length );
	}

	private static function rpos( $haystack, $needle ) {
		return function_exists( 'mb_strrpos' )
			? mb_strrpos( $haystack, $needle, 0, 'UTF-8' )
			: strrpos( $haystack, $needle );
	}

	private static function lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	/* ------------------------------------------------------------------ *
	 * Cache
	 * ------------------------------------------------------------------ */

	public static function flush_cache_for_post( $post_id ) {
		$post_id = (int) $post_id;
		foreach ( array( 'de', 'en' ) as $lang ) {
			delete_transient( 'hs_seo_meta2_' . $post_id . '_' . $lang );
			delete_transient( 'hs_seo_meta_' . $post_id . '_' . $lang );  // Altlast v1.1
			delete_transient( 'hs_seo_desc_' . $post_id . '_' . $lang ); // Altlast v1.0
		}
	}

	/* -------------------------------------------------------------------- *
	 * Landing-CSS serverseitig aus hs-landing.css ausgeben
	 * -------------------------------------------------------------------- */

	/**
	 * Gibt das Stylesheet der Landingpages im <head> aus.
	 *
	 * Das komplette Styling entsteht in hs-landing.js erst zur Laufzeit, in vier
	 * per JavaScript injizierten <style>-Bloecken. Der serverseitige Snapshot
	 * verwendet 138 dieser Klassen, haette ohne diese Ausgabe aber keine
	 * einzige davon als Regel im Dokument und waere bis zum Ausfuehren des
	 * Skripts voellig unformatiert.
	 *
	 * Quelle ist ab v1.7.0 die Datei hs-landing.css neben diesem Plugin, nicht
	 * mehr ein eingebetteter Nowdoc-Block. Grund: Die Regeln lagen doppelt vor
	 * -- einmal hier, einmal in hs-landing.js -- und Aenderungen an der
	 * JS-Fassung blieben stillschweigend wirkungslos, weil injectStyles() sich
	 * selbst abschaltet. Jetzt gibt es genau eine Quelle, und zwar eine echte
	 * CSS-Datei statt PHP- oder JavaScript-Strings.
	 *
	 * Die drei leeren <style>-Elemente sind kein Versehen: hs-landing.js prueft
	 * vor jeder der vier Injektionen per getElementById auf die jeweilige ID.
	 * Die Regeln aller vier Bloecke stehen gesammelt in hs-landing.css, die
	 * uebrigen drei IDs muessen aber trotzdem im Dokument existieren, sonst
	 * injiziert das Skript seine eigenen Fassungen zusaetzlich.
	 *
	 * Fehlt die Datei, wird NICHTS ausgegeben. Dann findet injectStyles() seine
	 * ID nicht und uebernimmt wieder -- die Seite bleibt formatiert, lediglich
	 * der serverseitige Vorteil entfaellt. Ein fehlerhafter Deploy fuehrt also
	 * nicht zu einer unformatierten Seite.
	 */
	public static function render_landing_css() {
		$ctx = self::get_root_context();
		if ( ! $ctx ) {
			return; // Keine Provisioner-Landingpage -> nichts anfassen.
		}

		$css = self::landing_css();
		if ( '' === $css ) {
			return; // Datei fehlt -> hs-landing.js uebernimmt.
		}

		echo "\n<!-- HEIM:SPIEL Landing-CSS serverseitig -->\n";
		// phpcs:ignore WordPress.Security.EscapeOutput -- statisches, eigenes CSS.
		echo '<style id="hs-landing-styles">' . $css . "</style>\n";

		// Nur Platzhalter, damit die uebrigen JS-Injektionen ausgesetzt werden.
		foreach ( array( 'hs-tc-panel-style', 'hs-cluster-body-fix', 'hs-detail-body-fix' ) as $guard_id ) {
			echo '<style id="' . esc_attr( $guard_id ) . '"></style>' . "\n";
		}
	}

	/**
	 * Liest hs-landing.css ein.
	 *
	 * Gecacht wird pro Request statisch und darueber hinaus in einem Transient,
	 * dessen Schluessel die Dateizeit enthaelt -- eine geaenderte Datei erzeugt
	 * damit automatisch einen neuen Schluessel und greift sofort, ohne dass
	 * irgendein Cache geleert werden muss.
	 *
	 * @return string CSS oder Leerstring, wenn die Datei fehlt.
	 */
	private static function landing_css() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		$cached = '';

		$path = __DIR__ . '/' . self::CSS_FILE;
		if ( ! is_readable( $path ) ) {
			return $cached;
		}

		$mtime = (int) filemtime( $path );
		$key   = 'hs_landing_css_' . $mtime;

		$stored = get_transient( $key );
		if ( is_string( $stored ) && '' !== $stored ) {
			return $cached = $stored;
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return $cached;
		}

		// Kommentarbloecke und Zeilenumbrueche entfernen -- der Kommentarkopf der
		// Datei ist Dokumentation fuer Entwickler, nicht fuer den Browser.
		$raw = preg_replace( '#/\*.*?\*/#s', '', $raw );
		if ( ! is_string( $raw ) ) {
			return $cached;
		}
		$raw = trim( preg_replace( '/\s*\n\s*/', '', $raw ) );

		if ( '' === $raw ) {
			return $cached;
		}

		set_transient( $key, $raw, DAY_IN_SECONDS );

		return $cached = $raw;
	}
	/* -------------------------------------------------------------------- *
	 * Subtask 10 / Stufe 2: Snapshot ohne JavaScript nutzbar machen
	 * -------------------------------------------------------------------- */

	/**
	 * Korrigiert das ausgelieferte Snapshot-Markup an zwei Stellen.
	 *
	 * Greift ausschliesslich auf Provisioner-Landingpages und nur dann, wenn im
	 * Inhalt ueberhaupt eines der beiden Muster vorkommt. Beide Ersetzungen sind
	 * zusaetzlich gegen einen preg-Fehler abgesichert: Falls
	 * preg_replace_callback null liefert, bleibt der urspruengliche Inhalt
	 * unveraendert, statt eine leere Seite auszuliefern.
	 *
	 * @param  string $content Post-Content nach wpautop/Shortcodes.
	 * @return string
	 */
	public static function filter_snapshot_markup( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		$has_counter = ( false !== strpos( $content, 'hs-sb-val' ) );
		$has_fade    = ( false !== strpos( $content, 'fade-in' ) );

		if ( ! $has_counter && ! $has_fade ) {
			return $content;
		}
		if ( ! self::get_root_context() ) {
			return $content; // Keine Provisioner-Landingpage -> nichts anfassen.
		}

		if ( $has_counter ) {
			$new = preg_replace_callback(
				'/<div([^>]*\bhs-sb-val\b[^>]*)>\s*0\s*<\/div>/',
				array( __CLASS__, 'counter_callback' ),
				$content
			);
			if ( is_string( $new ) && '' !== $new ) {
				$content = $new;
			}
		}

		if ( $has_fade ) {
			$new = preg_replace_callback(
				'/class="([^"]*fade-in[^"]*)"/',
				array( __CLASS__, 'fade_in_callback' ),
				$content
			);
			if ( is_string( $new ) && '' !== $new ) {
				$content = $new;
			}
		}

		return $content;
	}

	/**
	 * Ersetzt die hartcodierte 0 eines Key-Facts-Zaehlers durch den Wert aus
	 * data-target.
	 *
	 * Die Formatierung entspricht exakt hs-landing.js:
	 *     el.textContent = t >= 1000 ? v.toLocaleString('de-DE') : String(v);
	 * Also Tausenderpunkt ab 1000, sonst die reine Zahl. Bewusst auch auf den
	 * englischen Seiten, damit serverseitiger Wert und Animationsergebnis
	 * identisch sind und beim Uebernehmen durch das Skript nichts umspringt.
	 *
	 * @param  array $m Treffer aus preg_replace_callback.
	 * @return string
	 */
	public static function counter_callback( $m ) {
		if ( ! preg_match( '/data-target="(\d+)"/', $m[1], $t ) ) {
			return $m[0]; // Kein Zielwert -> unveraendert lassen.
		}

		$n     = (int) $t[1];
		$value = ( $n >= 1000 ) ? number_format( $n, 0, ',', '.' ) : (string) $n;

		return '<div' . $m[1] . '>' . $value . '</div>';
	}

	/**
	 * Entfernt aus einer Klassenliste genau das Token "fade-in".
	 *
	 * Wird tokenweise verglichen, damit die Flatsome-Klassen hover-fade-in,
	 * bg-fade-in, image-fade-in und slide-fade-in unberuehrt bleiben.
	 *
	 * @param  array $m Treffer aus preg_replace_callback.
	 * @return string
	 */
	public static function fade_in_callback( $m ) {
		$classes = preg_split( '/\s+/', $m[1], -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $classes ) ) {
			return $m[0];
		}

		$kept = array();
		foreach ( $classes as $class ) {
			if ( 'fade-in' !== $class ) {
				$kept[] = $class;
			}
		}

		return 'class="' . implode( ' ', $kept ) . '"';
	}

}

HS_Seo_Meta::init();
