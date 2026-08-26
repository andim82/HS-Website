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
 * Version:     1.3.1
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
}

HS_Seo_Meta::init();
