#!/usr/bin/env node
/*
  Reads every page and reports the things a crawler, a screen reader or a
  browser would trip over:

      node tools/audit.js

  Exit code is 1 when something is actually broken (a missing file, a bad
  width/height, a page without exactly one H1), so it can gate a release.
  Warnings are printed but do not fail the run.
*/
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
/* A cache-busting "?v=2" on an asset URL still points at the same file, so
   drop the query and fragment before touching the disk - the same thing the
   href check below does. */
const assetFile = (rel) => (rel || '').split('#')[0].split('?')[0];
const exists = (rel) => fs.existsSync(path.join(root, assetFile(rel).replace(/\//g, path.sep)));
const bytes = (p) => fs.statSync(p).size;
const kb = (n) => (n / 1024).toFixed(1) + 'kb';
const norm = (s) => (s || '').replace(/\s+/g, ' ').trim();

const broken = [];
const warn = [];
const lines = [];
const say = (s) => lines.push(s);
const fail = (s) => { broken.push(s); say('  BROKEN  ' + s); };
const caution = (s) => { warn.push(s); say('  warn    ' + s); };

function walk(dir, filter) {
  const found = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.name === 'node_modules' || entry.name === '.git') continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) found.push(...walk(full, filter));
    else if (filter(entry.name)) found.push(full);
  }
  return found;
}

/* ---------------- file dimensions, without any image library ------------- */

