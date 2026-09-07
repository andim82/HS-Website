#!/usr/bin/env node
/**
 * wp.mjs -- schlanker CLI-Wrapper um die WordPress REST API von heimspiel.de.
 *
 * Auth ueber Application Password (WP-Core, Benutzerprofil > Anwendungspasswoerter).
 * Zugangsdaten kommen aus der Umgebung oder aus .env im Projektwurzelverzeichnis:
 *
 *   WP_BASE_URL=https://heimspiel.de
 *   WP_USER=dein-wp-login
 *   WP_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx
 *
 * Beispiele:
 *   node scripts/wp.mjs whoami
 *   node scripts/wp.mjs routes
 *   node scripts/wp.mjs list pages --search=olympic --status=draft --lang=de
 *   node scripts/wp.mjs get pages 4888
 *   node scripts/wp.mjs get pages 4888 --raw          # Gutenberg-Quelltext statt gerendert
 *   node scripts/wp.mjs meta 4888 --type=pages
 *   node scripts/wp.mjs update pages 4888 --title="Neuer Titel" --dry-run
 *   node scripts/wp.mjs update pages 4888 --data=@patch.json
 *   node scripts/wp.mjs media ./bild.webp --title="Hero Olympia"
 *   node scripts/wp.mjs raw GET hs-cache/v1/coverage/ski
 */

import fs from "node:fs";
import path from "node:path";

// ---------------------------------------------------------------- Konfiguration

const SQ = String.fromCharCode(39); // einfaches Anfuehrungszeichen

function loadDotEnv() {
  const file = path.join(process.cwd(), ".env");
  if (!fs.existsSync(file)) return;
  for (const line of fs.readFileSync(file, "utf-8").split(/\r?\n/)) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/);
    if (!m) continue;
    let val = m[2].trim();
    const quoted =
      (val.startsWith('"') && val.endsWith('"')) ||
      (val.startsWith(SQ) && val.endsWith(SQ));
    if (quoted && val.length >= 2) val = val.slice(1, -1);
    if (process.env[m[1]] === undefined) process.env[m[1]] = val;
  }
}
loadDotEnv();

const WP_BASE = (process.env.WP_BASE_URL || "https://heimspiel.de").replace(/\/+$/, "");
const WP_USER = process.env.WP_USER || "";
const WP_APP_PASSWORD = process.env.WP_APP_PASSWORD || "";

function authHeader() {
  if (!WP_USER || !WP_APP_PASSWORD) {
    console.error(
      "Abbruch: WP_USER / WP_APP_PASSWORD fehlen.\n" +
        "Application Password anlegen unter " + WP_BASE + "/wp-admin/profile.php\n" +
        "und in .env eintragen (Vorlage: .env.example)."
    );
    process.exit(1);
  }
  // Leerzeichen im Application Password sind erlaubt, WP entfernt sie selbst.
  return "Basic " + Buffer.from(WP_USER + ":" + WP_APP_PASSWORD).toString("base64");
}

// ---------------------------------------------------------------- Argumente

const argv = process.argv.slice(2);
const positional = [];
const flags = {};
for (const arg of argv) {
  const m = arg.match(/^--([^=]+)(?:=([\s\S]*))?$/);
  if (m) flags[m[1]] = m[2] === undefined ? true : m[2];
  else positional.push(arg);
}

function flagBody(name) {
  // --data=@datei.json liest aus einer Datei, sonst wird der Wert als JSON geparst.
  const v = flags[name];
  if (v === undefined) return null;
  const text = typeof v === "string" && v.startsWith("@") ? fs.readFileSync(v.slice(1), "utf-8") : v;
  try {
    return JSON.parse(text);
  } catch {
    throw new Error("--" + name + " ist kein gueltiges JSON: " + String(text).slice(0, 120));
  }
}

// ---------------------------------------------------------------- HTTP

async function api(method, route, opts = {}) {
  const { query = {}, body = null, headers = {}, auth = true } = opts;
  const url = new URL(WP_BASE + "/wp-json/" + String(route).replace(/^\/+/, ""));
  if (flags.lang) query.wpml_language = flags.lang;
  for (const [k, v] of Object.entries(query)) {
    if (v !== undefined && v !== null && v !== "") url.searchParams.set(k, v);
  }

  const req = { method, headers: Object.assign({ Accept: "application/json" }, headers) };
  if (auth) req.headers.Authorization = authHeader();
  if (Buffer.isBuffer(body)) {
    req.body = body;
  } else if (body !== null) {
    req.headers["Content-Type"] = "application/json";
    req.body = JSON.stringify(body);
  }

  const res = await fetch(url, req);
  const text = await res.text();
  let json = null;
  try {
    json = JSON.parse(text);
  } catch {
    /* keine gueltige JSON-Antwort */
  }

  if (!res.ok) {
    const msg = (json && (json.message || json.code)) || text.slice(0, 300);
    throw new Error("HTTP " + res.status + " " + method + " " + url.pathname + url.search + "\n  " + msg);
  }
  return { json, text, res };
}

function out(value) {
  console.log(typeof value === "string" ? value : JSON.stringify(value, null, 2));
}

// ---------------------------------------------------------------- Befehle

