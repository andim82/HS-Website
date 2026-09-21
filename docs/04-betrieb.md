# 04 · Betrieb, Deployment und Sicherheit

Für IT-Review und Betrieb: wie Änderungen live gehen, welche Geheimnisse wo liegen, welche
Automatismen laufen, was schiefgehen kann und wie man es zurückdreht.

## 1. Deployment

Es gibt **keine Deployment-Pipeline** für den WordPress-Teil. Dateien werden manuell an ihren
Ablageort gebracht (WP File Manager oder SFTP). GitHub ist Quellcode-Verwaltung und
Ablageort für die Hero-Bilder, nicht Deploy-Ziel. Die Reihenfolge „erst Commit + Push,
dann Deployment aus dem Repo" hat sich bewährt, weil man dann den Dateiinhalt im
WP-Backend direkt von `raw.githubusercontent.com` laden kann und nie Copy-Paste-Fehler
(Zeichensatz, abgeschnittene Zeilen) riskiert.

| Was | Wohin | Danach |
|---|---|---|
| `cache.php`, `rest-api.php`, `prerender-*.php`, `cron.php`, `admin.php`, `hs-wpml-auto-translate-exclusions.php` | `wp-content/plugins/hs-cache/includes/` | Bei Logikänderungen in `cache.php`: Cache-Refresh, damit die Transients mit der neuen Logik entstehen |
| `heimspiel-data-cache.php` | `wp-content/plugins/hs-cache/` | — |
| `hs-landing-provisioner-v4.15.php` | `wp-content/plugins/hs-landing-provisioner-v4.15/` | — |
| `hs-seo-meta.php`, `hs-translations.php`, `hs-perf.php`, `hs-rest-gzip.php`, `hs-landing.css` | `wp-content/mu-plugins/` | Seitencache (WP Super Cache) leeren |
| `hs-landing.min.js` | WPCode → Code Snippets → Snippet 1432 → Code ersetzen → Update | Seitencache leeren; Snapshots erneuern sich in der Folgenacht |

**Vor jedem PHP-Deployment:** `php -l datei.php` lokal. Ein Syntaxfehler in einem MU-Plugin oder in `cache.php` legt die gesamte Site still (weißer Bildschirm, auch das Backend).

