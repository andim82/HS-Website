import sharp from "sharp";

const WP_BASE = process.env.WP_BASE_URL || "https://heimspiel.de";
const USER_AGENT = "HeimspielPrerenderClient/1.0";
const OUT_DIR = "dist/hero-images";

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
    .resize({ width: 1920, withoutEnlargement: true })
    .webp({ quality: 88, effort: 6 })
    .toBuffer();
}

async function main() {
  const rawIndex = await fetchIndex();
  const rows = rawIndex.map(normalizeRow);

  const clusters = rows.filter(
    (r) => (r.type || "").toLowerCase() === "cluster" && r.herobgurl && !r.herobgurlcached
  );

  console.log(`Gefunden: ${clusters.length} Cluster-Zeilen mit heroBgUrl ohne heroBgUrlCached.`);

  const fs = await import("fs");
  fs.mkdirSync(OUT_DIR, { recursive: true });

  const repo = process.env.GITHUB_REPOSITORY || "andim82/HS-Website";
  const branch = process.env.GITHUB_REF_NAME || "main";
  const rawBase = `https://raw.githubusercontent.com/${repo}/${branch}/${OUT_DIR}`;

  const results = [];
  for (const row of clusters) {
    // FIX: Sheet-Spalte heisst "discipline_key" (mit Unterstrich), aber
    // normalizeRow() entfernt Unterstriche nicht -- nur row.disciplinekey
    // (ohne Unterstrich) wurde bisher geprueft, war daher IMMER undefined,
    // wodurch der bundlename-Fallback bei JEDER Zeile griff (bei den
    // meisten Sportarten zufaellig unauffaellig, bei "US Sports" mit
    // langem Mitglieder-bundleName sichtbar falsch).
    const disciplineKey = row.discipline_key || row.disciplinekey || slugify(row.bundlename);
    const displayName = row.displayname || row.bundlename || disciplineKey;
    const altText = `${displayName} Daten & API Coverage – HEIM:SPIEL`.substring(0, 120);
    const filename = `${slugify(disciplineKey)}-hero.webp`;

    try {
      console.log(`→ ${disciplineKey}: lade ${row.herobgurl}`);
      const original = await downloadImage(row.herobgurl);
      const webp = await toWebp(original);
      fs.writeFileSync(`${OUT_DIR}/${filename}`, webp);
      results.push({
        disciplineKey,
        oldUrl: row.herobgurl,
        filename,
        repoPath: `${OUT_DIR}/${filename}`,
        rawUrl: `${rawBase}/${filename}`,
        altText,
        title: displayName,
        status: "ok",
      });
      console.log(`  ✓ geschrieben: ${OUT_DIR}/${filename}`);
    } catch (err) {
      results.push({ disciplineKey, oldUrl: row.herobgurl, status: "error", error: err.message });
      console.error(`  ✗ Fehler bei ${disciplineKey}: ${err.message}`);
    }
    await new Promise((r) => setTimeout(r, 300));
  }

  fs.mkdirSync("output", { recursive: true });
  fs.writeFileSync("output/image-mapping.json", JSON.stringify(results, null, 2));
  fs.writeFileSync(`${OUT_DIR}/image-mapping.json`, JSON.stringify(results, null, 2));

  const ok = results.filter((r) => r.status === "ok").length;
  const failed = results.length - ok;
  console.log(`\nFertig: ${ok} erfolgreich, ${failed} fehlgeschlagen.`);
  if (failed > 0 && ok === 0) process.exitCode = 1;
}

main().catch((err) => {
  console.error("Abbruch:", err);
  process.exit(1);
});
