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
 * Version:     1.6.1
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
		$graph[] = $webpage;

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
	 * Subtask 10 / Stufe 1: Landing-CSS serverseitig ausgeben
	 * -------------------------------------------------------------------- */

	/**
	 * Gibt die vier Style-Bloecke aus hs-landing.js im <head> aus, damit der
	 * vorgerenderte Snapshot sofort korrekt formatiert dargestellt wird.
	 *
	 * ACHTUNG -- DIES IST DIE EINZIGE WIRKSAME CSS-QUELLE:
	 * Weil injectStyles() in hs-landing.js sich selbst abschaltet, sobald es hier
	 * ein <style> mit derselben ID findet, ist dieser Block die einzige Fassung,
	 * die tatsaechlich greift. CSS-Aenderungen in hs-landing.js bleiben ohne
	 * Wirkung, solange dieses MU-Plugin aktiv ist. Jede Regelaenderung muss also
	 * HIER erfolgen -- und zur Nachvollziehbarkeit zusaetzlich in hs-landing.js,
	 * damit die beiden Fassungen nicht auseinanderlaufen.
	 *
	 * Abweichungen gegenueber dem Stand von hs-landing.js:
	 *   v1.6.1  .hs-cov-name -> margin:0;color:inherit;
	 *           Notwendig, seit die Kartentitel <h3> statt <span> sind: Flatsome
	 *           setzt h1..h6{color:#555}, das Child-Theme h1..h6{color:#323232}.
	 *           Beides hebelt die Vererbung von .hs-cov-head (color:#fff) aus,
	 *           die ein <span> noch hatte.
	 *   v1.6.1  .hs-subtext-bar -> padding:0 0 5rem
	 *           Seit der Erklaerungstext UNTER den Karten steht, lagen 112 px
	 *           darueber und 0 px darunter -- der Text wirkte dadurch als Teil
	 *           des folgenden dunklen Abschnitts.
	 *
	 * hs-cluster-body-fix und hs-detail-body-fix haben identischen Inhalt und
	 * sind jeweils nur fuer einen Seitentyp gedacht; ausgegeben wird deshalb
	 * nur der zum aktuellen data-type passende Block.
	 */
	public static function render_landing_css() {
		$ctx = self::get_root_context();
		if ( ! $ctx ) {
			return; // Keine Provisioner-Landingpage -> nichts anfassen.
		}

		$blocks = array(
			'hs-landing-styles' => self::css_landing(),
			'hs-tc-panel-style' => self::css_tc_panel(),
		);

		$type = isset( $ctx['type'] ) ? $ctx['type'] : '';
		if ( 'detail' === $type ) {
			$blocks['hs-detail-body-fix'] = self::css_body_fix();
		} elseif ( 'cluster' === $type ) {
			$blocks['hs-cluster-body-fix'] = self::css_body_fix();
		}

		echo "\n<!-- HEIM:SPIEL Landing-CSS serverseitig -->\n";

		foreach ( $blocks as $id => $rules ) {
			$rules = trim( $rules );
			if ( '' === $rules ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput -- statisches, eigenes CSS.
			echo '<style id="' . esc_attr( $id ) . '">' . $rules . "</style>\n";
		}
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

	/** @return string Inhalt von <style id="hs-landing-styles">. */
	private static function css_landing() {
		return <<<'HS_CSS_LANDING'
@keyframes hsSpin{to{transform:rotate(360deg)}}
#hs-root,
.hs-hero,.hs-stats-bar,.hs-cards-section,.hs-coverage-section,.hs-events-section,.hs-contact,.hs-breadcrumb,
.usps-section,.trust-section,.tech-section,[class*='technology'],[class*='trust'],[class*='usps'],[class*='why']{
  position:relative!important;
  left:50%!important;
  right:50%!important;
  margin-left:-50vw!important;
  margin-right:-50vw!important;
  width:100vw!important;
  max-width:100vw!important;
  box-sizing:border-box!important;
}
#hs-root{overflow-x:hidden;font-family:Lato,sans-serif;color:#323232;margin-top:0!important;padding-top:0!important;}
.wp-block-html,.wp-block-html>div,.entry-content>.wp-block-html{margin-top:0!important;padding-top:0!important;}
.hs-hero{min-height:580px;display:flex;align-items:center;background:#061d3e;overflow:hidden;margin-top:0!important;}
.hs-hero-bg{position:absolute;inset:0;z-index:0;overflow:hidden;}
.hs-hero-bg::after{content:'';position:absolute;inset:0;background:linear-gradient(to right,rgba(6,29,62,0.60) 0%,rgba(6,29,62,0.35) 35%,rgba(6,29,62,0.10) 65%,rgba(6,29,62,0.00) 100%);}
.hs-hero-inner{position:relative;z-index:2;width:100%;max-width:1280px;margin:0 auto;padding:80px 64px;}
.hs-eyebrow-pill{display:inline-flex;align-items:center;gap:8px;background:rgba(231,85,25,0.18);border:1px solid rgba(231,85,25,0.4);border-radius:4px;padding:5px 14px 5px 10px;margin-bottom:28px;}
.hs-eyebrow-dot{width:8px;height:8px;border-radius:50%;background:#e75519;flex-shrink:0;box-shadow:0 0 6px #e75519;}
.hs-eyebrow-text{font-size:.7rem;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#e75519;}
.hs-headline{font-size:clamp(2.2rem,4.5vw,4rem);font-weight:900;line-height:1.05;letter-spacing:-.02em;color:#fff;margin-bottom:20px;}
.hs-accent{color:#e75519;}
.hs-desc{font-size:1rem;color:rgba(255,255,255,.65);max-width:540px;line-height:1.7;margin-bottom:36px;}
.hs-ctas{display:flex;flex-wrap:wrap;gap:14px;}
.hs-cta-primary{display:inline-block;font-weight:900;font-size:.95rem;padding:14px 32px;background:#e75519;color:#fff!important;text-decoration:none;transition:background .2s;border:none;cursor:pointer;}
.hs-cta-primary:hover{background:#b84010;color:#fff!important;}
.hs-cta-secondary{display:inline-block;font-weight:700;font-size:.95rem;padding:14px 32px;border-radius:6px;background:rgba(255,255,255,.1);border:1.5px solid rgba(255,255,255,.3);color:#fff;text-decoration:none;}
.hs-cta-secondary:hover{background:rgba(255,255,255,.18);}
@media(max-width:768px){.hs-hero-inner{padding:40px 20px;}}
.hs-breadcrumb{background:#f0f2f5;padding:.65rem 0;border-bottom:1px solid #e2e6ec;}
.hs-container{width:100%;max-width:1180px;margin:0 auto;padding:0 24px;box-sizing:border-box;}
.hs-bc-link{color:#e75519;font-size:.82rem;font-weight:700;text-decoration:none;}
.hs-bc-link:hover{text-decoration:underline;}
.hs-bc-sep{color:#9ca3af;margin:0 .4rem;font-size:.82rem;}
.hs-bc-current{color:#374151;font-size:.82rem;font-weight:600;}
.hs-stats-bar{background:#e75519;margin-bottom:0;}
.hs-stats-inner{display:grid;max-width:1180px;margin:0 auto;}
.hs-sb-item{padding:1.75rem 1.5rem;text-align:center;border-right:1px solid rgba(255,255,255,.18);}
.hs-sb-item:last-child{border-right:none;}
.hs-sb-val{font-size:2.1rem;font-weight:900;color:#fff;line-height:1;}
.hs-sb-label{font-size:.75rem;font-weight:700;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.07em;margin-top:.45rem;}
@media(max-width:600px){.hs-stats-inner{grid-template-columns:repeat(2,1fr)!important;}.hs-sb-item:nth-child(2){border-right:none;}.hs-sb-item:nth-child(-n+2){border-bottom:1px solid rgba(255,255,255,.18);}.hs-sb-item:last-child:nth-child(odd){grid-column:1/-1;border-right:none;border-top:1px solid rgba(255,255,255,.18);}}
.hs-cards-section{padding:80px 0;background:#f8f9fb;}
.hs-section-eyebrow{color:#e75519!important;font-size:.75rem;font-weight:700;letter-spacing:.18em;text-transform:uppercase;display:block;text-align:center;margin-bottom:.5rem;}
.hs-section-title{font-size:clamp(1.6rem,4vw,2.4rem);font-weight:900;color:#061d3e;text-align:center;line-height:1.2;margin:0;}
.hs-section-bar{width:50px;height:3px;background:#e75519;margin:.75rem auto 2.5rem;display:block;}
.hs-cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1.25rem;min-width:0;}
.hs-card{display:flex;flex-direction:column;background:#fff;border:1px solid #e2e6ec;border-radius:6px;overflow:hidden;text-decoration:none;color:inherit;transition:all .2s;min-width:0;max-width:100%;box-sizing:border-box;}
.hs-card:hover{transform:translateY(-3px);box-shadow:0 6px 32px rgba(0,0,0,.1);border-color:transparent;}
.hs-card-head{background:#061d3e;padding:.9rem 1.1rem;display:flex;align-items:center;justify-content:flex-start;gap:.5rem;min-width:0;max-width:100%;box-sizing:border-box;}
.hs-card-sport{font-size:.9rem;font-weight:900;color:#fff;text-align:left;flex:1 1 auto;min-width:0;overflow-wrap:normal;word-break:normal;white-space:normal;line-height:1.3;}
.hs-lt-badge{font-size:.58rem;font-weight:900;padding:.2rem .5rem;border-radius:3px;background:#e75519;color:#fff;letter-spacing:.05em;white-space:nowrap;}
.hs-card-body{display:grid;grid-template-columns:repeat(2,1fr);padding:.85rem 1.1rem;background:#f8f9fb;flex:1;}
.hs-card-stat{text-align:center;padding:.5rem .25rem;}
.hs-stat-num{display:block;font-size:1.5rem;font-weight:900;color:#e75519;line-height:1.1;}
.hs-stat-lbl{display:block;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;margin-top:.15rem;}
.hs-card-footer{padding:.65rem 1.1rem;font-size:.78rem;font-weight:700;color:#e75519;background:#fff;display:flex;align-items:center;gap:.4rem;}
.hs-arrow{transition:transform .2s;}
.hs-card:hover .hs-arrow{transform:translateX(4px);}
.hs-card-compact{min-height:auto;}
.hs-card-compact .hs-card-head{padding:.75rem 1rem;min-height:56px;}
.hs-card-compact .hs-card-sport{font-size:.82rem;line-height:1.3;}
.hs-card-compact .hs-card-footer{padding:.55rem 1rem;font-size:.72rem;flex-direction:column;align-items:flex-start;gap:.2rem;}
.hs-card-footer-count{font-weight:700;color:#374151;}
.hs-card-footer-link{font-weight:700;color:#e75519;display:flex;align-items:center;gap:.3rem;}
.hs-cards-grid-compact{grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.85rem;}
.hs-cards-section-compact{padding-top:0;padding-bottom:24px;margin-top:0;background:#f8f9fb;display:flow-root;}
.hs-coverage-top{font-size:1.1rem;font-weight:900;color:#061d3e;margin:0 0 1rem;text-align:left;}
.hs-coverage-intro-section{background:#f8f9fb;}
.hs-coverage-intro{padding-top:40px;padding-bottom:8px;}
.hs-coverage-intro .hs-section-bar{margin-bottom:0;}
.hs-cards-section-compact .hs-section-title{font-size:clamp(1.3rem,2.2vw,1.9rem);}
.hs-subtext-bar{padding:0 0 5rem;background:#f8f9fb;}
.hs-subtext{font-size:.9rem;color:#6b7280;line-height:1.7;max-width:860px;margin:0 auto;text-align:center;}
.hs-coverage-section{padding:40px 0 80px;background:#f8f9fb;}
.hs-cov-grid{display:grid;gap:1.125rem;}
@media(max-width:1024px){.hs-cov-grid{grid-template-columns:repeat(2,1fr)!important;}}
@media(max-width:640px){.hs-cov-grid{grid-template-columns:1fr!important;}}
.hs-cov-card{border:1px solid #e2e6ec;border-radius:6px;overflow:hidden;display:flex;flex-direction:column;transition:all .2s;}
.hs-cov-card:hover{transform:translateY(-3px);box-shadow:0 6px 32px rgba(0,0,0,.1);border-color:transparent;}
.hs-cov-head{background:#061d3e;color:#fff;padding:.9rem 1.1rem;display:flex;align-items:center;gap:.7rem;flex-shrink:0;}
.hs-cov-icon{width:30px;height:30px;border-radius:6px;background:rgba(231,85,25,0.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.hs-cov-icon svg{width:16px;height:16px;stroke:#e75519;}
.hs-cov-name{font-size:.875rem;font-weight:900;margin:0;color:inherit;}
.hs-cov-body{padding:.85rem 1.1rem;background:#f8f9fb;flex:1;}
.hs-cov-list{list-style:none;padding:0;margin:0;}
.hs-cov-list li{font-size:.825rem;color:#6b7280;padding:.14rem 0;display:flex;align-items:flex-start;gap:.5rem;}
.hs-cov-list li::before{content:'';width:4px;height:4px;border-radius:50%;background:#e75519;flex-shrink:0;margin-top:.45rem;}
.hs-events-section{padding:80px 0;background:#f8f9fb;}
.hs-filter-bar{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.5rem;justify-content:center;}
.hs-filter-btn{padding:.5rem 1.25rem;border:1.5px solid #e2e6ec;background:#fff;border-radius:4px;font-size:.82rem;font-weight:700;color:#374151;cursor:pointer;transition:all .15s;}
.hs-filter-btn:hover{border-color:#e75519;color:#e75519;}
.hs-filter-btn.active{background:#e75519;border-color:#e75519;color:#fff;}
.hs-table-wrap{overflow-x:auto;border-radius:6px;border:1px solid #e2e6ec;background:#fff;}
.hs-events-table{width:100%;border-collapse:collapse;font-size:.875rem;table-layout:fixed;}
.hs-events-table thead th{background:#061d3e;color:#fff;padding:.85rem 1.5rem!important;text-align:left;font-weight:900;font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;}
.hs-events-table thead th:nth-child(1){width:220px;}
.hs-events-table thead th:nth-child(3){width:140px;text-align:center;}
.hs-events-table tbody tr{border-bottom:1px solid #f0f2f5;transition:background .15s;}
.hs-events-table tbody tr:last-child{border-bottom:none;}
.hs-events-table tbody tr:hover{background:#fef9f7;}
#hs-root .hs-event-row.hs-hidden{display:none!important;}
.hs-event-name{padding:.75rem 1.5rem!important;font-weight:700;color:#111827!important;}
.hs-event-stats{padding:.75rem 1.25rem!important;vertical-align:top;}
.hs-event-lt{padding:.75rem 1.5rem!important;text-align:center;vertical-align:middle;}
.hs-stats-expand{display:inline-flex;align-items:center;justify-content:center;background:none;border:1px solid #e75519;border-radius:10px;padding:1px 7px;font-size:.72rem;font-weight:700;color:#e75519;cursor:pointer;margin-left:4px;vertical-align:middle;line-height:1.4;}
.hs-th-hide-mobile{}
@media(max-width:640px){#hs-root .hs-table-wrap{overflow-x:hidden;touch-action:pan-y;}#hs-root .hs-table-wrap.hs-wrap-expanded{overflow:visible!important;touch-action:auto!important;}#hs-root .hs-events-table{display:block!important;width:100%!important;}#hs-root .hs-events-table thead{display:block!important;width:100%!important;}#hs-root .hs-events-table thead tr{display:flex!important;width:100%!important;background:#061d3e;}#hs-root .hs-events-table thead th{color:#fff;font-size:.75rem;font-weight:900;text-transform:uppercase;letter-spacing:.06em;padding:.7rem 1rem!important;}#hs-root .hs-events-table thead th:nth-child(2){display:none!important;}#hs-root .hs-events-table thead th:nth-child(1){flex:1!important;text-align:left!important;}#hs-root .hs-events-table thead th:nth-child(3){width:80px!important;text-align:center!important;flex-shrink:0!important;}#hs-root .hs-events-table tbody{display:block!important;width:100%!important;}#hs-root .hs-event-row{display:grid!important;grid-template-columns:1fr auto;grid-template-rows:auto auto;width:100%!important;box-sizing:border-box;border-bottom:1px solid #e5e7eb;}#hs-root .hs-events-table tbody tr td{display:block!important;min-width:0;box-sizing:border-box;}#hs-root .hs-event-name{grid-column:1!important;grid-row:1!important;padding:.65rem 1rem!important;}#hs-root .hs-event-lt{grid-column:2!important;grid-row:1!important;padding:.65rem .75rem!important;text-align:center!important;white-space:nowrap;}#hs-root .hs-event-stats{grid-column:1/-1!important;grid-row:2!important;padding:.4rem 1rem .75rem!important;border-top:1px solid #f0f0f0;}}
.hs-stat-tag{display:inline-block;background:#fff5f5;border:1px solid #fca5a5;color:#374151;font-size:.72rem;font-weight:600;padding:.18rem .45rem;border-radius:4px;margin:.1rem .15rem .1rem 0;}
.hs-stat-tags-wrap{position:relative;line-height:1.6;padding-bottom:2px;}
.hs-stat-tags-wrap.hs-stat-tags-expanded{}
.hs-stat-tags-wrap .hs-stats-expand{vertical-align:middle;}
.hs-tag-hidden{display:none!important;}
.hs-no-data{color:#9ca3af;font-size:.82rem;}
.hs-lt-yes{color:#16a34a;font-weight:900;font-size:.82rem;}
.hs-lt-request{color:#d97706;font-weight:700;font-size:.78rem;}
.hs-lt-no{color:#d1d5db;font-size:.82rem;}
.hs-contact{padding:80px 0;background:#061d3e;}
.hs-contact .hs-section-title{color:#fff;}
.hs-input{width:100%;padding:.75rem 1rem;border:1px solid #d1d5db;border-radius:4px;font-size:.9rem;font-family:Lato,sans-serif;box-sizing:border-box;background:#fff;color:#111827;outline:none;transition:border-color .15s;}
.hs-input:focus{border-color:#e75519;}
.hs-textarea{resize:vertical;min-height:120px;}
.fade-in{opacity:0;transform:translateY(16px);transition:opacity .45s,transform .45s;}
.fade-in.visible{opacity:1;transform:none;}
@media(max-width:640px){.hs-cards-grid{grid-template-columns:1fr;min-width:0;width:100%;max-width:100%;box-sizing:border-box;}.hs-cards-grid .hs-coverage-more-item{min-width:0;max-width:100%;box-sizing:border-box;}.hs-cards-grid .hs-tc-card-wrap{min-width:0;max-width:100%;box-sizing:border-box;}.hs-cards-grid .hs-card{min-width:0;max-width:100%;box-sizing:border-box;border:1px solid #e2e6ec;border-radius:8px;margin-bottom:0;}.hs-cards-grid .hs-card-head{min-width:0;max-width:100%;box-sizing:border-box;flex-wrap:wrap;}.hs-cards-grid .hs-card-sport{min-width:0;max-width:100%;overflow-wrap:normal;word-break:normal;white-space:normal;}.hs-coverage-more-list{padding:.9rem 0!important;margin-top:0!important;background:transparent!important;box-sizing:border-box;max-width:100%;overflow-x:hidden;}.hs-coverage-more-list .hs-cards-grid{gap:.75rem;}}
@media(max-width:600px){#hs-contact-form>div:first-child{grid-template-columns:1fr;}}
.hs-integration{padding:80px 0;background:#061d3e;position:relative;left:50%;right:50%;margin-left:-50vw;margin-right:-50vw;width:100vw;max-width:100vw;}
.hs-int-headline{font-size:clamp(1.5rem,3vw,2.2rem);font-weight:900;color:#fff;text-align:center;margin:0 0 .5rem;}
.hs-int-bar{display:block;width:50px;height:3px;background:#e75519;margin:0 auto 3rem;}
.hs-int-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:2rem;max-width:1100px;margin:0 auto;}
@media(max-width:900px){.hs-int-grid{grid-template-columns:1fr;}}
.hs-int-card{text-align:center;padding:2rem 1.5rem;border:1px solid rgba(255,255,255,.1);border-radius:8px;background:rgba(255,255,255,.04);transition:background .2s;}
.hs-int-card:hover{background:rgba(255,255,255,.08);}
.hs-int-icon{width:80px;height:80px;margin:0 auto 1.25rem;display:flex;align-items:center;justify-content:center;}
.hs-int-icon svg{width:100%;height:100%;}
.hs-int-sub{font-size:.72rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:#e75519;margin-bottom:.5rem;}
.hs-int-title{font-size:1.15rem;font-weight:900;color:#fff;margin:0 0 1rem;}
.hs-int-desc{font-size:.875rem;color:rgba(255,255,255,.65);line-height:1.7;margin:0;}
.hs-mid-cta{padding:60px 0;background:#fff;position:relative;left:50%;right:50%;margin-left:-50vw;margin-right:-50vw;width:100vw;max-width:100vw;}
.hs-mid-cta-inner{display:flex;align-items:center;justify-content:space-between;gap:2rem;flex-wrap:wrap;}
.hs-mid-cta-text{flex:1;min-width:260px;}
.hs-mid-cta-headline{font-size:clamp(1.2rem,2.5vw,1.7rem);font-weight:900;color:#061d3e;margin:0 0 .5rem;line-height:1.3;}
.hs-mid-cta-sub{font-size:.9rem;color:#6b7280;margin:0;line-height:1.6;}
@media(max-width:640px){.hs-mid-cta-inner{flex-direction:column;text-align:center;align-items:center;}.hs-int-card{text-align:center;}}
.hs-related{padding:4rem 0;background:#f8f9fb;}
.hs-related-title{margin-bottom:.5rem;}
.hs-rel-wrap{position:relative;display:flex;align-items:center;gap:12px;}
.hs-rel-track{display:flex;gap:16px;overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;scrollbar-width:none;padding:8px 0 16px;flex:1;}
.hs-rel-track::-webkit-scrollbar{display:none;}
.hs-rel-card,.hs-rel-card--nolink{flex:0 0 240px;height:160px;border-radius:8px;overflow:hidden;scroll-snap-align:start;text-decoration:none;display:block;}
.hs-rel-card-inner{width:100%;height:100%;display:flex;flex-direction:column;justify-content:flex-end;padding:20px;box-sizing:border-box;position:relative;}
.hs-rel-card-name{color:#fff;font-size:1.1rem;font-weight:800;line-height:1.2;text-shadow:0 1px 3px rgba(0,0,0,.4);}
.hs-rel-card-arrow{position:absolute;top:16px;right:16px;color:rgba(255,255,255,.7);font-size:1.3rem;transition:transform .2s;}
.hs-rel-card:hover .hs-rel-card-arrow{transform:translateX(4px);}
.hs-rel-card:hover .hs-rel-card-inner{filter:brightness(1.1);}
.hs-rel-card--nolink{opacity:.75;cursor:default;}
.hs-rel-arrow{flex-shrink:0;width:36px;height:36px;border-radius:50%;background:#061d3e;color:#fff;border:none;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center;transition:background .2s;z-index:1;}
.hs-rel-arrow:hover{background:#e75519;}
@media(max-width:640px){.hs-rel-arrow{display:none;}.hs-rel-track{padding-bottom:12px;}.hs-rel-wrap::after{content:"";position:absolute;right:0;top:0;bottom:0;width:56px;background:linear-gradient(to right,transparent,rgba(255,255,255,.92) 60%);pointer-events:none;z-index:2;border-radius:0 8px 8px 0;transition:opacity .3s;}.hs-rel-wrap::before{content:"›";position:absolute;right:6px;top:50%;transform:translateY(-50%);font-size:1.6rem;color:#e75519;font-weight:900;pointer-events:none;z-index:3;line-height:1;text-shadow:0 0 6px rgba(255,255,255,.8);transition:opacity .3s;}.hs-rel-wrap.hs-rel-end::after,.hs-rel-wrap.hs-rel-end::before{opacity:0;}}
.hs-show-more-row td{text-align:center;padding:1rem 0 .5rem;}
.hs-show-more-row{text-align:center;padding:1rem 0 .5rem;}
.hs-table-wrap--collapsed{position:relative;max-height:680px;overflow:hidden;}
.hs-table-wrap--collapsed::after{content:"";position:absolute;bottom:0;left:0;right:0;height:120px;background:linear-gradient(to bottom,rgba(255,255,255,0) 0%,rgba(255,255,255,.95) 100%);pointer-events:none;}
.hs-show-more-btn{background:none;border:1px solid #e75519;border-radius:4px;padding:.5rem 1.5rem;font-size:.85rem;font-weight:600;color:#e75519;cursor:pointer;transition:all .2s;}
.hs-show-more-btn:hover{background:#e75519;color:#fff;border-color:#e75519;}
.hs-gslot-why{background:#061d3e;padding:4rem 0;}
.hs-gslot-trust{background:#f7f6f2;padding:4rem 0;}
.faq-accordion{max-width:780px;margin:0 auto;}
.faq-item{border-bottom:1px solid rgba(255,255,255,.12);}
.faq-trigger{display:flex;align-items:center;width:100%;padding:1.1rem 0;cursor:pointer;background:none;border:none;text-align:left;gap:1rem;color:#fff;}
.faq-trigger:hover .faq-trigger-title{color:#e75519;}
.faq-trigger-title{font-size:1rem;font-weight:700;flex:1;line-height:1.3;}
.faq-arrow{font-size:1.4rem;font-weight:300;color:#e75519;transition:transform .25s;flex-shrink:0;width:24px;text-align:center;}
.faq-item.open .faq-arrow{transform:rotate(45deg);}
.faq-panel{padding:.2rem 0 1.2rem calc(40px + 1rem);}
.faq-desc{color:rgba(255,255,255,.75);font-size:.9rem;line-height:1.65;margin:0;}
.usp-icon-wrap{width:40px;height:40px;background:rgba(231,85,25,.15);border-radius:4px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.usp-icon-svg{width:20px;height:20px;color:#e75519;}
.hs-gslot-tech{background:#061d3e;padding:4rem 0;}
.tech-top-grid{display:grid!important;grid-template-columns:repeat(2,1fr);gap:1.5rem;}.tech-bottom-grid{display:grid!important;grid-template-columns:repeat(2,1fr);gap:1.5rem;}
@media(min-width:768px){.tech-top-grid{grid-template-columns:repeat(4,1fr);}.tech-bottom-grid{grid-template-columns:repeat(3,1fr);}}
.tech-bottom-grid>*:last-child:nth-child(3n+1){grid-column:1/-1;max-width:33%;margin:0 auto;}
@media(max-width:767px){.tech-bottom-grid>*:last-child:nth-child(2n+1){grid-column:1/-1;max-width:50%;margin:0 auto;}}
#hs-detail-tech-rendered .tech-stat-val{color:#e75519;}
.hs-gslot-contact{background:#fff;padding:4rem 0;}
.hs-gslot-eyebrow{font-size:.75rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#4f98a3;margin-bottom:.5rem;}
.hs-gslot-eyebrow--dark{color:#e75519;}
.hs-gslot-sub{color:#7a7974;margin:0 0 2rem;max-width:60ch;}
.hs-gslot-faq-list{display:grid;gap:8px;max-width:780px;margin:1.5rem auto 0;}
.hs-gslot-faq-item{border-bottom:1px solid rgba(255,255,255,.12);}
.hs-gslot-faq-trigger{display:flex;align-items:center;justify-content:space-between;width:100%;padding:1rem 0;background:none;border:none;text-align:left;cursor:pointer;gap:1rem;}
.hs-gslot-faq-q{color:#fff;font-weight:700;font-size:.95rem;flex:1;}
.hs-gslot-faq-arrow{color:#e75519;font-size:1.2rem;font-weight:300;flex-shrink:0;}
.hs-gslot-faq-panel{padding:.25rem 0 1rem;}
.hs-gslot-faq-a{color:rgba(255,255,255,.75);font-size:.9rem;line-height:1.65;margin:0;}
.hs-gslot-brand-group{margin-bottom:2rem;}
.hs-gslot-brand-label{font-size:.75rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#7a7974;margin-bottom:.75rem;text-align:center;}
.hs-gslot-brand-logos{display:flex;flex-wrap:wrap;justify-content:center;align-items:center;gap:1.5rem;}
.hs-gslot-logo{height:36px;width:auto;object-fit:contain;filter:grayscale(1);opacity:.65;transition:opacity .2s;}
.hs-gslot-logo:hover{opacity:1;filter:none;}
.hs-gslot-stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1.5rem;margin-top:1rem;}
.hs-gslot-stat{text-align:center;}
.hs-gslot-stat-val{font-size:2.2rem;font-weight:900;color:#4f98a3;line-height:1.1;}
.hs-gslot-stat-sub{font-size:.85rem;color:rgba(255,255,255,.7);margin-top:.35rem;}
.hs-gslot-form{display:flex;flex-direction:column;gap:.75rem;max-width:560px;margin:1.5rem auto 0;}
.hs-gslot-submit{width:100%;margin-top:.5rem;}
#top-link{position:fixed!important;-webkit-transform:translateZ(0)!important;transform:translateZ(0)!important;will-change:transform!important;display:block!important;visibility:visible!important;opacity:1!important;pointer-events:auto!important;}
@media(max-width:767px){#top-link.hide-for-medium{display:block!important;}}
.hs-cluster-cards{padding:0 0 80px;background:#f8f9fb;margin-top:0;display:flow-root;}
.hs-cluster-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1.125rem;margin-bottom:1.5rem;}
@media(max-width:1024px){.hs-cluster-grid{grid-template-columns:repeat(3,1fr)!important;}}
@media(max-width:768px){.hs-cluster-grid{grid-template-columns:repeat(2,1fr)!important;}}
@media(max-width:480px){.hs-cluster-grid{grid-template-columns:1fr!important;}}
.hs-cluster-card{display:block;border-radius:8px;overflow:hidden;text-decoration:none;color:#fff;min-height:150px;position:relative;transition:all .2s;}
.hs-cluster-card:hover{transform:translateY(-3px);box-shadow:0 8px 28px rgba(0,0,0,.22);}
.hs-cluster-card-inner{position:relative;z-index:1;padding:1.2rem 1.1rem;display:flex;flex-direction:column;justify-content:space-between;height:100%;min-height:150px;}
.hs-cluster-card-name{font-size:1.05rem;font-weight:900;margin:0 0 .6rem;color:#fff;}
.hs-cluster-card-stats{margin-bottom:.75rem;}
.hs-cluster-card-stat{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:rgba(255,255,255,.85);}
.hs-cluster-card-link{font-size:.78rem;font-weight:700;color:#fff;display:inline-flex;align-items:center;gap:.3rem;}
.hs-cluster-card:hover .hs-cluster-card-link{gap:.5rem;}
.hs-coverage-intl-heading{font-size:1.1rem;font-weight:900;color:#061d3e;margin:2rem 0 1rem;padding-top:1.5rem;border-top:1px solid #e2e6ec;}
.hs-cluster-grid-intl{margin-bottom:1.5rem;}
.hs-coverage-more-wrap{margin-top:1rem;text-align:left;}
.hs-coverage-more-toggle{background:#fff;border:1px solid #e2e6ec;border-radius:6px;padding:.7rem 1.2rem;font-size:.85rem;font-weight:700;color:#e75519;cursor:pointer;transition:all .2s;}
.hs-coverage-more-toggle:hover{background:#f8f9fb;border-color:#e75519;}
.hs-coverage-more-list{margin-top:1rem;background:#f8f9fb;border-radius:8px;padding:1.2rem;}
.hs-coverage-search{width:100%;padding:.7rem 1rem;border:1px solid #e2e6ec;border-radius:6px;font-size:.9rem;margin-bottom:1rem;box-sizing:border-box;}
.hs-coverage-more-ul{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;}
@media(max-width:768px){.hs-coverage-more-ul{grid-template-columns:repeat(2,1fr)!important;}}
@media(max-width:480px){.hs-coverage-more-ul{grid-template-columns:1fr!important;}}
.hs-coverage-more-item a{display:flex;justify-content:space-between;align-items:center;padding:.6rem .8rem;background:#fff;border-radius:5px;text-decoration:none;color:#323232;font-size:.85rem;border:1px solid #e2e6ec;transition:all .2s;}
.hs-coverage-more-item a:hover{border-color:#e75519;color:#e75519;}
.hs-coverage-more-count{font-size:.72rem;font-weight:700;color:#6b7280;}
.hs-flag-round{display:inline-block;width:32px;height:32px;border-radius:50%;background-size:cover;background-position:center;flex-shrink:0;box-shadow:0 0 0 1px rgba(255,255,255,.2);}
.hs-card-compact .hs-card-head{display:flex;align-items:center;gap:.5rem;width:100%;box-sizing:border-box;justify-content:flex-start;}
.hs-coverage-group-heading{font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin:1.25rem 0 .6rem;}
.hs-coverage-more-list .hs-coverage-group-heading:first-of-type{margin-top:0;}
.hs-coverage-fed-grid{margin-bottom:.5rem;}
HS_CSS_LANDING;
	}

	/** @return string Inhalt von <style id="hs-tc-panel-style">. */
	private static function css_tc_panel() {
		return <<<'HS_CSS_TCPANEL'
.hs-tc-card-wrap{display:contents;}
.hs-coverage-more-item.hs-tc-grid-item-open,.hs-tc-grid-item-open{grid-column:1/-1;}
.hs-tc-card{width:100%;text-align:left;background:none;border:none;padding:0;font:inherit;cursor:pointer;}
.hs-tc-arrow{transition:transform .2s ease;}
.hs-tc-open .hs-tc-arrow{transform:rotate(90deg);}
.hs-tc-panel{grid-column:1/-1;background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;margin-top:-.5rem;padding:1rem 1.25rem 1.25rem;}
.hs-tc-panel[hidden]{display:none;}
.hs-tc-panel .hs-table-wrap{overflow-x:hidden;max-width:100%;}
.hs-tc-panel .hs-events-table{width:100%;max-width:100%;border-collapse:collapse;font-size:.9rem;table-layout:fixed;}
.hs-tc-panel .hs-events-table thead th{background:#061d3e;color:#fff;text-align:left;font-weight:800;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;padding:.6rem .6rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.hs-tc-panel .hs-events-table td{padding:.55rem .6rem;border-bottom:1px solid #f0f1f3;vertical-align:middle;overflow:hidden;}
.hs-tc-panel .hs-tc-col-num{text-align:center;white-space:nowrap;width:112px;min-width:112px;max-width:112px;box-sizing:border-box;font-weight:600;}
.hs-tc-panel .hs-tc-th-num{text-align:center!important;font-size:.66rem!important;letter-spacing:.01em!important;white-space:normal!important;line-height:1.15!important;overflow:visible!important;text-overflow:unset!important;padding:.5rem .25rem!important;}
.hs-tc-panel .hs-tc-th-name{width:auto;}
.hs-tc-panel .hs-tc-th-stats{width:auto;}
.hs-tc-panel .hs-tc-group-row td{background:#f8f9fb;font-weight:800;font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;color:#061d3e;padding:.45rem .6rem;border-bottom:1px solid #e5e7eb;}
.hs-tc-panel .hs-event-name-inner{display:flex;align-items:center;gap:.5rem;font-weight:600;}
@media(max-width:640px){.hs-tc-panel{overflow:hidden;}.hs-tc-panel .hs-table-wrap{overflow-x:hidden!important;width:100%;max-width:100%;padding:0;border:none;background:transparent;box-shadow:none;}.hs-tc-panel .hs-events-table{display:block;width:100%!important;max-width:100%!important;table-layout:fixed!important;border-collapse:separate;border-spacing:0;background:transparent;box-sizing:border-box;}.hs-tc-panel .hs-events-table thead{display:none!important;visibility:hidden!important;height:0!important;overflow:hidden!important;}.hs-tc-panel .hs-events-table tbody{display:block;width:100%;max-width:100%;box-sizing:border-box;background:#f7f7f8;padding:.15rem;border-radius:10px;}.hs-tc-panel .hs-events-table tr.hs-tc-group-row{display:block;width:100%;max-width:100%;margin:0;padding:0;background:transparent!important;border:none!important;box-shadow:none!important;}.hs-tc-panel .hs-events-table tr.hs-tc-group-row td{display:block;width:100%;max-width:100%;padding:.75rem 1rem .35rem;background:transparent!important;border:none!important;color:#061d3e;font-size:.72rem;font-weight:800;line-height:1.2;letter-spacing:.04em;box-shadow:none!important;}#hs-root .hs-tc-panel .hs-events-table tr.hs-event-row{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;grid-template-areas:'name name name' 'stats stats stats' 'm ls lt'!important;gap:.75rem;width:100%;max-width:100%;padding:1rem;background:#fff;border:1px solid #e2e6ec!important;border-radius:8px;margin:0 0 .65rem 0;box-sizing:border-box;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);}#hs-root .hs-tc-panel .hs-events-table tr.hs-event-row:last-child{margin-bottom:0;}.hs-tc-panel .hs-events-table tr.hs-event-row td{display:block;min-width:0;max-width:100%;padding:0;border:none;box-sizing:border-box;overflow-wrap:anywhere;word-break:break-word;}#hs-root .hs-tc-panel .hs-events-table tr.hs-event-row td.hs-event-name{grid-area:name!important;grid-column:1/-1!important;grid-row:1!important;width:100%!important;max-width:100%!important;margin:0;padding:.65rem 1rem!important;box-sizing:border-box!important;}.hs-tc-panel .hs-events-table tr.hs-event-row .hs-event-name .hs-event-name-inner{align-items:flex-start;gap:.55rem;line-height:1.25;}.hs-tc-panel .hs-events-table tr.hs-event-row .hs-event-name img,.hs-tc-panel .hs-events-table tr.hs-event-row .hs-event-name svg{flex:0 0 auto;}#hs-root .hs-tc-panel .hs-events-table tr.hs-event-row td.hs-event-stats{grid-area:stats!important;grid-column:1/-1!important;grid-row:2!important;width:100%!important;max-width:100%!important;padding-top:.15rem;border-top:1px solid #eef1f4;}.hs-tc-panel .hs-events-table tr.hs-event-row .hs-event-stats::before{content:attr(data-label);display:block;margin:.55rem 0 .45rem;font-size:.68rem;font-weight:800;line-height:1.15;letter-spacing:.04em;text-transform:uppercase;color:#344054;}.hs-tc-panel .hs-events-table tr.hs-event-row .hs-event-stats .hs-stat-tags-wrap{max-width:100%;overflow:hidden;}.hs-tc-panel .hs-events-table tr.hs-event-row .hs-event-stats .hs-stat-tag{max-width:100%;}.hs-tc-panel .hs-events-table tr.hs-event-row .hs-tc-col-num{min-width:0;max-width:100%;padding:.7rem .45rem;border:1px solid #d9dde3;border-radius:6px;background:#f7f7f8;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;white-space:normal;overflow:hidden;}#hs-root .hs-tc-panel .hs-events-table tr.hs-event-row td.hs-tc-col-num:nth-of-type(3){grid-area:m!important;grid-column:1!important;grid-row:3!important;}#hs-root .hs-tc-panel .hs-events-table tr.hs-event-row td.hs-tc-col-num:nth-of-type(4){grid-area:ls!important;grid-column:2!important;grid-row:3!important;}#hs-root .hs-tc-panel .hs-events-table tr.hs-event-row td.hs-tc-col-num:nth-of-type(5){grid-area:lt!important;grid-column:3!important;grid-row:3!important;}.hs-tc-panel .hs-events-table tr.hs-event-row .hs-tc-col-num::before{content:attr(data-label);display:block;margin-bottom:.3rem;font-size:.56rem;font-weight:700;line-height:1.1;letter-spacing:.03em;text-transform:uppercase;color:#5f6b7a;white-space:normal;word-break:normal;overflow-wrap:anywhere;}.hs-tc-panel .hs-events-table tr.hs-event-row .hs-lt-yes,.hs-tc-panel .hs-events-table tr.hs-event-row .hs-lt-no{font-size:1rem;line-height:1;}}
HS_CSS_TCPANEL;
	}

	/** @return string Inhalt der body-fix-Bloecke (identisch fuer beide IDs). */
	private static function css_body_fix() {
		return <<<'HS_CSS_BODYFIX'
.wp-block-html,.wp-block-html>div,.entry-content>.wp-block-html{margin-top:0!important;padding-top:0!important;}#hs-lp-root,#hs-root{margin-top:0!important;padding-top:0!important;}@media(max-width:768px){body>*:not(script):not(style),.site-main,.main-content,#main,#content,.wp-site-blocks,.is-layout-flow,[class*="wp-container"]{padding-top:0!important;}}
HS_CSS_BODYFIX;
	}
}

HS_Seo_Meta::init();
