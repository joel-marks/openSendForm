/*
 * OpenSendForm — header painted-surface regression check (REAL Firefox).
 *
 * WHY THIS EXISTS
 * ---------------
 * The two-row admin header must paint ONE shared surface across both rows
 * (architect art-direction ruling, reversing fix/5d-polish-v3's surface
 * split):
 *   row 1  .osf-header   -> --osf-bg-inset   (near-black)
 *   row 2  .osf-tabnav   -> --osf-bg-inset   (same surface as row 1)
 * Operators repeatedly reported the tab row (row 2) rendering "grey / raised"
 * in Firefox on macOS in dark mode, while headless *Chromium* checks and CSS
 * text review kept declaring the rules correct. getComputedStyle only reports
 * the DECLARED value, not the COMPOSITED pixel a user actually sees. This
 * script therefore samples the ACTUAL PAINTED PIXELS from a screenshot taken
 * by a REAL Firefox build, in BOTH themes, and compares them to the token
 * values resolved from tokens.css at runtime.
 *
 * It is deliberately NOT wired into `composer test` or CI: it needs a
 * ~90 MB Firefox download and a running app, neither available in the PHPUnit
 * lane.
 *
 * HOW TO RUN
 * ----------
 *   1. One-time browser download (Firefox specifically — not chromium/webkit):
 *        npm install --save-dev playwright
 *        npx playwright install firefox --with-deps
 *   2. Serve the app with a seeded, logged-in-able admin + at least one form:
 *        composer serve            # http://127.0.0.1:8080
 *   3. Run this check:
 *        node tests/browser/header-surface-check.mjs
 *
 * Environment overrides:
 *   OSF_BASE_URL   base URL of the running app     (default http://127.0.0.1:8080)
 *   OSF_ADMIN_EMAIL / OSF_ADMIN_PASSWORD           (default diag@example.com / diagnostic-pass-123)
 *   OSF_DIAG=1     dump the full getComputedStyle + elementsFromPoint walk
 *   OSF_SHOT_DIR   directory to write screenshots   (default alongside this file)
 *
 * EXIT CODE: 0 iff every sampled pixel in both rows matches its token in both
 * themes; non-zero (with a printed report) on any deviation or setup failure.
 */

import { firefox } from 'playwright';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const BASE = process.env.OSF_BASE_URL || 'http://127.0.0.1:8080';
const EMAIL = process.env.OSF_ADMIN_EMAIL || 'diag@example.com';
const PASSWORD = process.env.OSF_ADMIN_PASSWORD || 'diagnostic-pass-123';
const DIAG = process.env.OSF_DIAG === '1';
const SHOT_DIR = process.env.OSF_SHOT_DIR || dirname(fileURLToPath(import.meta.url));
const TOLERANCE = 2; // per-channel; absorbs any sub-pixel AA, not a real tint

/** Parse an rgb()/rgba() string into [r,g,b] (0-255), ignoring alpha. */
function parseRgb(s) {
  const m = String(s).match(/(\d+(?:\.\d+)?)/g);
  if (!m || m.length < 3) return null;
  return [Math.round(+m[0]), Math.round(+m[1]), Math.round(+m[2])];
}

function near(a, b) {
  return a && b && Math.abs(a[0] - b[0]) <= TOLERANCE
    && Math.abs(a[1] - b[1]) <= TOLERANCE
    && Math.abs(a[2] - b[2]) <= TOLERANCE;
}

const hex = (rgb) => rgb ? '#' + rgb.map(n => n.toString(16).padStart(2, '0')).join('') : 'n/a';

