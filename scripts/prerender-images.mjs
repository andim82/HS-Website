import sharp from "sharp";

const WP_BASE = process.env.WP_BASE_URL || "https://heimspiel.de";
const WP_USER = process.env.WP_USER;
const WP_APP_PASSWORD = process.env.WP_APP_PASSWORD;

if (!WP_USER || !WP_APP_PASSWORD) {
  console.error("WP_USER / WP_APP_PASSWORD fehlen (GitHub Secrets pruefen).");
  process.exit(1);
}

const AUTH_HEADER = "Basic " + Buffer.from(`${WP_USER}:${WP_APP_PASSWORD}`).toString("base64");
const USER_AGENT = "HeimspielPrerenderClient/1.0";

function slugify(str) {
  return String(str || "")
    .trim().toLowerCase()
    .replace(/ä/g, "ae").replace(/ö/g, "oe").replace(/ü/g, "ue").replace(/ß/g, "ss")
    .normalize("NFD").replace(/[\u0300-\u036f]/g, "")
    .replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
}

async function fetchIndex() {
  const res = await fetch(`${WP_BASE}/wp-json/hs-cache/v1/index`, {
    headers: { "User-Agent": USER_AGENT },
  });
  if (!res.ok) throw new Error(`Index HTTP ${res.status}`);
  const json = await res.json();
  return json.index || json[Object.keys(json)[0]] || [];
}

function normalizeRow(row) {
  const out = {};
  for (const k in row) out[k.trim().toLowerCase()] = row[k];
  return out;
}

async function downloadImage(url) {
  const res = await fetch(url, { headers: { "User-Agent": USER_AGENT } });
  if (!res.ok) throw new Error(`Bild-Download HTTP ${res.status}`);
  const buf = Buffer.from(await res.arrayBuffer());
  return buf;
}

async function toWebp(buf) {
  return sharp(buf)
    .resize({ width: 1600, withoutEnlargement: true })
    .webp({ quality: 78 })
    .toBuffer();
}

async function uploadToWp(webpBuf, filename, altText, title) {
  const res = await fetch(`${WP_BASE}/wp-json/hs-cache/v1/prerender/media`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: AUTH_HEADER,
      "User-Agent": USER_AGENT,
      Accept: "application/json",
    },
    body: JSON.stringify({
      filename,
      mime_type: "image/webp",
      data_base64: webpBuf.toString("base64"),
      alt_text: altText,
      title,
    }),
  });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(json.code ? `${json.code}: ${json.message}` : (json.error || `Upload HTTP ${res.status}`));
  }
  return json; // { id, url }
}

async function main() {
  const rawIndex = await fetchIndex();
  const rows = rawIndex.map(normalizeRow);

  const clusters = rows.filter(
    (r) => (r.type || "").toLowerCase() === "cluster" && r.herobgurl && !r.herobgurlcached
  );

  console.log(`Gefunden: ${clusters.length} Cluster-Zeilen mit heroBgUrl ohne heroBgUrlCached.`);

  const results = [];
  for (const row of clusters) {
    const disciplineKey = row.disciplinekey || slugify(row.bundlename);
    const displayName = row.displayname || row.bundlename || disciplineKey;
    const altText = `${displayName} Daten & API Coverage – HEIM:SPIEL`.substring(0, 120);
    const filename = `${slugify(disciplineKey)}-hero.webp`;

    try {
      console.log(`→ ${disciplineKey}: lade ${row.herobgurl}`);
      const original = await downloadImage(row.herobgurl);
      const webp = await toWebp(original);
      const uploaded = await uploadToWp(webp, filename, altText, displayName);
      results.push({
        disciplineKey,
        oldUrl: row.herobgurl,
        newUrl: uploaded.url,
        mediaId: uploaded.id,
        altText,
        status: "ok",
      });
      console.log(`  ✓ hochgeladen: ${uploaded.url}`);
    } catch (err) {
      results.push({ disciplineKey, oldUrl: row.herobgurl, status: "error", error: err.message });
      console.error(`  ✗ Fehler bei ${disciplineKey}: ${err.message}`);
    }
    await new Promise((r) => setTimeout(r, 500));
  }

  const fs = await import("fs");
  fs.mkdirSync("output", { recursive: true });
  fs.writeFileSync("output/image-mapping.json", JSON.stringify(results, null, 2));

  const ok = results.filter((r) => r.status === "ok").length;
  const failed = results.length - ok;
  console.log(`\nFertig: ${ok} erfolgreich, ${failed} fehlgeschlagen.`);
  if (failed > 0) process.exitCode = 1;
}

main().catch((err) => {
  console.error("Abbruch:", err);
  process.exit(1);
});
