# 03 · Dateien im Repository

Jede Datei mit Zweck, Ablageort auf dem Server, Abhängigkeiten und den Stellen, an denen man
ansetzt. Die Dateien liegen im Repo flach nebeneinander, auf dem Server aber an vier
verschiedenen Orten — die Spalte „Ablageort" ist deshalb die wichtigste.

Kurzübersicht:

| Datei | Ablageort auf dem Server | Rolle |
|---|---|---|
| `heimspiel-data-cache.php` | `wp-content/plugins/hs-cache/` | Plugin-Hauptdatei, Konstanten, lädt `includes/` |
| `cache.php` | `wp-content/plugins/hs-cache/includes/` | Sheet-Abruf, Refresh, alle Aggregationen |
| `rest-api.php` | dito | Öffentliche Lese-Endpunkte `hs-cache/v1` |
| `prerender-snapshot.php` | dito | Snapshot-Writeback `hs-prerender/v1/snapshot` |
| `prerender-sync.php` | dito | Hero-Bilder aus GitHub importieren (Pull) |
| `prerender-api.php` | dito | Legacy-Push-Endpunkte (nicht mehr im Workflow) |
| `cron.php` | dito | Monatsintervall, Cron-Hook |
| `admin.php` | dito | Admin-Seite „HEIM:SPIEL Cache" |
| `hs-wpml-auto-translate-exclusions.php` | dito | WPML-Autoübersetzung für Provisioner-Seiten aus |
| `hs-landing-provisioner-v4.15.php` | `wp-content/plugins/hs-landing-provisioner-v4.15/` | Seiten anlegen (eigenes Plugin) |
| `hs-seo-meta.php` | `wp-content/mu-plugins/` | title, Meta, OG, JSON-LD, CSS-Ausgabe, Snapshot-Korrekturen |
| `hs-translations.php` | `wp-content/mu-plugins/` | Übersetzungs-Pipeline (OpenAI, Glossar) |
| `hs-perf.php` | `wp-content/mu-plugins/` | Drei gezielte Ladezeit-Eingriffe |
| `hs-rest-gzip.php` | `wp-content/mu-plugins/` | gzip für `hs-cache/v1` |
| `hs-landing.css` | `wp-content/mu-plugins/` | Einzige CSS-Quelle der Landingpages |
| `hs-landing-v101.24.js` | — (Quelle) | Frontend-Renderer, lesbar |
| `hs-landing.min.js` | WPCode-Snippet 1432 | Deployte, minifizierte Fassung |
| `scripts/*.mjs` | — (lokal / GitHub Actions) | Prerender-Skripte, REST-Wrapper |
| `.github/workflows/*.yml` | GitHub | Zwei nächtliche Workflows |
| `dist/hero-images/` | GitHub (WordPress holt ab) | WebP-Bilder + Mapping |
| `test-*.php` | — (lokal) | Logiktests ohne WordPress |

---

## Plugin „HEIMSPIEL Data Cache" (`wp-content/plugins/hs-cache/`)

### `heimspiel-data-cache.php`

**Zweck:** Plugin-Hauptdatei. Definiert Konstanten, lädt die Includes defensiv (fehlt eine Datei, erscheint ein Admin-Hinweis statt eines Fatal Errors) und registriert Aktivierungs-/Deaktivierungs-Hooks für den Cron.

**Konstanten:**

| Konstante | Wert | Bedeutung |
|---|---|---|
| `HS_CACHE_VERSION` | `1.4` | User-Agent der ausgehenden Requests |
| `HS_CACHE_TTL` | 31 Tage | Lebensdauer aller Daten-Transients |
| `HS_CRON_HOOK` | `hs_refresh_all_cache_event` | Name des Monats-Cron-Ereignisses |
| `HS_GSHEET_APP_BASE_URL` | `https://script.google.com/macros/s/…/exec` | Apps-Script-Deployment. **Ändert sich bei jedem neuen Deployment**, sofern dort nicht „gleiche URL beibehalten" gewählt wird |
| `HS_GSHEET_INDEX_URL`, `…_INDEX_DE_URL`, `…_GENERAL_URL`, `…_GENERAL_DE_URL` | Basis-URL + `?sheet=…` | Die vier Steuer-Tabs |
| `HS_GSHEET_CSV_BASE` | `https://docs.google.com/spreadsheets/d/1EZYrk7…/export?format=csv&gid=` | Sport-Tabs per gid |