async function run() {
  const browser = await firefox.launch();
  const context = await browser.newContext({ deviceScaleFactor: 1, viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();

  // --- Log in ---------------------------------------------------------------
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name="email"]', EMAIL);
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type="submit"], input[type="submit"]'),
  ]);
  if (!/\/admin\/?$/.test(new URL(page.url()).pathname) && !(await page.$('.osf-tabnav'))) {
    throw new Error(`Login did not reach an admin page with a tab bar (at ${page.url()}). ` +
      `Check credentials (OSF_ADMIN_EMAIL/OSF_ADMIN_PASSWORD) and that the app is seeded.`);
  }
  await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });

  const failures = [];

  for (const theme of ['dark', 'light']) {
    await page.evaluate((t) => window.localStorage.setItem('osf-theme', t), theme);
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForSelector('.osf-header');
    await page.waitForSelector('.osf-tabnav');

    // Expected surfaces, resolved from tokens.css at runtime (never hardcoded).
    const expected = await page.evaluate(() => {
      const cs = getComputedStyle(document.documentElement);
      const probe = (val) => {
        const d = document.createElement('div');
        d.style.color = val;
        document.body.appendChild(d);
        const rgb = getComputedStyle(d).color;
        d.remove();
        return rgb;
      };
      return {
        theme: document.documentElement.getAttribute('data-theme'),
        bg: probe(cs.getPropertyValue('--osf-bg').trim()),
        bgInset: probe(cs.getPropertyValue('--osf-bg-inset').trim()),
        bgRaised: probe(cs.getPropertyValue('--osf-bg-raised').trim()),
      };
    });
    const expBg = parseRgb(expected.bg);
    const expInset = parseRgb(expected.bgInset);

    // Bounding boxes of the two rows.
    const boxes = await page.evaluate(() => {
      const r = (sel) => {
        const el = document.querySelector(sel);
        if (!el) return null;
        const b = el.getBoundingClientRect();
        return { x: b.x, y: b.y, width: b.width, height: b.height };
      };
      return { header: r('.osf-header'), tabnav: r('.osf-tabnav') };
    });
    if (!boxes.header || !boxes.tabnav) throw new Error('Header/tabnav not found in DOM');

    // Screenshot the full viewport, then read painted pixels back through the
    // browser's own PNG decoder + a canvas (true composited pixels, no extra
    // npm decode dependency).
    const shotPath = join(SHOT_DIR, `header-${theme}.png`);
    const buf = await page.screenshot({ path: shotPath, clip: {
      x: 0, y: 0, width: 1280, height: Math.ceil(boxes.tabnav.y + boxes.tabnav.height) + 4,
    } });
    const dataUrl = 'data:image/png;base64,' + buf.toString('base64');

    // Sample points chosen INSIDE each row, away from text/icons/borders.
    const sample = (box, fx, fyOffset) => ({
      x: Math.round(box.x + box.width * fx),
      y: Math.round(box.y + fyOffset),
    });
    const midHeader = boxes.header.height / 2;
    const midTab = boxes.tabnav.height / 2;
    const points = [
      // Row 1 (.osf-header) — empty band between brand (far left) and actions (far right).
      { row: 'header', label: 'header@40%', ...sample(boxes.header, 0.40, midHeader), expect: expInset, token: '--osf-bg-inset' },
      { row: 'header', label: 'header@50%', ...sample(boxes.header, 0.50, midHeader), expect: expInset, token: '--osf-bg-inset' },
      { row: 'header', label: 'header@60%', ...sample(boxes.header, 0.60, midHeader), expect: expInset, token: '--osf-bg-inset' },
      // Row 2 (.osf-tabnav) — empty right side, above the 1px bottom border.
      // Same surface as row 1: --osf-bg-inset (one-surface ruling). The tab
      // strip now carries six tabs and reaches ~60% of the width, so the
      // empty-surface samples sit to the RIGHT of it (past the last tab).
      { row: 'tabnav', label: 'tabnav@80%', ...sample(boxes.tabnav, 0.80, midTab - 2), expect: expInset, token: '--osf-bg-inset' },
      { row: 'tabnav', label: 'tabnav@88%', ...sample(boxes.tabnav, 0.88, midTab - 2), expect: expInset, token: '--osf-bg-inset' },
      { row: 'tabnav', label: 'tabnav@96%', ...sample(boxes.tabnav, 0.96, midTab - 2), expect: expInset, token: '--osf-bg-inset' },
    ];

    const sampled = await page.evaluate(async ({ dataUrl, points }) => {
      const img = await new Promise((res, rej) => {
        const i = new Image();
        i.onload = () => res(i);
        i.onerror = rej;
        i.src = dataUrl;
      });
      const canvas = document.createElement('canvas');
      canvas.width = img.width;
      canvas.height = img.height;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0);
      return points.map((p) => {
        const d = ctx.getImageData(p.x, p.y, 1, 1).data;
        return { label: p.label, rgb: [d[0], d[1], d[2]] };
      });
    }, { dataUrl, points });

    console.log(`\n=== THEME: ${theme} (data-theme=${expected.theme}) ===`);
    console.log(`  expected --osf-bg=${hex(expBg)}  --osf-bg-inset=${hex(expInset)}  --osf-bg-raised=${hex(parseRgb(expected.bgRaised))}`);
    for (let i = 0; i < points.length; i++) {
      const p = points[i];
      const got = sampled[i].rgb;
      const ok = near(got, p.expect);
      console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${p.label.padEnd(12)} @(${p.x},${p.y})  got ${hex(got)}  expect ${hex(p.expect)} (${p.token})`);
      if (!ok) failures.push({ theme, ...p, got: hex(got), expect: hex(p.expect) });
    }

    if (DIAG) {
      const dump = await page.evaluate(() => {
        const out = { computed: {}, walk: [] };
        const sels = ['html', 'body', '.osf-header', '.osf-header-inner', '.osf-tabnav', '.osf-tabnav-inner'];
        for (const s of sels) {
          const el = document.querySelector(s);
          out.computed[s] = el ? getComputedStyle(el).backgroundColor : '(absent)';
        }
        // Every ancestor from the tab row up to <body>.
        let node = document.querySelector('.osf-tabnav');
        const chain = [];
        while (node && node !== document.body) {
          chain.push(`${node.tagName.toLowerCase()}.${(node.className || '').toString().replace(/\s+/g, '.')} -> ${getComputedStyle(node).backgroundColor}`);
          node = node.parentElement;
        }
        out.ancestors = chain;
        // elementsFromPoint walk at several coords inside the tab row.
        const b = document.querySelector('.osf-tabnav').getBoundingClientRect();
        for (const fx of [0.6, 0.78, 0.92]) {
          const x = Math.round(b.x + b.width * fx), y = Math.round(b.y + b.height / 2);
          const els = document.elementsFromPoint(x, y).map(e =>
            `${e.tagName.toLowerCase()}.${(e.className || '').toString().replace(/\s+/g, '.')}=${getComputedStyle(e).backgroundColor}`);
          out.walk.push({ x, y, els });
        }
        return out;
      });
      console.log('  --- DIAG getComputedStyle(background-color) ---');
      for (const [k, v] of Object.entries(dump.computed)) console.log(`      ${k}: ${v}`);
      console.log('  --- DIAG ancestor chain (tabnav -> body) ---');
      for (const line of dump.ancestors) console.log(`      ${line}`);
      console.log('  --- DIAG elementsFromPoint walk inside tab row ---');
      for (const w of dump.walk) console.log(`      @(${w.x},${w.y}): ${w.els.join('  |  ')}`);
    }
  }

  await browser.close();

  if (failures.length) {
    console.error(`\nFAIL: ${failures.length} sampled pixel(s) deviated from the token surface:`);
    for (const f of failures) console.error(`  [${f.theme}] ${f.label}: got ${f.got}, expected ${f.expect} (${f.token})`);
    process.exit(1);
  }
  console.log('\nPASS: both header rows paint their token surfaces in both themes.');
}

run().catch((e) => { console.error('ERROR:', e.message); process.exit(2); });