const commands = {
  async whoami() {
    const { json } = await api("GET", "wp/v2/users/me", { query: { context: "edit" } });
    out({
      id: json.id,
      name: json.name,
      slug: json.slug,
      roles: json.roles,
      kann_seiten_bearbeiten: !!(json.capabilities || {}).edit_pages,
    });
  },

  async routes() {
    const { json } = await api("GET", "", { auth: false });
    out({ name: json.name, url: json.url, namespaces: json.namespaces });
  },

  // list <type> [--search=] [--status=] [--per-page=] [--page=] [--parent=] [--slug=]
  async list(type) {
    const { json } = await api("GET", "wp/v2/" + (type || "pages"), {
      query: {
        search: flags.search,
        status: flags.status || "any",
        per_page: flags["per-page"] || 20,
        page: flags.page,
        parent: flags.parent,
        slug: flags.slug,
        orderby: flags.orderby,
        order: flags.order,
        context: "edit",
        _fields: "id,slug,status,type,link,title,modified",
      },
    });
    out(
      json.map((p) => ({
        id: p.id,
        status: p.status,
        slug: p.slug,
        title: p.title && (p.title.raw !== undefined ? p.title.raw : p.title.rendered),
        modified: p.modified,
        link: p.link,
      }))
    );
  },

  // get <type> <id> [--raw] [--fields=a,b]
  async get(type, id) {
    if (!type || !id) throw new Error("Aufruf: get <type> <id>   z.B. get pages 4888");
    const { json } = await api("GET", "wp/v2/" + type + "/" + id, {
      query: { context: "edit", _fields: flags.fields },
    });
    if (flags.raw) {
      out(json.content ? json.content.raw : "");
      return;
    }
    out({
      id: json.id,
      status: json.status,
      slug: json.slug,
      link: json.link,
      title: json.title && json.title.raw,
      parent: json.parent,
      template: json.template,
      modified: json.modified,
      excerpt: json.excerpt && json.excerpt.raw,
      content_zeichen: json.content && json.content.raw ? json.content.raw.length : 0,
    });
  },

  // meta <id> [--type=pages]
  async meta(id) {
    if (!id) throw new Error("Aufruf: meta <id> [--type=pages]");
    const { json } = await api("GET", "wp/v2/" + (flags.type || "pages") + "/" + id, {
      query: { context: "edit", _fields: "id,meta" },
    });
    out(json.meta || {});
  },

  // update <type> <id> [--title=] [--status=] [--content=@datei.html] [--data=@patch.json] [--dry-run]
  async update(type, id) {
    if (!type || !id) throw new Error("Aufruf: update <type> <id> --title=... | --data=@patch.json");
    const body = flagBody("data") || {};
    for (const key of ["title", "status", "slug", "excerpt", "template", "parent", "menu_order"]) {
      if (flags[key] !== undefined) body[key] = flags[key];
    }
    if (flags.content !== undefined) {
      body.content =
        typeof flags.content === "string" && flags.content.startsWith("@")
          ? fs.readFileSync(flags.content.slice(1), "utf-8")
          : flags.content;
    }
    if (!Object.keys(body).length) throw new Error("Nichts zu aendern -- keine Felder angegeben.");

    if (flags["dry-run"]) {
      out({ dry_run: true, ziel: WP_BASE + "/wp-json/wp/v2/" + type + "/" + id, body });
      return;
    }
    const { json } = await api("POST", "wp/v2/" + type + "/" + id, { body });
    out({ ok: true, id: json.id, status: json.status, link: json.link, modified: json.modified });
  },

  // media <datei> [--title=]
  async media(file) {
    if (!file) throw new Error("Aufruf: media <datei> [--title=...]");
    const buf = fs.readFileSync(file);
    const name = path.basename(file);
    const ext = path.extname(name).toLowerCase().slice(1);
    const types = {
      webp: "image/webp",
      png: "image/png",
      jpg: "image/jpeg",
      jpeg: "image/jpeg",
      gif: "image/gif",
      svg: "image/svg+xml",
      pdf: "application/pdf",
    };
    const { json } = await api("POST", "wp/v2/media", {
      body: buf,
      headers: {
        "Content-Type": types[ext] || "application/octet-stream",
        "Content-Disposition": 'attachment; filename="' + name + '"',
      },
    });
    if (flags.title) await api("POST", "wp/v2/media/" + json.id, { body: { title: flags.title } });
    out({ ok: true, id: json.id, source_url: json.source_url });
  },

  // raw <METHODE> <route> [--data=@body.json]  -- Notausgang fuer alles andere
  async raw(method, route) {
    if (!method || !route) throw new Error("Aufruf: raw <GET|POST|DELETE> <route> [--data=...]");
    const { json, text } = await api(method.toUpperCase(), route, { body: flagBody("data") });
    out(json !== null ? json : text);
  },

  help() {
    const src = fs.readFileSync(new URL(import.meta.url), "utf-8");
    const block = src.slice(src.indexOf("/**") + 3, src.indexOf("*/"));
    out(block.replace(/^ \* ?/gm, "").trim());
  },
};

// ---------------------------------------------------------------- Einstieg

const cmd = positional.shift() || "help";
if (!commands[cmd]) {
  console.error("Unbekannter Befehl: " + cmd + "\nVerfuegbar: " + Object.keys(commands).join(", "));
  process.exit(1);
}
Promise.resolve()
  .then(() => commands[cmd](...positional))
  .catch((err) => {
    console.error("Fehler: " + err.message);
    process.exit(1);
  });