**Ladereihenfolge** (`$hs_includes`): `cache.php` → `rest-api.php` → `prerender-api.php` → `prerender-sync.php` → `prerender-snapshot.php` → `hs-wpml-auto-translate-exclusions.php` → `cron.php` → `admin.php`. Die Reihenfolge ist relevant: `hs_slugify()` aus `cache.php` wird überall vorausgesetzt.

### `cache.php`

**Zweck:** Herzstück des Backends — holt Daten aus Google, schreibt Transients und berechnet die Aggregationen, die das Frontend fertig konsumiert.

**Funktionen:**

| Funktion | Aufgabe |
|---|---|
| `hs_slugify($str)` | Slug wie im Frontend (ä/ö/ü/ß, dann `[^a-z0-9]+`→`-`) |
| `hs_fetch_with_retry($fn, $label)` | Bis zu 3 Versuche mit Backoff 2 s/4 s (Apps-Script-Kaltstarts) |
| `hs_refresh_all_cache_v2()` | Der Refresh in 7 Schritten (siehe 02-ablauf.md §3), schreibt `hs_cache_refresh_report` |
| `hs_refresh_all_cache()` | Abwärtskompatible Hülle für den Cron-Hook, gibt `bool` zurück |
| `hs_fetch_gsheet_rows($url, $keys, $label)` | Apps-Script-JSON laden, Zeilenliste extrahieren |
| `hs_fetch_index()`, `hs_fetch_index_de()`, `hs_fetch_general_index()`, `hs_fetch_general_index_de()` | Die vier Steuer-Tabs |
| `hs_fetch_csv($gid)`, `hs_parse_csv($text)` | Sport-Tab laden und parsen (`str_getcsv`, Header = Spaltennamen) |
| `hs_country_iso_map()`, `hs_country_to_iso($name)` | Ländername → ISO-Code für Flaggen (statische Tabelle) |
| `hs_collect_sport_tabs($index)` | Alle Cluster-Zeilen mit gid → `[gid => [key, label]]`; Basis für Bundle- und Event-Template |
| `hs_build_coverage_for_sport($sport)` | Fall A (eigene gid → ganzer Tab) / Fall B (Bundle → alle Tabs, ClubBundle → Mitgliederliste), dann `hs_aggregate_coverage()` |
| `hs_build_bundle_coverage(...)` | **Nicht aktiv** — alte Bundle-Variante, aus Vorsicht belassen |
| `hs_build_comp_lookup_from_rows($rows)` | Wettbewerbs-Lookup eines Tabs (Name, Land, Matches, Live, Stats) |
| `hs_pick_last_completed_season_end($rows)` | Jüngstes `season_end` ≤ heute je Wettbewerb |
| `hs_build_last_season_stats($rows)` | **Legacy**, nicht mehr aufgerufen |
| `hs_build_last_season_family_stats($rows)` | Saison-Clusterung über `season_end` (Lücke > 200 Tage), Kennzahlen der letzten abgeschlossenen Saison, `hasCurrentSeason`, `statsList` |
| `hs_build_bundle_totals($slug)` | Summen für die Stats-Bar der Bundle-Seiten; gleiche Tab-Weiche wie Fall B |
| `hs_rank_country_top_competitions($list, $n)` | Top 5 je Land: Jugendwettbewerbe (`age` gesetzt) raus, bevorzugt IDs ≤ `HS_TOP_COMP_ID_CAP` (500, alte Kern-Wettbewerbe), sortiert nach `competition_order`, dann ID; Rest alphabetisch |
| `hs_aggregate_coverage($rows, $curated)` | Gruppiert nach Land/Föderation, filtert eingestellte Wettbewerbe (> 4 Jahre), baut `countries[]`, `international[]`, `topCompetitions[]` |
| `hs_build_event_coverage($slug)` | Event-Template: Namensfilter über alle Tabs, Gruppierung nach `sport`-Spalte oder Tab, Saison-Dedupe, Staleness |
| `hs_event_short_name($full, $filter)` | „Olympische Winterspiele - Slalom" → „Slalom" |

