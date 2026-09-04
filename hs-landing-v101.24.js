/**
 * hs-landing.js -- HEIM:SPIEL Dynamic Landing Pages
 * Working build: v101.22
 * Status: reliable / working build (Fixes: hs-tc-Tabellen-Layout --
 * gleiche Spaltenbreiten, Zentrierung, keine Ueberlagerung/Overflow;
 * Header-Titel MATCHES/LIVE SCORES/LIVE TICKER werden jetzt VOLLSTAENDIG
 * angezeigt, ohne "..." -- Ursache war ein CSS-Spezifitaets-Konflikt
 * (thead th schlaegt .hs-tc-th-num), geloest mit !important auf allen
 * ueberschreibenden Properties in .hs-tc-th-num; Spaltenbreite 112px;
 * buildCompetitionSuffix() zeigt Gender-Suffix nur noch fuer female/mixed,
 * NICHT mehr fuer male (Standardfall). gender-Feld selbst + competition_id-
 * Dedupe sind Backend-Fixes in cache.php)
 */

(function () {
  "use strict";

window.hsRenderComplete = false;
function hsMarkRenderComplete() {
  window.hsRenderComplete = true;
  document.body.setAttribute("data-hs-rendered", "true");
}

  const CONFIG = {
    // WordPress Cache-Endpunkte (heimspiel-data-cache Plugin)
    // Daten kommen vom WP-Server statt direkt von Sheety/Google
    SHEETY_INDEX_URL: (window.HS_CACHE_BASE || "") + "/wp-json/hs-cache/v1/index",
    SHEETS_CSV_BASE:  (window.HS_CACHE_BASE || "") + "/wp-json/hs-cache/v1/csv/",
    SHEETY_GENERAL_URL: (window.HS_CACHE_BASE || "") + "/wp-json/hs-cache/v1/generalIndex",
    // DE language endpoints
    SHEETY_INDEX_URL_DE:          (window.HS_CACHE_BASE || "") + "/wp-json/hs-cache/v1/indexDe",
    SHEETY_GENERAL_URL_DE:        (window.HS_CACHE_BASE || "") + "/wp-json/hs-cache/v1/generalIndexDe",
  // Coverage-Endpoint (v84, neu): Länder-/Föderations-Aggregation für
  // Sportarten ohne Disziplin-Zwischenebene (z.B. Fußball, Tennis)
  COVERAGE_BASE: (window.HS_CACHE_BASE || "") + "/wp-json/hs-cache/v1/coverage/",
  // NEU (Event-Template): nach Sportart gruppierte Wettbewerbe eines
  // Multi-Sport-Events, Auswahl per Namensfilter statt kuratierter IDs.
  EVENT_COVERAGE_BASE: (window.HS_CACHE_BASE || "") + "/wp-json/hs-cache/v1/event-coverage/",
  BUNDLE_TOTALS_BASE: (window.HS_CACHE_BASE || "") + "/wp-json/hs-cache/v1/bundle-totals/",
  };

  // ── Language detection ────────────────────────────────────────────────────
  const pageLang = (document.documentElement.lang || "en").toLowerCase().substring(0, 2);
  const isDE     = pageLang === "de";

// NEU: Muss VOR dem ersten renderLoader()-Aufruf stehen (der synchron ganz
// oben im Skript laeuft) -- sonst ist LOADER_TEXT zur Laufzeit noch undefined
// (var-Deklarationen werden gehoistet, Wertzuweisungen NICHT).
var LOADER_TEXT = {
  de: "Daten werden geladen\u2026",
  en: "Loading data\u2026",
  fr: "Chargement des donn\u00e9es\u2026",
  es: "Cargando datos\u2026",
  it: "Caricamento dati in corso\u2026"
};

function renderLoader() {
  var txt = LOADER_TEXT[pageLang] || LOADER_TEXT.en;
  return '<div style="padding:4rem;text-align:center;font-family:Lato,sans-serif;">' +
    '<div style="display:inline-block;width:32px;height:32px;border:3px solid #e75519;border-top-color:transparent;border-radius:50%;animation:hsSpin .8s linear infinite;"></div>' +
    '<p style="color:#6b7280;margin-top:1rem;font-size:.9rem;">' + txt + '</p>' +
    '</div>';
}

  /**
   * resolveUrl(url) -- v82
   * Stellt sicher dass interne relative URLs auf DE-Seiten das /de/-Präfix erhalten.
   * - Absolute URLs (http/https) werden unveraendert zurueckgegeben.
   * - Anchor-Links (#...) werden unveraendert zurueckgegeben.
   * - Relative Pfade die bereits mit /de/ beginnen werden unveraendert zurueckgegeben.
   * - Alle anderen relativen Pfade erhalten auf DE-Seiten /de/ vorangestellt.
   */
  function resolveUrl(url) {
    if (!url) return url;
    if (!isDE) return url;
    if (url.charAt(0) === '#') return url;
    if (url.match(/^https?:\/\//)) return url;
    if (url.match(/^\/de\//)) return url;
    return '/de' + (url.charAt(0) === '/' ? '' : '/') + url;
  }

  /**
   * slugify(str) -- v85, neu
   * Normalisiert einen Bundle-/Sport-Key sprachunabhaengig zu einem reinen
   * ASCII-Slug: Umlaute (ä/ö/ü) und ß werden transliteriert, verbleibende
   * Akzente entfernt, alles klein geschrieben, Leerzeichen/Sonderzeichen zu
   * "-" vereinheitlicht. Verhindert 404s beim Coverage-Endpoint, dessen
   * REST-Route nur [a-zA-Z0-9_-] akzeptiert (z.B. "fußball" -> "fussball").
   * Muss auf BEIDEN Seiten (Frontend-Aufruf UND PHP-Vergleich im Backend)
   * gleich angewendet werden, damit Gross-/Kleinschreibung, Umlaute und
   * Leerzeichen unabhaengig von der Seitensprache (DE/EN/...) immer auf
   * denselben Wert fuehren -- ohne Pflege pro Sprache.
   */
  function slugify(str) {
    if (!str) return "";
    return String(str)
      .trim()
      .toLowerCase()
      .replace(/ä/g, "ae")
      .replace(/ö/g, "oe")
      .replace(/ü/g, "ue")
      .replace(/ß/g, "ss")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .replace(/[^a-z0-9_-]+/g, "-")
      .replace(/-+/g, "-")
      .replace(/^-|-$/g, "");
  }

  const root = document.getElementById("hs-root");
  if (!root) return;

  const type       = root.dataset.type;
  const discipline = root.dataset.discipline;
  const bundle     = root.dataset.bundle;

    injectStyles();

  // CLS-Fix. Der Snapshot liefert die Seite vollstaendig aus; wird sie hier
  // durch den Loader ersetzt, fallen rund 5.900 px Hoehe weg. Der Footer
  // rutscht dadurch von y=6.314 auf y=433, also mitten in den sichtbaren
  // Bereich, und ~700 ms spaeter wieder zurueck. Gemessener CLS: 0,7504 aus
  // genau zwei Spruengen. Mit der Reservierung: 0.
  //
  // Die Schwelle von 400 px sorgt dafuer, dass Seiten OHNE Snapshot sich
  // unveraendert verhalten -- dort ist nichts zu reservieren und der Loader
  // laeuft wie bisher.
  var hsReservedHeight = root.getBoundingClientRect().height;
  if (hsReservedHeight > 400) {
    root.style.minHeight = Math.round(hsReservedHeight) + "px";
  }

  root.innerHTML = renderLoader();

  // Gibt die Reservierung frei, sobald echter Inhalt im Container steht.
  if (root.style.minHeight) {
    var hsHeightObs = new MutationObserver(function () {
      if (root.querySelector(".hs-hero, .hs-stats-bar, .hs-cards-section")) {
        hsHeightObs.disconnect();
        requestAnimationFrame(function () { root.style.minHeight = ""; });
      }
    });
    hsHeightObs.observe(root, { childList: true });
  }

  Promise.all([fetchIndex(), fetchGeneralIndex()])
  .then(([index, general]) => {
    var g = (general || [])[0] || {};
    // Global verfuegbar machen, damit window.hsRenderCompetitionPanel()
    // (Subtask E) auf Labels wie labelTopCompetitions zugreifen kann, ohne
    // dass g durch alle Render-Funktionen durchgereicht werden muss.
    window.hsGeneralIndexData = g;
    if (type === "cluster") renderCluster(root, index, bundle, g);
    else if (type === "detail") renderDetail(root, index, discipline, g);
    else root.innerHTML = "<p>Unbekannter Typ: " + type + "</p>";
  }).catch((err) => {
    root.innerHTML =
      '<p style="color:red;padding:2rem;text-align:center;">Fehler beim Laden der Daten: ' +
      err.message + '</p>';
  });

  // ── Feldsuche ─────────────────────────────────────────────────────────────
  // Liest einen Wert aus dem Sheety-Objekt (obj).
  // Sheety liefert Keys exakt wie der Spaltenname im Sheet (lowercase erster Buchstabe).
  // Kurzbezeichner aus der Tabelle oben werden direkt abgebildet.

function f(obj, key, fallback) {
  if (!obj) return fallback || "";
  // Exact match first
  if (obj[key] !== undefined && String(obj[key]).trim() !== "") {
    return String(obj[key]).trim();
  }
  var lk = key.toLowerCase();
  for (var k in obj) {
    if (k.toLowerCase() === lk && String(obj[k]).trim() !== "") {
      return String(obj[k]).trim();
    }
  }
  return fallback || "";
}

// Mehrzeilige Zelle parsen (Alt+Enter = \n im CSV-Export)
function parseList(str) {
  if (!str) return [];
  return str.split(/\n|\\n|;/).map(function(s) { return s.trim(); }).filter(Boolean);
}

// Mini-Template-Engine fuer SEO-FAQ-Texte aus dem Sheet.
// Unterstuetzt:
//   {displayName} / {bundleName}   -- einfacher Text-Platzhalter
//   {preEvent} {live} {postEvent} {imageLibrary} -- Alt+Enter-Listen aus dem
//     Sheet, natuerlich verknuepft ("A, B and C")
//   {{#feld}}...{{/feld}}          -- Block wird nur gerendert, wenn das
//     Sheet-Feld "feld" truthy ist (z.B. liveCompetitions > 0)
function joinListNatural(items) {
  if (!items || !items.length) return "";
  if (items.length === 1) return items[0];
  return items.slice(0, -1).join(", ") + " and " + items[items.length - 1];
}

function fillPlaceholders(str, obj) {
  if (!str) return str;
  var out = str;

  out = out.replace(/\{\{#(\w+)\}\}([\s\S]*?)\{\{\/\1\}\}/g, function(match, key, inner) {
    var val = f(obj, key, "");
    var num = parseFloat(val);
    var truthy = !isNaN(num) ? num > 0 : !!(val && String(val).trim());
    return truthy ? inner : "";
  });

  ["preEvent", "live", "postEvent", "imageLibrary"].forEach(function(key) {
    var items = parseList(f(obj, key, ""));
    out = out.replace(new RegExp("\\{" + key + "\\}", "g"), joinListNatural(items));
  });

  var dispName = f(obj, "displayName", f(obj, "bundleName", (obj && obj.name) || ""));
  out = out.replace(/\{displayName\}/g, dispName).replace(/\{bundleName\}/g, dispName);

  return out;
}

  // ── Data fetching ─────────────────────────────────────────────────────────

  
  // Normalize object keys: lowercase first char, strip underscores to camelCase
  function normKeys(obj) {
    var out = {};
    for (var k in obj) {
      // Normalize: strip spaces, snake_case → camelCase, then fully lowercase key
      // This ensures "FAQ1headline", "faq1Headline", "faq1headline" all map to "faq1headline"
      var nk = k.trim()
               .replace(/\s+/g, '')
               .replace(/_([a-z])/gi, function(_, c){ return c.toUpperCase(); });
      nk = nk.toLowerCase();
      out[nk] = obj[k];
    }
    return out;
  }

async function fetchIndex() {
  const url = isDE ? CONFIG.SHEETY_INDEX_URL_DE : CONFIG.SHEETY_INDEX_URL;
  const res = await fetch(url);
  if (!res.ok) throw new Error("WP Cache HTTP " + res.status);
  const json = await res.json();
  const rows = json.indexDe || json.index || json[Object.keys(json)[0]] || [];
  if (rows.length > 0) return rows.map(normKeys);
  throw new Error("WP Cache leer");
}

  // ── Coverage Aggregation abrufen (v84, neu) ──────────────────────────────
  // Liefert { totalCompetitions, totalCountries, countries[], international[] }
  // für Sportarten ohne Disziplin-Zwischenebene. Bei Fehler: null (Cluster-Seite
  // zeigt dann einen Lade-/Fehlerhinweis statt abzustürzen).
  async function fetchCoverage(bundleKey) {
    try {
      const res = await fetch(CONFIG.COVERAGE_BASE + encodeURIComponent(bundleKey));
      if (!res.ok) throw new Error("Coverage HTTP " + res.status);
      const json = await res.json();
      if (json && json.error) throw new Error(json.error);
      return json;
    } catch (e) {
      console.warn("[hs-landing] Coverage-Endpoint nicht erreichbar:", e.message);
      return null;
    }
  }

  // NEU (Event-Template): Multi-Sport-Events wie Olympische Spiele. Liefert
  // die nach Sportart gruppierten Wettbewerbe, ausgewaehlt ueber den
  // Namensfilter der Cluster-Zeile (Index-Spalte "nameFilter").
  async function fetchEventCoverage(bundleKey) {
    try {
      const res = await fetch(CONFIG.EVENT_COVERAGE_BASE + encodeURIComponent(bundleKey));
      if (!res.ok) throw new Error("Event-Coverage HTTP " + res.status);
      const json = await res.json();
      if (json && json.error) throw new Error(json.error);
      return json;
    } catch (e) {
      console.warn("[hs-landing] Event-Coverage-Endpoint nicht erreichbar:", e.message);
      return null;
    }
  }

  async function fetchBundleTotals(bundleKey) {
    try {
      const res = await fetch(CONFIG.BUNDLE_TOTALS_BASE + encodeURIComponent(bundleKey));
      if (!res.ok) throw new Error("Bundle-Totals HTTP " + res.status);
      const json = await res.json();
      if (json && json.error) throw new Error(json.error);
      return json;
    } catch (e) {
      console.warn("[hs-landing] Bundle-Totals-Endpoint nicht erreichbar:", e.message);
      return null;
    }
  }

async function fetchSheetCSV(gid) {
  const res = await fetch(CONFIG.SHEETS_CSV_BASE + gid);
  if (!res.ok) throw new Error("WP Cache CSV HTTP " + res.status);
  const text = await res.text();
  if (text.trim().length > 10) return parseCSV(text);
  throw new Error("WP Cache CSV leer");
}


  // ── General Index – Text-Injektion in bereits gerenderte Elemente ──────────
 async function fetchGeneralIndex() {
  const url = isDE ? CONFIG.SHEETY_GENERAL_URL_DE : CONFIG.SHEETY_GENERAL_URL;
  try {
    const res = await fetch(url);
    if (!res.ok) throw new Error("HTTP " + res.status);
    const json = await res.json();
    const rows = json.generalIndexDe || json.generalIndex || json[Object.keys(json)[0]] || [];
    if (rows.length > 0) return rows.map(normKeys);
    throw new Error("leer");
  } catch (e) {
    console.warn("[hs-landing] General Index nicht erreichbar:", e.message);
    return [];
  }
}

  // Hilfsfunktion: Text-Node sicher ersetzen
  function setTxt(el, val) { if (el && val) el.textContent = val; }
  function setHtml(el, val) { if (el && val) el.innerHTML = val; }

  function parseCSV(text) {
    const lines = text.trim().split("\n");
    const headers = splitCSVLine(lines[0]);
    return lines.slice(1).map((line) => {
      const vals = splitCSVLine(line);
      const obj = {};
      headers.forEach((h, i) => { obj[h.trim()] = (vals[i] || "").trim(); });
      return obj;
    });
  }

  function splitCSVLine(line) {
    const result = []; let cur = ""; let inQ = false;
    for (let i = 0; i < line.length; i++) {
      const c = line[i];
      if (c === '"') { inQ = !inQ; }
      else if (c === "," && !inQ) { result.push(cur); cur = ""; }
      else { cur += c; }
    }
    result.push(cur);
    return result;
  }

  function getColumnValues(rows, colLetter) {
    const idx = colIndex(colLetter);
    return rows.slice(1).map((row) => Object.values(row)[idx] || "");
  }

  function colIndex(col) {
    col = (col || "").toUpperCase().trim();
    let idx = 0;
    for (let i = 0; i < col.length; i++) { idx = idx * 26 + col.charCodeAt(i) - 64; }
    return idx - 1;
  }

  // ── Cluster render ────────────────────────────────────────────────────────

 async function renderCluster(root, index, bundleKey, g) {
    // Cluster-Metadaten-Zeile (type="cluster") separat lesen.
    //
    // FIX: Zwei-Phasen-Suche statt einer einzelnen .find() mit 4 ODER-
    // Bedingungen. Vorher konnte eine BUNDLE-Zeile (z.B. "US Sports" mit
    // bundle="Basketball,American_Football,Eishockey,Fußball") faelschlich
    // VOR der eigentlich zustaendigen Einzelsport-Zeile (z.B.
    // "American_Football", disciplinekey="american-football") gefunden
    // werden -- einfach weil Array.find() beim ERSTEN Treffer stoppt,
    // egal ueber welche der 4 Bedingungen er zustande kam, und die
    // Bundle-Zeile im Sheet vor der Einzelsport-Zeile stand.
    //
    // Phase 1: EXAKTER Treffer (bundle, bundlename ODER disciplinekey
    // === bundleKey) hat immer Vorrang -- das ist die eindeutige,
    // korrekte Zuordnung fuer eine eigenstaendige Cluster-Seite.
    let clusterMeta = index.find(
      (d) => (d.type || "").toLowerCase() === "cluster" && (
        (d.bundle || "").toLowerCase() === bundleKey.toLowerCase() ||
        (d.bundlename || "").toLowerCase() === bundleKey.toLowerCase() ||
        (d.disciplinekey || "").toLowerCase() === bundleKey.toLowerCase()
      )
    );
    // Phase 2: NUR falls kein exakter Treffer existiert, auf Gruppen-
    // Mitgliedschaft zurueckfallen (bundleKey ist Teil einer komma-
    // getrennten "bundle"-Liste, z.B. direkter Aufruf von "us-sports").
    if (!clusterMeta) {
      clusterMeta = index.find(
        (d) => (d.type || "").toLowerCase() === "cluster" &&
          (d.bundle || "").toLowerCase().split(",").map(function(s){return s.trim();}).indexOf(bundleKey.toLowerCase()) !== -1
      );
    }
  const canonicalBundleValue = (clusterMeta && clusterMeta.bundle) ? clusterMeta.bundle : bundleKey;

  function inBundle(d) {
      return (d.bundle || "").split(",").map(function(s){ return s.trim().toLowerCase(); })
        .indexOf(canonicalBundleValue.toLowerCase()) !== -1;
    }
  const disciplines = index.filter(
    (d) => inBundle(d) && (d.type || "").toLowerCase() !== "cluster"
  );
  const b = clusterMeta || disciplines[0] || {};

  // ── Struktur-Weiche (v84, neu) ──────────────────────────────────────────
  // Sportarten OHNE eigene Disziplin-Zeilen im Index (z.B. Fußball, Tennis:
  // hunderte Wettbewerbe, keine feste Zwischenebene) beziehen ihre Struktur
  // automatisch aus dem aggregierten /coverage/{sport}-Endpoint statt aus
  // Disziplin-Kacheln. Wintersport (14 Disziplinen) bleibt unverändert,
  // da disciplines.length dort > 0 ist.
  // v85: bundleKey vor dem Coverage-Request normalisieren (slugify), damit
  // Umlaute/ß/Gross-Kleinschreibung im data-bundle-Attribut (z.B. "fußball")
  // sprachunabhaengig auf denselben ASCII-Slug treffen wie im Backend.
  const normalizedBundleKey = slugify(bundleKey);

  // NEU (Bundle-Template): clusterTemplate="bundle" -- zeigt ausschliesslich
  // die in topCompetitions kuratierten Wettbewerbe (koennen aus mehreren
  // Sport-Tabs stammen, siehe hs_build_bundle_coverage() im Backend). Kein
  // Laender-/Foederations-Ausklappblock, keine general-purpose-Disziplin-
  // Kacheln -- unabhaengig davon, ob 1 oder mehrere Sportarten beteiligt sind.
  const rawClusterTemplate = (b.clustertemplate || "").toLowerCase();
  const isBundleTemplate = rawClusterTemplate === "bundle" || rawClusterTemplate === "clubbundle";

  // NEU (Event-Template): Multi-Sport-Events wie Olympische Spiele.
  // Optik des multisport-Templates (Kachel je Sportart), aber OHNE eigene
  // Detailseiten -- die Wettbewerbe klappen wie beim general-purpose-Template
  // direkt in der Kachel auf. Die Auswahl der Wettbewerbe kommt aus dem
  // Namensfilter der Cluster-Zeile, nicht aus kuratierten competition_ids.
  const isEventTemplate = rawClusterTemplate === "event";

  // Struktur-Weiche: Event-Seiten haben wie general-purpose-Seiten keine
  // Disziplin-Zeilen im Index, ziehen ihre Struktur aber aus dem
  // Event-Endpoint. Der regulaere Coverage-Abruf entfaellt dort deshalb.
  const useCoverageMode = disciplines.length === 0 && !isEventTemplate;
  const coverageData = useCoverageMode ? await fetchCoverage(normalizedBundleKey) : null;
  const eventData = isEventTemplate ? await fetchEventCoverage(normalizedBundleKey) : null;


    // NEU: Fuer das Bundle-Template (mehrere Sport-Tabs kuratiert zusammengefuehrt)
    // liefert /bundle-totals/{bundle} die korrekten Summen ueber ALLE kuratierten
    // Wettbewerbe (totalEvents, liveCompetitions/Live Scores, livetickCount).
    // Ohne diesen Abruf zeigte die Stats-Bar faelschlich nur die coverageData-
    // Werte (Laender/Wettbewerbe/Matches EINER Sportart statt der Bundle-Summe).
    const bundleTotals = isBundleTemplate ? await fetchBundleTotals(normalizedBundleKey) : null;

  // TASK 5 (Subtask E): statsTranslations-Map global verfuegbar machen, damit
  // window.hsRenderCompetitionPanel() (Kachel-Panels) dieselben Uebersetzungen
  // nutzen kann wie die Event-Tabelle auf Detail-Seiten (siehe disc._statsMap).
  window.hsStatsTranslationMap = {};
  var rawClusterTrans = (b.statstranslations || g.statstranslations || "").trim();
  if (rawClusterTrans) {
    rawClusterTrans.split(",").forEach(function(pair) {
      var parts = pair.split("=");
      if (parts.length === 2) {
        window.hsStatsTranslationMap[parts[0].trim().toLowerCase()] = parts[1].trim();
      }
    });
  }
    const bundleName = root.dataset.bundleName || f(b, "bundleName", capitalize(bundleKey));

    const bundleGid = (disciplines[0] || {}).gid || "";

    const disciplineData = await Promise.all(
  disciplines.map(async (disc) => {
    let totalEvents = parseInt(disc.totalevents) || 0;
    let livetickCount = parseInt(disc.livetickcount) || 0;
    let liveCompetitions = parseInt(disc.livecompetitions) || 0;
    const gid = disc.gid || bundleGid;
    if (gid && totalEvents === 0 && livetickCount === 0) {
      try {
        const rows = await fetchSheetCSV(gid);
        totalEvents = parseInt(Object.values(rows[0] || {})[0]) || 0;
        livetickCount = parseInt(Object.values(rows[0] || {})[1]) || 0;
      } catch { /* bleib bei 0 */ }
    }
    return {
      name: f(disc, "displayName", disc.name || ""),
      detail_url: disc.detailurl,
      total_events: totalEvents,
      // ACHTUNG Namensfalle: liveticker_count enthaelt liveCompetitions, NICHT
      // den Live-Ticker-Wert. Das bleibt so, weil renderClusterCards() und
      // statBarValues darauf zugreifen -- ein Umbenennen waere hier riskanter
      // als der irritierende Name.
      liveticker_count: liveCompetitions,
      // NEU: der echte redaktionelle Live-Ticker-Wert, bisher nie aggregiert.
      livetick_count: livetickCount,
    };
  })
);
	  
    // Alphabetisch aufsteigend nach Name sortieren
    disciplineData.sort((a, b) => a.name.localeCompare(b.name, "de"));

const totalEvents = disciplineData.reduce((s, d) => s + d.total_events, 0);
  const totalLive = disciplineData.reduce((s, d) => s + d.liveticker_count, 0);

  // Echte Summe der redaktionellen Live-Ticker: 188 bei Wintersport.
  // totalLive ist trotz seines Namens die Summe der Live-Wettbewerbe (373).
  const totalLiveticker = disciplineData.reduce((s, d) => s + (d.livetick_count || 0), 0);

  // Aufzaehlung der Sportarten fuer die neue FAQ, z.B. "Biathlon, Bob, ...
  // und Snowboard". formatEnumeration() setzt "und" bzw. "and" sprachrichtig.
  // Steht hier NACH der alphabetischen Sortierung, damit die Reihenfolge der
  // Aufzaehlung zur Reihenfolge der Kacheln passt.
  const sportsList = formatEnumeration(
    disciplineData.map(function (d) { return d.name; }).filter(Boolean).join("\n")
  );

  // Stats-Bar-Werte: im Coverage-Modus aus coverageData ableiten,
  // sonst unverändert wie bisher aus disciplineData.

  const statBarValues = isEventTemplate
    ? [
        { val: (eventData && eventData.totalSports) || 0, label: f(b, "labelSports", g.labelsports || "Sportarten") },
        { val: (eventData && eventData.totalEvents) || 0, label: f(b, "labelEvents", g.labelevents || "Events gesamt") },
        { val: (eventData && eventData.totalLive) || 0, label: f(b, "labelLive", g.labellive || "Live") },
      ]
    : isBundleTemplate
    ? [
        { val: (bundleTotals && bundleTotals.totalEvents) || 0, label: f(b, "labelSports", g.labelsports || "Spiele") },
        { val: (bundleTotals && bundleTotals.liveCompetitions) || 0, label: f(b, "labelLive", g.labellive || "Live") },
        { val: (bundleTotals && bundleTotals.livetickCount) || 0, label: f(b, "labelLiveticker", g.labelliveticker || "Live-Ticker") },
      ]

    : useCoverageMode
    ? [
        { val: (coverageData && coverageData.totalCountries) || 0, label: f(b, "labelSports", g.labelsports || "Länder") },
        { val: (coverageData && coverageData.totalCompetitions) || 0, label: f(b, "labelEvents", g.labelevents || "Wettbewerbe gesamt") },
        { val: (coverageData && coverageData.totalMatches) || 0, label: f(b, "labelLiveticker", g.labelliveticker || "Matches") },
      ]
    : [
        { val: disciplineData.length, label: f(b, "labelSports", g.labelsports || "Sportarten") },
        { val: totalEvents, label: f(b, "labelEvents", g.labelevents || "Events gesamt") },
        { val: totalLive, label: f(b, "labelLive", g.labellive || "Live Coverage") },
      ];

    // ── Body-Fix: weißen Balken unter dem Header entfernen (mobil) ──────────
    (function() {
      var s = document.getElementById('hs-cluster-body-fix');
      if (!s) {
        s = document.createElement('style');
        s.id = 'hs-cluster-body-fix';
        s.textContent = '.wp-block-html,.wp-block-html>div,.entry-content>.wp-block-html{margin-top:0!important;padding-top:0!important;}' +
          '#hs-lp-root,#hs-root{margin-top:0!important;padding-top:0!important;}' +
          '@media(max-width:768px){body>*:not(script):not(style),.site-main,.main-content,#main,#content,.wp-site-blocks,.is-layout-flow,[class*="wp-container"]{padding-top:0!important;}}';
        document.head.appendChild(s);
      }
    })();

    // v100: Top Competitions NUR dann anzeigen, wenn das Backend echte,
    // explizit definierte Eintraege liefert. Reine Fallback-/Auto-Objekte ohne
    // competition_id/detail_url sollen NICHT als gueltige Top Competitions
    // zaehlen, damit der Block komplett ausblendet und die Coverage-Liste
    // direkt ausgeklappt erscheint.
    // v101 logic folded into v100 output: Top Competitions nur anzeigen,
    // wenn im Cluster-Metadatensatz / Sheet wirklich etwas fuer
    // topCompetitions gepflegt ist. Wenn das Feld leer ist, ignorieren wir
    // ALLE vom Coverage-Endpoint gelieferten Fallback-TopCompetitions
    // (decision=fallback_by_matches etc.) komplett.
    const rawConfiguredTopCompetitions = f(b, "topCompetitions", "");
    const hasConfiguredTopCompetitions = rawConfiguredTopCompetitions
      .split(',')
      .map(function(s){ return s.trim(); })
      .filter(Boolean)
      .length > 0;
const validTopCompetitions = hasConfiguredTopCompetitions && coverageData && Array.isArray(coverageData.topCompetitions)
  ? coverageData.topCompetitions.filter(function(c) {
      if (!c) return false;
      var hasRealTarget = !!(
        (c.competition_id && String(c.competition_id).trim() !== "") ||
        (c.detail_url && String(c.detail_url).trim() !== "")
      );
      var hasRealLabel = !!(
        (c.label && String(c.label).trim() !== "") ||
        (c.competition_name && String(c.competition_name).trim() !== "")
      );
      return hasRealTarget && hasRealLabel;
    })
  : [];

   const hasTopCompetitions = hasConfiguredTopCompetitions && validTopCompetitions.length > 0;

    // FIX: Sportart-Pille nur zeigen, wenn es sich um ein Bundle-Template
    // handelt UND die kuratierten Top Competitions tatsaechlich mehr als
    // eine unterschiedliche Sportart abdecken (z.B. US Sports: NBA+NHL+NFL+
    // Bundesliga). Bei Single-Sport-Seiten (American Football) oder Bundles
    // mit nur einer Sportart bleibt die Pille aus.
    const distinctSportsInTop = new Set(
      (validTopCompetitions || [])
        .map(function(c) { return (c.sport || "").trim().toLowerCase(); })
        .filter(Boolean)
    );
    const showSportPill = isBundleTemplate && distinctSportsInTop.size > 1;

const sportKeyToDisplayName = {};

index.forEach(function(d) {
  if ((d.type || "").toLowerCase() !== "cluster") return;
  var name = f(d, "displayName", d.name || d.bundlename || "");
  if (!name) return;
  [d.bundlename, d.disciplinekey].forEach(function(raw) {
    var key = slugify(raw || "");
    if (key) sportKeyToDisplayName[key] = name;
  });
});
root.innerHTML = renderHeroCluster(bundleName, b, g) + renderStatsBar(statBarValues) +
(isEventTemplate
  // Kein renderCoverageIntro(): renderEventSportCards() bringt Eyebrow,
  // Titel und Trennbalken selbst mit -- wie renderClusterCards() beim
  // multisport-Template. Sonst stuende die Ueberschrift doppelt da.
  ? (eventData && eventData.sports && eventData.sports.length
      ? renderEventSportCards(eventData, b, g, normalizedBundleKey, sportKeyToDisplayName)
      : '<section class="hs-cards-section"><div class="hs-container"><p style="text-align:center;color:#888;padding:2rem;">' + (f(b, "labelNoCompetitions", g.labelnocompetitions || "Keine Wettbewerbe gefunden.")) + '</p></div></section>')
  : isBundleTemplate
  ? renderCoverageIntro(b, g) +
    (hasTopCompetitions
      ? renderTopCompetitionsCards(validTopCompetitions, b, g, normalizedBundleKey, sportKeyToDisplayName, showSportPill)
      : '<section class="hs-cards-section"><div class="hs-container"><p style="text-align:center;color:#888;padding:2rem;">' + (f(b, "labelNoCompetitions", g.labelnocompetitions || "Keine Wettbewerbe konfiguriert.")) + '</p></div></section>')
  : (useCoverageMode ? renderCoverageIntro(b, g) : "") +
    (useCoverageMode && hasTopCompetitions ? renderTopCompetitionsCards(validTopCompetitions, b, g, normalizedBundleKey, sportKeyToDisplayName, showSportPill) : "") +
    (useCoverageMode ? renderCoverageCards(coverageData, b, g, normalizedBundleKey, !hasTopCompetitions) : renderClusterCards(disciplineData, b, g))
) +
renderCoverageSection(b, g) +
  renderSubTextSeparator(b, g) +
  renderRelatedServices(b, index, g) +
  renderIntegrationSection(b) +
  renderMidCTA(b) +
  '<div id="hs-detail-why" class="hs-detail-general-slot"></div>' +
  '<div id="hs-detail-trust" class="hs-detail-general-slot"></div>' +
  '<section id="tech-trust" class="hs-detail-general-slot" style="background:var(--hs-dark,#061d3e);padding:5rem 0;">' +
    '<div class="hs-container" style="padding-left:clamp(1.25rem,5vw,3rem);padding-right:clamp(1.25rem,5vw,3rem);">' +
      '<h2 class="section-title" style="font-size:clamp(1.6rem,4vw,2.4rem);font-weight:900;color:#fff;text-align:center;line-height:1.2;margin:0 0 .5rem 0;display:block;width:100%;"></h2>' +
      '<div style="width:60px;height:4px;background:#e75519;border-radius:2px;margin:10px auto 48px;display:block;"></div>' +
      '<div class="tech-top-grid" style="align-items:end;margin-bottom:3.5rem;">' +
        '<div class="text-center fade-in"><div class="tech-stat-val" style="font-size:clamp(2rem,4vw,2.8rem);font-weight:900;color:#e75519;line-height:1.1;margin-bottom:.5rem;"></div><div style="color:rgba(255,255,255,.75);font-size:.88rem;font-weight:600;"></div></div>' +
        '<div class="text-center fade-in"><div class="tech-stat-val" style="font-size:clamp(2rem,4vw,2.8rem);font-weight:900;color:#e75519;line-height:1.1;margin-bottom:.5rem;"></div><div style="color:rgba(255,255,255,.75);font-size:.88rem;font-weight:600;"></div></div>' +
        '<div class="text-center fade-in"><div class="tech-stat-val" style="font-size:clamp(2rem,4vw,2.8rem);font-weight:900;color:#e75519;line-height:1.1;margin-bottom:.5rem;"></div><div style="color:rgba(255,255,255,.75);font-size:.88rem;font-weight:600;"></div></div>' +
        '<div class="text-center fade-in"><div class="tech-stat-val" style="font-size:clamp(2rem,4vw,2.8rem);font-weight:900;color:#e75519;line-height:1.1;margin-bottom:.5rem;"></div><div style="color:rgba(255,255,255,.75);font-size:.88rem;font-weight:600;"></div></div>' +
      '</div>' +
      '<div class="tech-bottom-grid" style="align-items:start;width:100%;">' +
        '<div class="text-center fade-in"><div class="tech-stat-val" style="font-size:clamp(1.6rem,3vw,2.2rem);font-weight:900;color:#e75519;line-height:1.1;margin-bottom:.5rem;"></div><div style="color:rgba(255,255,255,.75);font-size:.88rem;font-weight:600;"></div></div>' +
        '<div class="text-center fade-in"><div class="tech-stat-val" style="font-size:clamp(1.6rem,3vw,2.2rem);font-weight:900;color:#e75519;line-height:1.1;margin-bottom:.5rem;"></div><div style="color:rgba(255,255,255,.75);font-size:.88rem;font-weight:600;"></div></div>' +
        '<div class="text-center fade-in"><div class="tech-stat-val" style="font-size:clamp(1.6rem,3vw,2.2rem);font-weight:900;color:#e75519;line-height:1.1;margin-bottom:.5rem;"></div><div style="color:rgba(255,255,255,.75);font-size:.88rem;font-weight:600;"></div></div>' +
      '</div>' +
    '</div>' +
  '</section>' +
  '<div id="hs-detail-contact" class="hs-detail-general-slot"></div>' +
  "";
    initAnimations();
    initFullWidthSections();
    setTimeout(function(){ hsFitCompetitionTitles(root); }, 300);
    // General Index: nur Texte in bestehende DOM-Elemente injizieren
    // generalIndex already loaded — g available from parameter
    var seoVars, whyFaqItems;    (function() {
      var r = root;
      var d = document;

      // ── Integration Section (#hs-root) ────────────────────────────────────
      setTxt(r.querySelector('.hs-int-headline'), g.integrationsectionheadline);
      var cards = r.querySelectorAll('.hs-int-card');
      var cardKeys = ['Api', 'Widget', 'Datacenter'];
      cardKeys.forEach(function(k, i) {
        if (!cards[i]) return;
        setTxt(cards[i].querySelector('.hs-int-title'), g['integrationheadline' + k.toLowerCase()]);
        setTxt(cards[i].querySelector('.hs-int-sub'),   g['integrationsub' + k.toLowerCase()]);
        setTxt(cards[i].querySelector('.hs-int-desc'),  g['integrationdescription' + k.toLowerCase()]);
      });

      // ── Mid CTA (#hs-root) ────────────────────────────────────────────────
      setTxt(r.querySelector('.hs-mid-cta-headline'), g.midctaheadline);
      setTxt(r.querySelector('.hs-mid-cta-sub'),      g.midctasubtext);
      setTxt(r.querySelector('.hs-mid-cta .hs-cta-primary'), g.midctabutton);
      setTxt(r.querySelector('.hs-related-title'), g.relatedtitle);

      // ── SEO-FAQ Variablen (Cluster) ──────────────────────────────────────
      
      seoVars = {
        sportartName: f(b, "displayName", bundleName),
        displayName: f(b, "displayName", bundleName),
	sportEyebrow: f(b, "sportEyebrow", ""),
	heroHeadline: f(b, "heroHeadline", bundleName),
        totalCompetitions: useCoverageMode ? ((coverageData && coverageData.totalCompetitions) || "") : disciplineData.length,
        totalCountries: useCoverageMode ? ((coverageData && coverageData.totalCountries) || "") : "",
        // KORREKTUR: Im Disziplin-Cluster stand hier liveCompetitions = totalEvents
        // (711, alle Events) und livetickCount = totalLive (373, die Live-
        // Wettbewerbe). Die Live-FAQ haette damit "711 Live-Wettbewerbe, davon
        // 373 mit Live-Ticker" behauptet -- beide Zahlen falsch. Richtig sind
        // 373 Live-Wettbewerbe und 188 mit redaktionellem Live-Ticker.
        // Die Zweige fuer Bundle- und Coverage-Seiten bleiben unveraendert.
        livetickCount: isBundleTemplate ? ((bundleTotals && bundleTotals.livetickCount) || "") : (useCoverageMode ? ((f(b, "livetickCount", "") || (coverageData && coverageData.totalLiveTicker)) || 0) : totalLiveticker),
        liveCompetitions: isBundleTemplate ? ((bundleTotals && bundleTotals.liveCompetitions) || "") : (useCoverageMode ? (f(b, "liveCompetitions", "") || 0) : totalLive),
        topLeagueNamesList: useCoverageMode ? buildTopLeagueNamesList(validTopCompetitions) : "",
        // Neu fuer die Sportarten-FAQ. Auf Coverage-Seiten bewusst leer --
        // daran haengt, dass die Frage nur auf Multisport-Clustern erscheint.
        sportsCount: useCoverageMode ? "" : disciplineData.length,
        sportsList: useCoverageMode ? "" : sportsList,
        totalEvents: useCoverageMode ? "" : totalEvents,
        preEvent: formatEnumeration(f(b, "preEvent", "")),
        live: formatEnumeration(f(b, "live", "")),
        postEvent: formatEnumeration(f(b, "postEvent", "")),
                // ACHTUNG: Diese beiden Flags steuern AUSSCHLIESSLICH die Formulierung
        // in den FAQ-Texten. Die echte Konstante isBundleTemplate bleibt
        // unberuehrt -- an ihr haengen die Struktur-Weiche, statBarValues und
        // die Zweige weiter oben in diesem Objekt. Sie umzuschalten wuerde die
        // Wintersport-Seite auf das Bundle-Template umleiten.
        //
        // Fuer die Formulierung ist nicht entscheidend, ob ein Bundle vorliegt,
        // sondern ob die Zahlen Events oder Wettbewerbe zaehlen. Beim
        // Multisport-Cluster sind es Events: 711 Events, 373 davon live.
        // "373 competitions live" waere sachlich falsch.
        isBundleTemplate: isBundleTemplate || rawClusterTemplate === "multisport",
        isNotBundleTemplate: !(isBundleTemplate || rawClusterTemplate === "multisport"),
        eyebrow: f(b, "eyebrow", ""),
        name: f(b, "name", ""),
 	  bundleName: bundleName,
        labelSports: f(b, "labelSports", g.labelsports || ""),
        labelEvents: f(b, "labelEvents", g.labelevents || ""),
        labelLive: f(b, "labelLive", g.labellive || ""),
        labelLiveticker: f(b, "labelLiveticker", g.labelliveticker || ""),
        detailsText: f(b, "detailsText", g.detailstext || ""),
        labelTopCompetitions: f(b, "labelTopCompetitions", g.labeltopcompetitions || ""),
        labelFederations: g.labelfederations || ""
      };

      whyFaqItems = buildWhyFaqItems(g, seoVars);

      // ── Dynamisches Rendering der allgemeinen Sektionen ──────────────────
      // Identischer slotsMap-Ansatz wie renderDetail: Slot-Divs werden ersetzt.
      var slotsMap = {};

      // ── WHY / FAQ ─────────────────────────────────────────────────────────
      var whyEyebrow = g.faqeyebrow || "OUR STRENGTHS";
      var whyTitle   = g.faqtitle   || "Why HEIM:SPIEL?";
      var faqIcons = [
        '<svg class="usp-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>',
        '<svg class="usp-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v4M12 19v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M1 12h4M19 12h4M4.22 19.78l2.83-2.83M16.95 7.05l2.83-2.83"/></svg>',
        '<svg class="usp-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
        '<svg class="usp-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>',
        '<svg class="usp-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>',
        '<svg class="usp-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v4M12 19v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M1 12h4M19 12h4M4.22 19.78l2.83-2.83M16.95 7.05l2.83-2.83"/></svg>',
        '<svg class="usp-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
        '<svg class="usp-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13.6 19.79 19.79 0 0 1 1.61 5a2 2 0 0 1 1.95-2H6.5a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 10.1a16 16 0 0 0 6 6l.38-.38a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21 18z"/></svg>',
        '<svg class="usp-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>'
      ];
      var whyItems   = "";
      whyFaqItems.forEach(function(item, idx) {
        var iconSvg = faqIcons[idx % faqIcons.length];
        whyItems += '<div class="faq-item" id="hs-gslot-faq-' + (idx + 1) + '">' +
          '<button class="faq-trigger" onclick="(function(b){' +
            'var item=b.parentNode;' +
            'var panel=b.nextElementSibling;' +
            'var open=item.classList.contains(\'open\');' +
            'item.classList.toggle(\'open\',!open);' +
            'panel.style.display=open?\'none\':\'block\';' +
          '})(this)">' +
            '<span class="usp-icon-wrap">' + iconSvg + '</span>' +
            '<span class="faq-trigger-title">' + item.headline + '</span>' +
            '<span class="faq-arrow">+</span>' +
          '</button>' +
          (item.text ? '<div class="faq-panel" style="display:none"><p class="faq-desc">' + item.text + '</p></div>' : '') +
        '</div>';
      });
            slotsMap["hs-detail-why"] =
        '<section class="hs-gslot-why" id="hs-detail-why-rendered" style="background:var(--hs-dark,#061d3e);padding:5rem 0;">' +
          '<div class="hs-container">' +
            '<p style="font-size:.75rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#e75519;margin-bottom:.5rem;text-align:center;">' + whyEyebrow + '</p>' +
            '<h2 style="font-size:clamp(1.6rem,4vw,2.4rem);font-weight:900;color:#fff;text-align:center;line-height:1.2;margin:0 0 .5rem 0;">' + whyTitle + '</h2>' +
            '<div style="width:60px;height:4px;background:#e75519;border-radius:2px;margin:10px auto 48px;display:block;"></div>' +
            '<div class="faq-accordion">' + whyItems + '</div>' +
          '</div>' +
        '</section>';

      // ── TRUST / PARTNERS ──────────────────────────────────────────────────
      var trustEyebrow = g.partnerseyebrow  || "TRUSTED BY LEADING BRANDS";
      var trustTitle   = g.partnerstitle    || "Our Partners";
      var trustSub     = g.partnerssub      || "";
      var groupsHtml   = "";
      var secMaxLogos  = [8,5,5,4];
      for (var gi = 0; gi < 4; gi++) {
        var secNum = gi + 1;
        var gh = g["partnerssection" + secNum + "headline"] || "";
        var logosHtml = "";
        for (var li = 1; li <= secMaxLogos[gi]; li++) {
          var lv = g["partnerssection" + secNum + "logo" + li] || "";
          if (lv) logosHtml += '<img class="hs-gslot-logo" src="' + lv + '" alt="' + hsLogoAlt(lv) + '" loading="lazy">';
        }
        if (logosHtml) groupsHtml +=
          '<div class="hs-gslot-brand-group">' +
            (gh ? '<p class="hs-gslot-brand-label">' + gh + '</p>' : '') +
            '<div class="hs-gslot-brand-logos">' + logosHtml + '</div>' +
          '</div>';
      }
      slotsMap["hs-detail-trust"] =
        '<section class="hs-gslot-trust" id="hs-detail-trust-rendered">' +
          '<div class="hs-container">' +
            '<p class="hs-gslot-eyebrow hs-gslot-eyebrow--dark" style="text-align:center;">' + trustEyebrow + '</p>' +
            '<h2 class="hs-section-title" style="text-align:center;">' + trustTitle + '</h2>' +
            '<span class="hs-section-bar"></span>' +
            (trustSub ? '<p class="hs-gslot-sub" style="text-align:center;margin:0 auto 2rem;">' + trustSub + '</p>' : '') +
            groupsHtml +
          '</div>' +
        '</section>';

      // ── TECH / TRUST STATS: same as cluster — setTxt on pre-rendered elements ─
      var techEl = r.querySelector("#tech-trust") || r.querySelector("#hs-detail-tech-rendered");
      if (techEl) {
        setTxt(techEl.querySelector('.section-title'), g.trustsectiontitle);
        var statVals = techEl.querySelectorAll('.tech-stat-val');
        statVals.forEach(function(el, i) {
          var idx = i + 1;
          if (g['trustsectionvalue' + idx]) setTxt(el, g['trustsectionvalue' + idx]);
          var sub = el.nextElementSibling;
          if (sub && g['trustsectionsub' + idx]) setTxt(sub, g['trustsectionsub' + idx]);
        });
      }

      // ── CONTACT ───────────────────────────────────────────────────────────
      var cEyebrow = g.contacteyebrow || "";
      var cTitle   = g.contacttitle   || "Get in Touch";
      var cSub     = g.contactsub     || "";
      var cBtn     = g.contactbutton  || "Send Request";
      var fieldsHtml = "";
      var fldKeys = ["contactfield1","contactfield2","contactfield3","contactfield4",
                     "contactfield5","contactfield6","contactfield7","contactfield8"];
      fldKeys.forEach(function(k) {
        var fv = g[k] || "";
        if (!fv) return;
        var parts = fv.split("|");
        var ftype = (parts[1] || "text").trim();
        var fname = (parts[2] || "field").trim();
        var fph   = (parts[0] || "").trim();
        if (ftype === "textarea") {
          fieldsHtml += '<textarea name="' + fname + '" placeholder="' + fph + '" class="hs-gslot-input" rows="4"></textarea>';
        } else {
          fieldsHtml += '<input type="' + ftype + '" name="' + fname + '" placeholder="' + fph + '" class="hs-gslot-input">';
        }
      });
      slotsMap["hs-detail-contact"] =
        '<section class="hs-gslot-contact" id="hs-detail-contact-rendered">' +
          '<div class="hs-container">' +
            '<p class="hs-gslot-eyebrow hs-gslot-eyebrow--dark" style="text-align:center;">' + cEyebrow + '</p>' +
            '<h2 class="hs-section-title" style="text-align:center;">' + cTitle + '</h2>' +
            '<span class="hs-section-bar"></span>' +
            (cSub ? '<p class="hs-gslot-sub" style="text-align:center;margin:0 auto 2rem;">' + cSub + '</p>' : '') +
            '<form class="hs-gslot-form" onsubmit="return false;">' +
              fieldsHtml +
              '<button type="submit" class="hs-cta-primary hs-gslot-submit">' + cBtn + '</button>' +
            '</form>' +
          '</div>' +
        '</section>';

      // ── Replace all slots in ONE pass ─────────────────────────────────────
      Object.keys(slotsMap).forEach(function(slotId) {
        var el = r.querySelector("#" + slotId);
        if (!el) return;
        var tmp = document.createElement("div");
        tmp.innerHTML = slotsMap[slotId];
        el.parentNode.replaceChild(tmp.firstElementChild, el);
      });

      // ── Contact scroll helper ────────────────────────────────────────
      window.hsScrollToContact = function() {
        var t = document.querySelector('#hs-detail-contact-rendered') ||
                document.querySelector('#hs-detail-contact') ||
                document.querySelector('#contact');
        if (t) t.scrollIntoView({behavior: 'smooth'});
      };

})();

    var seoOpts = {
      title:       f(b, "heroHeadline", bundleName) + " Data API | HEIM:SPIEL",
      description: f(b, "description", ""),
      keywords:    f(b, "displayName", bundleName) + " data, sports data API, live scores, statistics, HEIM:SPIEL",
      url:         window.location.href,
      image:       f(b, "heroBgUrl", ""),
      faqItems:    buildWhyFaqItems(g, seoVars)
    };
    injectSEO(seoOpts);

    // Auto-Übersetzung der Competition-Namen (fire-and-forget, nur wenn pageLang != "de")
var hsTranslationPromise = Promise.resolve(null);
// Event-Seiten muessen hier mit hinein: useCoverageMode ist dort bewusst
// false (kein /coverage-Abruf), die Sportart- und Event-Namen brauchen aber
// dieselbe Uebersetzungs-Anmeldung wie bei den anderen Templates.
if (useCoverageMode || isEventTemplate) {
  // Wird von hsPrerenderPanels() und hsRenderCompetitionPanel() fuer die
  // Tabellen-Caption gebraucht, in beiden Faellen ausserhalb dieses Scopes.
  window.hsSportDisplayName = f(b, "displayName", bundleName);

  var compNames = [];
  var seenCompName = {};
  function addCompName(n) {
    var s = String(n || "").trim();
    if (!s || seenCompName[s]) return;
    seenCompName[s] = true;
    compNames.push(s);
  }

  // Bisher wurden ausschliesslich die vier Top-Wettbewerbe angemeldet. Deshalb
  // blieben die Namen in der Laender- und Foederationsliste unuebersetzt --
  // auf der englischen Seite stand "WM" statt "World Cup".
  validTopCompetitions.forEach(function(c){ if (c) addCompName(c.competition_name); });

  // NEU: alle Wettbewerbsnamen der Coverage-Antwort, also auch die 1.065
  // Eintraege in den Panels.
  //
  // Die Statistik-Schluessel bleiben bewusst aussen vor: Das sind technische
  // Feldnamen des Datenlieferanten (ball_win_removed_opponents), bei denen eine
  // KI-Uebersetzung nur Ungenauigkeit erzeugt. Zustaendig ist dafuer das
  // Sheet-Feld statsTranslations.
  if (coverageData) {
    [].concat(coverageData.countries || [], coverageData.international || [])
      .forEach(function(grp) {
        (grp.topCompetitions || []).forEach(function(c) { if (c) addCompName(c.name); });
      });
  }

  // NEU (Event-Template): Sportart-Namen der Kacheln UND die gekuerzten
  // Event-Namen der aufklappbaren Listen anmelden. Beides laeuft ueber
  // dieselbe Mechanik wie bei den anderen Templates:
  //   - Kachel-Namen patcht applyCompetitionTranslations() ueber .hs-card-sport
  //   - Zeilennamen loest compRowHtml() ueber hsTranslateCompName() auf
  // Angemeldet wird der SHORT name, weil genau der als c.name im Panel steht.
  if (eventData && eventData.sports) {
    eventData.sports.forEach(function(sp) {
      addCompName(sp.name);
      (sp.events || []).forEach(function(ev) { addCompName(ev.shortName || ev.name); });
    });
  }

  // Statistik-Beschriftungen derselben Event-Daten separat anmelden. Sie gehen
  // NICHT in compNames: dort gilt der Wettbewerbsnamen-Prompt, der Eigennamen
  // unveraendert laesst -- bei Messgroessen genau das falsche Verhalten.
  // statsPillsHtml() liest das Ergebnis spaeter aus window.hsStatsAiMap.
  if (eventData && eventData.sports) {
    var statTerms = [];
    var seenStat  = {};
    eventData.sports.forEach(function(sp) {
      (sp.events || []).forEach(function(ev) {
        String(ev.statsList || "").split(",").forEach(function(raw) {
          var t = raw.trim();
          if (!t || seenStat[t]) return;
          seenStat[t] = true;
          statTerms.push(t);
        });
      });
    });
    if (statTerms.length) {
      translateEventStats(statTerms, pageLang).then(function(map) {
        if (map) window.hsStatsAiMap = map;
      });
    }
  }

  if (compNames.length) {
    hsTranslationPromise = translateCompetitions(compNames, pageLang, normalizedBundleKey).then(function(translations){
      if (translations) window.hsCompTranslations = translations;
      finalizeCompetitionTranslations(translations, root, validTopCompetitions, seoVars, g, seoOpts);
      hsPatchCompetitionPreviews(root);
      // Erst NACH den Uebersetzungen vorrendern, damit im englischen Snapshot
      // "World Cup" steht und nicht "WM". hsMarkRenderComplete() haengt an
      // .finally() derselben Promise, laeuft also garantiert danach -- der
      // Prerender-Dienst wartet auf data-hs-rendered und bekommt damit den
      // vollstaendigen Stand.
      try { hsPrerenderPanels(root, g); } catch (e) { console.warn("[hs-landing] hsPrerenderPanels:", e); }
    });
  } else {
    try { hsPrerenderPanels(root, g); } catch (e) { console.warn("[hs-landing] hsPrerenderPanels:", e); }
  }
}
hsTranslationPromise.finally(hsMarkRenderComplete);

}

  // ── Detail render ─────────────────────────────────────────────────────────

  async function renderDetail(root, index, disciplineKey, g) {
    const disc = index.find(
      (d) => (d.disciplinekey || "").toLowerCase() === disciplineKey.toLowerCase()
    );
    if (!disc) {
      root.innerHTML = '<p style="padding:2rem;text-align:center;">Disziplin "' + disciplineKey + '" nicht im Index gefunden.</p>';
      return;
    }

    // Daten kommen direkt aus Sheety-Index als TEXTJOIN(CHAR(10);...)-Zellen:
    //   eventsList     → alle Event-Namen, zeilengetrennt
    //   statsList      → Statistik-Werte, zeilengetrennt (gleiche Reihenfolge)
    //   livetickList   → Liveticker-Werte, zeilengetrennt ("Ja", "Anfrage" oder leer)
    function splitList(val) {
      return (val || "").split(/\r?\n|\r/).map(function(s){ return s.trim(); });
    }
    const eventNames = splitList(disc.eventslist);
    const statsVals  = splitList(disc.statslist);
    const ltVals     = splitList(disc.liveticklist);

    const events = eventNames
      .map(function(name, i) {
        return { name: name, stats: statsVals[i] || "", liveticker: ltVals[i] || "" };
      })
      .filter(function(e) { return e.name.trim() !== ""; })
      .sort(function(a, b) { return a.name.localeCompare(b.name, "de"); });

    // totalEvents & livetickCount: aus Liste berechnen (kein separates Feld nötig)
const totalEvents = events.length;
const livetickCount = events.filter(function(e) {
  var lt = e.liveticker.toLowerCase();
  return lt === "ja" || lt === "yes";
}).length;
const liveCompetitions = parseInt(disc.livecompetitions) || 0;

    // statsTranslations: "Ergebnisse=Results,Tabelle=Standings" → disc._statsMap
    disc._statsMap = {};
    var rawTrans = (disc.statstranslations || "").trim();
    if (rawTrans) {
      rawTrans.split(",").forEach(function(pair) {
        var parts = pair.split("=");
        if (parts.length === 2) {
          disc._statsMap[parts[0].trim().toLowerCase()] = parts[1].trim();
        }
      });
    }

    (function() {
      var s = document.getElementById('hs-detail-body-fix');
      if (!s) {
        s = document.createElement('style');
        s.id = 'hs-detail-body-fix';
        s.textContent = '.wp-block-html,.wp-block-html>div,.entry-content>.wp-block-html{margin-top:0!important;padding-top:0!important;}' +
          '#hs-lp-root,#hs-root{margin-top:0!important;padding-top:0!important;}' +
          '@media(max-width:768px){body>*:not(script):not(style),.site-main,.main-content,#main,#content,.wp-site-blocks,.is-layout-flow,[class*="wp-container"]{padding-top:0!important;}}';
        document.head.appendChild(s);
      }
    })();
   root.innerHTML = renderHeroDetail(disc, g) + renderStatsBar([
  { val: totalEvents, label: f(disc, "labelEvents", g.labelevents || "Events gesamt") },
  { val: liveCompetitions, label: f(disc, "labelLive", g.labellive || "Live") },
  { val: livetickCount, label: f(disc, "labelLiveticker", g.labelliveticker || "mit Liveticker") },
]) + renderEventsSection(disc, events, totalEvents, livetickCount, g) + renderDetailCoverageSection(disc, liveCompetitions, g) +
      renderRelatedServices(disc, index, g) +
      renderIntegrationSection({}) +
      renderMidCTA(disc) +
      '<div id="hs-detail-why" class="hs-detail-general-slot"></div>' +
      '<div id="hs-detail-trust" class="hs-detail-general-slot"></div>' +
      '<section id="tech-trust" class="hs-detail-general-slot" style="background:var(--hs-dark,#061d3e);padding:5rem 0;">' +
        '<div class="hs-container" style="padding-left:clamp(1.25rem,5vw,3rem);padding-right:clamp(1.25rem,5vw,3rem);">' +
          '<h2 class="section-title" style="font-size:clamp(1.6rem,4vw,2.4rem);font-weight:900;color:#fff;text-align:center;line-height:1.2;margin:0 0 .5rem 0;display:block;width:100%;"></h2>' +
          '<div style="width:60px;height:4px;background:#e75519;border-radius:2px;margin:10px auto 48px;display:block;"></div>' +
          '<div class="tech-top-grid" style="align-items:end;margin-bottom:3.5rem;">' +
            '<div class="text-center fade-in"><div class="tech-stat-val" style="font-size:clamp(2rem,4vw,2.8rem);font-weight:900;color:#e75519;line-height:1.1;margin-bottom:.5rem;">99,99 %</div><div style="color:rgba(255,255,255,.75);font-size:.88rem;font-weight:600;">Service Uptime</div></div>' +
            '<div class="text-center fade-in"><div class="tech-stat-val" style="font-size:clamp(2rem,4vw,2.8rem);font-weight:900;color:#e75519;line-height:1.1;margin-bottom:.5rem;">24/7</div><div style="color:rgba(255,255,255,.75);font-size:.88rem;font-weight:600;">Support &amp; Monitoring</div></div>' +
            '<div class="text-center fade-in"><div class="tech-stat-val" style="font-size:clamp(2rem,4vw,2.8rem);font-weight:900;color:#e75519;line-height:1.1;margin-bottom:.5rem;">15.000</div><div style="color:rgba(255,255,255,.75);font-size:.88rem;font-weight:600;">Competitions from 50+ sports</div></div>' +
            '<div class="text-center fade-in"><div class="tech-stat-val" style="font-size:clamp(2rem,4vw,2.8rem);font-weight:900;color:#e75519;line-height:1.1;margin-bottom:.5rem;">1 bn+</div><div style="color:rgba(255,255,255,.75);font-size:.88rem;font-weight:600;">Page Impressions / Month</div></div>' +
          '</div>' +
          '<div class="tech-bottom-grid" style="align-items:start;width:100%;">' +
            '<div class="text-center fade-in"><div class="tech-stat-val" style="font-size:clamp(1.6rem,3vw,2.2rem);font-weight:900;color:#e75519;line-height:1.1;margin-bottom:.5rem;">80 Employees</div><div style="color:rgba(255,255,255,.75);font-size:.88rem;font-weight:600;">+\u00a0350 Freelancers from around the world</div></div>' +
            '<div class="text-center fade-in"><div class="tech-stat-val" style="font-size:clamp(1.6rem,3vw,2.2rem);font-weight:900;color:#e75519;line-height:1.1;margin-bottom:.5rem;">Since 2002</div><div style="color:rgba(255,255,255,.75);font-size:.88rem;font-weight:600;">Nearly 25 years of sports data experience</div></div>' +
            '<div class="text-center fade-in"><div class="tech-stat-val" style="font-size:clamp(1.6rem,3vw,2.2rem);font-weight:900;color:#e75519;line-height:1.1;margin-bottom:.5rem;">100+ Sources</div><div style="color:rgba(255,255,255,.75);font-size:.88rem;font-weight:600;">Aggregated data sources worldwide</div></div>' +
          '</div>' +
        '</div>' +
      '</section>' +
      '<div id="hs-detail-contact" class="hs-detail-general-slot"></div>' +
      "";
    initFilter();
    initAnimations();
    initFullWidthSections();
    setTimeout(function(){ if(window.hsInitStatsOverflow) window.hsInitStatsOverflow(); }, 300);
    setTimeout(function(){ hsFitCompetitionTitles(root); }, 300);

    // Related Services swipe indicator
    (function(){
      var track = root.querySelector('.hs-rel-track');
      if (!track) return;
      var wrap = track.closest('.hs-rel-wrap');
      function checkEnd(){
        var atEnd = track.scrollLeft + track.clientWidth >= track.scrollWidth - 4;
        wrap.classList.toggle('hs-rel-end', atEnd);
      }
      track.addEventListener('scroll', checkEnd, {passive:true});
      checkEnd();
    })();

// Auto-Übersetzung, nur wenn pageLanguage != "de" -- render-complete-Flag
// wird erst NACH Abschluss der Übersetzung gesetzt (oder sofort bei "de").
var pageLang = (disc.pagelanguage || document.documentElement.lang || "de").trim();
translateEvents(events, pageLang, disciplineKey).then(function(translations) {
  applyEventTranslations(translations, root);
}).finally(hsMarkRenderComplete);

// General Index Text-Injection in WP-Blöcke
// generalIndex already loaded — g available from parameter

// Diese Variablen müssen im Scope von renderDetail() liegen,
// nicht nur in der nachfolgenden IIFE.
var seoVars;
var whyFaqItems;

(function() {
  var r = root;
  var d = document;
      setTxt(r.querySelector(".hs-int-headline"), g.integrationsectionheadline);
      var cards = r.querySelectorAll(".hs-int-card");
      ["Api","Widget","Datacenter"].forEach(function(k, i) {
        if (!cards[i]) return;
        setTxt(cards[i].querySelector(".hs-int-title"), g["integrationheadline" + k.toLowerCase()]);
        setTxt(cards[i].querySelector(".hs-int-sub"),   g["integrationsub" + k.toLowerCase()]);
        setTxt(cards[i].querySelector(".hs-int-desc"),  g["integrationdescription" + k.toLowerCase()]);
      });
      setTxt(r.querySelector(".hs-mid-cta-headline"), g.midctaheadline);
      setTxt(r.querySelector(".hs-mid-cta-sub"),      g.midctasubtext);
      setTxt(r.querySelector(".hs-mid-cta .hs-cta-primary"), g.midctabutton);
      setTxt(r.querySelector(".hs-related-title"), g.relatedtitle);
      // WP-Blöcke außerhalb hs-root
      var usps = d.getElementById("usps");
      if (usps) {
        setTxt(usps.querySelector(".section-eyebrow"), g.faqeyebrow);
        setTxt(usps.querySelector(".section-title"),   g.faqtitle);
        for (var i = 0; i <= 8; i++) {
          var item = d.getElementById("faq-" + i);
          if (!item) continue;
          var si = i + 1;
setTxt(item.querySelector('.faq-trigger-title'), fillPlaceholders(g['faq' + si + 'headline'], disc));
setTxt(item.querySelector('.faq-desc'), fillPlaceholders(g['faq' + si + 'text'], disc));
        }
      }
      var trust = d.getElementById("trust");
      if (trust) {
        var tPs = trust.querySelectorAll("p");
        setTxt(tPs[0], g.partnerseyebrow);
        setTxt(trust.querySelector(".section-title"), g.partnerstitle);
        setTxt(tPs[1], g.partnerssub);
        var groups = trust.querySelectorAll("#trust-groups > div");
        var maxLogos = [8,5,5,4];
        groups.forEach(function(grp, gi) {
          var sn = gi + 1;
          setTxt(grp.querySelector("h3"), g["partnerssection" + sn + "headline"]);
          var imgs = grp.querySelectorAll("img");
          for (var li = 0; li < maxLogos[gi]; li++) {
            var lv = g["partnerssection" + sn + "logo" + (li+1)];
            if (lv && imgs[li]) imgs[li].src = lv;
          }
        });
      }
      var tech = d.getElementById("tech-trust");
      if (tech) {
        setTxt(tech.querySelector(".section-title"), g.trustsectiontitle);
        tech.querySelectorAll(".tech-stat-val").forEach(function(el, i) {
          setTxt(el, g["trustsectionvalue" + (i+1)]);
          setTxt(el.nextElementSibling, g["trustsectionsub" + (i+1)]);
        });
      }
      var contact = d.getElementById("contact");
      if (contact) {
        var cPs = contact.querySelectorAll("p");
        setTxt(cPs[0], g.contacteyebrow);
        setTxt(contact.querySelector(".section-title"), g.contacttitle);
        setTxt(cPs[1], g.contactsub);
        var flds = contact.querySelectorAll("input[name], textarea[name]");
        ["contactfield1","contactfield2","contactfield3","contactfield4","contactfield5"].forEach(function(fk, fi) {
          if (g[fk] && flds[fi]) flds[fi].placeholder = g[fk];
        });
        setTxt(d.getElementById("form-btn"), g.contactbutton);
      }

      // ── Detail-Seite: allgemeine Slots mit generalIndex-Daten befüllen ──
      // (WP-Blöcke existieren hier nicht → in hs-root rendern)
      function slotTxt(sel, val) { if (val) { var el = d.querySelector(sel); if (el) el.textContent = val; } }

      // ── SEO-FAQ Variablen (Detail) ──────────────────────────────────────
     seoVars = {
        sportartName: f(disc, "displayName", disc.name),
		displayName: f(disc, "displayName", disc.name),
		sportEyebrow: f(disc, "sportEyebrow", ""),
		heroHeadline: f(disc, "heroHeadline", f(disc, "displayName", disc.name)), 
                totalCompetitions: totalEvents,
        totalCountries: "",
        livetickCount: livetickCount,
        // KORREKTUR: Hier stand totalEvents. Bei Biathlon sind das 73, also
        // alle Events -- nicht die live erfassten. Der richtige Wert 42 wird
        // oben aus disc.livecompetitions bereits ermittelt und war ungenutzt.
        liveCompetitions: liveCompetitions,
        topLeagueNamesList: "",
        preEvent: formatEnumeration(f(disc, "preEvent", "")),
        live: formatEnumeration(f(disc, "live", "")),
        postEvent: formatEnumeration(f(disc, "postEvent", "")),
        // NEU: Diese drei Schluessel fehlten hier komplett. Ohne eyebrow blieb
        // "{eyebrow}" als Literal im Satz stehen, und ohne die beiden Flags
        // entfernte applyConditionalBlocks() beide Zweige, sodass das
        // Substantiv fehlte. Detailseiten des Multisport-Templates zaehlen
        // ebenfalls Events, nicht Wettbewerbe.
        eyebrow: f(disc, "eyebrow", ""),
        isBundleTemplate: (String(disc.detailtemplate || "").toLowerCase() === "multisport"),
        isNotBundleTemplate: !(String(disc.detailtemplate || "").toLowerCase() === "multisport")
      };
      whyFaqItems = buildWhyFaqItems(g, seoVars);

      // ── Build all general slots at once (avoids outerHTML DOM-detachment) ──
      var slotsMap = {};

      // ── WHY / FAQ ─────────────────────────────────────────────────────────
      var whyEyebrow = g.faqeyebrow || "OUR STRENGTHS";
      var whyTitle   = g.faqtitle   || "Why HEIM:SPIEL?";
      var faqIcons = [
        '<svg class="usp-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>',
        '<svg class="usp-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v4M12 19v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M1 12h4M19 12h4M4.22 19.78l2.83-2.83M16.95 7.05l2.83-2.83"/></svg>',
        '<svg class="usp-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
        '<svg class="usp-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>',
        '<svg class="usp-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>',
        '<svg class="usp-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v4M12 19v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M1 12h4M19 12h4M4.22 19.78l2.83-2.83M16.95 7.05l2.83-2.83"/></svg>',
        '<svg class="usp-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
        '<svg class="usp-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13.6 19.79 19.79 0 0 1 1.61 5a2 2 0 0 1 1.95-2H6.5a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 10.1a16 16 0 0 0 6 6l.38-.38a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21 18z"/></svg>',
        '<svg class="usp-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>'
      ];
      var whyItems   = "";
      whyFaqItems.forEach(function(item, idx) {
        var iconSvg = faqIcons[idx % faqIcons.length];
        whyItems += '<div class="faq-item" id="hs-gslot-faq-' + (idx + 1) + '">' +
          '<button class="faq-trigger" onclick="(function(b){' +
            'var item=b.parentNode;' +
            'var panel=b.nextElementSibling;' +
            'var open=item.classList.contains(\'open\');' +
            'item.classList.toggle(\'open\',!open);' +
            'panel.style.display=open?\'none\':\'block\';' +
          '})(this)">' +
            '<span class="usp-icon-wrap">' + iconSvg + '</span>' +
            '<span class="faq-trigger-title">' + item.headline + '</span>' +
            '<span class="faq-arrow">+</span>' +
          '</button>' +
          (item.text ? '<div class="faq-panel" style="display:none"><p class="faq-desc">' + item.text + '</p></div>' : '') +
        '</div>';
      });
            slotsMap["hs-detail-why"] =
        '<section class="hs-gslot-why" id="hs-detail-why-rendered" style="background:var(--hs-dark,#061d3e);padding:5rem 0;">' +
          '<div class="hs-container">' +
            '<p style="font-size:.75rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#e75519;margin-bottom:.5rem;text-align:center;">' + whyEyebrow + '</p>' +
            '<h2 style="font-size:clamp(1.6rem,4vw,2.4rem);font-weight:900;color:#fff;text-align:center;line-height:1.2;margin:0 0 .5rem 0;">' + whyTitle + '</h2>' +
            '<div style="width:60px;height:4px;background:#e75519;border-radius:2px;margin:10px auto 48px;display:block;"></div>' +
            '<div class="faq-accordion">' + whyItems + '</div>' +
          '</div>' +
        '</section>';

      // ── TRUST / PARTNERS ──────────────────────────────────────────────────
      var trustEyebrow = g.partnerseyebrow  || "TRUSTED BY LEADING BRANDS";
      var trustTitle   = g.partnerstitle    || "Our Partners";
      var trustSub     = g.partnerssub      || "";
      var groupsHtml   = "";
      var secMaxLogos  = [8,5,5,4];
      for (var gi = 0; gi < 4; gi++) {
        var secNum = gi + 1;
        var gh = g["partnerssection" + secNum + "headline"] || "";
        var logosHtml = "";
        for (var li = 1; li <= secMaxLogos[gi]; li++) {
          var lv = g["partnerssection" + secNum + "logo" + li] || "";
          if (lv) logosHtml += '<img class="hs-gslot-logo" src="' + lv + '" alt="' + hsLogoAlt(lv) + '" loading="lazy">';
        }
        if (logosHtml) groupsHtml +=
          '<div class="hs-gslot-brand-group">' +
            (gh ? '<p class="hs-gslot-brand-label">' + gh + '</p>' : '') +
            '<div class="hs-gslot-brand-logos">' + logosHtml + '</div>' +
          '</div>';
      }
      slotsMap["hs-detail-trust"] =
        '<section class="hs-gslot-trust" id="hs-detail-trust-rendered">' +
          '<div class="hs-container">' +
            '<p class="hs-gslot-eyebrow hs-gslot-eyebrow--dark" style="text-align:center;">' + trustEyebrow + '</p>' +
            '<h2 class="hs-section-title" style="text-align:center;">' + trustTitle + '</h2>' +
            '<span class="hs-section-bar"></span>' +
            (trustSub ? '<p class="hs-gslot-sub" style="text-align:center;margin:0 auto 2rem;">' + trustSub + '</p>' : '') +
            groupsHtml +
          '</div>' +
        '</section>';

      // ── TECH / TRUST STATS: same as cluster — setTxt on pre-rendered elements ─
      var techEl = r.querySelector("#tech-trust") || r.querySelector("#hs-detail-tech-rendered");
      if (techEl) {
        setTxt(techEl.querySelector('.section-title'), g.trustsectiontitle);
        var statVals = techEl.querySelectorAll('.tech-stat-val');
        statVals.forEach(function(el, i) {
          var idx = i + 1;
          if (g['trustsectionvalue' + idx]) setTxt(el, g['trustsectionvalue' + idx]);
          var sub = el.nextElementSibling;
          if (sub && g['trustsectionsub' + idx]) setTxt(sub, g['trustsectionsub' + idx]);
        });
      }

      // ── CONTACT ───────────────────────────────────────────────────────────
      var cEyebrow = g.contacteyebrow || "";
      var cTitle   = g.contacttitle   || "Get in Touch";
      var cSub     = g.contactsub     || "";
      var cBtn     = g.contactbutton  || "Send Request";
      var fieldsHtml = "";
      var fldKeys = ["contactfield1","contactfield2","contactfield3","contactfield4",
                     "contactfield5","contactfield6","contactfield7","contactfield8"];
      fldKeys.forEach(function(k) {
        var fv = g[k] || "";
        if (!fv) return;
        var parts = fv.split("|");
        var ftype = (parts[1] || "text").trim();
        var fname = (parts[2] || "field").trim();
        var fph   = (parts[0] || "").trim();
        if (ftype === "textarea") {
          fieldsHtml += '<textarea name="' + fname + '" placeholder="' + fph + '" class="hs-gslot-input" rows="4"></textarea>';
        } else {
          fieldsHtml += '<input type="' + ftype + '" name="' + fname + '" placeholder="' + fph + '" class="hs-gslot-input">';
        }
      });
      slotsMap["hs-detail-contact"] =
        '<section class="hs-gslot-contact" id="hs-detail-contact-rendered">' +
          '<div class="hs-container">' +
            '<p class="hs-gslot-eyebrow hs-gslot-eyebrow--dark" style="text-align:center;">' + cEyebrow + '</p>' +
            '<h2 class="hs-section-title" style="text-align:center;">' + cTitle + '</h2>' +
            '<span class="hs-section-bar"></span>' +
            (cSub ? '<p class="hs-gslot-sub" style="text-align:center;margin:0 auto 2rem;">' + cSub + '</p>' : '') +
            '<form class="hs-gslot-form" onsubmit="return false;">' +
              fieldsHtml +
              '<button type="submit" class="hs-cta-primary hs-gslot-submit">' + cBtn + '</button>' +
            '</form>' +
          '</div>' +
        '</section>';

      // ── Replace all slots in ONE pass ─────────────────────────────────────
      Object.keys(slotsMap).forEach(function(slotId) {
        var el = r.querySelector("#" + slotId);
        if (!el) return;
        var tmp = document.createElement("div");
        tmp.innerHTML = slotsMap[slotId];
        el.parentNode.replaceChild(tmp.firstElementChild, el);
      });
      // ── Contact scroll helper (set after DOM is ready) ───────────────
      window.hsScrollToContact = function() {
        var t = document.querySelector('#hs-detail-contact-rendered') ||
                document.querySelector('#hs-detail-contact') ||
                document.querySelector('#contact');
        if (t) t.scrollIntoView({behavior: 'smooth'});
      };
    })();
    var dispName = f(disc, "displayName", disc.name);
    injectSEO({
      title:       dispName + " Data & Live Scores API | HEIM:SPIEL",
      description: f(disc, "description", "HEIM:SPIEL provides " + dispName + " data, live scores, statistics and liveticker for publishers and media companies."),
      keywords:    dispName + " data API, " + dispName + " live scores, " + dispName + " statistics, sports data",
      url:         window.location.href,
      image:       f(disc, "heroBgUrl", ""),
      faqItems: whyFaqItems
    });
  }

  // ── Auto-Shrink Competition-Card-Titel ───────────────────────────────────
  // Verhindert, dass lange Wettbewerbsnamen (z.B. "Germany - DFB-Pokal
  // (Female)") die Kartenhoehe der Top-Competitions-/Coverage-Kacheln
  // sprengen. Erzwingt eine einheitliche 2-zeilige Kopf-Hoehe per Inline-
  // Style und verkleinert die Schrift schrittweise, bis der Text passt.
    function hsFitCompetitionTitles(root) {
    root = root || document;
    var MIN_SIZE = 0.62;
    var START_SIZE = 0.82;
    var STEP = 0.02;
    var LINE_HEIGHT = 1.35;
    var heads = root.querySelectorAll('.hs-card-compact .hs-card-head');
    if (!heads.length) return;

    heads.forEach(function (head) {
      var span = head.querySelector('.hs-card-sport');
      if (!span) return;

      // Erzwingt Flagge + Text NEBENEINANDER -- auch mobil. Verhindert,
      // dass eine bestehende Media-Query .hs-card-head auf column-Stapel
      // umschaltet (Flagge ueber dem Text statt daneben), was bei
      // 2-zeiligem Text die Gesamthoehe unnoetig aufblaeht.
      head.style.setProperty('display', 'flex', 'important');
      head.style.setProperty('flex-direction', 'row', 'important');
      head.style.setProperty('flex-wrap', 'nowrap', 'important');
      head.style.setProperty('align-items', 'center', 'important');

      // Text-Span muss den verbleibenden Platz neben der Flagge fuellen,
      // statt in eine neue Zeile/Spalte auszubrechen.
      span.style.setProperty('flex', '1 1 auto', 'important');
      span.style.setProperty('min-width', '0', 'important');

      span.style.setProperty('white-space', 'normal', 'important');
      span.style.setProperty('text-overflow', 'unset', 'important');
      span.style.setProperty('line-height', String(LINE_HEIGHT), 'important');
      span.style.setProperty('display', 'block', 'important');
      span.style.setProperty('overflow', 'visible', 'important');
      span.style.removeProperty('-webkit-line-clamp');
      span.style.removeProperty('-webkit-box-orient');

      var size = START_SIZE;
      span.style.setProperty('font-size', size + 'em', 'important');

      function twoLineTargetPx() {
        var fontPx = parseFloat(getComputedStyle(span).fontSize);
        return fontPx * LINE_HEIGHT * 2;
      }

      var attempts = 0;
      while (span.scrollHeight > twoLineTargetPx() + 1 && size > MIN_SIZE && attempts < 30) {
        size -= STEP;
        span.style.setProperty('font-size', size + 'em', 'important');
        attempts++;
      }

      span.style.setProperty('display', '-webkit-box', 'important');
      span.style.setProperty('-webkit-box-orient', 'vertical', 'important');
      span.style.setProperty('-webkit-line-clamp', '2', 'important');
      span.style.setProperty('overflow', 'hidden', 'important');
    });

    // Nach dem Row-Layout-Fix + Font-Fitting: einheitliche Hoehe anhand
    // der tatsaechlich hoechsten Karte im aktuellen Grid ermitteln.
    var maxHeight = 0;
    heads.forEach(function (head) {
      head.style.removeProperty('min-height');
    });
    heads.forEach(function (head) {
      if (head.offsetHeight > maxHeight) maxHeight = head.offsetHeight;
    });
    heads.forEach(function (head) {
      head.style.setProperty('min-height', maxHeight + 'px', 'important');
    });
  }

  // ── Loader ────────────────────────────────────────────────────────────────


  // ── Integration Section (dunkelblauer Hintergrund, 3 Varianten) ──────────
  // Sheet-Bezeichner: integrationSectionHeadline, integrationHeadlineApi,
  // integrationHeadlineWidget, integrationHeadlineDatacenter,
  // integrationSubApi, integrationSubWidget, integrationSubDatacenter,
  // integrationDescriptionApi, integrationDescriptionWidget, integrationDescriptionDatacenter

  function renderIntegrationSection(b) {
    var secHeadline = f(b, "integrationSectionHeadline", "Choose Your Integration");

    var cards = [
      {
        key:   "Api",
        title: f(b, "integrationHeadlineApi",        "JSON API"),
        sub:   f(b, "integrationSubApi",             "Direct data access"),
        desc:  f(b, "integrationDescriptionApi",
          "Connect directly to our REST API and receive structured sports data in JSON format. Full control over display and logic \u2013 ideal for developers building custom products."),
        svg:   '<svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">' +
               '<rect x="4" y="12" width="40" height="28" rx="4" stroke="#e75519" stroke-width="2.5" fill="none"/>' +
               '<path d="M4 20h40" stroke="#e75519" stroke-width="2"/>' +
               '<circle cx="11" cy="16" r="1.5" fill="#e75519"/>' +
               '<circle cx="17" cy="16" r="1.5" fill="#e75519"/>' +
               '<circle cx="23" cy="16" r="1.5" fill="#e75519"/>' +
               '<path d="M13 30l-4 2 4 2M35 30l4 2-4 2" stroke="#e75519" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
               '<path d="M27 27l-6 10" stroke="#e75519" stroke-width="2" stroke-linecap="round"/>' +
               '</svg>'
      },
      {
        key:   "Widget",
        title: f(b, "integrationHeadlineWidget",     "Customizable Widgets"),
        sub:   f(b, "integrationSubWidget",          "Plug & play integration"),
        desc:  f(b, "integrationDescriptionWidget",
          "Embed ready-to-use iFrame or JavaScript widgets directly into your site. Fully customizable to match your brand design \u2013 no development effort required."),
        svg:   '<svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">' +
               '<rect x="3" y="7" width="26" height="20" rx="3" stroke="#e75519" stroke-width="2.5" fill="none"/>' +
               '<rect x="19" y="23" width="26" height="18" rx="3" stroke="#e75519" stroke-width="2.5" fill="none"/>' +
               '<path d="M9 17h14M9 21h8" stroke="#e75519" stroke-width="2" stroke-linecap="round"/>' +
               '<path d="M25 30h14M25 34h8" stroke="#e75519" stroke-width="2" stroke-linecap="round"/>' +
               '</svg>'
      },
      {
        key:   "Datacenter",
        title: f(b, "integrationHeadlineDatacenter", "Hosted Data Center"),
        sub:   f(b, "integrationSubDatacenter",      "Fully managed solution"),
        desc:  f(b, "integrationDescriptionDatacenter",
          "Get a turnkey hosted data solution: ready-to-publish content pages, stats centers and widgets \u2013 hosted and maintained by HEIM:SPIEL with zero technical overhead for you."),
        svg:   '<svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">' +
               '<rect x="6" y="8" width="36" height="10" rx="3" stroke="#e75519" stroke-width="2.5" fill="none"/>' +
               '<rect x="6" y="22" width="36" height="10" rx="3" stroke="#e75519" stroke-width="2.5" fill="none"/>' +
               '<rect x="6" y="36" width="36" height="6" rx="3" stroke="#e75519" stroke-width="2.5" fill="none"/>' +
               '<circle cx="37" cy="13" r="2" fill="#e75519"/>' +
               '<circle cx="37" cy="27" r="2" fill="#e75519"/>' +
               '<circle cx="37" cy="39" r="2" fill="#e75519"/>' +
               '<path d="M12 13h16M12 27h16" stroke="#e75519" stroke-width="2" stroke-linecap="round"/>' +
               '</svg>'
      }
    ];

    var cardHtml = cards.map(function(c) {
      return '<div class="hs-int-card">' +
        '<div class="hs-int-icon">' + c.svg + '</div>' +
        '<div class="hs-int-sub">' + c.sub + '</div>' +
        '<h3 class="hs-int-title">' + c.title + '</h3>' +
        '<p class="hs-int-desc">' + c.desc + '</p>' +
      '</div>';
    }).join('');

    return '<section class="hs-integration">' +
      '<div class="hs-container">' +
        '<h2 class="hs-int-headline">' + secHeadline + '</h2>' +
        '<span class="hs-int-bar"></span>' +
        '<div class="hs-int-grid">' + cardHtml + '</div>' +
      '</div>' +
    '</section>';
  }

  // ── Mid-CTA Section (weisser Hintergrund) ────────────────────────────────
  // Sheet-Bezeichner: midCtaHeadline, midCtaSubtext, midCtaButton

  function renderMidCTA(b) {
    var dispName = f(b, "displayName", f(b, "bundleName", ""));
    var headline = f(b, "midCtaHeadline",
      "Want to learn more about HEIM:SPIEL\u2019s " + dispName + " coverage?");
    var subtext  = f(b, "midCtaSubtext",
      "Contact us today and schedule a call with one of our experts.");
    var btnTxt   = f(b, "midCtaButton", "Talk to Us");

    return '<section class="hs-mid-cta">' +
      '<div class="hs-container hs-mid-cta-inner">' +
        '<div class="hs-mid-cta-text">' +
          '<h2 class="hs-mid-cta-headline">' + headline + '</h2>' +
          '<p class="hs-mid-cta-sub">' + subtext + '</p>' +
        '</div>' +
        '<a href="#contact" class="hs-cta-primary"' +' onclick="event.preventDefault();if(window.hsScrollToContact)window.hsScrollToContact();">' + btnTxt + '</a>' +
      '</div>' +
    '</section>';
  }

  // ── SEO: JSON-LD + Meta Description ──────────────────────────────────────
  // Schreibt strukturierte Daten fuer Google + LLM-Crawler in den Head
  // Wird aufgerufen nach render, wenn Daten vorhanden sind

  // ── SEO: Template-Platzhalter ersetzen ─────────────────────────────────────
  function isTemplateVarTruthy(val) {
    if (val === undefined || val === null || val === "" || val === false) return false;
    if (val === 0 || val === "0") return false;
    return true;
  }

  function applyConditionalBlocks(str, vars) {
    return String(str).replace(/\{\{#(\w+)\}\}([\s\S]*?)\{\{\/\1\}\}/g, function (m, key, inner) {
      return isTemplateVarTruthy(vars[key]) ? inner : "";
    });
  }

  function fillSeoTemplate(str, vars) {
    var withConditionals = applyConditionalBlocks(str, vars);
    return String(withConditionals).replace(/\{(\w+)\}/g, function (m, key) {
      return (vars[key] !== undefined && vars[key] !== null && vars[key] !== "")
        ? vars[key] : m;
    });
  }

  function formatEnumeration(raw) {
    if (!raw) return "";
    var items = String(raw)
      .split(/\n|\\n|;|,/)
      .map(function (s) { return s.trim(); })
      .filter(Boolean);
    if (!items.length) return "";
    if (items.length === 1) return items[0];
    var conjunction = isDE ? "und" : "and";
    return items.slice(0, -1).join(", ") + " " + conjunction + " " + items[items.length - 1];
  }

  // ── SEO-FAQ Templates (aus General_Index, generisch fuer jede Sportart) ────
  // Erwartet Sheet-Spalten: seoFaqTpl1Headline/Text ... seoFaqTpl4Headline/Text
  // Jedes Template wird NUR gerendert, wenn ALLE benoetigten Variablen einen
  // nicht-leeren Wert haben (kein "undefined"/kaputter Text im Frontend).
  // KORREKTUR der Zuordnung. Die geforderten Variablen passten nicht zu den
  // Texten, die im Sheet unter derselben Nummer stehen:
  //
  //   Slot 2 verlangte topLeagueNamesList. Der Text dort fragt nach Live-Daten
  //   und braucht nur liveCompetitions. Auf Disziplin-Clustern ist
  //   topLeagueNamesList immer leer -- deshalb fehlte die Live-Frage dort ganz.
  //
  //   Slot 3 verlangte liveCompetitions und livetickCount. Der Text dort fragt
  //   nach Statistiken und braucht displayName, preEvent und postEvent.
  //
  //   Slot 1 braucht zusaetzlich topLeagueNamesList: Beide Textzweige enden
  //   auf "..., darunter {topLeagueNamesList}." Fehlt der Wert, stand dort
  //   bisher "darunter ." mit leerer Aufzaehlung.
  //
  // Slot 4 ist neu und greift nur auf Multisport-Clustern, weil sportsList
  // auf Coverage-Seiten leer bleibt.
  var SEO_FAQ_TEMPLATES = [
    // eyebrow gehoert in beide Bedingungen: Die Texte von Slot 1 und 2 beginnen
    // mit "{eyebrow}". Fehlt der Wert, stand dort die geschweifte Klammer im
    // fertigen Satz. Auf Detailseiten ist eyebrow im Sheet leer -- dort bleibt
    // die Live-Frage damit aus, statt fehlerhaft zu erscheinen.
    { n: 1, vars: ["eyebrow", "totalCompetitions", "totalCountries", "topLeagueNamesList"] },
    { n: 2, vars: ["eyebrow", "liveCompetitions"] },
    { n: 3, vars: ["displayName", "preEvent", "postEvent"] },
    { n: 4, vars: ["sportsList", "totalEvents"] }
  ];

  function buildSeoFaqItems(g, vars) {
    var items = [];
    SEO_FAQ_TEMPLATES.forEach(function (tpl) {
      var headlineRaw = g["seofaqtpl" + tpl.n + "headline"];
      var textRaw     = g["seofaqtpl" + tpl.n + "text"];
      if (!headlineRaw || !textRaw) return;
      var missing = tpl.vars.some(function (v) {
        return vars[v] === undefined || vars[v] === null || vars[v] === "";
      });
      if (missing) return;
      if (tpl.vars.indexOf("liveCompetitions") !== -1) {
        var lc = vars.liveCompetitions;
        if (lc === 0 || lc === "0" || Number(lc) === 0) return;
      }
items.push({
        headline: fillSeoTemplate(applyConditionalBlocks(headlineRaw, vars), vars),
        text: fillSeoTemplate(applyConditionalBlocks(textRaw, vars), vars),
        seoTpl: tpl.n
      });
    });
    return items;
  }


  // ── Kombiniert bestehende Trust-FAQs (Slot 1-9) + generische SEO-FAQs ─────
  // Bestehende Trust-FAQs bleiben unangetastet und werden zuerst uebernommen,
  // SEO-FAQs werden dahinter angehaengt (Slot 10+), sodass sie NICHT bestehende
  // manuell gepflegte Inhalte ueberschreiben oder verdraengen.
  function buildWhyFaqItems(g, seoVars) {
    var items = [];
    for (var i = 1; i <= 9; i++) {
      var q = g["faq" + i + "headline"];
      var a = g["faq" + i + "text"];
      if (q) items.push({ headline: q, text: a || "" });
    }
    buildSeoFaqItems(g, seoVars).forEach(function (item) { items.push(item); });
    return items;
  }

  // ── Baut den {topLeagueNamesList}-Wert aus validTopCompetitions ───────────
  function buildTopLeagueNamesList(validTopCompetitions) {
    if (!validTopCompetitions || !validTopCompetitions.length) return "";
    var names = validTopCompetitions.map(function (c) {
      return buildCompetitionDisplayLabel(c);
    }).filter(Boolean);
    if (!names.length) return "";
    if (names.length === 1) return names[0];
    return names.slice(0, -1).join(", ") + " und " + names[names.length - 1];
  }


  // ── SEO: nur noch der Dokumenttitel ───────────────────────────────────────
  // Description, Open Graph, Twitter Cards, Canonical, robots und JSON-LD
  // kommen seit Subtask 2-4 serverseitig aus dem MU-Plugin hs-seo-meta.php
  // und stehen damit schon im initialen HTML. Die clientseitige Injektion
  // wurde entfernt, weil sie
  //   - die serverseitigen Werte im DOM ueberschrieben hat (zwei Quellen
  //     der Wahrheit fuer dieselben Tags),
  //   - das Canonical auf window.location.href gesetzt hat -- inklusive
  //     Query-Parametern wie ?utm_source= oder ?gclid=, was ein Canonical
  //     nie enthalten darf,
  //   - das serverseitige robots-Tag "max-image-preview:large" durch
  //     "index,follow" ersetzt und damit grosse Bildvorschauen in den
  //     Suchergebnissen verloren hat,
  //   - und ein zweites JSON-LD erzeugt hat, das die USP-Aussagen aus
  //     Slot 1-9 als schema.org-Question ausgegeben hat (Richtlinien-
  //     verstoss) sowie ein Offer ohne price (ungueltig).
  //
  // document.title bleibt bis auf Weiteres hier, weil der serverseitige
  // <title> noch aus dem WordPress-Seitentitel kommt. Sobald der Title
  // ebenfalls serverseitig gesetzt wird, kann auch das entfallen.
  
// Seit Subtask 6 setzt hs-seo-meta.php auch den <title> serverseitig ueber
  // pre_get_document_title. Ein clientseitiges document.title wuerde ihn nur
  // wieder ueberschreiben -- und zwar mit einem abweichenden Wert, weil hier
  // zusaetzlich " Data API | HEIM:SPIEL" angehaengt wurde ("API & Widgets
  // Data API"). Die Funktion bleibt als leerer Platzhalter bestehen, damit
  // die beiden bestehenden Aufrufstellen unveraendert bleiben koennen.
  function injectSEO(opts) {
    // Absichtlich leer -- alle SEO-Daten kommen serverseitig.
  }


  // ── Related Services Slider ───────────────────────────────────────────────
  // Sheet-Feld: relatedServices (komma-getrennte Namen, z.B. "Winter Sports Package,Olympics Package")
  // Sucht in index nach eyebrow-Match → URL aus disc.url
  // Gradient: deterministisch aus Name-Hash (Spotify-Style)

  function strHash(str) {
    var h = 0;
    for (var i = 0; i < str.length; i++) {
      h = (Math.imul(31, h) + str.charCodeAt(i)) | 0;
    }
    return Math.abs(h);
  }

  function serviceGradient(name) {
    var h = strHash(name);
    var hue1 = h % 360;
    var hue2 = (hue1 + 40 + (h % 60)) % 360;
    var sat  = 55 + (h % 25);
    var lit1 = 28 + (h % 12);
    var lit2 = 20 + (h % 10);
    return "linear-gradient(135deg, hsl(" + hue1 + "," + sat + "%," + lit1 + "%) 0%, hsl(" + hue2 + "," + (sat-10) + "%," + lit2 + "%) 100%)";
  }

  // ── Detail Coverage Section ──────────────────────────────────────────────
  // Zeigt Pre-Event / Live (nur wenn livetickCount > 0) / Post-Event / Add-Ons
  // zwischen der Events-Tabelle und den Related Services auf Detailseiten.
  function renderDetailCoverageSection(disc, livetickCount, g) {
    var preItems  = parseList(f(disc, 'preEvent',     ''));
    var liveItems = parseList(f(disc, 'live',         ''));
    var postItems = parseList(f(disc, 'postEvent',    ''));
    var imgItems  = parseList(f(disc, 'imageLibrary', ''));

    if (!preItems.length && !liveItems.length && !postItems.length && !imgItems.length) return '';

    function cardSVG(type) {
      var svgs = {
        pre:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        live: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>',
        post: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
        img:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>'
      };
      return svgs[type] || '';
    }

    function buildCard(label, items, svgType) {
      if (!items.length) return '';
      return '<div class="hs-cov-card fade-in">' +
        '<div class="hs-cov-head">' +
          '<div class="hs-cov-icon">' + cardSVG(svgType) + '</div>' +
          '<h3 class="hs-cov-name">' + label + '</h3>' +
        '</div>' +
        '<div class="hs-cov-body"><ul class="hs-cov-list">' +
          items.map(function(t) { return '<li>' + t + '</li>'; }).join('') +
        '</ul></div>' +
      '</div>';
    }

    // Live-Karte nur rendern wenn livetickCount > 0
    var showLive = livetickCount > 0 && liveItems.length > 0;

    var cards = [
      buildCard((g.labelpreevent || 'Pre-Event'),  preItems,  'pre'),
      showLive ? buildCard((g.labellive || 'Live'), liveItems, 'live') : '',
      buildCard((g.labelpostevent || 'Post-Event'), postItems, 'post'),
      buildCard((g.labelimagelib || 'Add-Ons'),    imgItems,  'img')
    ].filter(Boolean);

    if (!cards.length) return '';

    var colCount = cards.length;

    var subText = f(disc, 'subText', '');
    var subTextHtml = subText
      ? '<div class="hs-subtext-bar">' +
          '<div class="hs-container">' +
            '<span class="hs-section-bar" style="margin:0 auto 1.25rem;display:block;"></span>' +
            '<p class="hs-subtext">' + subText + '</p>' +
          '</div>' +
        '</div>'
      : '';

        // NEU (SEO Paket 1): H2 ueber die Karten, Erklaerungstext (subText)
    // darunter statt darueber.
    var dpTitle = fillPlaceholders(
      (g.datapointstitle || 'Welche {displayName}-Daten liefert HEIM:SPIEL?'),
      disc
    );

    return '<section class="hs-coverage-section hs-detail-coverage">' +
      '<div class="hs-container">' +
        (dpTitle
          ? '<h2 class="hs-section-title">' + dpTitle + '</h2>' +
            '<span class="hs-section-bar"></span>'
          : '') +
        '<div class="hs-cov-grid" style="grid-template-columns:repeat(' + colCount + ',1fr)">' +
          cards.join('') +
        '</div>' +
      '</div>' +
    '</section>' +
    subTextHtml;
  }


  function renderRelatedServices(disc, index, g) {
    var raw = (disc.relatedservices || "").trim();
    if (!raw) return "";
    var names = raw.split(",").map(function(s) { return s.trim(); }).filter(Boolean);
    if (!names.length) return "";

    var label = (g.relatedtitle || "Related Services");

    var cards = names.map(function(name) {
      // Suche nach eyebrow-Match im Index (case-insensitive)
      var match = null;
      for (var i = 0; i < index.length; i++) {
        var eyebrow = (index[i].eyebrow || index[i].bundlename || index[i].name || "").trim();
        if (eyebrow.toLowerCase() === name.toLowerCase()) {
          match = index[i];
          break;
        }
      }
      // v82: resolveUrl() kapselt DE-Präfix-Logik + EN/DE URL-Auswahl
      var rawUrl = match ? (isDE ? (match.detailurl || "") : (match.url || match.clusterurl || match.detailurl || "")) : "";
      var url    = resolveUrl(rawUrl);
      var grad = serviceGradient(name);

      // NEU: Falls das verlinkte Package verfuegbar ist (echte url) UND
      // eine heroBgUrl im Index Sheet gepflegt hat, wird dessen Hero-Bild
      // mit 50% Opacity als Hintergrund-Layer HINTER dem Gradient/Text
      // eingeblendet. Ohne gueltige url (kein Match/kein Link) oder ohne
      // heroBgUrl bleibt die Kachel wie bisher rein im Gradient-Look.
      var heroBgUrl = match ? f(match, "heroBgUrl", "") : "";
      var showBg = !!(url && heroBgUrl);
      var bgLayerHtml = showBg
        ? '<div class="hs-rel-card-bgimg" style="position:absolute;inset:0;background-image:url(\'' + heroBgUrl.replace(/'/g, "%27") + '\');background-size:cover;background-position:center;opacity:.5;z-index:0;"></div>'
        : "";

      var inner = '<div class="hs-rel-card-inner" style="position:relative;overflow:hidden;background:' + grad + ';">' +
        bgLayerHtml +
        '<span class="hs-rel-card-name" style="position:relative;z-index:1;">' + name + '</span>' +
        (url ? '<span class="hs-rel-card-arrow" style="position:relative;z-index:1;">\u2192</span>' : "") +
      '</div>';
      return url
        ? '<a href="' + url + '" class="hs-rel-card">' + inner + '</a>'
        : '<div class="hs-rel-card hs-rel-card--nolink">' + inner + '</div>';
    }).join("");

    return '<section class="hs-related">' +
      '<div class="hs-container">' +
        '<h2 class="hs-section-title hs-related-title">' + label + '</h2>' +
        '<span class="hs-section-bar"></span>' +
        '<div class="hs-rel-wrap">' +
          '<button class="hs-rel-arrow hs-rel-prev" aria-label="Previous" onclick="(function(b){var w=b.closest(\'.hs-rel-wrap\');var t=w.querySelector(\'.hs-rel-track\');t.scrollBy({left:-280,behavior:\'smooth\'});})(this)">&#8592;</button>' +
          '<div class="hs-rel-track">' + cards + '</div>' +
          '<button class="hs-rel-arrow hs-rel-next" aria-label="Next" onclick="(function(b){var w=b.closest(\'.hs-rel-wrap\');var t=w.querySelector(\'.hs-rel-track\');t.scrollBy({left:280,behavior:\'smooth\'});})(this)">&#8594;</button>' +
        '</div>' +
      '</div>' +
    '</section>';
  }


  // ── Auto-Übersetzung via OpenAI (fire-and-forget, nur wenn pageLanguage != "de") ──
  // Sheet-Feld: pageLanguage (z.B. "en", "fr", "es") — wenn leer/de: kein API-Call
  // API-Key: window.HS_OPENAI_KEY (im WP als JS-Variable gesetzt, nie im Code)

  // ── Auto-Übersetzung (serverseitig via WP-REST, KEIN API-Key im Frontend) ──
  // v92: OpenAI-Aufruf laeuft NICHT mehr im Browser, sondern ausschliesslich
  // serverseitig per WP-Cron (siehe hs-translations.php). Das Frontend fragt
  // nur GET /translations/{lang}/{cacheKey} ab (reiner DB-Read, keine Kosten)
  // und meldet per POST fehlende Strings zur einmaligen Hintergrund-Uebersetzung
  // an. Bereits uebersetzte Begriffe werden NIE erneut angefragt, da Event-/
  // Wettbewerbsnamen sich praktisch nie aendern -- die Uebersetzung erfolgt
  // dauerhaft in der WP-Datenbank (wp_options), nicht mehr im sessionStorage.
  const HS_TRANSLATIONS_BASE = "/wp-json/hs-cache/v1/translations/";

  async function fetchStoredTranslations(targetLang, cacheKey) {
    try {
      var res = await fetch(HS_TRANSLATIONS_BASE + targetLang + "/" + encodeURIComponent(cacheKey));
      if (!res.ok) return {};
      var data = await res.json();
      return data.translations || {};
    } catch (e) {
      return {};
    }
  }

  function queueMissingTranslations(targetLang, cacheKey, strings) {
    if (!strings.length) return;
    // Fire-and-forget: meldet fehlende Strings an, loest serverseitig einen
    // WP-Cron-Job aus (kein synchroner OpenAI-Call, keine Kosten pro Besucher).
    fetch(HS_TRANSLATIONS_BASE + targetLang + "/" + encodeURIComponent(cacheKey), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ strings: strings })
    }).catch(function() {});
  }

  async function translateEvents(events, targetLang, disciplineKey) {
    if (!targetLang || targetLang.toLowerCase().startsWith("de")) return null;

    var strings = [];
    var seen = {};
    function addStr(s) {
      if (!s || !s.trim()) return;
      var key = s.trim();
      if (!seen[key]) { seen[key] = true; strings.push(key); }
    }
    events.forEach(function(e) {
      addStr(e.name);
      e.stats.split(",").forEach(function(s) { addStr(s.trim()); });
    });
    if (!strings.length) return null;

    var cacheKey = "events_" + disciplineKey;
    var stored = await fetchStoredTranslations(targetLang, cacheKey);
    var missing = strings.filter(function(s) { return !(s in stored); });
    if (missing.length) queueMissingTranslations(targetLang, cacheKey, missing);
    return stored;
  }

  // DOM-Injection: Übersetzungen nach Render in bestehende Zellen schreiben
  function applyEventTranslations(translations, root) {
    if (!translations) return;
    // Event-Namen in erster Spalte
    root.querySelectorAll(".hs-event-name").forEach(function(td) {
      var orig = td.textContent.trim();
      if (translations[orig]) td.textContent = translations[orig];
    });
    // Stat-Tags
    root.querySelectorAll(".hs-stat-tag").forEach(function(span) {
      var orig = span.textContent.trim();
      if (translations[orig]) span.textContent = translations[orig];
    });
  }

  // ── Auto-Übersetzung der Competition-Namen (Coverage-Cluster-Seiten) ─────
  // Gleiches Prinzip wie translateEvents(): reiner DB-Read beim Seitenaufruf,
  // fehlende Begriffe werden nur EINMALIG im Hintergrund per WP-Cron nachuebersetzt.
  async function translateCompetitions(names, targetLang, bundleKey) {
    if (!targetLang || targetLang.toLowerCase().startsWith("de")) return null;
    var uniqueNames = Array.from(new Set(names.filter(Boolean)));
    if (!uniqueNames.length) return null;

        // Ein gemeinsamer Schluessel fuer alle Sportarten statt einer pro Bundle.
    // Generische Begriffe wie "Pokal", "Freundschaft" oder "WM" kommen in
    // mehreren Sportarten vor und wurden bisher je Bundle erneut uebersetzt --
    // mit dem Risiko unterschiedlicher Schreibweisen auf verschiedenen Seiten.
    var cacheKey = "comp_all";
    var stored = await fetchStoredTranslations(targetLang, cacheKey);
    var missing = uniqueNames.filter(function(s) { return !(s in stored); });
    if (missing.length) queueMissingTranslations(targetLang, cacheKey, missing);
    return stored;
  }

  // ── Auto-Uebersetzung der Statistik-Beschriftungen (nur Event-Template) ──
  // Bei den regulaeren Cluster-Seiten bleiben die Statistik-Schluessel bewusst
  // aussen vor (siehe Kommentar bei addCompName): dort liefert die Quelle
  // technische Feldnamen wie "ball_win_removed_opponents", fuer die das
  // Sheet-Feld statsTranslations zustaendig ist. Die Wintersport-Tabs liefern
  // dagegen ausgeschriebene deutsche Beschriftungen ("Punkte 1. Durchgang",
  // "Fehler liegend", "Kür"), die auf der englischen Seite sonst deutsch
  // stehen bleiben -- 141 Begriffe ueber die 15 olympischen Sportarten.
  //
  // Eigener cacheKey statt "comp_all": serverseitig wählt
  // hs_openai_translate_batch() anhand des Praefix "stats_" einen Prompt fuer
  // Messgroessen statt fuer Wettbewerbsnamen. Ein gemeinsamer Schluessel ueber
  // alle Sportarten, weil "Gesamt" oder "Startliste" in vielen davon vorkommt.
  async function translateEventStats(terms, targetLang) {
    if (!targetLang || targetLang.toLowerCase().startsWith("de")) return null;
    var unique = Array.from(new Set(terms.filter(Boolean)));
    if (!unique.length) return null;

    var cacheKey = "stats_all";
    var stored = await fetchStoredTranslations(targetLang, cacheKey);
    var missing = unique.filter(function(s) { return !(s in stored); });
    if (missing.length) queueMissingTranslations(targetLang, cacheKey, missing);
    return stored;
  }

// ── Uebersetzung einzelner Wettbewerbsnamen ───────────────────────────────
  // Exakter Nachschlag, bewusst OHNE die Teilstring-Suche aus
  // applyCompetitionTranslations(): Bei rund 1.100 Eintraegen in der Map waere
  // das pro Element ein Durchlauf ueber alle Schluessel -- bei bis zu 1.065
  // Panel-Zeilen also ueber eine Million Vergleiche.
  function hsTranslateCompName(name) {
    var map = window.hsCompTranslations;
    if (!map || !name) return name;
    return map[name] || name;
  }

  // Baut die Kachel-Vorschau ("Bundesliga, DFB-Pokal …") neu auf, sobald die
  // Uebersetzungen eingetroffen sind. Die Originaldaten liegen schon in
  // window.hsCompetitionPanelData und sind ueber aria-controls mit der Kachel
  // verknuepft -- es braucht also keine Zwischenspeicherung im Markup.
  function hsPatchCompetitionPreviews(root) {
    var scope = root || document;
    scope.querySelectorAll(".hs-card-footer-names").forEach(function(el) {
      var wrap = el.closest(".hs-tc-card-wrap");
      var btn  = wrap ? wrap.querySelector("[aria-controls]") : null;
      var data = btn ? (window.hsCompetitionPanelData || {})[btn.getAttribute("aria-controls")] : null;
      var comps = (data && data.competitions) ? data.competitions : [];
      if (!comps.length) return;

      var names = [];
      var seen  = {};
      for (var pi = 0; pi < comps.length && names.length < 2; pi++) {
        var base = String((comps[pi] && comps[pi].name) || "").trim();
        if (!base) continue;
        var pk = base.toLowerCase();
        if (seen[pk]) continue;
        seen[pk] = true;
        names.push(hsTranslateCompName(base) + buildCompetitionSuffix(comps[pi]));
      }

      if (names.length) {
        el.textContent = names.join(", ") + (comps.length > names.length ? " \u2026" : "");
      }
    });
  }

  function applyCompetitionTranslations(translations, root) {
    if (!translations) return;
    root.querySelectorAll(".hs-card-sport").forEach(function(span) {
      var orig = span.textContent.trim();
      Object.keys(translations).forEach(function(key) {
        if (orig.indexOf(key) !== -1) {
          span.textContent = orig.split(key).join(translations[key]);
        }
      });
    });
  }

function finalizeCompetitionTranslations(translations, root, validTopCompetitions, seoVars, g, seoOpts) {
  applyCompetitionTranslations(translations, root);
  if (!translations) return;

  validTopCompetitions.forEach(function(c) {
    if (c && c.competition_name && translations[c.competition_name]) {
      c.competition_name = translations[c.competition_name];
    }
  });

    seoVars.topLeagueNamesList = buildTopLeagueNamesList(validTopCompetitions);
    var updatedFaqItems = buildWhyFaqItems(g, seoVars);

    // FAQ-DOM: nur die generischen SEO-FAQ-Panels (mit seoTpl-Flag) ersetzen
    updatedFaqItems.forEach(function(item, idx) {
      if (!item.seoTpl) return;
      var slotEl = root.querySelector('#hs-gslot-faq-' + (idx + 1) + ' .faq-trigger-title');
      if (slotEl) slotEl.textContent = item.headline;
      var descEl = root.querySelector('#hs-gslot-faq-' + (idx + 1) + ' .faq-desc');
      if (descEl) descEl.textContent = item.text;
    });

    // Kein erneutes JSON-LD noetig: Das FAQPage-Schema wird serverseitig aus
    // dem gespeicherten Snapshot-Markup gelesen. Da die Schleife oben die
    // sichtbaren FAQ-Panels VOR dem Snapshot-Writeback aktualisiert, enthaelt
    // der Snapshot bereits die uebersetzten Texte -- Schema und sichtbarer
    // Inhalt bleiben damit automatisch konsistent.
  }

  // ── Hero Cluster ──────────────────────────────────────────────────────────
  // #1-3: Liest "eyebrow", "description", "ctaText" direkt aus Sheety-Objekt
  // #4: Eyebrow-Pill nur rendern wenn Wert vorhanden

  function renderHeroCluster(bundleName, b, g) {
    const eyebrow  = f(b, "eyebrow",     "");
    const desc     = f(b, "description", "Alle Daten auf einen Blick \u2013 bereit zur Integration");
    const ctaUrl   = (g.ctaurl || "#contact");
    const ctaTxt   = (g.ctatext || "Jetzt anfragen");
    const bgUrl    = f(b, "heroBgUrlCached", "") || f(b, "heroBgUrl", "");

    const bgContent = bgUrl
      ? '<img src="' + bgUrl + '" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center 75%;">'
      : heroSVG();

    // #4: Eyebrow-Pill nur wenn Wert gesetzt
    const eyebrowHtml = eyebrow
      ? '<div class="hs-eyebrow-pill"><span class="hs-eyebrow-dot"></span><span class="hs-eyebrow-text">' + eyebrow + '</span></div>'
      : "";

    return '<section class="hs-hero">' +
      '<div class="hs-hero-bg">' + bgContent + '</div>' +
      '<div class="hs-hero-inner">' +
        eyebrowHtml +
        '<h1 class="hs-headline">' + f(b, "heroHeadline", bundleName) + '</h1>' +
        '<p class="hs-desc">' + desc + '</p>' +
        '<div class="hs-ctas">' +
          '<a href="' + ctaUrl + '" class="hs-cta-primary"' +' onclick="event.preventDefault();if(window.hsScrollToContact)window.hsScrollToContact();">' + ctaTxt + '</a>' +
        '</div>' +
      '</div>' +
    '</section>';
  }

  // ── Hero Detail ───────────────────────────────────────────────────────────

  function renderHeroDetail(disc, g) {
    const eyebrow = f(disc, "eyebrow",     "");
    const desc    = f(disc, "description", "Vollst\u00e4ndige Berichterstattung \u2013 Events, Stats & Liveticker");
    const ctaUrl  = (g.ctaurl || "#contact");
    const ctaTxt  = (g.ctatext || "Jetzt anfragen");
    const bgUrl   = f(disc, "heroBgUrlCached", "") || f(disc, "heroBgUrl", "");

    const bgContent = bgUrl
      ? '<img src="' + bgUrl + '" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center 75%;">'
      : heroSVG();

    const eyebrowHtml = eyebrow
      ? '<div class="hs-eyebrow-pill"><span class="hs-eyebrow-dot"></span><span class="hs-eyebrow-text">' + eyebrow + '</span></div>'
      : "";

    return '<section class="hs-hero" style="min-height:500px;">' +
      '<div class="hs-hero-bg">' + bgContent + '</div>' +
      '<div class="hs-hero-inner">' +
        eyebrowHtml +
        '<h1 class="hs-headline">' + (f(disc, "heroHeadline", "") || (f(disc, "displayName", disc.name)) + '<span class=\"hs-accent">\' + f(disc, \'labelAccent\', \' \\u2013 Vollabdeckung\') + \'</span>') + '</h1>' +
        '<p class="hs-desc">' + desc + '</p>' +
        '<div class="hs-ctas">' +
          '<a href="' + ctaUrl + '" class="hs-cta-primary"' +' onclick="event.preventDefault();if(window.hsScrollToContact)window.hsScrollToContact();">' + ctaTxt + '</a>' +
        '</div>' +
      '</div>' +
    '</section>';
  }

  // ── Breadcrumb ────────────────────────────────────────────────────────────

  function renderBreadcrumb(disc, ds) {
    const clusterUrl   = ds.clusterUrl   || "/";
    const clusterLabel = ds.clusterLabel || "Paket-\u00dcbersicht";
    return '<div class="hs-breadcrumb"><div class="hs-container">' +
      '<a href="' + clusterUrl + '" class="hs-bc-link">\u2190 ' + clusterLabel + '</a>' +
      '<span class="hs-bc-sep">/</span>' +
      '<span class="hs-bc-current">' + f(disc, "displayName", disc.name) + '</span>' +
      '</div></div>';
  }

  // ── Stats Bar ─────────────────────────────────────────────────────────────
  // #5: labelLiveticker aus Sheet → kein hardcodierter Text mehr

  function renderStatsBar(items) {
    const colCount = items.length;
    return '<div class="hs-stats-bar"><div class="hs-stats-inner" style="grid-template-columns:repeat(' + colCount + ',1fr);">' +
      items.map(function(it) {
        return '<div class="hs-sb-item"><div class="hs-sb-val" data-target="' + it.val + '">0</div><div class="hs-sb-label">' + it.label + '</div></div>';
      }).join("") +
      '</div></div>';
  }


  // ── Coverage-Kacheln für Sportarten ohne Disziplin-Ebene (v84, neu) ─────
  // Zeigt Top-12-Länder (nach Wettbewerbsanzahl) im gleichen Kachel-Stil wie
  // renderClusterCards, plus eine "International"-Kachel-Reihe (Föderationen)
  // und darunter eine ausklappbare, durchsuchbare Liste der restlichen Länder
  // (statt ein Kachel-Grid mit hunderten Elementen zu rendern).
  function countryDisplayName(name, iso, pageLang) {
    var resolvedIso = resolveFlagIso(iso, name);
    if (!resolvedIso) return name;
    var isoKey = resolvedIso.toLowerCase();
    if (NAME_TRANSLATIONS_OVERRIDE[isoKey]) {
      var langKey = (pageLang || "de").toLowerCase().slice(0, 2);
      return NAME_TRANSLATIONS_OVERRIDE[isoKey][langKey] || NAME_TRANSLATIONS_OVERRIDE[isoKey].en || name;
    }
    try {
      var dn = new Intl.DisplayNames([pageLang], { type: "region" });
      var translated = dn.of(resolvedIso.toUpperCase());
      return translated || name;
    } catch (e) {
      return name;
    }
  }

  function normalizeCountryKey(s) {
    return String(s).toLowerCase().trim()
      .replace(/[-–—]/g, " ")
      .replace(/&/g, " and ")
      .replace(/\bund\b/g, " and ")
      .replace(/\s+/g, " ")
      .trim();
  }

  const FLAG_ISO_OVERRIDES = {
    "kosovo": "xk", "xk": "xk", "kv": "xk",
    "bosnien": "ba", "bosnien und herzegowina": "ba", "bosnien herzegowina": "ba", "bosnia": "ba", "bosnia and herzegovina": "ba", "bosnia herzegovina": "ba", "ba": "ba",
    "andorra": "ad", "ad": "ad",
    "belarus": "by", "weissrussland": "by", "by": "by",
    "el salvador": "sv", "elsalvador": "sv", "sv": "sv",
    "faroer": "fo", "färöer": "fo", "faroe islands": "fo", "fo": "fo",
    "hongkong": "hk", "hong kong": "hk", "hk": "hk",
    "liechtenstein": "li", "li": "li",
    "gibraltar": "gi", "gi": "gi",
    "moldau": "md", "moldova": "md", "md": "md",
    "nicaragua": "ni", "ni": "ni",
    "nordirland": "gb-nir", "northern ireland": "gb-nir", "nir": "gb-nir", "gb nir": "gb-nir",
    "san marino": "sm", "sanmarino": "sm", "sm": "sm",
    "sudan": "sd", "sd": "sd",
    "syrien": "sy", "syria": "sy", "sy": "sy",
    "va emirate": "ae", "vae": "ae", "united arab emirates": "ae", "uae": "ae", "ae": "ae"
  };

  const FLAG_ISO_OVERRIDES_NORM = {};
  for (var _k in FLAG_ISO_OVERRIDES) { FLAG_ISO_OVERRIDES_NORM[normalizeCountryKey(_k)] = FLAG_ISO_OVERRIDES[_k]; }

  function resolveFlagIso(iso, name) {
    var direct = (iso || "").toLowerCase().trim();
    if (direct && FLAG_ISO_OVERRIDES[direct]) return FLAG_ISO_OVERRIDES[direct];
    if (name) {
      var normKey = normalizeCountryKey(name);
      if (FLAG_ISO_OVERRIDES_NORM[normKey]) return FLAG_ISO_OVERRIDES_NORM[normKey];
    }
    return iso || "";
  }

  // Manuelle Uebersetzungen fuer Regionen, die Intl.DisplayNames nicht kennt
  // (z.B. Sub-Region-Codes wie GB-NIR fuer Nordirland).
  const NAME_TRANSLATIONS_OVERRIDE = {
    "gb-nir": { de: "Nordirland", en: "Northern Ireland" },
    "gb-sct": { de: "Schottland", en: "Scotland" }
  };

  function flagIconHtml(iso, name) {
    var resolvedIso = resolveFlagIso(iso, name);
    // Kein Land zuordenbar (FIFA WM, UEFA Champions League, Foederationen):
    // Globus statt Leerstelle. Vorher stand hier "" -- die Beschriftung begann
    // dann direkt am Rand und stand versetzt zu den Kacheln mit Flagge.
    // Einfaerbung per CSS, siehe .hs-flag-globe in hs-landing.css.
    if (!resolvedIso) {
      return '<span class="hs-flag-globe" aria-hidden="true"></span>';
    }
    return '<span class="hs-flag-round" style="background-image:url(\'https://flagcdn.com/w80/' + resolvedIso.toLowerCase() + '.png\')" aria-hidden="true"></span>';
  }

  // ── Coverage Cards (Laender + Foederationen, ausschliesslich im Ausklapp-Bereich) ──
  // v89: Alle Laender/Foederationen erscheinen nur noch im "+N anzeigen"-Bereich,
  // im hs-card-compact-Stil (dunkler Kopf, weisser Footer), identisch zu den
  // Top-Wettbewerbe-Kacheln. Foederationen-Block zuerst, dann Laender-Block,
  // beide alphabetisch sprachabhaengig sortiert. Laendernamen + Flaggen werden
  // automatisch via Intl.DisplayNames uebersetzt bzw. via country_iso (vom
  // Backend geliefert) dargestellt -- keine manuelle Pflege im Sheet noetig.
  function renderCoverageIntro(b, g) {
    const eyebrow = f(b, "sportEyebrow", "ENTHALTENE LÄNDER");
    const title = (g.sporttitle || "Was ist im Paket?");
    return (
      '<section class="hs-coverage-intro-section">' +
        '<div class="hs-container hs-coverage-intro">' +
          '<span class="hs-section-eyebrow">' + eyebrow + '</span>' +
          '<h2 class="hs-section-title">' + title + '</h2>' +
          '<span class="hs-section-bar"></span>' +
        '</div>' +
      '</section>'
    );
  }

  function renderCoverageCards(coverageData, b, g, bundleKey, forceExpanded) {
    if (!coverageData || !coverageData.countries || !coverageData.countries.length) {
      return '<section class="hs-cluster-cards"><div class="hs-container"><p style="text-align:center;color:#888;padding:2rem;">Coverage-Daten konnten nicht geladen werden.</p></div></section>';
    }
    const countries = coverageData.countries.slice();
    const intl = (coverageData.international || []).slice();
    const detailsLabel = f(b, "detailsText", g.detailstext || "Wettbewerbe ansehen");
    const eventsLabel = f(b, "labelEvents", g.labelevents || "Wettbewerbe");
    const eyebrow = f(b, "sportEyebrow", "ENTHALTENE LÄNDER");
    const title = (g.sporttitle || "Was ist im Paket?");
    const labelCountries = f(b, "labelSports", g.labelsports || "Countries");
    const labelFederations = (g.labelfederations || "Federations");

    countries.forEach(function(c) { c._displayName = countryDisplayName(c.name, c.country_iso, pageLang); });
    countries.sort(function(a, b2) { return a._displayName.localeCompare(b2._displayName, pageLang); });
    intl.sort(function(a, b2) { return a.federation.localeCompare(b2.federation, pageLang); });

    window.hsCompetitionPanelData = window.hsCompetitionPanelData || {};
    var panelIdxCounter = 0;

    // Task 5 (Subtask D): Laender-/Foederations-Kacheln verweisen nicht mehr
    // auf Detailseiten (die es nicht gibt), sondern faehren bei Klick inline
    // auf volle Grid-Breite aus und zeigen ALLE untergeordneten Wettbewerbe
    // dieses Landes/dieser Foederation als Liste (im Unterschied zu den
    // Top-Competition-Kacheln, die genau EINEN Wettbewerb zeigen).
        // NEU (SEO Paket 1): Ersetzt die auf allen Kacheln identische Beschriftung
    // ("Details anzeigen" -- 109x auf einer Seite und damit rund 17 % des
    // gesamten Seitentexts) durch die kuratierten Leitwettbewerbe der Gruppe.
    // Namen und Kuratierungsgrenze liegen bereits in der Coverage-Antwort
    // (topCompetitions / topCompetitionsCount) -- kein neues Sheet-Feld.
    //
    // Doppelte Basisnamen werden fuer die Vorschau uebersprungen, damit nicht
    // "Bundesliga, Bundesliga (F)" dasteht -- im aufgeklappten Panel bleiben
    // beide Eintraege unveraendert erhalten.
    function competitionPreview(group, fallbackLabel) {
      var comps = (group && group.topCompetitions) ? group.topCompetitions : [];
      var names = [];
      var seen  = {};
      for (var ci = 0; ci < comps.length && names.length < 2; ci++) {
        var base = String((comps[ci] && comps[ci].name) || "").trim();
        if (!base) continue;
        var key = base.toLowerCase();
        if (seen[key]) continue;
        seen[key] = true;
        names.push(base + buildCompetitionSuffix(comps[ci]));
      }
      if (!names.length) return fallbackLabel;
      return names.join(", ") + (comps.length > names.length ? " \u2026" : "");
    }

    function buildCountryCard(c) {
      var panelId = "hs-cc-panel-" + bundleKey + "-" + (panelIdxCounter++);
      // countryIso wird an das Panel durchgereicht, damit compRowHtml() die
      // Zeilen-Icons unterdruecken kann: Innerhalb eines Laender-Panels ist
      // die Flagge in jeder Zeile redundant, und Wettbewerbe ohne eigenen
      // country_iso wuerden dort sonst faelschlich den Globus zeigen.
      window.hsCompetitionPanelData[panelId] = { competitions: c.topCompetitions || [], groupLabel: c._displayName, topCount: c.topCompetitionsCount || 0, countryIso: c.country_iso || "" };
      return (
        '<div class="hs-tc-card-wrap">' +
          '<button type="button" class="hs-card hs-card-compact hs-tc-card fade-in" ' +
            'aria-expanded="false" aria-controls="' + panelId + '" ' +
            'onclick="window.hsToggleCompetitionPanel(this, \'' + panelId + '\')">' +
            '<div class="hs-card-head">' + flagIconHtml(c.country_iso, c._displayName) + '<span class="hs-card-sport">' + c._displayName + '</span></div>' +
            '<div class="hs-card-footer"><div class="hs-card-footer-count">' + c.competitions + ' ' + eventsLabel + '</div><div class="hs-card-footer-link"><span class="hs-card-footer-names">' + competitionPreview(c, detailsLabel) + '</span> <span class="hs-arrow hs-tc-arrow">\u2192</span></div></div>' +
          '</button>' +
          '<div class="hs-tc-panel" id="' + panelId + '" hidden></div>' +
        '</div>'
      );
    }
    function buildFedCard(i) {
      var panelId = "hs-cc-panel-" + bundleKey + "-" + (panelIdxCounter++);
      // Verbaende (CONMEBOL, UEFA, FIFA ...) haben keinen Laender-Code. Leer
      // gesetzt heisst: Zeilen-Icons bleiben sichtbar, kontinentale
      // Wettbewerbe zeigen dort also weiterhin den Globus.
      window.hsCompetitionPanelData[panelId] = { competitions: i.topCompetitions || [], groupLabel: i.federation, topCount: i.topCompetitionsCount || 0, countryIso: "" };
      return (
        '<div class="hs-tc-card-wrap">' +
          '<button type="button" class="hs-card hs-card-compact hs-tc-card fade-in" ' +
            'aria-expanded="false" aria-controls="' + panelId + '" ' +
            'onclick="window.hsToggleCompetitionPanel(this, \'' + panelId + '\')">' +
            '<div class="hs-card-head"><span class="hs-card-sport">' + i.federation + '</span></div>' +
            '<div class="hs-card-footer"><div class="hs-card-footer-count">' + i.competitions + ' ' + eventsLabel + '</div><div class="hs-card-footer-link"><span class="hs-card-footer-names">' + competitionPreview(i, detailsLabel) + '</span> <span class="hs-arrow hs-tc-arrow">\u2192</span></div></div>' +
          '</button>' +
          '<div class="hs-tc-panel" id="' + panelId + '" hidden></div>' +
        '</div>'
      );
    }

    const allItemsHtml = [].concat(
      intl.map(function(i) { return '<div class="hs-coverage-more-item" data-name="' + i.federation.toLowerCase() + '">' + buildFedCard(i) + '</div>'; }),
      countries.map(function(c) { return '<div class="hs-coverage-more-item" data-name="' + c._displayName.toLowerCase() + '">' + buildCountryCard(c) + '</div>'; })
    ).join("");

    const totalCount = countries.length + intl.length;
    // NEU: Wenn die Gesamtzahl an Laender-/Foederations-Kacheln klein ist,
    // lohnt sich kein "Show More"-Klick -- Liste dann direkt aufgeklappt
    // anzeigen. Schwelle ueber Sheet-Feld "autoExpandThreshold" konfigurierbar,
    // Default 15.
    const autoExpandThreshold = parseInt(f(b, "autoExpandThreshold", g.autoexpandthreshold || "15"), 10) || 15;
    const effectiveExpanded = forceExpanded || totalCount <= autoExpandThreshold;
    const expandTemplate = g.expandlabeltemplate || g.labelshowcountries || "+ {n} Länder & Föderationen anzeigen";
    const toggleLabelOpen = expandTemplate.replace("{n}", totalCount);
    const toggleLabelClose = f(b, "labelShowLess", g.labelshowless || "Liste einklappen");
    const searchPlaceholder = (g.searchplaceholder || "Land oder Föderation suchen…");

    const fedHeadingHtml = intl.length ? ('<h4 class="hs-coverage-group-heading">' + labelFederations + '</h4>') : "";
    const countryHeadingHtml = countries.length ? ('<h4 class="hs-coverage-group-heading">' + labelCountries + '</h4>') : "";

    const innerListHtml = (
      '<input type="text" class="hs-coverage-search" placeholder="' + searchPlaceholder + '" ' +
        'oninput="var q=this.value.toLowerCase();this.closest(\'.hs-coverage-more-list\').querySelectorAll(\'.hs-coverage-more-item\').forEach(function(el){el.style.display=el.dataset.name.indexOf(q)!==-1?\'\':\'none\';});">' +
      fedHeadingHtml +
      (intl.length ? '<div class="hs-cards-grid hs-cards-grid-compact hs-coverage-fed-grid">' + intl.map(function(i) { return '<div class="hs-coverage-more-item" data-name="' + i.federation.toLowerCase() + '">' + buildFedCard(i) + '</div>'; }).join("") + '</div>' : "") +
      countryHeadingHtml +
      (countries.length ? '<div class="hs-cards-grid hs-cards-grid-compact">' + countries.map(function(c) { return '<div class="hs-coverage-more-item" data-name="' + c._displayName.toLowerCase() + '">' + buildCountryCard(c) + '</div>'; }).join("") + '</div>' : "") +
      ''
    );

    const listHtml = effectiveExpanded ? (
      '<div class="hs-coverage-more-wrap hs-coverage-expanded">' +
        '<div class="hs-coverage-more-list" style="display:block;">' +
          innerListHtml +
        '</div>' +
      '</div>'
    ) : (
      '<div class="hs-coverage-more-wrap">' +
        '<button class="hs-coverage-more-toggle" onclick="var l=this.nextElementSibling;var open=l.style.display===\'block\';l.style.display=open?\'none\':\'block\';this.textContent=open?\'' + toggleLabelOpen.replace(/'/g, "\\'") + '\':\'' + toggleLabelClose.replace(/'/g, "\\'") + '\';">' +
          toggleLabelOpen +
        '</button>' +
        '<div class="hs-coverage-more-list" style="display:none;">' +
          innerListHtml +
        '</div>' +
      '</div>'
    );

    return (
      '<section class="hs-cluster-cards">' +
        '<div class="hs-container">' +
          listHtml +
        '</div>' +
      '</section>'
    );
  }

  // ── Top Competitions Cards (kompakt, analog Wintersport-Disziplin-Kachel) ──
  // v89: Ländername im Label wird -- falls country_iso vorhanden -- automatisch
  // per Intl.DisplayNames in die Seitensprache uebersetzt (z.B. "Germany" statt
  // "Deutschland" auf EN-Seiten), OHNE eigene Sheet-Uebersetzungsspalte. Falls
  // kein country_iso vorhanden ist (internationaler Wettbewerb), wird die
  // federation als Praefix genutzt (unveraendert, da Foederationskuerzel
  // sprachunabhaengig sind). 'label' dient nur als Fallback.

  function buildCompetitionDisplayLabel(c) {
    if (!c) return "";
    var displayLabel = c.label || c.competition_name || "";
    if (c.country_iso && c.competition_name) {
      displayLabel = countryDisplayName((c.label || "").split(" - ")[0], c.country_iso, pageLang) + " - " + c.competition_name;
    } else if (c.federation && c.competition_name) {
      displayLabel = c.federation + " " + c.competition_name;
    }
    return displayLabel + buildCompetitionSuffix(c);
  }

  // NEU: Alt-Texte fuer Partnerlogos aus dem General Index (Spalte
  // "logoAltMap", Format wie statstranslations/genderTranslations:
  //   "ard=ARD, zdf=ZDF, dfb=DFB – Deutscher Fussball-Bund"
  // Der Schluessel wird aus dem Dateinamen abgeleitet, d.h.
  // ".../ard_130x80_duplex.png" -> "ard". Unbekannte Dateien behalten
  // bewusst alt="" -- ein falscher Markenname waere schlechter als ein
  // leerer Alt-Text.
  var hsLogoAltCache = null;
  function hsLogoAltMap() {
    if (hsLogoAltCache) return hsLogoAltCache;
    var gi = window.hsGeneralIndexData || {};
    var raw = String(gi.logoaltmap || "").trim();
    var map = {};
    if (raw) {
      raw.split(",").forEach(function (pair) {
        var i = pair.indexOf("=");
        if (i === -1) return;
        var key = pair.slice(0, i).trim().toLowerCase();
        if (key) map[key] = pair.slice(i + 1).trim();
      });
    }
    hsLogoAltCache = map;
    return map;
  }

  function hsLogoAlt(url) {
    var file = String(url || "").split("/").pop().split("?")[0];
    var slug = file
      .replace(/\.(png|jpe?g|webp|svg|gif)$/i, "")
      .replace(/_\d+x\d+(_duplex)?$/i, "")
      .toLowerCase();
    // Escaping ist zwingend: Der Wert wird per String-Konkatenation in ein
    // HTML-Attribut geschrieben. Aktuell enthaelt kein Markenname kritische
    // Zeichen, aber ein spaeter ergaenztes "AT&T" wuerde das Attribut sonst
    // zerlegen.
    return (hsLogoAltMap()[slug] || "")
      .replace(/&/g, "&amp;")
      .replace(/"/g, "&quot;")
      .replace(/</g, "&lt;");
  }

// NEU: Gender-Suffix-Uebersetzungen aus dem General Index (Spalte
  // "genderTranslations", Format wie statstranslations:
  //   "female=F, mixed=mixed"
  // Leerer Wert (z.B. "mixed=") blendet das Suffix fuer diesen Wert aus.
  // Faellt auf die bisherigen Defaults zurueck, falls die Spalte fehlt/leer
  // ist -- dadurch ist die Aenderung rueckwaertskompatibel.
  var hsGenderMapCache = null;
  function genderTranslationMap() {
    if (hsGenderMapCache) return hsGenderMapCache;
    var gi = window.hsGeneralIndexData || {};
    var raw = String(gi.gendertranslations || "").trim();
    var map;
    if (raw) {
      map = {};
      raw.split(",").forEach(function (pair) {
        var i = pair.indexOf("=");
        if (i === -1) return;
        var key = pair.slice(0, i).trim().toLowerCase();
        if (key) map[key] = pair.slice(i + 1).trim();
      });
    } else {
      map = isDE
        ? { female: "weiblich", mixed: "mixed" }
        : { female: "female",   mixed: "mixed" };
    }
    hsGenderMapCache = map;
    return map;
  }

  // NEU (v101.6): Haengt Geschlecht (uebersetzt) und/oder Altersklasse (unveraendert,
  // z.B. "U17") in Klammern an den Wettbewerbsnamen an, falls die Rohdaten-Spalten
  // "gender" bzw. "age" befuellt sind. "male"/"female" werden je nach Seitensprache
  // uebersetzt; alle anderen Gender-Werte werden unveraendert durchgereicht. Wird
  // sowohl von buildCompetitionDisplayLabel() (globale Top-Wettbewerbe) als auch
  // von compRowHtml() (Laender-/Foederations-Panel) genutzt, damit die Anzeige an
  // beiden Stellen konsistent ist
  function buildCompetitionSuffix(c) {
    if (!c) return "";
    var parts = [];
    var genderKey = String(c.gender || "").trim().toLowerCase();
    if (genderKey) {
      var map = genderTranslationMap();
      // "male" bleibt wie bisher der Standardfall ohne Suffix -- solange es
      // nicht explizit in genderTranslations gepflegt wird.
      if (Object.prototype.hasOwnProperty.call(map, genderKey)) {
        var label = map[genderKey];
        if (label) parts.push(label);
      }
    }
    var ageRaw = String(c.age || "").trim();
    if (ageRaw) parts.push(ageRaw);
    return parts.length ? " (" + parts.join(", ") + ")" : "";
  }

  /**
   * TASK 5 (Subtask E): Befuellt ein ausgeklapptes Kachel-Panel mit der
   * Wettbewerbsliste (Top-Competition-Kachel: 1 Wettbewerb; Laender-/
   * Foederations-Kachel: alle zugehoerigen Wettbewerbe). Wird per
   * window.hsToggleCompetitionPanel() (Subtask D) beim Oeffnen aufgerufen.
   *
   * Spaltenaufbau je Zeile: Wettbewerbsname (mit Flagge), Matches (letzte
   * abgeschlossene Saison), Live Scores, Live Ticker, Stats-Pills (aus
   * stats_list, kommagetrennt -- mit vorhandener +XX-Overflow-Logik ueber
   * window.hsInitStatsOverflow()).
   *
   * Der visuelle Trenner ("Top-Wettbewerbe" / labelTopCompetitions) wird nur
   * bei Laender-/Foederations-Panels gezeigt (mehrere Wettbewerbe), NICHT
   * bei Top-Competition-Panels (dort ist immer nur 1 Wettbewerb enthalten).
   */
  // ── Paket 3: Panels fuer den Snapshot vorrendern ───────────────────────────
  // Die Wettbewerbstabellen entstehen bisher erst beim Klick. Fuer den Nutzer
  // ist das genau richtig -- 1.271 Zeilen beim Seitenaufbau waeren Verschwendung.
  // Der Prerender-Snapshot sieht dadurch aber NICHTS davon, und damit sieht auch
  // Google nichts: gemessen waren 2 von 743 Wettbewerbsnamen im HTML.
  //
  // Diese Funktion legt deshalb in jedes noch leere Panel eine reduzierte, aber
  // semantisch vollstaendige Tabelle: die kuratierten Top-Wettbewerbe der
  // Gruppe, mit <caption>, ohne Statistik-Pills.
  //
  // Drei Punkte, die hier bewusst so sind:
  //
  // 1. dataset.hsRendered wird NICHT gesetzt. Beim Klick ersetzt
  //    hsRenderCompetitionPanel() den Inhalt durch die vollstaendige Liste.
  //    Das Panel ist bis dahin hidden (.hs-tc-panel[hidden]{display:none}), und
  //    hsToggleCompetitionPanel() rendert synchron im selben Task neu -- der
  //    Nutzer sieht die reduzierte Fassung also nie, auch nicht kurz.
  //
  // 2. KEINE fade-in-Klasse auf diesen Zeilen. Der IntersectionObserver laeuft
  //    nur einmal beim Seitenload; fade-in-Elemente in einem hidden-Container
  //    bekaemen nie die visible-Klasse und stuenden im Snapshot dauerhaft auf
  //    opacity:0 -- genau der Fehler mit den 124 unsichtbaren Elementen.
  //
  // 3. Keine Statistik-Pills. 11.178 wiederholte Pill-Begriffe wuerden die
  //    Boilerplate-Verwaesserung neu erzeugen, die wir gerade beseitigt haben.
  //    Die leere Zelle bleibt, damit die Spaltenzahl zur vollen Tabelle passt.
  //
  // Kalkuliert: 401 Zeilen, 131 KB roh, 6,3 KB gzip, 4.710 DOM-Knoten.
  // Auf 0 setzen heisst: nur kuratierte Gruppen (398 Zeilen).
  var HS_PRERENDER_FALLBACK_ROWS = 3;

  function hsPrerenderPanels(root, g) {
    g = g || window.hsGeneralIndexData || {};
    var store  = window.hsCompetitionPanelData || {};
    var panels = (root || document).querySelectorAll(".hs-tc-panel[id]");
    if (!panels.length) return;

    var labelMatches    = (g.labelmatches      || "Matches");
    var labelLiveScores = (g.labellivescores   || "Live Scores");
    var labelLiveTicker = (g.labellivetickcol  || "Liveticker");
    var labelStats      = (g.labelstatscol     || "Statistiken");
    var labelOnRequest  = (g.labelonrequest    || "Auf Anfrage");
    var captionTpl      = (g.panelcaption      || "{group} \u2013 {displayName}");
    var sportLabel      = (window.hsSportDisplayName || "");

    var theadHtml =
      '<thead><tr>' +
        '<th class="hs-tc-th-name"></th>' +
        '<th class="hs-tc-th-stats">' + labelStats + '</th>' +
        '<th class="hs-tc-col-num hs-tc-th-num">' + labelMatches + '</th>' +
        '<th class="hs-tc-col-num hs-tc-th-num">' + labelLiveScores + '</th>' +
        '<th class="hs-tc-col-num hs-tc-th-num">' + labelLiveTicker + '</th>' +
      '</tr></thead>';

    panels.forEach(function(panelEl) {
      if (panelEl.dataset.hsRendered === "1") return;
      if (panelEl.dataset.hsPrerendered === "1") return;

      var data  = store[panelEl.id];
      var comps = (data && data.competitions) ? data.competitions : [];
      if (!comps.length) return;

      var take = (data.topCount > 0) ? data.topCount : HS_PRERENDER_FALLBACK_ROWS;

      var rows = [];
      var seen = {};
      for (var pi = 0; pi < comps.length && rows.length < take; pi++) {
        var c = comps[pi];
        var base = String((c && c.name) || "").trim();
        if (!base) continue;

        var label = hsTranslateCompName(base) + buildCompetitionSuffix(c);

        // Dedup ueber compId statt ueber die Beschriftung. 69 der 1.065 Zeilen
        // tragen innerhalb ihrer Gruppe eine nicht eindeutige Beschriftung --
        // FIFA fuehrt zehn Zeilen "Freundschaft", UEFA vier Zeilen "EM", weil
        // dort das Feld age leer ist. Ein Dedup nach Beschriftung wuerde diese
        // Zeilen stillschweigend verwerfen, sobald sie in den kuratierten Teil
        // rutschen. Die compId ist eindeutig; nur wenn sie fehlt, dient die
        // Beschriftung als Notschluessel.
        var dk = String(c.compId || "").trim() || ("lbl:" + label.toLowerCase());
        if (seen[dk]) continue;
        seen[dk] = true;

        var flagHtml = (!c.country_iso && (data.countryIso || ""))
        ? ""
        : flagIconHtml(c.country_iso, label);

        rows.push(
          '<tr class="hs-event-row">' +
            '<td class="hs-event-name"><span class="hs-event-name-inner">' + flagHtml + label + '</span></td>' +
            '<td class="hs-event-stats"></td>' +
            '<td class="hs-tc-col-num" data-label="' + labelMatches + '">' + (c.seasonMatches || 0) + '</td>' +
            '<td class="hs-tc-col-num" data-label="' + labelLiveScores + '">' + (c.liveScores || 0) + '</td>' +
            '<td class="hs-tc-col-num" data-label="' + labelLiveTicker + '">' +
              // Nur im vorgerenderten Zustand ein Zeichen statt des Labels.
              // "Auf Anfrage" stand 209 mal im Quelltext und war mit 418
              // Woertern der haeufigste Text der ganzen Seite -- 18,11 % aller
              // Zweiwortphrasen entfielen auf "auf anfrage". Beim Aufklappen
              // ersetzt hsRenderCompetitionPanel() den Inhalt komplett, dort
              // steht das Label also unveraendert.
              (c.liveTicker > 0
                ? '<span class="hs-lt-yes">\u2713</span>'
                : '<span class="hs-lt-no">\u2013</span>') +
            '</td>' +
          '</tr>'
        );
      }

      if (!rows.length) return;

      var caption = String(captionTpl)
        .split("{group}").join((data && data.groupLabel) || "")
        .split("{displayName}").join(sportLabel);

      // Kein <thead> in der Vorschau: 109 Tabellenkoepfe brachten 545 Woerter
      // identischen Boilerplate-Text in den Quelltext. Die Panels sind vor dem
      // Klick ohnehin "hidden", und hsRenderCompetitionPanel() baut die
      // sichtbare Tabelle mit vollem Kopf neu auf.
      panelEl.innerHTML =
        '<div class="hs-table-wrap"><table class="hs-events-table">' +
          '<tbody>' + rows.join("") + '</tbody>' +
        '</table></div>';

      panelEl.dataset.hsPrerendered = "1";
    });
  }

  window.hsRenderCompetitionPanel = function(panelEl, panelId) {
    if (!panelEl || panelEl.dataset.hsRendered === "1") return;
    var data = (window.hsCompetitionPanelData || {})[panelId];
    if (!data) return;
    panelEl.dataset.hsRendered = "1";

    var g = window.hsGeneralIndexData || {};
    var comps = data.competitions || [];
    var topCount = typeof data.topCount === "number" ? data.topCount : 0;

    var labelMatches = (g.labelmatches || "Matches");
    var labelLiveScores = (g.labellivescores || "Live Scores");
    var labelLiveTicker = (g.labellivetickcol || "Liveticker");
    var labelStats = (g.labelstatscol || "Statistiken");
    var labelTop = (g.labeltopcompetitions || "Top Wettbewerbe");
    var labelRest = (g.labelothercompetitions || "Weitere Wettbewerbe");
    var labelOnRequest = (g.labelonrequest || "Auf Anfrage");

    // Laender-Code des Panels. MUSS deklariert sein: compRowHtml() liest ihn,
    // und ohne Deklaration bricht die Funktion unter "use strict" mit
    // ReferenceError ab -- dann bleibt die vorgerenderte Vorschau stehen
    // (nur 3 Zeilen, keine Statistik-Pills).
    var panelCountryIso = data.countryIso || "";

    if (!comps.length) {
      panelEl.innerHTML = '<p class="hs-no-data" style="padding:.5rem 0;">–</p>';
      return;
    }

    function statsPillsHtml(statsListRaw) {
      var arr = (statsListRaw || "").split(",").map(function(s){ return s.trim(); }).filter(Boolean);
      if (!arr.length) return '<span class="hs-no-data">–</span>';
      // Reihenfolge der Quellen: das handgepflegte Sheet-Feld
      // statsTranslations gewinnt immer, danach greift die per OpenAI
      // erzeugte Map der Event-Seiten (window.hsStatsAiMap, cacheKey
      // "stats_all"), zuletzt bleibt der deutsche Originalbegriff stehen.
      // Auf deutschen Seiten ist hsStatsAiMap nie gesetzt -- translateEventStats()
      // steigt fuer pageLang "de" sofort aus.
      var mapped = arr.map(function(s) {
        var statsMap = window.hsStatsTranslationMap || {};
        var aiMap    = window.hsStatsAiMap || {};
        return statsMap[s.toLowerCase()] || aiMap[s] || s;
      });
      var tagsHtml = mapped.map(function(s) { return '<span class="hs-stat-tag">' + s + '</span>'; }).join("");
      return '<div class="hs-stat-tags-wrap">' + tagsHtml + '</div>';
    }

    function compRowHtml(c) {
      // BUGFIX: Eintraege aus countries[]/international[].topCompetitions
      // haben ein 'name'-Feld (kein 'competition_name'/'label'/'country_iso')
      // -- buildCompetitionDisplayLabel() ist fuer die GLOBALEN topCompetitions
      // gedacht und lieferte hier immer "" zurueck. Fallback-Kette deckt beide
      // Datenformen ab (Top-Competition-Kachel vs. Laender-/Foederations-Kachel).
      var displayLabel = (c.name ? hsTranslateCompName(c.name) : (buildCompetitionDisplayLabel(c) || ""));
      // NEU (v101.6): Falls displayLabel ueber c.name (Laender-/Foederations-Struktur)
      // ermittelt wurde, ist der Gender/Age-Suffix aus buildCompetitionDisplayLabel()
      // NICHT enthalten (die Funktion wird in diesem Zweig gar nicht durchlaufen) --
      // wird hier separat ergaenzt, damit beide Datenformen konsistent sind.
      if (c.name) displayLabel += buildCompetitionSuffix(c);
      // Ohne Bedingung, damit Zeilen ohne Land den Globus bekommen und die
      // Namen aller Zeilen auf derselben Kante beginnen.
      // Innerhalb einer Ländergruppe kein Icon: Die Flagge steht bereits im
      // Kachelkopf darüber, in der Zeile waere sie reine Wiederholung -- im
      // Deutschland-Panel 136 mal dieselbe. Ein zeilen-eigenes country_iso
      // gewinnt trotzdem, falls es je welche gibt. Nur wenn weder Zeile noch
      // Gruppe ein Land haben (Foederationen, internationale Bewerbe), liefert
      // flagIconHtml() den Globus -- dort trägt das Icon echte Information.
      var flagHtml = (!c.country_iso && panelCountryIso)
        ? ""
        : flagIconHtml(c.country_iso, displayLabel);
      // v101.15: data-labels nur fuer das Mobile-Card-Layout der aufgeklappten
      // Wettbewerbszeilen. Desktop bleibt unveraendert, da diese Attribute erst
      // in der Mobile-Media-Query visuell genutzt werden.
      return (
        '<tr class="hs-event-row fade-in">' +
          '<td class="hs-event-name"><span class="hs-event-name-inner">' + flagHtml + displayLabel + '</span></td>' +
          '<td class="hs-event-stats" data-label="' + labelStats + '">' + statsPillsHtml(c.statsList) + '</td>' +
          '<td class="hs-tc-col-num" data-label="' + labelMatches + '">' + (c.seasonMatches || 0) + '</td>' +
          '<td class="hs-tc-col-num" data-label="' + labelLiveScores + '">' + (c.liveScores || 0) + '</td>' +
          '<td class="hs-tc-col-num" data-label="' + labelLiveTicker + '">' + (c.liveTicker > 0 ? '<span class="hs-lt-yes">\u2713</span>' : '<span class="hs-lt-no">' + labelOnRequest + '</span>') + '</td>' +
        '</tr>'
      );
    }

    // Desktop: Statistiken-Spalte STEHT LINKS neben dem Wettbewerbsnamen.
    // Mobile: per CSS (@media max-width:640px) wird die Statistiken-Spalte
    // stattdessen ans Ende verschoben (order-Property), damit auf schmalen
    // Bildschirmen zuerst die kompakten Zahlen-Spalten sichtbar sind.
    var theadHtml =
      '<thead><tr>' +
        '<th class="hs-tc-th-name"></th>' +
        '<th class="hs-tc-th-stats">' + labelStats + '</th>' +
        '<th class="hs-tc-col-num hs-tc-th-num">' + labelMatches + '</th>' +
        '<th class="hs-tc-col-num hs-tc-th-num">' + labelLiveScores + '</th>' +
        '<th class="hs-tc-col-num hs-tc-th-num">' + labelLiveTicker + '</th>' +
      '</tr></thead>';

    var bodyHtml;
    if (topCount > 0 && topCount < comps.length) {
      var topRows = comps.slice(0, topCount).map(compRowHtml).join("");
      var restRows = comps.slice(topCount).map(compRowHtml).join("");
      bodyHtml =
        '<tbody>' +
          '<tr class="hs-tc-group-row"><td colspan="5">' + labelTop + '</td></tr>' +
          topRows +
          '<tr class="hs-tc-group-row"><td colspan="5">' + labelRest + '</td></tr>' +
          restRows +
        '</tbody>';
    } else {
      bodyHtml = '<tbody>' + comps.map(compRowHtml).join("") + '</tbody>';
    }

    // Die Caption muss auch hier stehen, sonst verschwindet die Ueberschrift
    // beim Aufklappen wieder -- die vorgerenderte Fassung wird ja komplett
    // ersetzt.
    var panelCaption = String(g.panelcaption || "{group} \u2013 {displayName}")
      .split("{group}").join(data.groupLabel || "")
      .split("{displayName}").join(window.hsSportDisplayName || "");

    panelEl.innerHTML =
      '<div class="hs-table-wrap"><table class="hs-events-table">' +
        theadHtml + bodyHtml +
      '</table></div>';

    // BUGFIX: Dynamisch nachtraeglich eingefuegte .fade-in-Zeilen werden vom
    // globalen IntersectionObserver (der nur einmal beim initialen Seitenload
    // ueber querySelectorAll(".fade-in") laeuft) NIE erfasst -- sie bleiben
    // dauerhaft bei opacity:0 haengen (unsichtbarer "weiss auf weiss"-Text,
    // obwohl der Inhalt im Quellcode korrekt vorhanden ist, z.B. Copa America).
    // Da das Panel durch den Klick bereits sichtbar/aufgeklappt ist, macht ein
    // Scroll-Trigger hier ohnehin keinen Sinn -- die "visible"-Klasse wird
    // daher direkt gesetzt, statt auf den globalen Observer zu warten.
    panelEl.querySelectorAll(".fade-in").forEach(function(el) {
      el.classList.add("visible");
    });

    if (window.hsInitStatsOverflow) {
      setTimeout(function() { window.hsInitStatsOverflow(); }, 30);
    }
  };

function renderTopCompetitionsCards(topCompetitions, b, g, bundleKey, sportKeyToDisplayName, showSportPill) {
    sportKeyToDisplayName = sportKeyToDisplayName || {};
    // FIX: Sportart-Pille darf NUR bei Bundle-Templates mit mehreren
    // unterschiedlichen Sportarten erscheinen (z.B. US Sports), nicht bei
    // Single-Sport-Coverage-Seiten wie American Football, wo c.sport zwar
    // gesetzt ist, aber irrelevant fuer die Anzeige sein soll.
    showSportPill = !!showSportPill;

    if (!topCompetitions || !topCompetitions.length) return "";

    topCompetitions = topCompetitions.filter(function(c) {
      if (!c) return false;
      var hasRealTarget = !!((c.competition_id && String(c.competition_id).trim() !== "") || (c.detail_url && String(c.detail_url).trim() !== ""));
      var hasRealLabel = !!((c.label && String(c.label).trim() !== "") || (c.competition_name && String(c.competition_name).trim() !== ""));
      return hasRealTarget && hasRealLabel;
    });
    if (!topCompetitions.length) return "";

    const detailsTxt = f(b, "detailsText", g.detailstext || "See Details");
    const heading = f(b, "labelTopCompetitions", g.labeltopcompetitions || "Top Wettbewerbe");

    window.hsCompetitionPanelData = window.hsCompetitionPanelData || {};
    const cards = topCompetitions.map(function(c, idx) {
      var displayLabel = buildCompetitionDisplayLabel(c);
      var panelId = "hs-tc-panel-" + bundleKey + "-" + idx;
      // Top-Wettbewerbs-Panel: steht fuer einen einzelnen Wettbewerb, nicht
      // fuer ein Land -- die Flagge der Zeile bleibt deshalb sichtbar.
      window.hsCompetitionPanelData[panelId] = { competitions: [c], groupLabel: displayLabel, countryIso: "" };

	var sportKeyRaw = (c.sport && String(c.sport).trim() !== "") ? String(c.sport).trim() : "";
      var sportDisplayName = sportKeyRaw ? (sportKeyToDisplayName[sportKeyRaw.toLowerCase()] || sportKeyRaw) : "";
      var hasSport = showSportPill && sportDisplayName !== "";

      var wrapStyle = "display:block !important;position:relative !important;" + (hasSport ? "padding-top:10px !important;" : "");
      var sportPillHtml = "";
      if (hasSport) {
        sportPillHtml = "<span style=\"" +
          "position:absolute !important;" +
          "top:0 !important;" +
          "left:12px !important;" +
          "right:auto !important;" +
          "bottom:auto !important;" +
          "z-index:5 !important;" +
          "display:inline-block !important;" +
          "background:#e75519 !important;" +
          "color:#fff !important;" +
          "font-size:.65rem !important;" +
          "font-weight:700 !important;" +
          "text-transform:uppercase !important;" +
          "letter-spacing:.03em !important;" +
          "padding:3px 10px !important;" +
          "margin:0 !important;" +
          "border-radius:10px !important;" +
          "line-height:1.4 !important;" +
          "box-shadow:0 1px 3px rgba(0,0,0,.15) !important;" +
          "pointer-events:none !important;" +
          "white-space:nowrap !important;" +
          "\">" + sportDisplayName + "</span>";
      }

      return (
        "<div class=\"hs-tc-card-wrap\" style=\"" + wrapStyle + "\">" +
          sportPillHtml +
          "<button type=\"button\" class=\"hs-card hs-card-compact hs-tc-card fade-in\" " +
            "aria-expanded=\"false\" aria-controls=\"" + panelId + "\" " +
            "data-comp-name=\"" + (c.competition_name || "").replace(/"/g, "&quot;") + "\" " +
            "onclick=\"window.hsToggleCompetitionPanel(this, '" + panelId + "')\">" +
            "<div class=\"hs-card-head\">" + flagIconHtml(c.country_iso, displayLabel) + "<span class=\"hs-card-sport\">" + displayLabel + "</span></div>" +
            "<div class=\"hs-card-footer\"><div class=\"hs-card-footer-link\">" + detailsTxt + " <span class=\"hs-arrow hs-tc-arrow\">\u2192</span></div></div>" +
          "</button>" +
          "<div class=\"hs-tc-panel\" id=\"" + panelId + "\" hidden></div>" +
        "</div>"
      );
    }).join("");

    return (
      "<section class=\"hs-cards-section hs-cards-section-compact\" id=\"hs-top-competitions\">" +
        "<div class=\"hs-container\">" +
          "<h3 class=\"hs-coverage-top\">" + heading + "</h3>" +
          "<div class=\"hs-cards-grid hs-cards-grid-compact hs-tc-grid\">" + cards + "</div>" +
        "</div>" +
      "</section>"
    );
  }

  // ── Event-Template: Kachel je Sportart, Wettbewerbe klappen inline auf ────
  // Optik = multisport (renderClusterCards), Verhalten = general-purpose
  // (hsToggleCompetitionPanel / hsRenderCompetitionPanel). Bewusst KEINE
  // Detailseiten: bei einem Event wie Olympia gibt es Suchintention fuer das
  // Event als Ganzes, nicht fuer "Olympia-Biathlon" als eigenes Produkt.
  function renderEventSportCards(eventData, b, g, bundleKey, sportKeyToDisplayName) {
    const eyebrow    = f(b, "sportEyebrow", "ENTHALTENE SPORTARTEN");
    const title      = (g.sporttitle || "Was ist im Paket?");
    const detailsTxt = f(b, "detailsText", g.detailstext || "Details anzeigen");

    const lblEvents = f(b, "labelEvents", g.labelevents || "Events");
    const lblLive   = f(b, "labelLive",   g.labellive   || "Live Coverage");

    window.hsCompetitionPanelData = window.hsCompetitionPanelData || {};

    const cards = (eventData.sports || []).map(function(sport, idx) {
      var panelId = "hs-tc-panel-" + bundleKey + "-sport-" + idx;

      // Gruppennamen aus der sport-Spalte stehen bereits in der Sheet-
      // Schreibweise und bleiben unveraendert. Nur tab-abgeleitete Namen
      // (fromTab) stammen aus dem englischen Index und werden ueber die
      // sprachrichtige Zuordnung uebersetzt -- sonst stuende "Ice Hockey"
      // auf der deutschen Seite.
      var sportName = sport.name;
      if (sport.fromTab && sportKeyToDisplayName) {
        var mapped = sportKeyToDisplayName[(sport.sportKey || "").toLowerCase()];
        if (mapped) sportName = mapped;
      }

      // Die aufklappbare Liste nutzt dieselbe Datenstruktur wie die
      // Laender-/Foederations-Panels. compRowHtml() liest c.name, deshalb
      // steht dort der gekuerzte Name ("Slalom" statt "Olympische
      // Winterspiele - Slalom") -- der Event-Kontext ergibt sich aus Titel,
      // H1 und der Sportart-Ueberschrift der Kachel.
      var comps = (sport.events || []).map(function(ev) {
        return {
          name: ev.shortName || ev.name,
          fullName: ev.name,
          competition_id: ev.compId,
          seasonMatches: ev.matches,
          liveScores: ev.liveScores,
          liveTicker: ev.liveTicker,
          statsList: ev.statsList,
          gender: ev.gender,
          age: ev.age,
          sport: ev.sport,
          country_iso: ""
        };
      });

      // topCount = alle Events: der Vorrenderer schreibt damit die komplette
      // Liste in den Snapshot statt nur der ueblichen 3 Vorschauzeilen. Bei
      // Fussball waeren das 401 Zeilen (Boilerplate-Verwaesserung), bei einem
      // Event sind es pro Sportart eine Handvoll -- und genau diese Events
      // sind der Grund, warum die Seite ueberhaupt gefunden werden soll.
      window.hsCompetitionPanelData[panelId] = {
        competitions: comps,
        groupLabel: sportName,
        countryIso: "",
        topCount: comps.length
      };

      var liveTag = sport.liveCount > 0 ? '<span class="hs-lt-badge">● LIVE</span>' : "";

      return (
        '<div class="hs-tc-card-wrap" style="display:block !important;position:relative !important;">' +
          '<button type="button" class="hs-card hs-tc-card hs-event-card fade-in" ' +
            'aria-expanded="false" aria-controls="' + panelId + '" ' +
            'onclick="window.hsToggleCompetitionPanel(this, \'' + panelId + '\')">' +
            '<div class="hs-card-head"><span class="hs-card-sport">' + sportName + '</span>' + liveTag + '</div>' +
            '<div class="hs-card-body">' +
              '<div class="hs-card-stat"><span class="hs-stat-num">' + sport.eventCount + '</span><span class="hs-stat-lbl">' + lblEvents + '</span></div>' +
              '<div class="hs-card-stat"><span class="hs-stat-num">' + sport.liveCount + '</span><span class="hs-stat-lbl">' + lblLive + '</span></div>' +
            '</div>' +
            '<div class="hs-card-footer"><div class="hs-card-footer-link">' + detailsTxt + ' <span class="hs-arrow hs-tc-arrow">→</span></div></div>' +
          '</button>' +
          '<div class="hs-tc-panel" id="' + panelId + '" hidden></div>' +
        '</div>'
      );
    }).join("");

    return '<section class="hs-cards-section" id="hs-disciplines">' +
      '<div class="hs-container">' +
        '<span class="hs-section-eyebrow">' + eyebrow + '</span>' +
        '<h2 class="hs-section-title">' + title + '</h2>' +
        '<span class="hs-section-bar"></span>' +
        '<div class="hs-cards-grid hs-tc-grid">' + cards + '</div>' +
      '</div>' +
    '</section>';
  }

function renderClusterCards(disciplines, b, g) {
    const eyebrow    = f(b, "sportEyebrow", "ENTHALTENE SPORTARTEN");
    const title      = (g.sporttitle || "Was ist im Paket?");
    const detailsTxt = (g.detailstext || "Details ansehen");

    const lblEvents = f(b, "labelEvents", g.labelevents || "Events");
    const lblLive   = f(b, "labelLive",   g.labellive   || "Live Coverage");

    const cards = disciplines.map(function(d) {
      const liveTag = d.liveticker_count > 0 ? '<span class="hs-lt-badge">\u25cf LIVE</span>' : "";
      var cardUrl = resolveUrl(d.detail_url || '#');
      return '<a href="' + cardUrl + '" class="hs-card fade-in">' +
        '<div class="hs-card-head"><span class="hs-card-sport">' + d.name + '</span>' + liveTag + '</div>' +
        '<div class="hs-card-body">' +
          '<div class="hs-card-stat"><span class="hs-stat-num">' + d.total_events + '</span><span class="hs-stat-lbl">' + lblEvents + '</span></div>' +
          '<div class="hs-card-stat"><span class="hs-stat-num">' + d.liveticker_count + '</span><span class="hs-stat-lbl">' + lblLive + '</span></div>' +
        '</div>' +
        '<div class="hs-card-footer">' + detailsTxt + ' <span class="hs-arrow">\u2192</span></div>' +
      '</a>';
    }).join("");

    return '<section class="hs-cards-section" id="hs-disciplines">' +
      '<div class="hs-container">' +
        '<span class="hs-section-eyebrow">' + eyebrow + '</span>' +
        '<h2 class="hs-section-title">' + title + '</h2>' +
        '<span class="hs-section-bar"></span>' +
        '<div class="hs-cards-grid">' + cards + '</div>' +
      '</div>' +
    '</section>';
  }

  // ── SubText Separator (zwischen Cards und Coverage) ──────────────────────
  // Sheet-Bezeichner: "subText"
  // Nur rendern wenn subText gesetzt ODER Coverage vorhanden

  function renderSubTextSeparator(b, g) {
    const subText = f(b, "subText", "We deliver comprehensive coverage across a broad variety of competitions incl. the following datapoints, also available as historic data sets.");
    return '<div class="hs-subtext-bar">' +
      '<div class="hs-container">' +
        '<span class="hs-section-bar" style="margin:0 auto 1.25rem;display:block;"></span>' +
        '<p class="hs-subtext">' + subText + '</p>' +
      '</div>' +
    '</div>';
  }

  // ── Coverage Section ──────────────────────────────────────────────────────
  // #6: Kein Titel/Eyebrow – nur die 4 Karten

  function renderCoverageSection(b, g) {
    const preItems  = parseList(f(b, "preEvent",      ""));
    const liveItems = parseList(f(b, "live",          ""));
    const postItems = parseList(f(b, "postEvent",     ""));
    const imgItems  = parseList(f(b, "imageLibrary",  ""));

    if (!preItems.length && !liveItems.length && !postItems.length && !imgItems.length) return "";

    function cardSVG(type) {
      const svgs = {
        pre:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        live: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>',
        post: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
        img:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
      };
      return svgs[type] || svgs.pre;
    }

    function buildCard(label, items, svgType) {
      if (!items.length) return "";
      return '<div class="hs-cov-card fade-in">' +
        '<div class="hs-cov-head">' +
          '<div class="hs-cov-icon">' + cardSVG(svgType) + '</div>' +
          '<h3 class="hs-cov-name">' + label + '</h3>' +
        '</div>' +
        '<div class="hs-cov-body"><ul class="hs-cov-list">' +
          items.map(function(t) { return '<li>' + t + '</li>'; }).join("") +
        '</ul></div>' +
      '</div>';
    }

    const filledCount = [preItems, liveItems, postItems, imgItems].filter(function(a) { return a.length > 0; }).length;

        // NEU (SEO Paket 1): H2 ueber den Datenpunkt-Karten. Der Block hatte bisher
    // ueberhaupt keine Ueberschrift und lieferte damit kein
    // Ueberschriftensignal, obwohl er der inhaltlich wertvollste Teil der Seite
    // ist -- er enthaelt die sportartspezifischen Datenbegriffe aus den
    // Sheet-Feldern preEvent/live/postEvent/imageLibrary.
    //
    // Quelle ist das General-Index-Feld dataPointsTitle. {displayName} loest
    // fillPlaceholders() sprachabhaengig aus der Index-Zeile auf.
    const dpTitle = fillPlaceholders(
      (g.datapointstitle || "Welche {displayName}-Daten liefert HEIM:SPIEL?"),
      b
    );

    return '<section class="hs-coverage-section">' +
      '<div class="hs-container">' +
        (dpTitle
          ? '<h2 class="hs-section-title">' + dpTitle + '</h2>' +
            '<span class="hs-section-bar"></span>'
          : "") +
        '<div class="hs-cov-grid" style="grid-template-columns:repeat(' + filledCount + ',1fr);">' +
          buildCard((g.labelpreevent || "Pre-Event"),      preItems,  "pre")  +
          buildCard((g.labellive || "Live"),           liveItems, "live") +
          buildCard((g.labelpostevent || "Post-Event"),     postItems, "post") +
          buildCard((g.labelimagelib || "Image Library"),  imgItems,  "img")  +
        '</div>' +
      '</div>' +
    '</section>';
  }

  // ── Events Section (Detail) ───────────────────────────────────────────────

  function renderEventsSection(disc, events, totalEvents, livetickCount, g) {
    // Neue Sheet-Felder: eventsTitle, labelAll, labelStatsCol, labelLivetickCol
    var titleDefault  = f(disc, "displayName", disc.name) + " \u2013 Event-\u00dcbersicht";
    var title         = f(disc, "eventsTitle",      titleDefault);
    var labelAll      = (g.labelall || "Alle");
    var labelLt       = f(disc, "labelLiveticker", g.labelliveticker || "Mit Liveticker");
    var labelStatCol  = (g.labelstatscol || "Statistiken");
    var labelLtCol    = (g.labellivetickcol || "Liveticker");
    var labelEvtCol   = f(disc, "labelEventCol",     "Event");

    const rows = events.map(function(e) {
      const ltLow     = e.liveticker.toLowerCase();
      const hasLt     = ltLow === "ja" || ltLow === "yes";
      const ltAnfrage = ltLow.indexOf("anfrage") !== -1 || ltLow.indexOf("on request") !== -1;
      const hasStats  = e.stats.trim() !== "";
      const statTags  = hasStats
        ? e.stats.split(",").map(function(s) {
            var key = s.trim();
            var translated = (disc._statsMap && disc._statsMap[key.toLowerCase()]) || key;
            return '<span class="hs-stat-tag">' + translated + '</span>';
          }).join("")
        : '<span class="hs-no-data">\u2013</span>';
      const ltCell = hasLt     ? '<span class="hs-lt-yes">✓ ' + e.liveticker + '</span>'
                   : ltAnfrage ? '<span class="hs-lt-request">' + e.liveticker + '</span>'
                   :             '<span class="hs-lt-no">–</span>';
      const filterAttr = "all" + (hasLt ? " liveticker" : "") + (hasStats ? " stats" : "");
      var statArr = e.stats ? e.stats.split(",").map(function(s){ return s.trim(); }).filter(Boolean) : [];
      var allMapped = statArr.map(function(s){
        return disc._statsMap && disc._statsMap[s.toLowerCase()] ? disc._statsMap[s.toLowerCase()] : s;
      });
      function makeTag(s){ return '<span class="hs-stat-tag">' + s + '</span>'; }
      var allTagsHtml = allMapped.map(makeTag).join("");
            var statsCellContent;
      if (allMapped.length === 0) {
        statsCellContent = '<span class="hs-no-data">–</span>';
      } else {
        statsCellContent = '<div class="hs-stat-tags-wrap">' + allTagsHtml + '</div>';
      }
      return '<tr class="hs-event-row fade-in" data-filter="' + filterAttr + '">' +
        '<td class="hs-event-name"><span class="hs-event-name-inner">' + e.name + '</span></td>' +
        '<td class="hs-event-stats">' + statsCellContent + '</td>' +
        '<td class="hs-event-lt">' + ltCell + '</td>' +
      '</tr>';
    }).join("");

    var labelShowMore = (g.labelshowmore || "Show more");
    var labelShowLess = (g.labelshowless || "Show less");
    var visibleRows   = rows;
    var showMoreHtml  =
      '<div class="hs-show-more-row">' +
        '<button class="hs-show-more-btn" data-show-more="' + labelShowMore + '" data-show-less="' + labelShowLess + '" onclick="(function(b){' +
          'var wrap=b.parentNode.previousElementSibling;' +
          'var collapsed=wrap.classList.contains(\'hs-table-wrap--collapsed\');' +
          'wrap.classList.toggle(\'hs-table-wrap--collapsed\',!collapsed);' +
          'b.textContent=collapsed?b.getAttribute(\'data-show-less\'):b.getAttribute(\'data-show-more\');' +
          'wrap.classList.toggle(\'hs-wrap-expanded\',collapsed);' +
          'document.body.style.overflow=\'\';' +
          'document.documentElement.style.overflow=\'\';' +
          'var sec=document.getElementById(\'hs-events\');' +
          'if(!collapsed&&sec){setTimeout(function(){sec.scrollIntoView({behavior:\'smooth\',block:\'nearest\'});},50);}' +
        '})(this)">' + labelShowMore + '</button>' +
      '</div>';

    return '<section class="hs-events-section" id="hs-events">' +
      '<div class="hs-container">' +
        '<h2 class="hs-section-title">' + title + '</h2>' +
        '<span class="hs-section-bar"></span>' +
        '<div class="hs-filter-bar" id="hs-filter-bar">' +
          '<button class="hs-filter-btn active" data-filter="all">' + labelAll + ' (' + totalEvents + ')</button>' +
          '<button class="hs-filter-btn" data-filter="liveticker">' + labelLt + ' (' + livetickCount + ')</button>' +
        '</div>' +
        '<div class="hs-table-wrap hs-table-wrap--collapsed">' +
          '<table class="hs-events-table">' +
            '<thead><tr>' +
              '<th>' + labelEvtCol + '</th>' +
              '<th class="hs-th-hide-mobile">' + labelStatCol + '</th>' +
              '<th style="text-align:center;">' + labelLtCol + '</th>' +
            '</tr></thead>' +
            '<tbody id="hs-events-visible">' + visibleRows + '</tbody>' +
          '</table>' +
        '</div>' +
        showMoreHtml +
      '</div>' +
    '</section>';
  }

  // ── Contact Section MIT Formular (nur Detail-Seite) ───────────────────────

  function renderContactSection(disc, discName, g) {
    const heading = discName ? discName + " anfragen" : "Interesse?";
    const subtext = discName ? "Interesse an " + discName + "-Daten? Wir beraten Sie gerne." : "";
    const ctaTxt  = (g.ctatext || "Jetzt anfragen");

    return '<section class="hs-contact" id="contact">' +
      '<div class="hs-container" style="max-width:700px;">' +
        '<h2 class="hs-section-title" style="color:#fff;">' + heading + '</h2>' +
        '<span class="hs-section-bar"></span>' +
        '<p style="text-align:center;color:rgba(255,255,255,.7);margin-bottom:2rem;">' + subtext + '</p>' +
        '<form id="hs-contact-form" style="display:flex;flex-direction:column;gap:1rem;">' +
          '<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">' +
            '<input type="text"  name="name"    placeholder="Name *"    required class="hs-input">' +
            '<input type="email" name="email"   placeholder="E-Mail *"  required class="hs-input">' +
          '</div>' +
          '<input type="text"    name="company" placeholder="Unternehmen"        class="hs-input">' +
          '<textarea             name="message" placeholder="Nachricht *" required class="hs-input hs-textarea" rows="4"></textarea>' +
          '<button type="submit" class="hs-cta-primary" style="align-self:center;padding:14px 48px;cursor:pointer;border:none;font-family:Lato,sans-serif;">' + ctaTxt + '</button>' +
          '<div id="hs-form-msg" style="display:none;text-align:center;padding:.75rem;border-radius:4px;font-size:.9rem;"></div>' +
        '</form>' +
      '</div>' +
    '</section>';
  }

  // ── Animations & Filter ───────────────────────────────────────────────────

  // Runtime full-width fix for statische Sektionen (Technology You Can Trust etc.)
  // Findet alle Sektionen mit dunkelblauem Hintergrund (#061d3e) und macht sie full-width
  function initFullWidthSections() {
    // Warte bis DOM vollständig gerendert
    requestAnimationFrame(function() {
      // Dieselbe Selektor-Logik wie in injectStyles:
      // Statische WP-Sektionen per Klassenname erfassen (unabhängig von Hintergrundfarbe)
      const targets = document.querySelectorAll(
        '.usps-section, .trust-section, .tech-section, ' +
        '[class*="technology"], [class*="trust"], [class*="usps"], [class*="why"], ' +
        '.wp-block-group, .hs-stats-bar, .hs-integration, .hs-mid-cta, .hs-contact, .hs-hero'
      );
      const breakout = {
        position: 'relative',
        left: '50%',
        right: '50%',
        marginLeft: '-50vw',
        marginRight: '-50vw',
        width: '100vw',
        maxWidth: '100vw',
        boxSizing: 'border-box'
      };
      targets.forEach(function(el) {
        Object.assign(el.style, breakout);
      });
      // Zusätzlich: alle section/div mit nicht-transparentem Hintergrund (Fallback)
      document.querySelectorAll('section, .wp-block-group').forEach(function(el) {
        const bg = window.getComputedStyle(el).backgroundColor;
        if (bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') {
          Object.assign(el.style, breakout);
        }
      });
    });
  }






  function initAnimations() {
    // ── Counter Animation (WC-style, scroll/IO/fallback) ─────────────────
    (function () {
      var done = false;
      function count() {
        if (done) return; done = true;
        document.querySelectorAll('.hs-sb-val[data-target]').forEach(function (el) {
          var t = parseInt(el.getAttribute('data-target'), 10) || 0, d = 1600, s = null;
          function step(ts) {
            if (!s) s = ts;
            var p = Math.min((ts - s) / d, 1), v = Math.round((1 - Math.pow(1 - p, 3)) * t);
            el.textContent = t >= 1000 ? v.toLocaleString('de-DE') : String(v);
            if (p < 1) requestAnimationFrame(step);
          }
          requestAnimationFrame(step);
        });
      }
      var bar = document.querySelector('.hs-stats-bar');
      if (bar) {
        function chk() {
          var r = bar.getBoundingClientRect();
          if (r.top < window.innerHeight && r.bottom > 0) { count(); window.removeEventListener('scroll', chk); }
        }
        window.addEventListener('scroll', chk, { passive: true });
        if ('IntersectionObserver' in window) {
          new IntersectionObserver(function (en, ob) {
            if (en[0].isIntersecting) { count(); ob.disconnect(); }
          }, { threshold: 0.1 }).observe(bar);
        }
        var poll = setInterval(function () {
          var r = bar.getBoundingClientRect();
          if (r.top < window.innerHeight && r.bottom > 0) { count(); clearInterval(poll); }
        }, 400);
        setTimeout(function () { count(); clearInterval(poll); }, 4000);
      }
    })();
    // ── Fade-in Animations ───────────────────────────────────────────────
    const obs = new IntersectionObserver(function(entries) {
      entries.forEach(function(e) {
        if (e.isIntersecting) { e.target.classList.add("visible"); obs.unobserve(e.target); }
      });
    }, { threshold: 0.05 });
    document.querySelectorAll(".fade-in").forEach(function(el) { obs.observe(el); });

    const form = document.getElementById("hs-contact-form");
    const msg  = document.getElementById("hs-form-msg");
    if (form && msg) {
      form.addEventListener("submit", function(e) {
        e.preventDefault();
        const btn = form.querySelector("button[type=submit]");
        btn.disabled = true;
        btn.textContent = "Wird gesendet\u2026";
        fetch("https://formspree.io/f/mwkgpqab", {
          method: "POST",
          headers: { "Accept": "application/json" },
          body: new FormData(form)
        }).then(function(r) {
          if (r.ok) {
            form.reset();
            msg.style.display = "block"; msg.style.background = "#f0fdf4"; msg.style.color = "#16a34a";
            msg.textContent = "Vielen Dank! Wir melden uns in K\u00fcrze.";
          } else { throw new Error("server"); }
        }).catch(function() {
          msg.style.display = "block"; msg.style.background = "#fef2f2"; msg.style.color = "#dc2626";
          msg.textContent = "Fehler beim Senden. Bitte erneut versuchen.";
          btn.disabled = false; btn.textContent = "Jetzt anfragen";
        });
      });
    }
  }

  function initFilter() {
    var bar = document.getElementById('hs-filter-bar');
    if (!bar) return;
    function applyFilter(filterVal) {
      var btns = document.querySelectorAll('.hs-filter-btn');
      btns.forEach(function(b) {
        b.classList.toggle('active', b.dataset.filter === filterVal);
      });
      document.querySelectorAll('.hs-event-row').forEach(function(row) {
        var filters = row.dataset.filter || '';
        row.classList.toggle('hs-hidden', filterVal !== 'all' && filters.indexOf(filterVal) === -1);
      });
    }
    bar.addEventListener('click', function(e) {
      var btn = e.target.closest('.hs-filter-btn');
      if (!btn) return;
      applyFilter(btn.dataset.filter);
    });
    bar.addEventListener('touchend', function(e) {
      var btn = e.target.closest('.hs-filter-btn');
      if (!btn) return;
      e.preventDefault();
      applyFilter(btn.dataset.filter);
    }, {passive: false});
  }

  function capitalize(str) { return str.charAt(0).toUpperCase() + str.slice(1); }

  // ── SVG Background ────────────────────────────────────────────────────────

  function heroSVG() {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1536 910" preserveAspectRatio="xMidYMid slice" style="position:absolute;inset:0;width:100%;height:100%">' +
      '<defs>' +
        '<pattern id="hexpat" x="0" y="0" width="56" height="48" patternUnits="userSpaceOnUse">' +
          '<polygon points="28,2 54,15 54,41 28,54 2,41 2,15" fill="none" stroke="rgba(255,255,255,0.035)" stroke-width="1"/>' +
        '</pattern>' +
        '<linearGradient id="hslg" x1="0%" y1="0%" x2="100%" y2="0%">' +
          '<stop offset="0%" stop-color="#061d3e" stop-opacity="1"/>' +
          '<stop offset="55%" stop-color="#061d3e" stop-opacity="0.55"/>' +
          '<stop offset="100%" stop-color="#061d3e" stop-opacity="0.05"/>' +
        '</linearGradient>' +
      '</defs>' +
      '<rect width="1536" height="910" fill="#061d3e"/>' +
      '<rect width="1536" height="910" fill="url(#hexpat)"/>' +
      '<polygon points="950,-10 1150,-10 750,920 550,920" fill="#1e40af" opacity="0.2"/>' +
      '<polygon points="1100,-10 1300,-10 950,920 750,920" fill="#e75519" opacity="0.22"/>' +
      '<rect width="1536" height="910" fill="url(#hslg)"/>' +
    '</svg>';
  }

  // ── Styles ────────────────────────────────────────────────────────────────
  // #8/#9: .usps-section, .trust-section, .tech-section bekommen full-width
  // via direktem CSS-Override – greift auf statische Sektionen aus WM-Landingpage

  function injectStyles() {
    if (document.getElementById("hs-landing-styles")) return;
    const style = document.createElement("style");
    style.id = "hs-landing-styles";
    style.textContent = [
      "@keyframes hsSpin{to{transform:rotate(360deg)}}",
      // Full-width breakout: dynamische Sektionen + statische WM-Sektionen
      "#hs-root,",
      ".hs-hero,.hs-stats-bar,.hs-cards-section,.hs-coverage-section,.hs-events-section,.hs-contact,.hs-breadcrumb,",
      // #8/#9: statische Sektionen aus der WM-Landingpage-HTML
      ".usps-section,.trust-section,.tech-section,[class*='technology'],[class*='trust'],[class*='usps'],[class*='why']{",
      "  position:relative!important;",
      "  left:50%!important;",
      "  right:50%!important;",
      "  margin-left:-50vw!important;",
      "  margin-right:-50vw!important;",
      "  width:100vw!important;",
      "  max-width:100vw!important;",
      "  box-sizing:border-box!important;",
      "}",
      "#hs-root{overflow-x:hidden;font-family:Lato,sans-serif;color:#323232;margin-top:0!important;padding-top:0!important;}",
      ".wp-block-html,.wp-block-html>div,.entry-content>.wp-block-html{margin-top:0!important;padding-top:0!important;}",
      ".hs-hero{min-height:580px;display:flex;align-items:center;background:#061d3e;overflow:hidden;margin-top:0!important;}",
      ".hs-hero-bg{position:absolute;inset:0;z-index:0;overflow:hidden;}",
      ".hs-hero-bg::after{content:'';position:absolute;inset:0;background:linear-gradient(to right,rgba(6,29,62,0.60) 0%,rgba(6,29,62,0.35) 35%,rgba(6,29,62,0.10) 65%,rgba(6,29,62,0.00) 100%);}",
      ".hs-hero-inner{position:relative;z-index:2;width:100%;max-width:1280px;margin:0 auto;padding:80px 64px;}",
      ".hs-eyebrow-pill{display:inline-flex;align-items:center;gap:8px;background:rgba(231,85,25,0.18);border:1px solid rgba(231,85,25,0.4);border-radius:4px;padding:5px 14px 5px 10px;margin-bottom:28px;}",
      ".hs-eyebrow-dot{width:8px;height:8px;border-radius:50%;background:#e75519;flex-shrink:0;box-shadow:0 0 6px #e75519;}",
      ".hs-eyebrow-text{font-size:.7rem;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#e75519;}",
      ".hs-headline{font-size:clamp(2.2rem,4.5vw,4rem);font-weight:900;line-height:1.05;letter-spacing:-.02em;color:#fff;margin-bottom:20px;}",
      ".hs-accent{color:#e75519;}",
      ".hs-desc{font-size:1rem;color:rgba(255,255,255,.65);max-width:540px;line-height:1.7;margin-bottom:36px;}",
      ".hs-ctas{display:flex;flex-wrap:wrap;gap:14px;}",
      ".hs-cta-primary{display:inline-block;font-weight:900;font-size:.95rem;padding:14px 32px;background:#e75519;color:#fff!important;text-decoration:none;transition:background .2s;border:none;cursor:pointer;}",
      ".hs-cta-primary:hover{background:#b84010;color:#fff!important;}",
      ".hs-cta-secondary{display:inline-block;font-weight:700;font-size:.95rem;padding:14px 32px;border-radius:6px;background:rgba(255,255,255,.1);border:1.5px solid rgba(255,255,255,.3);color:#fff;text-decoration:none;}",
      ".hs-cta-secondary:hover{background:rgba(255,255,255,.18);}",
      "@media(max-width:768px){.hs-hero-inner{padding:40px 20px;}}",
      ".hs-breadcrumb{background:#f0f2f5;padding:.65rem 0;border-bottom:1px solid #e2e6ec;}",
      ".hs-container{width:100%;max-width:1180px;margin:0 auto;padding:0 24px;box-sizing:border-box;}",
      ".hs-bc-link{color:#e75519;font-size:.82rem;font-weight:700;text-decoration:none;}",
      ".hs-bc-link:hover{text-decoration:underline;}",
      ".hs-bc-sep{color:#9ca3af;margin:0 .4rem;font-size:.82rem;}",
      ".hs-bc-current{color:#374151;font-size:.82rem;font-weight:600;}",
      ".hs-stats-bar{background:#e75519;margin-bottom:0;}",
      ".hs-stats-inner{display:grid;max-width:1180px;margin:0 auto;}",
      ".hs-sb-item{padding:1.75rem 1.5rem;text-align:center;border-right:1px solid rgba(255,255,255,.18);}",
      ".hs-sb-item:last-child{border-right:none;}",
      ".hs-sb-val{font-size:2.1rem;font-weight:900;color:#fff;line-height:1;}",
      ".hs-sb-label{font-size:.75rem;font-weight:700;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.07em;margin-top:.45rem;}",
      "@media(max-width:600px){.hs-stats-inner{grid-template-columns:repeat(2,1fr)!important;}.hs-sb-item:nth-child(2){border-right:none;}.hs-sb-item:nth-child(-n+2){border-bottom:1px solid rgba(255,255,255,.18);}.hs-sb-item:last-child:nth-child(odd){grid-column:1/-1;border-right:none;border-top:1px solid rgba(255,255,255,.18);}}",
      ".hs-cards-section{padding:80px 0;background:#f8f9fb;}",
      ".hs-section-eyebrow{color:#e75519!important;font-size:.75rem;font-weight:700;letter-spacing:.18em;text-transform:uppercase;display:block;text-align:center;margin-bottom:.5rem;}",
      ".hs-section-title{font-size:clamp(1.6rem,4vw,2.4rem);font-weight:900;color:#061d3e;text-align:center;line-height:1.2;margin:0;}",
      ".hs-section-bar{width:50px;height:3px;background:#e75519;margin:.75rem auto 2.5rem;display:block;}",
      ".hs-cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1.25rem;min-width:0;}",
      ".hs-card{display:flex;flex-direction:column;background:#fff;border:1px solid #e2e6ec;border-radius:6px;overflow:hidden;text-decoration:none;color:inherit;transition:all .2s;min-width:0;max-width:100%;box-sizing:border-box;}",
      ".hs-card:hover{transform:translateY(-3px);box-shadow:0 6px 32px rgba(0,0,0,.1);border-color:transparent;}",
      ".hs-card-head{background:#061d3e;padding:.9rem 1.1rem;display:flex;align-items:center;justify-content:flex-start;gap:.5rem;min-width:0;max-width:100%;box-sizing:border-box;}",
      ".hs-card-sport{font-size:.9rem;font-weight:900;color:#fff;text-align:left;flex:1 1 auto;min-width:0;overflow-wrap:normal;word-break:normal;white-space:normal;line-height:1.3;}",
      ".hs-lt-badge{font-size:.58rem;font-weight:900;padding:.2rem .5rem;border-radius:3px;background:#e75519;color:#fff;letter-spacing:.05em;white-space:nowrap;}",
      ".hs-card-body{display:grid;grid-template-columns:repeat(2,1fr);padding:.85rem 1.1rem;background:#f8f9fb;flex:1;}",
      ".hs-card-stat{text-align:center;padding:.5rem .25rem;}",
      ".hs-stat-num{display:block;font-size:1.5rem;font-weight:900;color:#e75519;line-height:1.1;}",
      ".hs-stat-lbl{display:block;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;margin-top:.15rem;}",
      ".hs-card-footer{padding:.65rem 1.1rem;font-size:.78rem;font-weight:700;color:#e75519;background:#fff;display:flex;align-items:center;gap:.4rem;}",
      ".hs-arrow{transition:transform .2s;}",
      ".hs-card:hover .hs-arrow{transform:translateX(4px);}",
".hs-card-compact{min-height:auto;}",
".hs-card-compact .hs-card-head{padding:.75rem 1rem;min-height:56px;}",
".hs-card-compact .hs-card-sport{font-size:.82rem;line-height:1.3;}",
".hs-card-compact .hs-card-footer{padding:.55rem 1rem;font-size:.72rem;flex-direction:column;align-items:flex-start;gap:.2rem;}",
".hs-card-footer-count{font-weight:700;color:#374151;}",
".hs-card-footer-link{font-weight:700;color:#e75519;display:flex;align-items:center;gap:.3rem;min-width:0;}",
".hs-cards-grid-compact{grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.85rem;}",
".hs-cards-section-compact{padding-top:0;padding-bottom:24px;margin-top:0;background:#f8f9fb;display:flow-root;}",
      ".hs-coverage-top{font-size:1.1rem;font-weight:900;color:#061d3e;margin:0 0 1rem;text-align:left;}",
      ".hs-coverage-intro-section{background:#f8f9fb;}",
      ".hs-coverage-intro{padding-top:40px;padding-bottom:8px;}",
      ".hs-coverage-intro .hs-section-bar{margin-bottom:0;}",
".hs-cards-section-compact .hs-section-title{font-size:clamp(1.3rem,2.2vw,1.9rem);}",
      ".hs-subtext-bar{padding:0 0 5rem;background:#f8f9fb;}",
      ".hs-subtext{font-size:.9rem;color:#6b7280;line-height:1.7;max-width:860px;margin:0 auto;text-align:center;}",
      ".hs-coverage-section{padding:40px 0 80px;background:#f8f9fb;}",
      ".hs-cov-grid{display:grid;gap:1.125rem;}",
      "@media(max-width:1024px){.hs-cov-grid{grid-template-columns:repeat(2,1fr)!important;}}",
      "@media(max-width:640px){.hs-cov-grid{grid-template-columns:1fr!important;}}",
      ".hs-cov-card{border:1px solid #e2e6ec;border-radius:6px;overflow:hidden;display:flex;flex-direction:column;transition:all .2s;}",
      ".hs-cov-card:hover{transform:translateY(-3px);box-shadow:0 6px 32px rgba(0,0,0,.1);border-color:transparent;}",
      ".hs-cov-head{background:#061d3e;color:#fff;padding:.9rem 1.1rem;display:flex;align-items:center;gap:.7rem;flex-shrink:0;}",
      ".hs-cov-icon{width:30px;height:30px;border-radius:6px;background:rgba(231,85,25,0.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;}",
      ".hs-cov-icon svg{width:16px;height:16px;stroke:#e75519;}",
      ".hs-cov-name{font-size:.875rem;font-weight:900;margin:0;color:inherit;}",
      ".hs-card-footer-names{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}",
      ".hs-cov-body{padding:.85rem 1.1rem;background:#f8f9fb;flex:1;}",
      ".hs-cov-list{list-style:none;padding:0;margin:0;}",
      ".hs-cov-list li{font-size:.825rem;color:#6b7280;padding:.14rem 0;display:flex;align-items:flex-start;gap:.5rem;}",
      ".hs-cov-list li::before{content:'';width:4px;height:4px;border-radius:50%;background:#e75519;flex-shrink:0;margin-top:.45rem;}",
      ".hs-events-section{padding:80px 0;background:#f8f9fb;}",
      ".hs-filter-bar{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.5rem;justify-content:center;}",
      ".hs-filter-btn{padding:.5rem 1.25rem;border:1.5px solid #e2e6ec;background:#fff;border-radius:4px;font-size:.82rem;font-weight:700;color:#374151;cursor:pointer;transition:all .15s;}",
      ".hs-filter-btn:hover{border-color:#e75519;color:#e75519;}",
      ".hs-filter-btn.active{background:#e75519;border-color:#e75519;color:#fff;}",
      ".hs-table-wrap{overflow-x:auto;border-radius:6px;border:1px solid #e2e6ec;background:#fff;}",
      ".hs-events-table{width:100%;border-collapse:collapse;font-size:.875rem;table-layout:fixed;}",
      ".hs-events-table thead th{background:#061d3e;color:#fff;padding:.85rem 1.5rem!important;text-align:left;font-weight:900;font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;}",
      ".hs-events-table thead th:nth-child(1){width:220px;}",
      ".hs-events-table thead th:nth-child(3){width:140px;text-align:center;}",
      ".hs-events-table tbody tr{border-bottom:1px solid #f0f2f5;transition:background .15s;}",
      ".hs-events-table tbody tr:last-child{border-bottom:none;}",
      ".hs-events-table tbody tr:hover{background:#fef9f7;}",
      "#hs-root .hs-event-row.hs-hidden{display:none!important;}",
      ".hs-event-name{padding:.75rem 1.5rem!important;font-weight:700;color:#111827!important;}",
      ".hs-event-stats{padding:.75rem 1.25rem!important;vertical-align:top;}",
      ".hs-event-lt{padding:.75rem 1.5rem!important;text-align:center;vertical-align:middle;}",
      ".hs-stats-expand{display:inline-flex;align-items:center;justify-content:center;background:none;border:1px solid #e75519;border-radius:10px;padding:1px 7px;font-size:.72rem;font-weight:700;color:#e75519;cursor:pointer;margin-left:4px;vertical-align:middle;line-height:1.4;}",
      ".hs-th-hide-mobile{}",
      "@media(max-width:640px){#hs-root .hs-table-wrap{overflow-x:hidden;touch-action:pan-y;}#hs-root .hs-table-wrap.hs-wrap-expanded{overflow:visible!important;touch-action:auto!important;}#hs-root .hs-events-table{display:block!important;width:100%!important;}#hs-root .hs-events-table thead{display:block!important;width:100%!important;}#hs-root .hs-events-table thead tr{display:flex!important;width:100%!important;background:#061d3e;}#hs-root .hs-events-table thead th{color:#fff;font-size:.75rem;font-weight:900;text-transform:uppercase;letter-spacing:.06em;padding:.7rem 1rem!important;}#hs-root .hs-events-table thead th:nth-child(2){display:none!important;}#hs-root .hs-events-table thead th:nth-child(1){flex:1!important;text-align:left!important;}#hs-root .hs-events-table thead th:nth-child(3){width:80px!important;text-align:center!important;flex-shrink:0!important;}#hs-root .hs-events-table tbody{display:block!important;width:100%!important;}#hs-root .hs-event-row{display:grid!important;grid-template-columns:1fr auto;grid-template-rows:auto auto;width:100%!important;box-sizing:border-box;border-bottom:1px solid #e5e7eb;}#hs-root .hs-events-table tbody tr td{display:block!important;min-width:0;box-sizing:border-box;}#hs-root .hs-event-name{grid-column:1!important;grid-row:1!important;padding:.65rem 1rem!important;}#hs-root .hs-event-lt{grid-column:2!important;grid-row:1!important;padding:.65rem .75rem!important;text-align:center!important;white-space:nowrap;}#hs-root .hs-event-stats{grid-column:1/-1!important;grid-row:2!important;padding:.4rem 1rem .75rem!important;border-top:1px solid #f0f0f0;}}",
      ".hs-stat-tag{display:inline-block;background:#fff5f5;border:1px solid #fca5a5;color:#374151;font-size:.72rem;font-weight:600;padding:.18rem .45rem;border-radius:4px;margin:.1rem .15rem .1rem 0;}",
      ".hs-stat-tags-wrap{position:relative;line-height:1.6;padding-bottom:2px;}",
      ".hs-stat-tags-wrap.hs-stat-tags-expanded{}",
      ".hs-stat-tags-wrap .hs-stats-expand{vertical-align:middle;}",
      ".hs-tag-hidden{display:none!important;}",
      ".hs-no-data{color:#9ca3af;font-size:.82rem;}",
      ".hs-lt-yes{color:#16a34a;font-weight:900;font-size:.82rem;}",
      ".hs-lt-request{color:#d97706;font-weight:700;font-size:.78rem;}",
      ".hs-lt-no{color:#d1d5db;font-size:.82rem;}",
      ".hs-contact{padding:80px 0;background:#061d3e;}",
      ".hs-contact .hs-section-title{color:#fff;}",
      ".hs-input{width:100%;padding:.75rem 1rem;border:1px solid #d1d5db;border-radius:4px;font-size:.9rem;font-family:Lato,sans-serif;box-sizing:border-box;background:#fff;color:#111827;outline:none;transition:border-color .15s;}",
      ".hs-input:focus{border-color:#e75519;}",
      ".hs-textarea{resize:vertical;min-height:120px;}",
      ".fade-in{opacity:0;transform:translateY(16px);transition:opacity .45s,transform .45s;}",
      ".fade-in.visible{opacity:1;transform:none;}",
      "@media(max-width:640px){" +
      ".hs-cards-grid{grid-template-columns:1fr;min-width:0;width:100%;max-width:100%;box-sizing:border-box;}" +
      ".hs-cards-grid .hs-coverage-more-item{min-width:0;max-width:100%;box-sizing:border-box;}" +
      ".hs-cards-grid .hs-tc-card-wrap{min-width:0;max-width:100%;box-sizing:border-box;}" +
      ".hs-cards-grid .hs-card{min-width:0;max-width:100%;box-sizing:border-box;border:1px solid #e2e6ec;border-radius:8px;margin-bottom:0;}" +
      ".hs-cards-grid .hs-card-head{min-width:0;max-width:100%;box-sizing:border-box;flex-wrap:wrap;}" +
      ".hs-cards-grid .hs-card-sport{min-width:0;max-width:100%;overflow-wrap:normal;word-break:normal;white-space:normal;}" +
      ".hs-coverage-more-list{padding:.9rem 0!important;margin-top:0!important;background:transparent!important;box-sizing:border-box;max-width:100%;overflow-x:hidden;}" +
      ".hs-coverage-more-list .hs-cards-grid{gap:.75rem;}" +
    "}",
      "@media(max-width:600px){#hs-contact-form>div:first-child{grid-template-columns:1fr;}}",
      // Integration Section
      ".hs-integration{padding:80px 0;background:#061d3e;position:relative;left:50%;right:50%;margin-left:-50vw;margin-right:-50vw;width:100vw;max-width:100vw;}",
      ".hs-int-headline{font-size:clamp(1.5rem,3vw,2.2rem);font-weight:900;color:#fff;text-align:center;margin:0 0 .5rem;}",
      ".hs-int-bar{display:block;width:50px;height:3px;background:#e75519;margin:0 auto 3rem;}",
      ".hs-int-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:2rem;max-width:1100px;margin:0 auto;}",
      "@media(max-width:900px){.hs-int-grid{grid-template-columns:1fr;}}",
      ".hs-int-card{text-align:center;padding:2rem 1.5rem;border:1px solid rgba(255,255,255,.1);border-radius:8px;background:rgba(255,255,255,.04);transition:background .2s;}",
      ".hs-int-card:hover{background:rgba(255,255,255,.08);}",
      ".hs-int-icon{width:80px;height:80px;margin:0 auto 1.25rem;display:flex;align-items:center;justify-content:center;}",
      ".hs-int-icon svg{width:100%;height:100%;}",
      ".hs-int-sub{font-size:.72rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:#e75519;margin-bottom:.5rem;}",
      ".hs-int-title{font-size:1.15rem;font-weight:900;color:#fff;margin:0 0 1rem;}",
      ".hs-int-desc{font-size:.875rem;color:rgba(255,255,255,.65);line-height:1.7;margin:0;}",
      // Mid-CTA Section
      ".hs-mid-cta{padding:60px 0;background:#fff;position:relative;left:50%;right:50%;margin-left:-50vw;margin-right:-50vw;width:100vw;max-width:100vw;}",
      ".hs-mid-cta-inner{display:flex;align-items:center;justify-content:space-between;gap:2rem;flex-wrap:wrap;}",
      ".hs-mid-cta-text{flex:1;min-width:260px;}",
      ".hs-mid-cta-headline{font-size:clamp(1.2rem,2.5vw,1.7rem);font-weight:900;color:#061d3e;margin:0 0 .5rem;line-height:1.3;}",
      ".hs-mid-cta-sub{font-size:.9rem;color:#6b7280;margin:0;line-height:1.6;}",
      "@media(max-width:640px){.hs-mid-cta-inner{flex-direction:column;text-align:center;align-items:center;}.hs-int-card{text-align:center;}}",
      /* Related Services Slider */
      ".hs-related{padding:4rem 0;background:#f8f9fb;}",
      ".hs-related-title{margin-bottom:.5rem;}",
      ".hs-rel-wrap{position:relative;display:flex;align-items:center;gap:12px;}",
      ".hs-rel-track{display:flex;gap:16px;overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;scrollbar-width:none;padding:8px 0 16px;flex:1;}",
      ".hs-rel-track::-webkit-scrollbar{display:none;}",
      ".hs-rel-card,.hs-rel-card--nolink{flex:0 0 240px;height:160px;border-radius:8px;overflow:hidden;scroll-snap-align:start;text-decoration:none;display:block;}",
      ".hs-rel-card-inner{width:100%;height:100%;display:flex;flex-direction:column;justify-content:flex-end;padding:20px;box-sizing:border-box;position:relative;}",
      ".hs-rel-card-name{color:#fff;font-size:1.1rem;font-weight:800;line-height:1.2;text-shadow:0 1px 3px rgba(0,0,0,.4);}",
      ".hs-rel-card-arrow{position:absolute;top:16px;right:16px;color:rgba(255,255,255,.7);font-size:1.3rem;transition:transform .2s;}",
      ".hs-rel-card:hover .hs-rel-card-arrow{transform:translateX(4px);}",
      ".hs-rel-card:hover .hs-rel-card-inner{filter:brightness(1.1);}",
      ".hs-rel-card--nolink{opacity:.75;cursor:default;}",
      ".hs-rel-arrow{flex-shrink:0;width:36px;height:36px;border-radius:50%;background:#061d3e;color:#fff;border:none;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center;transition:background .2s;z-index:1;}",
      ".hs-rel-arrow:hover{background:#e75519;}",
      "@media(max-width:640px){.hs-rel-arrow{display:none;}.hs-rel-track{padding-bottom:12px;}.hs-rel-wrap::after{content:\"\";position:absolute;right:0;top:0;bottom:0;width:56px;background:linear-gradient(to right,transparent,rgba(255,255,255,.92) 60%);pointer-events:none;z-index:2;border-radius:0 8px 8px 0;transition:opacity .3s;}.hs-rel-wrap::before{content:\"›\";position:absolute;right:6px;top:50%;transform:translateY(-50%);font-size:1.6rem;color:#e75519;font-weight:900;pointer-events:none;z-index:3;line-height:1;text-shadow:0 0 6px rgba(255,255,255,.8);transition:opacity .3s;}.hs-rel-wrap.hs-rel-end::after,.hs-rel-wrap.hs-rel-end::before{opacity:0;}}",
      /* Show More Button */
      ".hs-show-more-row td{text-align:center;padding:1rem 0 .5rem;}",
      ".hs-show-more-row{text-align:center;padding:1rem 0 .5rem;}",
      ".hs-table-wrap--collapsed{position:relative;max-height:680px;overflow:hidden;}",
      ".hs-table-wrap--collapsed::after{content:\"\";position:absolute;bottom:0;left:0;right:0;height:120px;background:linear-gradient(to bottom,rgba(255,255,255,0) 0%,rgba(255,255,255,.95) 100%);pointer-events:none;}",
      ".hs-show-more-btn{background:none;border:1px solid #e75519;border-radius:4px;padding:.5rem 1.5rem;font-size:.85rem;font-weight:600;color:#e75519;cursor:pointer;transition:all .2s;}",
      ".hs-show-more-btn:hover{background:#e75519;color:#fff;border-color:#e75519;}",
      /* General Slots (Detail-Seite) */
      ".hs-gslot-why{background:#061d3e;padding:4rem 0;}",
      ".hs-gslot-trust{background:#f7f6f2;padding:4rem 0;}",
      ".faq-accordion{max-width:780px;margin:0 auto;}",
      ".faq-item{border-bottom:1px solid rgba(255,255,255,.12);}",
      ".faq-trigger{display:flex;align-items:center;width:100%;padding:1.1rem 0;cursor:pointer;background:none;border:none;text-align:left;gap:1rem;color:#fff;}",
      ".faq-trigger:hover .faq-trigger-title{color:#e75519;}",
      ".faq-trigger-title{font-size:1rem;font-weight:700;flex:1;line-height:1.3;}",
      ".faq-arrow{font-size:1.4rem;font-weight:300;color:#e75519;transition:transform .25s;flex-shrink:0;width:24px;text-align:center;}",
      ".faq-item.open .faq-arrow{transform:rotate(45deg);}",
      ".faq-panel{padding:.2rem 0 1.2rem calc(40px + 1rem);}",
      ".faq-desc{color:rgba(255,255,255,.75);font-size:.9rem;line-height:1.65;margin:0;}",
      ".usp-icon-wrap{width:40px;height:40px;background:rgba(231,85,25,.15);border-radius:4px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}",
      ".usp-icon-svg{width:20px;height:20px;color:#e75519;}",
      ".hs-gslot-tech{background:#061d3e;padding:4rem 0;}",
      ".tech-top-grid{display:grid!important;grid-template-columns:repeat(2,1fr);gap:1.5rem;}.tech-bottom-grid{display:grid!important;grid-template-columns:repeat(2,1fr);gap:1.5rem;}",
      "@media(min-width:768px){.tech-top-grid{grid-template-columns:repeat(4,1fr);}.tech-bottom-grid{grid-template-columns:repeat(3,1fr);}}",
      ".tech-bottom-grid>*:last-child:nth-child(3n+1){grid-column:1/-1;max-width:33%;margin:0 auto;}",
      "@media(max-width:767px){.tech-bottom-grid>*:last-child:nth-child(2n+1){grid-column:1/-1;max-width:50%;margin:0 auto;}}",
      "#hs-detail-tech-rendered .tech-stat-val{color:#e75519;}",
      ".hs-gslot-contact{background:#fff;padding:4rem 0;}",
      ".hs-gslot-eyebrow{font-size:.75rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#4f98a3;margin-bottom:.5rem;}",
      ".hs-gslot-eyebrow--dark{color:#e75519;}",
      ".hs-gslot-sub{color:#7a7974;margin:0 0 2rem;max-width:60ch;}",
      /* FAQ in gslot */
      ".hs-gslot-faq-list{display:grid;gap:8px;max-width:780px;margin:1.5rem auto 0;}",
      ".hs-gslot-faq-item{border-bottom:1px solid rgba(255,255,255,.12);}",
      ".hs-gslot-faq-trigger{display:flex;align-items:center;justify-content:space-between;width:100%;padding:1rem 0;background:none;border:none;text-align:left;cursor:pointer;gap:1rem;}",
      ".hs-gslot-faq-q{color:#fff;font-weight:700;font-size:.95rem;flex:1;}",
      ".hs-gslot-faq-arrow{color:#e75519;font-size:1.2rem;font-weight:300;flex-shrink:0;}",
      ".hs-gslot-faq-panel{padding:.25rem 0 1rem;}",
      ".hs-gslot-faq-a{color:rgba(255,255,255,.75);font-size:.9rem;line-height:1.65;margin:0;}",
      /* Brands in gslot */
      ".hs-gslot-brand-group{margin-bottom:2rem;}",
      ".hs-gslot-brand-label{font-size:.75rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#7a7974;margin-bottom:.75rem;text-align:center;}",
      ".hs-gslot-brand-logos{display:flex;flex-wrap:wrap;justify-content:center;align-items:center;gap:1.5rem;}",
      ".hs-gslot-logo{height:36px;width:auto;object-fit:contain;filter:grayscale(1);opacity:.65;transition:opacity .2s;}",
      ".hs-gslot-logo:hover{opacity:1;filter:none;}",
      /* Tech stats in gslot */
      ".hs-gslot-stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1.5rem;margin-top:1rem;}",
      ".hs-gslot-stat{text-align:center;}",
      ".hs-gslot-stat-val{font-size:2.2rem;font-weight:900;color:#4f98a3;line-height:1.1;}",
      ".hs-gslot-stat-sub{font-size:.85rem;color:rgba(255,255,255,.7);margin-top:.35rem;}",
      /* Contact form in gslot */
      ".hs-gslot-form{display:flex;flex-direction:column;gap:.75rem;max-width:560px;margin:1.5rem auto 0;}",
      ".hs-gslot-submit{width:100%;margin-top:.5rem;}"
    ,
      /* Fix: #top-link (WP theme back-to-top) auf Mobile durch overflow:hidden geclippt */
      "#top-link{position:fixed!important;-webkit-transform:translateZ(0)!important;transform:translateZ(0)!important;will-change:transform!important;display:block!important;visibility:visible!important;opacity:1!important;pointer-events:auto!important;}"
      /* Zusätzlich: zeige Element auch wenn Theme es per hide-for-medium ausblendet */
      ,"@media(max-width:767px){#top-link.hide-for-medium{display:block!important;}}",
      // ── v86: Coverage-Karten (Fussball/Tennis Laender-/Foederations-Grid) ──
      ".hs-cluster-cards{padding:0 0 80px;background:#f8f9fb;margin-top:0;display:flow-root;}",
      ".hs-cluster-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1.125rem;margin-bottom:1.5rem;}",
      "@media(max-width:1024px){.hs-cluster-grid{grid-template-columns:repeat(3,1fr)!important;}}",
      "@media(max-width:768px){.hs-cluster-grid{grid-template-columns:repeat(2,1fr)!important;}}",
      "@media(max-width:480px){.hs-cluster-grid{grid-template-columns:1fr!important;}}",
      ".hs-cluster-card{display:block;border-radius:8px;overflow:hidden;text-decoration:none;color:#fff;min-height:150px;position:relative;transition:all .2s;}",
      ".hs-cluster-card:hover{transform:translateY(-3px);box-shadow:0 8px 28px rgba(0,0,0,.22);}",
      ".hs-cluster-card-inner{position:relative;z-index:1;padding:1.2rem 1.1rem;display:flex;flex-direction:column;justify-content:space-between;height:100%;min-height:150px;}",
      ".hs-cluster-card-name{font-size:1.05rem;font-weight:900;margin:0 0 .6rem;color:#fff;}",
      ".hs-cluster-card-stats{margin-bottom:.75rem;}",
      ".hs-cluster-card-stat{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:rgba(255,255,255,.85);}",
      ".hs-cluster-card-link{font-size:.78rem;font-weight:700;color:#fff;display:inline-flex;align-items:center;gap:.3rem;}",
      ".hs-cluster-card:hover .hs-cluster-card-link{gap:.5rem;}",
      ".hs-coverage-intl-heading{font-size:1.1rem;font-weight:900;color:#061d3e;margin:2rem 0 1rem;padding-top:1.5rem;border-top:1px solid #e2e6ec;}",
      ".hs-cluster-grid-intl{margin-bottom:1.5rem;}",
      ".hs-coverage-more-wrap{margin-top:1rem;text-align:left;}",
      ".hs-coverage-more-toggle{background:#fff;border:1px solid #e2e6ec;border-radius:6px;padding:.7rem 1.2rem;font-size:.85rem;font-weight:700;color:#e75519;cursor:pointer;transition:all .2s;}",
      ".hs-coverage-more-toggle:hover{background:#f8f9fb;border-color:#e75519;}",
      ".hs-coverage-more-list{margin-top:1rem;background:#f8f9fb;border-radius:8px;padding:1.2rem;}",
      ".hs-coverage-search{width:100%;padding:.7rem 1rem;border:1px solid #e2e6ec;border-radius:6px;font-size:.9rem;margin-bottom:1rem;box-sizing:border-box;}",
      ".hs-coverage-more-ul{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;}",
      "@media(max-width:768px){.hs-coverage-more-ul{grid-template-columns:repeat(2,1fr)!important;}}",
      "@media(max-width:480px){.hs-coverage-more-ul{grid-template-columns:1fr!important;}}",
      ".hs-coverage-more-item a{display:flex;justify-content:space-between;align-items:center;padding:.6rem .8rem;background:#fff;border-radius:5px;text-decoration:none;color:#323232;font-size:.85rem;border:1px solid #e2e6ec;transition:all .2s;}",
      ".hs-coverage-more-item a:hover{border-color:#e75519;color:#e75519;}",
      ".hs-coverage-more-count{font-size:.72rem;font-weight:700;color:#6b7280;}",
      ".hs-flag-round{display:inline-block;width:32px;height:32px;border-radius:50%;background-size:cover;background-position:center;flex-shrink:0;box-shadow:0 0 0 1px rgba(255,255,255,.2);}",
      ".hs-card-compact .hs-card-head{display:flex;align-items:center;gap:.5rem;width:100%;box-sizing:border-box;justify-content:flex-start;}",
      ".hs-coverage-group-heading{font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin:1.25rem 0 .6rem;}",
      ".hs-coverage-more-list .hs-coverage-group-heading:first-of-type{margin-top:0;}",
      ".hs-coverage-fed-grid{margin-bottom:.5rem;}",
    ].join("\n");
    document.head.appendChild(style);
  }

})();

/**
 * Task 5 (Subtask D): Ein-/Ausklapp-Mechanik fuer Top-Competition- und
 * Laender-/Foederations-Kacheln. Statt zu einer (nicht existierenden)
 * Detailseite zu navigieren, faehrt die geklickte Kachel bei Klick auf volle
 * Grid-Breite aus und zeigt darunter die zugehoerige Wettbewerbsliste an.
 * Andere offene Panels im selben Grid werden dabei automatisch geschlossen
 * (nur ein Panel gleichzeitig offen, damit das Layout uebersichtlich bleibt).
 * Das eigentliche Befuellen der Liste (Matches/LiveScores/Stats-Pills)
 * passiert in window.hsRenderCompetitionPanel() (siehe Subtask E).
 */
window.hsToggleCompetitionPanel = function(btn, panelId) {
  var wrap = btn.closest('.hs-tc-card-wrap');
  var panel = document.getElementById(panelId);
  if (!wrap || !panel) return;

  // Das tatsaechliche CSS-Grid-Item ist entweder .hs-tc-card-wrap selbst
  // (Top-Competitions-Grid) ODER .hs-coverage-more-item (Laender-/
  // Foederations-Grid, wo .hs-tc-card-wrap zusaetzlich verschachtelt ist).
  var gridItem = wrap.closest('.hs-coverage-more-item') || wrap;
  var grid = gridItem.parentElement;
  var wasOpen = wrap.classList.contains('hs-tc-open');

  // Alle anderen offenen Panels im selben Grid schliessen.
  if (grid) {
    var openWraps = grid.querySelectorAll('.hs-tc-card-wrap.hs-tc-open');
    openWraps.forEach(function(ow) {
      if (ow === wrap) return;
      var oGridItem = ow.closest('.hs-coverage-more-item') || ow;
      var oPanel = ow.querySelector('.hs-tc-panel');
      var oBtn = ow.querySelector('.hs-tc-card');
      ow.classList.remove('hs-tc-open');
      oGridItem.classList.remove('hs-tc-grid-item-open');
      if (oPanel) oPanel.hidden = true;
      if (oBtn) oBtn.setAttribute('aria-expanded', 'false');
    });
  }

  if (wasOpen) {
    wrap.classList.remove('hs-tc-open');
    gridItem.classList.remove('hs-tc-grid-item-open');
    panel.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
    return;
  }

  wrap.classList.add('hs-tc-open');
  gridItem.classList.add('hs-tc-grid-item-open');
  panel.hidden = false;
  btn.setAttribute('aria-expanded', 'true');

  if (window.hsRenderCompetitionPanel) {
    window.hsRenderCompetitionPanel(panel, panelId);
  }

  setTimeout(function() {
    gridItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }, 100);
};

(function() {
  var style = document.getElementById('hs-tc-panel-style');
  if (style) return;
  style = document.createElement('style');
  style.id = 'hs-tc-panel-style';
  style.textContent = [
    // Grid-Item, das ausgefahren wird, nimmt die volle Breite des Grids ein
    // -- andere Items rutschen durch normalen Grid-Reflow automatisch weiter
    // nach unten (kein JS-Reflow-Handling notwendig).
    ".hs-tc-card-wrap{display:contents;}",
    ".hs-coverage-more-item.hs-tc-grid-item-open,.hs-tc-grid-item-open{grid-column:1/-1;}",
    ".hs-tc-card{width:100%;text-align:left;background:none;border:none;padding:0;font:inherit;cursor:pointer;}",
    ".hs-tc-arrow{transition:transform .2s ease;}",
    ".hs-tc-open .hs-tc-arrow{transform:rotate(90deg);}",
    ".hs-tc-panel{grid-column:1/-1;background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;margin-top:-.5rem;padding:1rem 1.25rem 1.25rem;}",
    ".hs-tc-panel[hidden]{display:none;}",
    ".hs-tc-panel .hs-table-wrap{overflow-x:hidden;max-width:100%;}",
    ".hs-tc-panel .hs-events-table{width:100%;max-width:100%;border-collapse:collapse;font-size:.9rem;table-layout:fixed;}",
    // Header-Hintergrund dunkel (konsistent mit hs-events-table auf Detailseiten)
    // -- Schriftfarbe WEISS statt der zu dunklen #485c79.
    ".hs-tc-panel .hs-events-table thead th{background:#061d3e;color:#fff;text-align:left;font-weight:800;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;padding:.6rem .6rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}",
    ".hs-tc-panel .hs-events-table td{padding:.55rem .6rem;border-bottom:1px solid #f0f1f3;vertical-align:middle;overflow:hidden;}",
    // v101.11 FIX (tatsaechliche Ursache): CSS-SPEZIFITAETS-KONFLIKT, kein
    // Platzproblem. Die Regel ".hs-tc-panel .hs-events-table thead th" hat
    // durch ihre 2 Typ-Selektoren (thead, th) eine HOEHERE Spezifitaet als
    // ".hs-tc-panel .hs-tc-th-num" (nur Klassen) -- dadurch gewinnen deren
    // white-space:nowrap/overflow:hidden/text-overflow:ellipsis IMMER, egal
    // was in .hs-tc-th-num gesetzt wird oder in welcher Reihenfolge die Regeln
    // stehen. !important auf allen ueberschreibenden Properties in
    // .hs-tc-th-num loest den Konflikt endgueltig. Spaltenbreite 112px.
    ".hs-tc-panel .hs-tc-col-num{text-align:center;white-space:nowrap;width:112px;min-width:112px;max-width:112px;box-sizing:border-box;font-weight:600;}",
    // v101.11 FIX: !important erzwingt white-space:normal (Zeilenumbruch
    // erlaubt) UND overflow:visible/text-overflow:unset gegen die hoehere
    // Spezifitaet von "thead th" -- das ist der fehlende Baustein, der in
    // v101.10 trotz white-space:normal noch zur Abkuerzung fuehrte.
    ".hs-tc-panel .hs-tc-th-num{text-align:center!important;font-size:.66rem!important;letter-spacing:.01em!important;white-space:normal!important;line-height:1.15!important;overflow:visible!important;text-overflow:unset!important;padding:.5rem .25rem!important;}",
    ".hs-tc-panel .hs-tc-th-name{width:auto;}",
    ".hs-tc-panel .hs-tc-th-stats{width:auto;}",
    ".hs-tc-panel .hs-tc-group-row td{background:#f8f9fb;font-weight:800;font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;color:#061d3e;padding:.45rem .6rem;border-bottom:1px solid #e5e7eb;}",
    ".hs-tc-panel .hs-event-name-inner{display:flex;align-items:center;gap:.5rem;font-weight:600;}",
    // v101.15: Mobile-only Card-Layout fuer aufgeklappte Wettbewerbe.
    // Zielbild gem. Feedback/Scribble: KEIN blauer Tabellen-Header mobil,
    // Competition oben, Statistics direkt darunter, KPI-Boxen Matches / Live
    // Scores / Live Ticker als drei grosse Karten in der letzten Zeile.
    // Desktop bleibt unangetastet.
    "@media(max-width:640px){" +
      ".hs-tc-panel{overflow:hidden;}" +
      ".hs-tc-panel .hs-table-wrap{overflow-x:hidden!important;width:100%;max-width:100%;padding:0;border:none;background:transparent;box-shadow:none;}" +
      ".hs-tc-panel .hs-events-table{display:block;width:100%!important;max-width:100%!important;table-layout:fixed!important;border-collapse:separate;border-spacing:0;background:transparent;box-sizing:border-box;}" +
      ".hs-tc-panel .hs-events-table thead{display:none!important;visibility:hidden!important;height:0!important;overflow:hidden!important;}" +
      ".hs-tc-panel .hs-events-table tbody{display:block;width:100%;max-width:100%;box-sizing:border-box;background:#f7f7f8;padding:.15rem;border-radius:10px;}" +
      ".hs-tc-panel .hs-events-table tr.hs-tc-group-row{display:block;width:100%;max-width:100%;margin:0;padding:0;background:transparent!important;border:none!important;box-shadow:none!important;}" +
      ".hs-tc-panel .hs-events-table tr.hs-tc-group-row td{display:block;width:100%;max-width:100%;padding:.75rem 1rem .35rem;background:transparent!important;border:none!important;color:#061d3e;font-size:.72rem;font-weight:800;line-height:1.2;letter-spacing:.04em;box-shadow:none!important;}" +
      "#hs-root .hs-tc-panel .hs-events-table tr.hs-event-row{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;grid-template-areas:'name name name' 'stats stats stats' 'm ls lt'!important;gap:.75rem;width:100%;max-width:100%;padding:1rem;background:#fff;border:1px solid #e2e6ec!important;border-radius:8px;margin:0 0 .65rem 0;box-sizing:border-box;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);}" +
      "#hs-root .hs-tc-panel .hs-events-table tr.hs-event-row:last-child{margin-bottom:0;}" +
      
      ".hs-tc-panel .hs-events-table tr.hs-event-row td{display:block;min-width:0;max-width:100%;padding:0;border:none;box-sizing:border-box;overflow-wrap:anywhere;word-break:break-word;}" +
      "#hs-root .hs-tc-panel .hs-events-table tr.hs-event-row td.hs-event-name{grid-area:name!important;grid-column:1/-1!important;grid-row:1!important;width:100%!important;max-width:100%!important;margin:0;padding:.65rem 1rem!important;box-sizing:border-box!important;}" +
      ".hs-tc-panel .hs-events-table tr.hs-event-row .hs-event-name .hs-event-name-inner{align-items:flex-start;gap:.55rem;line-height:1.25;}" +
      ".hs-tc-panel .hs-events-table tr.hs-event-row .hs-event-name img,.hs-tc-panel .hs-events-table tr.hs-event-row .hs-event-name svg{flex:0 0 auto;}" +
      "#hs-root .hs-tc-panel .hs-events-table tr.hs-event-row td.hs-event-stats{grid-area:stats!important;grid-column:1/-1!important;grid-row:2!important;width:100%!important;max-width:100%!important;padding-top:.15rem;border-top:1px solid #eef1f4;}" +
      ".hs-tc-panel .hs-events-table tr.hs-event-row .hs-event-stats::before{content:attr(data-label);display:block;margin:.55rem 0 .45rem;font-size:.68rem;font-weight:800;line-height:1.15;letter-spacing:.04em;text-transform:uppercase;color:#344054;}" +
      ".hs-tc-panel .hs-events-table tr.hs-event-row .hs-event-stats .hs-stat-tags-wrap{max-width:100%;overflow:hidden;}" +
      ".hs-tc-panel .hs-events-table tr.hs-event-row .hs-event-stats .hs-stat-tag{max-width:100%;}" +
      ".hs-tc-panel .hs-events-table tr.hs-event-row .hs-tc-col-num{min-width:0;max-width:100%;padding:.7rem .45rem;border:1px solid #d9dde3;border-radius:6px;background:#f7f7f8;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;white-space:normal;overflow:hidden;}" +
      "#hs-root .hs-tc-panel .hs-events-table tr.hs-event-row td.hs-tc-col-num:nth-of-type(3){grid-area:m!important;grid-column:1!important;grid-row:3!important;}" +
      "#hs-root .hs-tc-panel .hs-events-table tr.hs-event-row td.hs-tc-col-num:nth-of-type(4){grid-area:ls!important;grid-column:2!important;grid-row:3!important;}" +
      "#hs-root .hs-tc-panel .hs-events-table tr.hs-event-row td.hs-tc-col-num:nth-of-type(5){grid-area:lt!important;grid-column:3!important;grid-row:3!important;}" +
      ".hs-tc-panel .hs-events-table tr.hs-event-row .hs-tc-col-num::before{content:attr(data-label);display:block;margin-bottom:.3rem;font-size:.56rem;font-weight:700;line-height:1.1;letter-spacing:.03em;text-transform:uppercase;color:#5f6b7a;white-space:normal;word-break:normal;overflow-wrap:anywhere;}" +
      ".hs-tc-panel .hs-events-table tr.hs-event-row .hs-lt-yes,.hs-tc-panel .hs-events-table tr.hs-event-row .hs-lt-no{font-size:1rem;line-height:1;}" +
    "}",
  ].join("\n");
  document.head.appendChild(style);
})();

window.hsInitStatsOverflow = function() {
  var wraps = document.querySelectorAll('.hs-stat-tags-wrap:not(.hs-overflow-init)');
  wraps.forEach(function(wrap) {
    wrap.classList.add('hs-overflow-init');
    var tags = Array.prototype.slice.call(wrap.querySelectorAll('.hs-stat-tag'));
    if (tags.length === 0) return;
    // Measure row boundary: find the offsetTop of the first tag in row 3
    var firstTop = tags[0].offsetTop;
    var rowH = tags[0].offsetHeight;
    var twoRowLimit = firstTop + rowH * 2 + 4; // allow 2 full rows
    // Find tags that start in row 3+
    var cutIdx = tags.length;
    for (var i = 1; i < tags.length; i++) {
      if (tags[i].offsetTop >= twoRowLimit) { cutIdx = i; break; }
    }
    if (cutIdx >= tags.length) return; // all fit in 2 rows
    var hidden = tags.length - cutIdx;
    // Hide overflow tags via CSS class
    for (var i = cutIdx; i < tags.length; i++) {
      tags[i].classList.add('hs-tag-hidden');
    }
    var btn = document.createElement('button');
    btn.className = 'hs-stats-expand';
    btn.textContent = '+' + hidden;
    btn.setAttribute('data-hidden', hidden);
    btn.onclick = function() {
      var expanded = wrap.classList.toggle('hs-stat-tags-expanded');
      tags.forEach(function(t) { t.classList.toggle('hs-tag-hidden', !expanded && t.classList.contains('hs-tag-hidden') || false); });
      if (expanded) {
        tags.slice(cutIdx).forEach(function(t) { t.classList.remove('hs-tag-hidden'); });
      } else {
        tags.slice(cutIdx).forEach(function(t) { t.classList.add('hs-tag-hidden'); });
      }
      btn.textContent = expanded ? '−' : '+' + hidden;
    };
    wrap.appendChild(btn);
  });
};
