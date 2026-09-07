# CLAUDE.md

## WordPress-Zugriff

Die Live-Site ist `https://heimspiel.de` (WordPress mit WPML, Sprachen `de` und `en`,
`/de/`-Praefix). Zugriff laeuft direkt ueber die REST API -- **kein MCP-Server noetig**.

Wrapper: [scripts/wp.mjs](scripts/wp.mjs). Zugangsdaten in `.env` (gitignored, Vorlage
`.env.example`). Auth ist ein **Application Password** aus
`https://heimspiel.de/wp-admin/profile.php` -- nicht das normale Login-Passwort.

```bash
node scripts/wp.mjs whoami                                   # Auth + Rechte pruefen
node scripts/wp.mjs list pages --search=olympic --status=draft
node scripts/wp.mjs get pages 4888                           # Metadaten
node scripts/wp.mjs get pages 4888 --raw                     # Gutenberg-Quelltext
node scripts/wp.mjs meta 4888                                # Post-Meta
node scripts/wp.mjs update pages 4888 --title="X" --dry-run  # erst trocken pruefen
node scripts/wp.mjs media ./hero.webp --title="Hero"
node scripts/wp.mjs raw GET hs-cache/v1/coverage/ski         # eigene Namespaces
```

Regeln:

- **Schreibende Aufrufe gehen auf die Live-Site.** Vor jedem `update` erst `--dry-run`
  zeigen und die Aenderung bestaetigen lassen.
- Beim Bearbeiten von Inhalten immer `--raw` lesen, nicht die gerenderte Fassung --
  sonst gehen Gutenberg-Blockkommentare verloren.
- `--lang=de` bzw. `--lang=en` setzt WPMLs `wpml_language`-Parameter.
- Fuer alles, was der Wrapper nicht abdeckt, ist `raw <METHODE> <route>` der Notausgang.

Eigene REST-Namespaces dieses Repos: `hs-cache/v1` (lesend, oeffentlich),
`hs-prerender/v1` und `hs-prov/v1` (schreibend, per API-Key). Der Prerender-Workflow
laeuft ueber GitHub Actions und nutzt `HS_SNAPSHOT_API_KEY`, siehe
[scripts/push-snapshots.mjs](scripts/push-snapshots.mjs).