**Konstanten:** `HS_FETCH_MAX_RETRIES` (3), `HS_FETCH_RETRY_DELAY_SECONDS` (2), `HS_TOP_COMP_ID_CAP` (500).

**Hinweis für Änderungen:** Die Datei ist ohne WordPress testbar — `test-event-coverage.php` und `test-bundle-coverage.php` laden sie mit Stubs. Beide Tests nach jeder Änderung laufen lassen.

### `rest-api.php`

**Zweck:** Registriert die öffentlichen Lese-Routen unter `hs-cache/v1` und bedient sie aus den Transients (lazy Aufbau, falls leer). Enthält eine Sicherheitskopie von `hs_slugify()` hinter `function_exists`.

**Routen:** `index`, `generalIndex`, `indexDe`, `generalIndexDe`, `csv/{gid}`, `coverage/{sport}`, `event-coverage/{slug}`, `bundle-totals/{bundle}`, `coverage-debug/{sport}` — Details in 01-architektur.md §5. Alle mit `permission_callback => __return_true`, Parameter über `sanitize_title` / `sanitize_text_field`.

### `prerender-snapshot.php`

**Zweck:** Nimmt das von Puppeteer gerenderte HTML entgegen und schreibt es in den Container `#hs-root` der zugehörigen Seite. Version 1.4.

**Route:** `POST hs-prerender/v1/snapshot` mit Body `{url, html}` und Header `X-HS-Snapshot-Key`.

**Funktionen:**

| Funktion | Aufgabe |
|---|---|
| `hs_prerender_check_snapshot_key()` | `hash_equals` gegen `HS_SNAPSHOT_API_KEY` aus `wp-config.php`; 500 wenn nicht definiert, 403 bei Fehlschlag |
| `hs_prerender_lang_from_url($url)` | Erstes Pfadsegment gegen aktive WPML-Sprachen prüfen |
| `hs_prerender_resolve_post_id($url)` | WPML umschalten, `url_to_postid` mit Slash-Varianten, `wpml_object_id` ohne Fallback |
| `hs_prerender_find_matching_div_close($content, $start)` | Schließendes `</div>` per Tiefenzählung |
| `hs_prerender_allow_form_tags($tags, $ctx)` | Erlaubt `form/input/textarea/select/option/label` nur während des einen `wp_update_post` |
| `hs_prerender_write_snapshot($req)` | Ablauf: Key → Post → Sprachabgleich (409) → Container finden (422) → ersetzen → Meta setzen |

**Bekannter Schönheitsfehler:** Zeile 4 schreibt bei jedem PHP-Request `HS DEBUG: prerender-snapshot.php wurde geladen.` ins Error-Log. Kandidat zum Entfernen.

### `prerender-sync.php`

**Zweck:** Holt die von GitHub Actions erzeugten Hero-WebPs aus dem Repository in die Mediathek — Pull-Variante, weil eingehende Requests von GitHub-IPs durch die Hosting-Firewall blockiert wurden. Version 6.

