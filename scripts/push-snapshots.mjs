const WP_BASE = process.env.WP_BASE_URL || "https://heimspiel.de";
const SNAPSHOT_API_KEY = process.env.HS_SNAPSHOT_API_KEY || "";
const MAPPING_PATH = "output/snapshot-mapping.json";
const REPORT_PATH = "output/push-report.json";

async function pushOne(entry) {
  const res = await fetch(`${WP_BASE}/wp-json/hs-prerender/v1/snapshot`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-HS-Snapshot-Key": SNAPSHOT_API_KEY,
    },
    body: JSON.stringify({ url: entry.url, html: entry.rootInnerHtml }),
  });

  const text = await res.text();
  let json = null;
  try { json = JSON.parse(text); } catch { /* keine gueltige JSON-Antwort */ }

  if (!res.ok) {
    const msg = (json && json.message) || text.substring(0, 200);
    throw new Error(`HTTP ${res.status}: ${msg}`);
  }

  return json;
}

async function main() {
  if (!SNAPSHOT_API_KEY) {
    console.error("Abbruch: HS_SNAPSHOT_API_KEY ist nicht gesetzt (GitHub Actions Secret fehlt).");
    process.exit(1);
  }

  const fs = await import("fs");
  if (!fs.existsSync(MAPPING_PATH)) {
    console.error(`Abbruch: ${MAPPING_PATH} nicht gefunden -- zuerst node scripts/snapshot-pages.mjs ausfuehren.`);
    process.exit(1);
  }

  const mapping = JSON.parse(fs.readFileSync(MAPPING_PATH, "utf-8"));
  const eligible = mapping.filter((e) => (e.status === "ok" || e.status === "ok_timeout") && e.rootInnerHtml);

  console.log(`Gefunden: ${mapping.length} Snapshot-Eintraege, davon ${eligible.length} sendebereit.`);

  const results = [];
  let ok = 0, failed = 0;

  for (const entry of eligible) {
    console.log(`→ ${entry.lang.toUpperCase()} ${entry.type}: ${entry.url}`);
    try {
      const res = await pushOne(entry);
      results.push({ url: entry.url, status: "ok", postId: res && res.post_id, title: res && res.title });
      ok++;
      console.log(`  ✓ geschrieben (Post-ID ${res && res.post_id})`);
    } catch (err) {
      results.push({ url: entry.url, status: "error", error: err.message });
      failed++;
      console.error(`  ✗ Fehler: ${err.message}`);
    }
    await new Promise((r) => setTimeout(r, 200));
  }

  const skipped = mapping.length - eligible.length;
  fs.writeFileSync(REPORT_PATH, JSON.stringify({ ok, failed, skipped, results }, null, 2));

  console.log(`\nFertig: ${ok} geschrieben, ${failed} fehlgeschlagen, ${skipped} uebersprungen (kein gueltiger Snapshot).`);
  if (failed > 0 && ok === 0) process.exitCode = 1;
}

main().catch((err) => {
  console.error("Abbruch:", err);
  process.exit(1);
});