function dimensions(rel) {
  const abs = path.join(root, assetFile(rel).replace(/\//g, path.sep));
  if (!fs.existsSync(abs)) return null;
  const b = fs.readFileSync(abs);

  if (b.subarray(1, 4).toString() === 'PNG') {
    return { width: b.readUInt32BE(16), height: b.readUInt32BE(20) };
  }
  if (b.subarray(0, 4).toString() === 'RIFF' && b.subarray(8, 12).toString() === 'WEBP') {
    const tag = b.subarray(12, 16).toString();
    if (tag === 'VP8X') return { width: 1 + b.readUIntLE(24, 3), height: 1 + b.readUIntLE(27, 3) };
    if (tag === 'VP8 ') return { width: b.readUInt16LE(26) & 0x3fff, height: b.readUInt16LE(28) & 0x3fff };
    if (tag === 'VP8L') {
      const bits = b.readUInt32LE(21);
      return { width: 1 + (bits & 0x3fff), height: 1 + ((bits >> 14) & 0x3fff) };
    }
    return null;
  }
  if (b[0] === 0xff && b[1] === 0xd8) {
    let i = 2;
    while (i < b.length - 9) {
      if (b[i] !== 0xff) { i++; continue; }
      const marker = b[i + 1];
      const size = b.readUInt16BE(i + 2);
      if (marker >= 0xc0 && marker <= 0xcf && ![0xc4, 0xc8, 0xcc].includes(marker)) {
        return { height: b.readUInt16BE(i + 5), width: b.readUInt16BE(i + 7) };
      }
      if (marker === 0xd8 || marker === 0x01 || (marker >= 0xd0 && marker <= 0xd7)) { i += 2; continue; }
      i += 2 + size;
    }
  }
  return null;
}

/* ------------------------------- inventory ------------------------------ */

const pages = walk(root, (n) => /\.html$/i.test(n))
  // includes/*.html are nav and footer fragments, not pages: they have no
  // head, title or main landmark by design.
  .filter((p) => !/[\\/]includes[\\/]/.test(p))
  .sort();

say('=== INVENTORY ===');
say(`pages: ${pages.length}`);
for (const p of pages) say(`  ${path.relative(root, p).replace(/\\/g, '/')}  ${kb(bytes(p))}`);

for (const dir of ['images', 'css', 'includes', 'tools']) {
  const abs = path.join(root, dir);
  if (!fs.existsSync(abs)) { caution(`no ${dir}/ folder`); continue; }
  const files = walk(abs, () => true);
  say(`  ${dir}/: ${files.length} files, ${kb(files.reduce((s, f) => s + bytes(f), 0))}`);
}

for (const special of ['robots.txt', 'sitemap.xml', '404.html', 'LAUNCH-CHECKLIST.md']) {
  say(`  ${special}: ${exists(special) ? 'PRESENT ' + kb(bytes(path.join(root, special))) : 'missing (expected until tools/set-domain.php runs)'}`);
}

/* ------------------------------ per page -------------------------------- */

const meta = (html, name) => {
  const tag = html.match(new RegExp(`<meta[^>]+(?:name|property)=["']${name}["'][^>]*>`, 'i'));
  if (!tag) return null;
  const c = tag[0].match(/content=["']([^"']*)["']/i);
  return c ? c[1].trim() : '';
};

const titles = {};
const descriptions = {};

say('');
say('=== PAGES ===');

for (const p of pages) {
  const rel = path.relative(root, p).replace(/\\/g, '/');
  const html = fs.readFileSync(p, 'utf8');
  const headEnd = html.toLowerCase().indexOf('</head>');
  const head = html.slice(0, headEnd);
  const body = html.slice(headEnd);

  say(`--- ${rel} ---`);

  const title = norm((html.match(/<title[^>]*>([\s\S]*?)<\/title>/i) || [, null])[1]);
  const desc = meta(head, 'description');
  if (title === null) fail(`${rel}: no <title>`);
  else {
    (titles[title.toLowerCase()] ||= []).push(rel);
    if (title.length > 60) caution(`${rel}: title ${title.length} chars (Google truncates near 60)`);
  }
  if (desc === null) fail(`${rel}: no meta description`);
  else {
    (descriptions[desc.toLowerCase()] ||= []).push(rel);
    if (desc.length > 160) caution(`${rel}: description ${desc.length} chars`);
    if (desc.length < 70) caution(`${rel}: description only ${desc.length} chars`);
  }

  say(`  title     ${title === null ? 'MISSING' : `"${title}" (${title.length}ch)`}`);
  say(`  desc      ${desc === null ? 'MISSING' : `${desc.length}ch`}`);
  if (!/name=["']viewport["']/i.test(head)) fail(`${rel}: no viewport meta`);
  say(`  lang      ${(html.match(/<html[^>]*\blang=["']([^"']*)["']/i) || [, 'MISSING'])[1]}`);
  say(`  robots    ${meta(head, 'robots') || 'index'}`);
  const canonical = (head.match(/<link[^>]+rel=["']canonical["'][^>]+href=["']([^"']*)["']/i) || [])[1];
  say(`  canonical ${canonical || 'none (set-domain.php adds it at launch)'}`);
  if (canonical && /localhost|127\.0\.0\.1|:8731/.test(canonical)) fail(`${rel}: canonical points at a machine name`);

  const og = ['og:type', 'og:site_name', 'og:title', 'og:description', 'og:url', 'og:image', 'twitter:card']
    .filter((k) => meta(head, k) !== null);
  say(`  social    ${og.join(', ') || 'NONE'}`);
  if (!og.includes('og:image')) caution(`${rel}: no og:image, so shares show no picture`);

  for (const ld of [...html.matchAll(/<script[^>]+application\/ld\+json[^>]*>([\s\S]*?)<\/script>/gi)]) {
    try {
      const data = JSON.parse(ld[1]);
      const list = Array.isArray(data) ? data : [data];
      say(`  json-ld   ${list.map((d) => d['@type']).join(', ')} — valid JSON`);
      for (const d of list) {
        for (const forbidden of ['aggregateRating', 'review', 'openingHours', 'foundingDate', 'sameAs', 'geo']) {
          if (d[forbidden]) caution(`${rel}: json-ld publishes ${forbidden} — is that verified?`);
        }
      }
    } catch (e) {
      fail(`${rel}: structured data is not valid JSON (${e.message})`);
    }
  }

  /* headings */
  const h1 = [...body.matchAll(/<h1[^>]*>([\s\S]*?)<\/h1>/gi)];
  if (h1.length !== 1) fail(`${rel}: ${h1.length} H1 tags (want exactly 1)`);
  else say(`  h1        "${norm(h1[0][1].replace(/<[^>]+>/g, ''))}"`);
  for (const level of [2, 3, 4, 5, 6]) {
    const open = (body.match(new RegExp(`<h${level}\\b`, 'gi')) || []).length;
    const close = (body.match(new RegExp(`</h${level}>`, 'gi')) || []).length;
    if (open !== close) fail(`${rel}: h${level} open ${open} close ${close}`);
  }
  const order = [...body.matchAll(/<h([1-6])/g)].map((m) => +m[1]);
  const skips = [];
  for (let i = 1; i < order.length; i++) if (order[i] - order[i - 1] > 1) skips.push(`h${order[i - 1]}->h${order[i]}`);
  if (skips.length) caution(`${rel}: heading jumps ${skips.slice(0, 5).join(', ')}`);

  /* landmarks */
  const mains = (body.match(/<main\b/gi) || []).length;
  if (mains !== 1) fail(`${rel}: ${mains} <main> landmarks (want 1)`);
  const navs = (body.match(/<nav\b/gi) || []).length;
  const header = (body.match(/<header\b|id=["']site-header["']/gi) || []).length;
  const footer = (body.match(/<footer\b|id=["']site-footer["']/gi) || []).length;
  say(`  landmarks main ${mains}, nav ${navs}, header ${header}, footer ${footer}`);

  /* images */
  const imgTags = [...body.matchAll(/<img\b[^>]*>/gi)].map((m) => m[0]);
  const noAlt = imgTags.filter((t) => !/\balt=/i.test(t));
  if (noAlt.length) fail(`${rel}: ${noAlt.length} <img> without alt`);
  const dimsBad = [];
  for (const tag of imgTags) {
    const src = (tag.match(/\bsrc=["']([^"']*)["']/i) || [, ''])[1];
    if (!src || /^(https?:|data:)/i.test(src)) continue;
    if (!exists(src)) { fail(`${rel}: missing image ${src}`); continue; }
    const w = (tag.match(/\bwidth=["'](\d+)/i) || [])[1];
    const h = (tag.match(/\bheight=["'](\d+)/i) || [])[1];
    if (!w || !h) { caution(`${rel}: ${src} has no width/height (layout shift)`); continue; }
    const real = dimensions(src);
    if (real && (String(real.width) !== w || String(real.height) !== h)) {
      const transposed = String(real.width) === h && String(real.height) === w;
      dimsBad.push(`${src} says ${w}x${h}, file is ${real.width}x${real.height}${transposed ? ' (swapped)' : ''}`);
    }
    if (real && real.width === 0) fail(`${rel}: ${src} is unreadable`);
  }
  for (const d of dimsBad) caution(`${rel}: aspect ratio wrong — ${d}`);
  const lazy = imgTags.filter((t) => /loading=["']lazy["']/i.test(t)).length;
  say(`  images    ${imgTags.length} tags, ${lazy} lazy, ${noAlt.length} without alt, ${dimsBad.length} with wrong width/height`);

  /* references */
  const hrefs = [...new Set([...html.matchAll(/<a\b[^>]*\bhref=["']([^"']*)["']/gi)].map((m) => m[1]))];
  const local = hrefs.filter((h) => !/^(https?:|mailto:|tel:|javascript:|data:)/i.test(h));
  for (const h of local) {
    const file = h.replace(/^\/+/, '').split('#')[0].split('?')[0];
    if (!file) continue;
    if (!exists(file)) fail(`${rel}: link to missing file "${h}"`);
  }
  const dead = hrefs.filter((h) => h === '#');
  if (dead.length) caution(`${rel}: ${dead.length} placeholder "#" links`);
  const anchors = [...new Set(hrefs.filter((h) => /^#.+/.test(h)))];
  for (const a of anchors) {
    if (!new RegExp(`id=["']${a.slice(1)}["']`).test(html)) fail(`${rel}: in-page link ${a} has no target`);
  }
  say(`  links     ${hrefs.length} unique, ${local.length} local, ${dead.length} placeholders`);

  for (const tag of [...html.matchAll(/<(?:link|script)\b[^>]*>/gi)]) {
    const href = (tag[0].match(/(?:href|src)=["']([^"']*)["']/i) || [])[1];
    const rel2 = (tag[0].match(/rel=["']([^"']*)["']/i) || [])[1] || '';
    if (!href || /^(https?:|data:|#)/i.test(href)) continue;
    if (/preconnect|dns-prefetch/i.test(rel2)) continue;
    if (!exists(href.replace(/^\/+/, ''))) {
      if (/apple-touch-icon|manifest|og:/i.test(rel2)) caution(`${rel}: ${rel2} points at missing ${href}`);
      else fail(`${rel}: ${rel2 || 'tag'} points at missing ${href}`);
    }
  }

  for (const url of [...html.matchAll(/url\(\s*["']?([^"')]+)["']?\s*\)/gi)].map((m) => m[1])) {
    if (/^(https?:|data:)/i.test(url)) continue;
    if (!exists(url.replace(/^\/+/, ''))) fail(`${rel}: CSS url(${url}) is missing`);
  }

  /* forms */
  for (const form of [...html.matchAll(/<form\b([^>]*)>/gi)]) {
    const attrs = form[1];
    const action = (attrs.match(/action=["']([^"']*)["']/i) || [])[1];
    const method = (attrs.match(/method=["']([^"']*)["']/i) || [])[1];
    const guarded = /data-form-guard/i.test(attrs);
    const line = `form ${attrs.match(/id=["']([^"']*)["']/i)?.[1] || '(unnamed)'}: action=${action || 'none'} method=${method || 'GET'} guard=${guarded ? 'yes' : 'NO'}`;
    say(`  ${line}`);
    if (!guarded) caution(`${rel}: form is not guarded, it will post to a static page`);
    if (method && !/post/i.test(method)) fail(`${rel}: form uses ${method}`);
  }

  /* weight */
  const inlineCss = [...html.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/gi)].reduce((s, m) => s + m[1].length, 0);
  const inlineJs = [...html.matchAll(/<script(?![^>]*src)[^>]*>([\s\S]*?)<\/script>/gi)]
    .filter((m) => !/application\/ld\+json/.test(m[0]))
    .reduce((s, m) => s + m[1].length, 0);
  const sheets = [...new Set([...html.matchAll(/<link[^>]+rel=["']stylesheet["'][^>]*href=["']([^"']*)["']/gi)].map((m) => m[1])
    .concat([...html.matchAll(/<link[^>]+href=["']([^"']*)["'][^>]*rel=["']stylesheet["']/gi)].map((m) => m[1])))];
  const dupSheet = sheets.length !== new Set(sheets.map((s) => s.replace(/^\/+/, ''))).size;
  if (dupSheet) caution(`${rel}: the same stylesheet is linked twice`);
  let external = 0;
  for (const s of sheets) if (!/^https?:/i.test(s) && exists(s.replace(/^\/+/, ''))) external += bytes(path.join(root, s.replace(/^\/+/, '').replace(/\//g, path.sep)));
  say(`  weight    html ${kb(bytes(p))} + inline css ${(inlineCss / 1024).toFixed(1)}kb + inline js ${(inlineJs / 1024).toFixed(1)}kb + local css ${kb(external)}`);
  if (/^@import/m.test(fs.readFileSync(path.join(root, 'css/shared.css'), 'utf8'))) caution(`${rel}: shared.css still starts with an @import (blocks rendering)`);

  if (/@import\s+url/i.test([...html.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/gi)].map((m) => m[1]).join(''))) {
    caution(`${rel}: an inline @import holds up first paint`);
  }

  const lorem = /lorem ipsum/i.test(html);
  if (lorem) fail(`${rel}: contains lorem ipsum`);
  const placeholder = /\[(?:ADD|INSERT|TBD|REPLACE)[^\]]*\]|TODO:|FIXME|Add Company [\w ]+|Replace with (?:official|verified)[\w ]*|(?:tel:\+?\w*[Xx]{3,}|mailto:[\w.+-]*@example\.\w{2,6})/g;
  for (const marker of (html.match(placeholder) || [])) {
    fail(`${rel}: unfinished text "${marker.trim()}"`);
  }
  const stale = (html.match(/EASTERN|XAMPP\\htdocs|C:\\\\Users/gi) || []).length;
  if (stale) caution(`${rel}: ${stale} local path reference(s) left in the markup`);
}

/* ----------------------------- cross-page ------------------------------- */

say('');
say('=== DUPLICATES ===');
for (const [kind, map] of Object.entries({ title: titles, description: descriptions })) {
  for (const [key, list] of Object.entries(map)) {
    if (list.length > 1) caution(`${kind} shared by ${list.join(', ')} — "${key.slice(0, 60)}"`);
  }
}

const indexable = pages.filter((p) => !/noindex/i.test(fs.readFileSync(p, 'utf8')));
say(`indexable pages: ${indexable.map((p) => path.relative(root, p).replace(/\\/g, '/')).join(', ')}`);

/* internal linking between the three commercial pages */
say('');
say('=== INTERNAL LINKS ===');
const targets = ['portfolio.html', 'services.html', 'quote-request.html', 'about.html',
  'contact.html', 'testimonials.html', 'privacy-policy.html', 'terms-and-conditions.html'];
for (const rel of indexable.map((p) => path.relative(root, p).replace(/\\/g, '/'))) {
  if (rel === '404.html') continue;
  const html = fs.readFileSync(path.join(root, rel), 'utf8');
  const out = targets.filter((t) => html.includes(`href="${t}"`));
  say(`  ${rel.padEnd(26)} -> ${out.join(', ') || 'nothing'}`);
  // The shared header already links everywhere, so an isolated page is a
  // content problem rather than a broken site.
  if (!out.length && rel !== 'index.html' && rel !== 'home.html') caution(`${rel}: no in-content link to another page`);
  if (!out.includes('quote-request.html') && rel !== 'quote-request.html') caution(`${rel}: no path to the quote request`);
}

/* sitemap, if generated */
if (exists('sitemap.xml')) {
  const xml = fs.readFileSync(path.join(root, 'sitemap.xml'), 'utf8');
  const locs = [...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1]);
  say('');
  say(`=== SITEMAP (${locs.length} urls) ===`);
  for (const l of locs) say(`  ${l}`);
  if (/localhost|127\.0\.0\.1|:8731/.test(xml)) fail('sitemap.xml contains a machine name');
  for (const l of locs) {
    const file = l.replace(/^https?:\/\/[^/]+\/?/, '') || 'index3.html';
    if (!exists(file)) fail(`sitemap.xml lists ${file}, which does not exist`);
  }
  for (const rel of indexable.map((p) => path.relative(root, p).replace(/\\/g, '/'))) {
    if (rel === '404.html') continue;
    if (!locs.some((l) => l.endsWith('/' + rel) || (rel === 'index3.html' && l.endsWith('/')))) {
      caution(`${rel} is indexable but absent from sitemap.xml`);
    }
  }
}

say('');
say(`=== RESULT: ${broken.length} broken, ${warn.length} warnings ===`);
for (const b of broken) say(`BROKEN  ${b}`);
for (const w of warn) say(`warn    ${w}`);

fs.writeFileSync(path.join(__dirname, 'audit-report.txt'), lines.join('\n') + '\n');
console.log(lines.join('\n'));
process.exit(broken.length ? 1 : 0);