**Konfiguration:** `HS_GITHUB_REPO_OWNER` (`andim82`), `HS_GITHUB_REPO_NAME` (`HS-Website`), `HS_GITHUB_REPO_BRANCH` (`main`), `HS_GITHUB_MAPPING_PATH` (`dist/hero-images/image-mapping.json`); Secret `HS_GITHUB_TOKEN` in `wp-config.php` (Fine-grained PAT, nur „Contents: Read-only" für dieses Repo).

**Funktionen:** `hs_github_api_get_file($path)` (Contents API, Retry bei 403/429/502/503 mit 3/8/15 s, Base64-Decode), `hs_prerender_sync_run()` (Mapping lesen, je Eintrag prüfen/importieren, Report in `hs_prerender_sync_last_report`), `hs_prerender_sync_finish()`, `hs_prerender_sync_handle_manual_trigger()` (Button, `manage_options`, Nonce), `hs_prerender_sync_render_admin_box()` (Admin-Box mit Button, letztem Report und Liste aller importierten Bilder).

**Cron:** `hs_prerender_sync_cron`, täglich, erstmals „morgen 04:00" (Site-Zeit), registriert bei `init`.

**Idempotenz:** Attachment-Meta `_hs_prerender_filename`; vorhandene Dateien werden übersprungen. Es liest nur Einträge mit `status: "ok"`.

### `prerender-api.php`

**Zweck:** Zwei ältere, capability-geschützte Endpunkte (`POST hs-cache/v1/prerender/media`, `/prerender/page`) aus der ursprünglichen Push-Architektur. **Nicht mehr im Workflow** — die Hosting-Firewall blockte authentifizierte POSTs aus GitHub-Actions-IP-Bereichen. Bleibt vorerst im Plugin. Sicherheitsbetrachtung in 04-betrieb.md.

### `cron.php`

**Zweck:** Eigenes Intervall `hs_monthly` (30 Tage), `hs_schedule_cron()` / `hs_unschedule_cron()` (an Aktivierung/Deaktivierung gebunden), Hook `HS_CRON_HOOK` → `hs_refresh_all_cache()`.

### `admin.php`

**Zweck:** Einstellungen → „HEIM:SPIEL Cache". Button „Cache jetzt aktualisieren" (Nonce `hs_refresh_cache_action`), Status je Datenquelle aus dem letzten Report, Cache-Zustand, nächster Cron-Lauf, Liste der Endpunkte. Unten hängt per Action `hs_data_cache_admin_page_footer` die Bild-Sync-Box aus `prerender-sync.php`; oben blendet `hs-translations.php` seine Reset-Buttons per `admin_notices` ein.

### `hs-wpml-auto-translate-exclusions.php`

**Zweck:** Filter `wpml_exclude_post_from_auto_translate` → `true`, wenn Post-Meta `_hs_no_auto_translate` gesetzt ist. Der Provisioner setzt dieses Meta beim Anlegen; damit übersetzt WPML „Translate Everything" die Landingpages nicht automatisch (die Inhalte kommen ohnehin aus dem Sheet).

---

## Plugin „HS Landing Provisioner" (`wp-content/plugins/hs-landing-provisioner-v4.15/`)

### `hs-landing-provisioner-v4.15.php`

**Zweck:** Admin-Werkzeug (Menüpunkt „HS Provisioner"), das aus dem Cache-Index Cluster- und Detail-Seiten anlegt. Die Seitenerstellung selbst läuft über den WordPress-Core-Endpunkt `POST wp/v2/pages` (Nonce), das Plugin liefert die Oberfläche und vier Hilfs-Endpunkte.

**PHP-Teil:**

| Element | Aufgabe |
|---|---|
| `register_post_meta('page', '_hs_no_auto_translate')` | Meta REST-fähig machen, damit es im selben POST mitgeht (`auth_callback`: `manage_options`) |
| Hook `rest_after_insert_page` | Erkennt Provisioner-Seiten am Marker `id="hs-root"` und setzt WPML Translation Priority auf „nicht benötigt" |
| `POST hs-prov/v1/link-translation` | EN- und DE-Seite über `wpml_set_element_language_details` zu einem Übersetzungspaar (trid) verbinden |
| `POST hs-prov/v1/bulk-publish` | Liste von IDs veröffentlichen |
| `GET hs-prov/v1/find-parent` | Seite per Slug finden (direkte, vorbereitete DB-Abfrage, sprachunabhängig), dann Übersetzung in Zielsprache auflösen |
| `POST hs-prov/v1/rename-slug` | `post_name` ändern (z. B. nach Dubletten `-2`) |

Alle Routen: `permission_callback` `manage_options`.

**JS-Teil (im Admin-Screen):** Dropdown aus `/hs-cache/v1/index` (Cluster-Zeilen, Wert = `discipline_key`), Schritt 1 legt die Cluster-Seite an (`buildClusterContent(key)` → `<div id="hs-root" data-type="cluster" data-bundle="KEY"></div><script></script>`), Schritt 2 die Detail-Seiten (`buildContent(key, clusterUrl)` → `data-type="detail" data-discipline="KEY" data-cluster-url="…"`) mit Parent = Cluster. Zweisprachig: EN zuerst, DE danach mit Slug aus `Index_DE.detail_url`, dann `link-translation`. Vorhandene Seiten werden erkannt und mit EN/DE-Tags angezeigt; Einzel- und Sammel-Löschen (Papierkorb) möglich. Seiten entstehen im Status, den das Formular vorgibt (Standard Entwurf).

**Wichtig seit v4.15:** `data-bundle` trägt den `discipline_key`, nicht den rohen `bundle`-Wert. Die Zuordnung der Detail-Zeilen in Schritt 2 läuft weiterhin über die Spalte `bundle` der Kind-Zeilen.

---

## MU-Plugins (`wp-content/mu-plugins/`)

### `hs-seo-meta.php`

**Zweck:** Alles Serverseitige, das Crawler ohne JavaScript brauchen — ausschließlich auf Seiten mit `#hs-root`, alle anderen Seiten bleiben unberührt. Klasse `HS_Seo_Meta`, Version 1.7.0.

| Hook | Methode | Ergebnis |
|---|---|---|
| `wp_head` Prio 1 | `render_head()` | `<link rel="preload">` für die JSON-Endpunkte, `meta description`, Open Graph (`property=`), Twitter Cards, JSON-LD `@graph` (og:url und WebPage.url nutzen `wp_get_canonical_url`; ein eigenes `<link rel="canonical">` gibt das Plugin nicht aus) |
| `pre_get_document_title` | `filter_document_title()` | `<title>` aus `seoTitle`, sonst `heroHeadline` + „HEIM:SPIEL Sportdaten / Sports Data", max. 65 Zeichen |
| `mtm_head_meta_tags` Prio 99 | `filter_mtm_tags()` | Entfernt fehlerhafte og/twitter-Tags des Plugins Meta Tag Manager |
| `wp_head` Prio 999 | `render_landing_css()` | `hs-landing.css` als `<style id="hs-landing-styles">` plus drei leere Platzhalter-IDs, damit `injectStyles()` im JS sich überspringt |
| `the_content` Prio 20 | `filter_snapshot_markup()` | Zähler aus `data-target` als Text setzen, Klasse `fade-in` entfernen (sonst unsichtbar ohne JS) |
| `save_post` | `flush_cache_for_post()` | 6-h-Cache der Meta-Daten leeren |

**JSON-LD `@graph`:** Organization, WebSite, WebPage (mit `inLanguage`, `primaryImageOfPage`), SoftwareApplication, FAQPage (Fragen/Antworten **aus dem gespeicherten Snapshot**, damit Schema und sichtbarer Inhalt identisch sind), ItemList der Wettbewerbe (`competitions_from_snapshot()`, angereichert mit Wikidata-IDs und Verbänden aus statischen Tabellen). Ausgabe mit `JSON_UNESCAPED_UNICODE`, bewusst **ohne** `JSON_UNESCAPED_SLASHES` (XSS-Schutz für `</script>`).

**Datenquellen:** Index-Zeile über internen `rest_do_request()` (kein HTTP), Kontext aus dem `post_content` (`data-*`-Attribute und Snapshot-HTML). Zeilensuche wie im Frontend zweistufig: exakter Treffer auf `bundle`/`bundlename`/`disciplinekey`, dann Mitgliedschaft in der Kommaliste.

**Beschreibungs-Fallback:** Fehlt `seoDescription`, wird `description` satzweise ab dem ersten Satz gekürzt, der einen seitenspezifischen Begriff enthält (vermeidet 21 identische Descriptions aus dem gemeinsamen Einleitungssatz).

### `hs-translations.php`

**Zweck:** Serverseitige Übersetzungs-Pipeline (Version 4). Routen `GET/POST hs-cache/v1/translations/{lang}/{cacheKey}`, Cron-Handler `hs_translate_batch_cron_handler`, Glossar aus dem Sheet-Tab (gid 1129563872), OpenAI-Aufruf `hs_openai_translate_batch($strings, $lang, $cacheKey)`.

**Zwei Prompts:** Wettbewerbsnamen (Eigennamen wie „Bundesliga" bleiben unverändert, nur generische Anteile werden übersetzt) und — für cacheKeys mit Präfix `stats_` — Statistik-Labels (alles übersetzen, Fachsprache der Sportart, snake_case-Feldnamen in lesbare Labels auflösen, jeden Schlüssel zurückliefern). Für `stats_` zusätzlich ein Backfill nicht beantworteter Begriffe auf sich selbst.

**Kosten-/Schutzmechanik:** Lock-Transient je Sprache+Key (5 min, bei Fortsetzung 10 min), Chunks à 80 Strings, max. 4 Chunks pro Cron-Lauf, Speichern nach jedem Chunk, `temperature 0`, `response_format json_object`, Modell `gpt-4o-mini`. Secret `HS_OPENAI_API_KEY` in `wp-config.php`.

**Admin:** Buttons „Glossar jetzt neu laden" und „Alle Übersetzungen zurücksetzen" (löscht alle `hs_trans_%`-Optionen — verursacht anschließend neue API-Kosten für den gesamten Bestand, aktuell > 1.300 Wettbewerbsnamen).

### `hs-perf.php`

**Zweck:** Drei gemessene Ladezeit-Eingriffe, bewusst minimal (kein globales `defer`, weil Flatsome und WPML synchrone Inline-Skripte auf jQuery nutzen): `jquery-migrate` aus den jQuery-Abhängigkeiten entfernen, das leere Stylesheet `tf-footer-style` unterdrücken, `wp-statistics/tracker.js` auf `defer` setzen. Klasse `HS_Perf`. Der Dateikopf dokumentiert außerdem ein **doppelt eingebundenes Google-Tag** (einmal ungesperrt am Consent vorbei), das nur manuell im Theme/Snippet zu entfernen ist.

### `hs-rest-gzip.php`

**Zweck:** Filter `rest_pre_serve_request`: komprimiert Antworten unter `/hs-cache/v1/` mit gzip (Level 6), nur GET, nur wenn der Client gzip akzeptiert, kein `zlib.output_compression`, kein bereits gesetztes `Content-Encoding`. Setzt `Content-Encoding`, `Vary`, `Content-Length` und ein Diagnose-Header `X-HS-Gzip: vorher->nachher`. Effekt: ~580 KB JSON pro Seitenaufruf → ~43 KB.

### `hs-landing.css`

**Zweck:** Einzige Quelle für das Styling der Landingpages (Version 1.7.0), 35 KB. Wird von `hs-seo-meta.php` inline ausgegeben. Drei Abschnitte entsprechen den drei CSS-Blöcken, die `hs-landing.js` sonst injizieren würde. Änderungen an CSS-Strings im JavaScript sind wirkungslos, solange diese Datei existiert.

---

## Frontend

### `hs-landing-v101.24.js`

**Zweck:** Der komplette Renderer der Landingpages (≈ 3.700 Zeilen, lesbare Quelle mit Kommentaren). Läuft als IIFE, sobald `#hs-root` existiert.

**Ablauf:** `CONFIG` (Endpunkte relativ zu `window.HS_CACHE_BASE`) → Sprache aus `<html lang>` → Höhe des Snapshots reservieren (CLS) → Loader → `fetchIndex()` + `fetchGeneralIndex()` parallel → `renderCluster()` oder `renderDetail()` → Sektionen (Hero, Stats-Bar, Kacheln, Coverage, Integration, Mid-CTA, WHY/FAQ, Partner, Trust, Kontakt) → Übersetzungen → `hsMarkRenderComplete()`.

**Wichtige Funktionen:**

| Bereich | Funktionen |
|---|---|
| Daten | `fetchIndex`, `fetchGeneralIndex`, `fetchSheetCSV`, `fetchCoverage`, `fetchEventCoverage`, `fetchBundleTotals`, `f(obj, key, fallback)` (case-insensitive Feldzugriff) |
| Rendering Cluster | `renderCluster`, `renderHeroCluster`, `renderStatsBar`, `renderCoverageSection`, `renderTopCompetitions`, `renderEventSportCards`, `renderCoverageCards`, `buildCountryCard`, `buildFedCard` |
| Rendering Detail | `renderDetail`, `renderHeroDetail`, `renderBreadcrumb`, `renderDetailCoverageSection`, `renderEventsSection`, `renderContactSection` |
| Panels | `hsPrerenderPanels()` (für Snapshot, ohne Pills), `window.hsRenderCompetitionPanel()` (live, mit Pills), `window.hsToggleCompetitionPanel()` |
| SEO/Texte | `fillPlaceholders`, `applyConditionalBlocks`, `buildSeoFaqItems` (`SEO_FAQ_TEMPLATES`), `buildTopLeagueNamesList`, `formatEnumeration` |
| Übersetzung | `translateCompetitions` (`comp_all`), `translateEventStats` (`stats_all`), `translateEvents` (`events_*`), `hsTranslateCompName`, `applyCompetitionTranslations`, `NAME_TRANSLATIONS_OVERRIDE` (z. B. gb-sct → Scotland) |
| UI | `initAnimations` (Zähler mit `data-target`, Fade-in per IntersectionObserver), `injectStyles` (Fallback-CSS) |

**Globale Zustände:** `window.hsRenderComplete` (Puppeteer wartet darauf), `window.hsCompetitionPanelData`, `window.hsCompTranslations`, `window.hsStatsTranslationMap` (Sheet-Spalte `statsTranslations`, derzeit leer), `window.hsStatsAiMap`, `window.hsGeneralIndexData`.

### `hs-landing.min.js`

**Zweck:** Das, was tatsächlich läuft: Terser-Build der Quelle (`--compress --format comments=false`, **ohne** Mangling, ≈ 130 KB), eingetragen im WPCode-Snippet 1432 („Site Wide Footer"). Wird im Repo mitgeführt, damit das Deployment die Datei direkt aus GitHub laden kann.

```bash
node node_modules/terser/bin/terser hs-landing-v101.24.js --compress --format comments=false -o hs-landing.min.js
```

---

## Skripte (`scripts/`)

### `scripts/snapshot-pages.mjs`

Liest `index` und `indexDe`, bildet aus `detail_url` (DE mit `/de/`-Präfix) die URL-Liste aller `cluster`/`detail`-Zeilen, rendert jede mit Puppeteer (`domcontentloaded`, dann `waitForFunction(window.hsRenderComplete === true)`, 20 s), liest `#hs-root.innerHTML` und schreibt `output/snapshot-mapping.json` mit Status `ok` / `ok_timeout` / `error`. User-Agent `HeimspielSnapshotBot/1.0`. Läuft im Workflow, lokal mit `WP_BASE_URL`.

### `scripts/push-snapshots.mjs`

Liest das Mapping und sendet jeden brauchbaren Eintrag (`ok`, `ok_timeout`) per `POST /wp-json/hs-prerender/v1/snapshot` mit `X-HS-Snapshot-Key` (Env `HS_SNAPSHOT_API_KEY`). 200 ms Pause zwischen Requests. Schreibt `output/push-report.json`.

### `scripts/prerender-images.mjs`

Liest den **gecachten** Index, filtert `cluster`/`detail`-Zeilen mit `heroBgUrl` und ohne `heroBgUrlCached`, wandelt Drive-Freigabelinks in Direkt-Download-URLs, lädt, konvertiert mit `sharp` (max. 1920 px, WebP q90, effort 6), schreibt `dist/hero-images/{discipline_key}-hero.webp` und `image-mapping.json` (Delta des Laufs). Erkennt HTML-Antworten von Drive (Freigabe-/Virenscan-Seite) als Fehler.

### `scripts/wp.mjs`

CLI-Wrapper um die WordPress-REST-API mit Application Password (Basic Auth). Liest `.env` (gitignored). Befehle: `whoami`, `routes`, `list`, `get [--raw]`, `meta`, `update [--dry-run]`, `media`, `raw <METHODE> <route>`; `--lang=de|en` setzt `wpml_language`. Regeln in `CLAUDE.md`: schreibende Aufrufe erst mit `--dry-run`, Inhalte immer `--raw` lesen (Gutenberg-Kommentare).

---

## GitHub Actions (`.github/workflows/`)

### `prerender-snapshot.yml`

`schedule: 0 6 * * *` + `workflow_dispatch`; `permissions: contents: read`. Node 20, `npm install`, `snapshot-pages.mjs`, `push-snapshots.mjs` (Secret `HS_SNAPSHOT_API_KEY`), Artefakt `snapshot-reports`.

### `prerender-images.yml`

`schedule: 0 1 * * *` + `workflow_dispatch`; `permissions: contents: write`. Node 20, `prerender-images.mjs`, Artefakt `image-mapping`, dann `git add dist/hero-images` und Commit als `github-actions[bot]` mit Nachricht „Auto: neue Hero-Images (WebP) via Prerender-Workflow" — nur wenn es Änderungen gibt. **Folge:** Der Bot committet auf `main`; lokale Branches müssen vor dem Push rebased werden.

---

## Build-Artefakte und Konfiguration

| Datei | Inhalt |
|---|---|
| `dist/hero-images/*.webp` | 27 WebP-Hero-Bilder, benannt `{discipline_key}-hero.webp` (einige ältere Varianten mit Bundle-Namen stammen aus der Zeit vor dem `discipline_key`-Fix) |
| `dist/hero-images/image-mapping.json` | Ergebnis des **letzten** Bild-Laufs (aktuell `[]`), Format `[{disciplineKey, oldUrl, filename, repoPath, rawUrl, altText, title, status}]` |
| `package.json` | `type: module`, Abhängigkeiten `sharp ^0.33.5`, `puppeteer ^23.0.0` (Terser liegt in `node_modules`, ist aber nicht deklariert — siehe Betrieb) |
| `.gitignore` | `node_modules/`, `*.min.js` **außer** `hs-landing.min.js` |
| `.env.example` | Vorlage für `WP_BASE_URL`, `WP_USER`, `WP_APP_PASSWORD`, `HS_SNAPSHOT_API_KEY` |
| `.gitattributes` (noch untracked) | Merge-Treiber für `graphify-out/graph.json` |
| `CLAUDE.md` / `AGENTS.md` (letzteres untracked) | Arbeitsanweisungen für KI-Assistenten: REST-Zugriff, Regeln |
| `graphify-out/` (untracked) | Generierter Abhängigkeitsgraph des Repos (`graph.html`, `GRAPH_REPORT.md`) |

## Tests

### `test-event-coverage.php`

Lädt `cache.php` mit WordPress-Stubs (WP_Error, Transients, `hs_fetch_index`/`hs_fetch_csv` durch Testdaten ersetzt) und prüft `hs_build_event_coverage()`: Gruppierung über `sport`-Spalte und über Tab-Name, Saison-Dedupe, Staleness, `result_list` als Statistikquelle, Fehlerfälle.

### `test-bundle-coverage.php`

Gleiches Muster für das Bundle-Template: leere `bundle`-Spalte, alte Mitgliederliste (rückwärtskompatibel), Abgrenzung zu `clubBundle` und Fall A, Bundle-Totals, Fehlerfall.

```bash
php test-event-coverage.php && php test-bundle-coverage.php
```