**Nach jedem Deployment prüfen:** Startseite lädt (HTTP 200, kein „Fatal error" im Quelltext), `/wp-json/hs-cache/v1/index` antwortet, eine Landingpage rendert.

**Rollback:** Vorherige Fassung aus dem Git-Verlauf (`git show <commit>:<datei>`) an denselben Ort. Im Plugin-Ordner `includes/` dürfen Sicherungskopien liegen (`cache~.php`), in `mu-plugins/` **nicht** (dort wird jede `.php` geladen).

### JavaScript-Build

```bash
node node_modules/terser/bin/terser hs-landing-v101.24.js --compress --format comments=false -o hs-landing.min.js
node --check hs-landing.min.js
```

Ohne Mangling (`--mangle` fehlt bewusst): Funktionsnamen bleiben erhalten, Stacktraces im Browser bleiben lesbar. Terser ist derzeit nur lokal installiert und in `package.json` **nicht** deklariert (`npm ls` meldet „extraneous"). Für reproduzierbare Builds sollte er als `devDependency` aufgenommen werden (`npm install --save-dev terser`).

## 2. Konfiguration und Geheimnisse

| Name | Ort | Zweck | Wer braucht es | Rotation |
|---|---|---|---|---|
| `HS_SNAPSHOT_API_KEY` | `wp-config.php` **und** GitHub Secret | Auth für `POST hs-prerender/v1/snapshot` | Snapshot-Workflow | Beide Stellen gleichzeitig ändern |
| `HS_GITHUB_TOKEN` | `wp-config.php` | Fine-grained PAT, Repo `andim82/HS-Website`, nur „Contents: Read-only" | Bild-Import (WordPress liest aus GitHub) | Läuft laut GitHub-Einstellung ab; Admin-Seite meldet fehlenden Token |
| `HS_OPENAI_API_KEY` | `wp-config.php` | Übersetzungen | WP-Cron | Bei Verlust: alle Aufrufe schlagen still fehl (Error-Log), Seiten zeigen deutsche Namen |
| `WP_APP_PASSWORD` | lokal `.env` (gitignored) | `scripts/wp.mjs` | Entwickler | Unter Profil → Anwendungspasswörter widerrufbar; personengebunden, nicht teilen |
| `HS_GSHEET_APP_BASE_URL` | `heimspiel-data-cache.php` (im Repo) | Apps-Script-Deployment | Cache | Ändert sich bei neuem Deployment ohne „gleiche URL beibehalten" |
| Sheet-ID `1EZYrk7…` | `heimspiel-data-cache.php` | CSV-Export der Sport-Tabs | Cache | — |
| `GITHUB_TOKEN` (automatisch) | GitHub Actions | Commit der Hero-Bilder | `prerender-images.yml` | von GitHub verwaltet |

Grundsätze: Kein Geheimnis liegt im Repository. Der OpenAI-Key war früher im Frontend sichtbar — das ist mit v4 von `hs-translations.php` behoben und darf nicht zurückkehren. Das Application Password ist personengebunden und gehört nur in die lokale `.env`.

## 3. Alle Automatismen auf einen Blick

| Mechanismus | Zeitplan | Definiert in | Eingriff |
|---|---|---|---|
| WP-Cron `hs_refresh_all_cache_event` | alle 30 Tage | `cron.php` | Button „Cache jetzt aktualisieren" |
| WP-Cron `hs_prerender_sync_cron` | täglich 04:00 Site-Zeit | `prerender-sync.php` | Button „Bilder jetzt synchronisieren" |
| WP-Cron `hs_translate_batch_cron` | einmalig +5 s nach Anmeldung | `hs-translations.php` | Reset-Buttons |
| GitHub `prerender-images.yml` | täglich 01:00 UTC | `.github/workflows/` | „Run workflow" |
| GitHub `prerender-snapshot.yml` | täglich 06:00 UTC | `.github/workflows/` | „Run workflow" |
| Transient-Ablauf | 31 Tage (Daten), 6 h (SEO-Meta) | Konstanten | Refresh bzw. Seite speichern |

WP-Cron hängt an Seitenaufrufen. Wird die Site selten besucht oder ist `DISABLE_WP_CRON` gesetzt, sollte `wp-cron.php` per System-Cron aufgerufen werden (z. B. alle 15 Minuten) — sonst verschieben sich der Bild-Import und die Übersetzungen unvorhersehbar.

## 4. Sicherheitsbetrachtung

### 4.1 Angriffsfläche

| Schnittstelle | Auth | Schreibt | Bewertung |
|---|---|---|---|
| `GET hs-cache/v1/*` | keine | nein | Liefert ausschließlich Daten, die im Google Sheet bereits öffentlich abrufbar sind. Antworten gecacht, gzip, `max-age=3600`. Kein Nutzerbezug. |
| `GET hs-cache/v1/csv/{gid}` | keine | nein | Proxy auf **jede** gid der einen Tabelle. Da der CSV-Export der Tabelle ohnehin öffentlich ist, entsteht keine neue Offenlegung — aber: Jeder neue Tab in dieser Tabelle ist damit automatisch abrufbar. Interne oder personenbezogene Daten gehören nicht in diese Tabelle. Ein unbekannter gid legt beim ersten Aufruf einen Transient an (begrenzt: gid-Zeichenklasse `[a-zA-Z0-9_-]`). |
| `GET hs-cache/v1/coverage-debug/{sport}` | keine | nein | Diagnose, ungecacht; Rohdaten des Sport-Tabs. Unkritisch, aber ohne Nutzen für Besucher — Kandidat für `manage_options`. |
| `POST hs-prerender/v1/snapshot` | statischer API-Key im Header, `hash_equals` | `post_content` der aufgelösten Seite | Ersetzt nur den Inhalt von `#hs-root`; KSES bleibt aktiv (`<script>` überlebt nicht), Formular-Tags werden nur für diesen Aufruf zugelassen — darunter `onsubmit`. Ein kompromittierter Key erlaubt das Setzen beliebigen HTML (ohne Script) in Provisioner-Seiten. Kein Rate-Limit. Empfehlung: Key ≥ 32 Zufallszeichen, regelmäßig rotieren, Erfolg nur an Post-Meta `_hs_last_snapshot_at` und Workflow-Artefakt nachvollziehbar (keine Audit-Tabelle). |
| `hs-prov/v1/*` | eingeloggt + `manage_options` + Nonce | Seiten, WPML-Verknüpfung, Slugs | Nur Administratoren. `find-parent` nutzt vorbereitete SQL-Abfrage. |
| `POST hs-cache/v1/prerender/media`, `/page` | Application Password + Capability | Mediathek, `post_content` (mit `unfiltered_html`) | **Legacy, ungenutzt.** `/page` schreibt ungefiltertes HTML inklusive `<script>` in beliebige Seiten-IDs, sobald ein Konto mit `unfiltered_html` kompromittiert ist. Empfehlung: entfernen oder `hs_register_prerender_routes` deaktivieren. |
| `POST wp/v2/pages` (Core) | Nonce / Application Password | Seiten | WordPress-Standard. Application Passwords sind so mächtig wie das Konto; nur Admin-Konten mit 2FA. |
| Ausgehend: Apps Script, Drive, GitHub API, OpenAI | — | — | Alle per HTTPS; GitHub mit Bearer-Token; OpenAI mit Bearer-Key; Timeouts 30 s. Ausfall einer Quelle lässt den letzten Cache stehen (kein Datenverlust, aber Veralterung). |

### 4.2 Datenflüsse und Datenschutz

Es fließen keine personenbezogenen Daten durch das System. Zum Übersetzen gehen ausschließlich Wettbewerbs- und Statistikbezeichnungen an OpenAI. Das Kontaktformular der Landingpages ist Markup; die Verarbeitung erfolgt außerhalb dieses Codes. Der gespeicherte Snapshot enthält nur eigene, öffentlich gerenderte Inhalte.

### 4.3 Härtung im Code

- Alle REST-Parameter laufen durch `sanitize_title` / `sanitize_text_field` / `absint`; Routen-Regexe begrenzen Zeichenklassen.
- Ausgabe im `<head>` durchgehend mit `esc_attr` / `esc_url`; JSON-LD ohne `JSON_UNESCAPED_SLASHES` (kein `</script>`-Ausbruch).
- Admin-Aktionen mit Nonce und Capability-Prüfung.
- `hash_equals` für den API-Key (kein Timing-Leck).
- Fremd-HTML wird nirgends ungefiltert gespeichert, außer über die Legacy-Route (siehe oben).

### 4.4 Empfehlungen aus dem Review

1. `prerender-api.php` aus `$hs_includes` nehmen oder löschen (Legacy-Schreibpfad mit `unfiltered_html`).
2. `coverage-debug` auf `manage_options` beschränken.
3. Debug-`error_log` in Zeile 4 von `prerender-snapshot.php` entfernen (schreibt bei jedem Request).
4. Terser als `devDependency` deklarieren; Node-Version für lokale Builds fixieren (`.nvmrc` oder `engines`).
5. `HS_SNAPSHOT_API_KEY` rotieren und Länge prüfen; Rotation im Kalender.
6. System-Cron für `wp-cron.php` einrichten, damit die täglichen WP-Cron-Läufe zeitlich verlässlich sind.
7. Optional: Snapshot-Writeback auf `IP-Allowlist` oder HMAC über den Body erweitern, falls der Key jemals in Logs auftaucht.

## 5. Runbooks

### 5.1 Inhalt aus dem Sheet ist nicht live

1. Einstellungen → HEIM:SPIEL Cache → „Cache jetzt aktualisieren" (≈ 60 s). Alle Zeilen grün?
2. Rot bei `Index`/`GeneralIndex`: Apps-Script-URL prüfen (`HS_GSHEET_APP_BASE_URL`) — neues Deployment? Im Browser aufrufen, es muss JSON kommen.
3. Rot bei `csv`: gid in der Cluster-Zeile prüfen; Tab existiert? CSV-Export im Browser testen.
4. Grün, aber Seite alt: WP-Super-Cache leeren, Browser-Cache umgehen (`?x=1`).
5. Quelltext/JSON-LD alt: normal — bis zum nächsten Snapshot (06:00 UTC) oder Workflow manuell starten.

### 5.2 Snapshot manuell erneuern

GitHub → Actions → „Prerender - Snapshot Pages to WordPress" → Run workflow. Nach ≈ 10–30 min: Artefakt `snapshot-reports` prüfen (`push-report.json`: `ok`, `failed`, `skipped`). Typische Fehler: `409 lang_mismatch` (Sprachpaar in WPML falsch verknüpft), `422 no_hs_root` (Seite ist keine Provisioner-Seite), `404 not_found` (URL im Index stimmt nicht mit dem Slug überein), `403` (Key stimmt nicht überein).

**Vor dem Lauf prüfen:** Rendert die Seite im Browser korrekt? Der Snapshot friert exakt das ein, was `hs-landing.js` erzeugt — ein Fehler im Sheet oder JavaScript landet im Quelltext aller Seiten und im FAQPage-Schema. Bei kaputtem Sheet-Text lieber erst korrigieren.

### 5.3 Neues Hero-Bild einführen

1. Bild in Google Drive ablegen, Freigabe „Jeder mit dem Link", Link in `heroBgUrl` (Index + Index_DE) eintragen.
2. Cache-Refresh (sonst sieht der Workflow die Zeile nicht).
3. Workflow „Prerender - Hero Images to WebP" abwarten (01:00 UTC) oder manuell starten. Log: „Gefunden: n Zeilen". Commit auf `main` erscheint.
4. Bild-Import abwarten (04:00) oder Button „Bilder jetzt synchronisieren". Report zeigt `imported` mit URL.
5. URL in `heroBgUrlCached` (Index + Index_DE) eintragen, Cache-Refresh.
6. Prüfen: `<img>` im Hero zeigt auf `heimspiel.de/wp-content/uploads/…webp`, `og:image` ebenso.

„0 Zeilen gefunden" bedeutet fast immer: Schritt 2 vergessen. Drive-Fehler „HTML statt Bild": Freigabe fehlt oder Datei > 100 MB (Virenscan-Zwischenseite).

### 5.4 Neue Seite anlegen

1. Zeile in `Index` und `Index_DE` (gleicher `discipline_key`, jeweils `detail_url`, `type`, Template, Texte). Bei neuer Sportart zusätzlich Sport-Tab und `gid`.
2. Cache-Refresh; Fehlermeldungen im Report beheben (z. B. `missing_name_filter` bei Event-Template ohne `nameFilter`).
3. HS Provisioner → Cluster wählen → Schritt 1 (ggf. zweisprachig) → bei Multisport Schritt 2.
4. Entwurf im Browser prüfen (`/?page_id=ID` bzw. `/de/?page_id=ID`), dann veröffentlichen.
5. Snapshot in der Folgenacht; danach Quelltext und Rich-Results-Test prüfen.

### 5.5 Übersetzungen falsch oder fehlend

- Einzelne falsche Übersetzung: im Glossar-Tab (gid 1129563872) Quellbegriff + Spalte `en` eintragen → „Glossar jetzt neu laden" → betroffenen Eintrag aus `hs_trans_*` entfernen ODER „Alle Übersetzungen zurücksetzen" (teuer: gesamter Bestand wird neu übersetzt).
- Fehlend: Erster Besucher stößt an; WP-Cron muss laufen; `HS_OPENAI_API_KEY` gesetzt? Error-Log nach „HS Translation Error".
- Nie übersetzt werden: Statistik-Pills außerhalb des Event-Templates (technische Feldnamen; Sheet-Spalte `statsTranslations` ist der vorgesehene Weg) und deutsche Seiten.

### 5.6 Site nach Deployment weiß

1. Zuletzt deployte Datei per SFTP/File Manager auf die vorherige Fassung zurücksetzen (Git-Verlauf).
2. In `mu-plugins/` nach fremden `.php`-Dateien suchen (Kopien, `~`-Dateien) — alles außer den fünf bekannten Dateien entfernen.
3. `wp-content/debug.log` bzw. Server-Error-Log auf „Cannot redeclare" / „Parse error".

## 6. Monitoring-Punkte

| Signal | Wo | Gesund |
|---|---|---|
| Cache-Report | Einstellungen → HEIM:SPIEL Cache | Alle Quellen ✅, letzter Refresh < 31 Tage |
| Bild-Sync-Report | dieselbe Seite, unten | Status OK, keine `error` |
| Snapshot-Aktualität | Post-Meta `_hs_last_snapshot_at` bzw. `node scripts/wp.mjs meta <id>` | < 48 h für veröffentlichte Seiten |
| Workflow-Läufe | GitHub Actions | Beide täglich grün; Artefakte vorhanden |
| gzip aktiv | Response-Header `X-HS-Gzip` an `/hs-cache/v1/*` | vorhanden |
| Render-Flag | `document.body.dataset.hsRendered === "true"` | nach ≤ 3 s |
| OpenAI-Kosten | Anbieter-Dashboard | Nach dem Erstlauf nur noch vereinzelte Aufrufe (neue Wettbewerbe) |

## 7. Bekannte Fallstricke und technische Schulden

- **Zwei Renderer für Wettbewerbszeilen** (`hsPrerenderPanels` für den Snapshot, `hsRenderCompetitionPanel` live). Markup-Änderungen an einer Stelle allein erzeugen Unterschiede zwischen Quelltext und sichtbarer Seite.
- **CSS nur in `hs-landing.css`.** Die CSS-Strings in `hs-landing.js` sind toter Code, solange das MU-Plugin die Datei ausliefert — sie werden aber weiterhin mit deployt (≈ 30 KB im Snippet).
- **Stats-Bar zeigt vor der Animation `0`.** Der echte Wert steht in `data-target`; ohne Scrollen in den Bereich bleibt die Anzeige bei 0. Für Crawler korrigiert `hs-seo-meta.php` das serverseitig.
- **Der Hero-Workflow liest den Cache, nicht das Sheet.** Ohne Refresh keine neuen Bilder.
- **`image-mapping.json` ist ein Delta.** Nach einem Lauf ohne Treffer ist sie `[]`; das ist kein Fehler.
- **`Index` (EN) ist Strukturquelle.** `gid`, `clusterTemplate`, `topCompetitions`, `nameFilter` werden nur aus dem englischen Index gelesen. Im DE-Index gepflegte Abweichungen wirken nicht.
- **Sheet-Änderungen an Template-Texten können Inhalt vernichten.** Beispiel September 2026: `seoFaqTpl1Text` (EN) war auf die Überschrift reduziert; ein Snapshot-Lauf hätte die FAQ-Antwort auf allen EN-Seiten entfernt. Vor dem Prerender die Seiten im Browser gegenprüfen.
- **Der Bot committet auf `main`.** Lokale Arbeit vor dem Push mit `git fetch && git rebase origin/main` aktualisieren.
- **Kompetitions-IDs müssen tabübergreifend eindeutig bleiben.** Bundle- und Event-Template verlassen sich darauf (Stand 2026-09-04: 3.299 IDs, keine Dublette). Beim Anlegen eines neuen Sport-Tabs aus einer anderen Quelle nachmessen.
- **Legacy im Code:** `prerender-api.php`, `hs_build_bundle_coverage()`, `hs_build_last_season_stats()`, die vier CSS-Blöcke in `hs-landing.js`, Debug-`error_log` in `prerender-snapshot.php`.
- **Manuelle Glieder in automatischen Ketten:** `heroBgUrlCached` ins Sheet eintragen, Cache-Refresh nach Sheet-Änderungen, WPCode-Update des JavaScripts. Kandidaten für Automatisierung, wenn der Betrieb es rechtfertigt.
