import puppeteer from "puppeteer";

const WP_BASE = process.env.WP_BASE_URL || "https://heimspiel.de";
const USER_AGENT = "HeimspielSnapshotBot/1.0";
const RENDER_TIMEOUT_MS = 20000;
const NAV_TIMEOUT_MS = 30000;
const OUT_DIR = "output";

async function fetchJson(url) {
  const res = await fetch(url, { headers: { "User-Agent": USER_AGENT } });
  if (!res.ok) throw new Error(`HTTP ${res.status} bei ${url}`);
  return res.json();
}

function extractRows(json) {
  if (Array.isArray(json)) return json;
  if (Array.isArray(json.index)) return json.index;
  if (Array.isArray(json.indexDe)) return json.indexDe;
  const firstArr = Object.values(json).find((v) => Array.isArray(v));
  return firstArr || [];
}

function normalizeRow(row) {
  const out = {};
  for (const k in row) out[k.trim().toLowerCase()] = row[k];
  return out;
}

function resolveUrl(rawPath, isDE) {
  if (!rawPath) return null;
  const p = String(rawPath).trim();
  if (!p) return null;
  if (/^https?:\/\//i.test(p)) return p;
  let path = p.startsWith("/") ? p : `/${p}`;
  if (isDE && !path.startsWith("/de/") && path !== "/de") {
    path = `/de${path}`;
  }
  return `${WP_BASE}${path}`;
}

async function buildUrlList() {
  const [enJson, deJson] = await Promise.all([
    fetchJson(`${WP_BASE}/wp-json/hs-cache/v1/index`),
    fetchJson(`${WP_BASE}/wp-json/hs-cache/v1/indexDe`).catch(() => null),
  ]);

  const enRows = extractRows(enJson).map(normalizeRow);
  const deRows = deJson ? extractRows(deJson).map(normalizeRow) : [];

  const urls = [];
  const seen = new Set();

  function addRows(rows, isDE) {
    for (const row of rows) {
      const type = (row.type || "").toLowerCase();
      if (type !== "cluster" && type !== "detail") continue;
      const rawPath = row.detailurl || row.url || row.clusterurl;
      const fullUrl = resolveUrl(rawPath, isDE);
      if (!fullUrl || seen.has(fullUrl)) continue;
      seen.add(fullUrl);
      urls.push({
        url: fullUrl,
        type,
        lang: isDE ? "de" : "en",
        disciplineKey: row.discipline_key || row.disciplinekey || "",
        bundleName: row.bundlename || "",
      });
    }
  }

  addRows(enRows, false);
  addRows(deRows, true);
  return urls;
}

async function snapshotPage(browser, entry) {
  const page = await browser.newPage();
  page.setDefaultNavigationTimeout(NAV_TIMEOUT_MS);
  await page.setUserAgent(USER_AGENT);

  const result = { ...entry, status: "error", error: null, html: null, rootInnerHtml: null, renderComplete: false };

  try {
    await page.goto(entry.url, { waitUntil: "domcontentloaded", timeout: NAV_TIMEOUT_MS });

    let renderComplete = false;
    try {
      await page.waitForFunction(
        () => window.hsRenderComplete === true,
        { timeout: RENDER_TIMEOUT_MS }
      );
      renderComplete = true;
    } catch {
      // Timeout: Seite trotzdem snapshotten, aber als unvollstaendig markieren.
      renderComplete = false;
    }

    // Kurze zusaetzliche Wartezeit fuer letzte DOM-Updates (Bilder, Fade-ins).
    await new Promise((r) => setTimeout(r, 500));

    const html = await page.content();
    const rootInnerHtml = await page.evaluate(() => {
      const el = document.getElementById("hs-root");
      return el ? el.innerHTML : null;
    });

    result.html = html;
    result.rootInnerHtml = rootInnerHtml;
    result.renderComplete = renderComplete;
    result.status = rootInnerHtml ? (renderComplete ? "ok" : "ok_timeout") : "error";
    if (!rootInnerHtml) result.error = "#hs-root nicht gefunden oder leer";
  } catch (err) {
    result.error = err.message;
    result.status = "error";
  } finally {
    await page.close();
  }

  return result;
}

async function main() {
  console.log(`Lade Index von ${WP_BASE} ...`);
  const urlList = await buildUrlList();
  console.log(`Gefunden: ${urlList.length} Cluster-/Detail-URLs (EN+DE).`);

  const browser = await puppeteer.launch({
    headless: "new",
    args: ["--no-sandbox", "--disable-setuid-sandbox"],
  });

  const results = [];
  let ok = 0, okTimeout = 0, failed = 0;

  for (const entry of urlList) {
    console.log(`→ ${entry.lang.toUpperCase()} ${entry.type}: ${entry.url}`);
    const r = await snapshotPage(browser, entry);
    results.push(r);
    if (r.status === "ok") { ok++; console.log(`  ✓ gerendert (render-flag OK)`); }
    else if (r.status === "ok_timeout") { okTimeout++; console.log(`  ⚠ Timeout beim Render-Flag, Snapshot trotzdem gespeichert`); }
    else { failed++; console.error(`  ✗ Fehler: ${r.error}`); }
  }

  await browser.close();

  const fs = await import("fs");
  fs.mkdirSync(OUT_DIR, { recursive: true });
  fs.writeFileSync(`${OUT_DIR}/snapshot-mapping.json`, JSON.stringify(results, null, 2));

  console.log(`\nFertig: ${ok} ok, ${okTimeout} ok mit Timeout, ${failed} fehlgeschlagen. Insgesamt ${results.length}.`);
  if (failed > 0 && ok === 0 && okTimeout === 0) process.exitCode = 1;
}

main().catch((err) => {
  console.error("Abbruch:", err);
  process.exit(1);
});
