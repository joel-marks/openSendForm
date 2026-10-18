/*
 * OpenSendForm — content-width drift check (REAL Firefox).
 *
 * WHY THIS EXISTS
 * ----------------
 * Operator report (fix/installer-polish-r2, Task 6): some admin tabs (Email,
 * Admins, Status) rendered content 1-2px narrower than short pages (e.g. the
 * Dashboard). ROOT CAUSE: the vertical scrollbar. A page tall enough to
 * scroll reserves the scrollbar's own width out of the viewport, narrowing
 * every `width: 100%` box (including `.container`, which is centred with
 * `margin: 0 auto` inside that narrowed viewport) by a few pixels versus a
 * short page with no scrollbar at all — so the SAME `.container` rule
 * resolves to a different pixel width purely as a function of page height,
 * not any per-page CSS. The fix is `scrollbar-gutter: stable` on `html`
 * (public/assets/admin.css): it reserves the scrollbar's gutter at all
 * times, so a page's content width no longer depends on whether that page
 * happens to be tall enough to scroll.
 *
 * This script loads a short admin page (Dashboard) alongside pages known to
 * be tall enough to scroll (Email, Admins, Status) and asserts
 * `main.container`'s content-box width is IDENTICAL across all of them, in
 * both themes.
 *
 * Like the other tests/browser/ scripts it is deliberately NOT wired into
 * `composer test` or CI: it needs a real browser download and a running app.
 *
 * HOW TO RUN
 * ----------
 *   1. One-time browser download (Firefox specifically):
 *        npm install --save-dev playwright
 *        npx playwright install firefox --with-deps
 *   2. Serve the app with a seeded, logged-in-able admin:
 *        composer serve            # http://127.0.0.1:8080
 *   3. Run this check:
 *        node tests/browser/content-width-check.mjs
 *
 * Environment overrides mirror the other browser checks:
 *   OSF_BASE_URL   base URL of the running app     (default http://127.0.0.1:8080)
 *   OSF_ADMIN_EMAIL / OSF_ADMIN_PASSWORD           (default diag@example.com / diagnostic-pass-123)
 *
 * EXIT CODE: 0 iff every checked page reports the same main.container width
 * (within a 0.5px rounding tolerance) in both themes; non-zero otherwise.
 */

import { firefox } from 'playwright';

const BASE = process.env.OSF_BASE_URL || 'http://127.0.0.1:8080';
const EMAIL = process.env.OSF_ADMIN_EMAIL || 'diag@example.com';
const PASSWORD = process.env.OSF_ADMIN_PASSWORD || 'diagnostic-pass-123';
const TOLERANCE = 0.5; // px; absorbs sub-pixel layout rounding, not real drift

// A short page (no scrollbar) alongside the three pages the operator
// reported as drifting (tall enough to scroll).
const PAGES = [
  { label: 'Dashboard', url: '/admin' },
  { label: 'Email', url: '/admin/mail' },
  { label: 'Admins', url: '/admin/admins' },
  { label: 'Status', url: '/admin/status' },
];

async function containerWidth(page) {
  return page.evaluate(() => {
    const el = document.querySelector('main.container');
    return el ? el.getBoundingClientRect().width : null;
  });
}

async function run() {
  const browser = await firefox.launch();
  // A viewport narrower than .container's 60rem max-width, so the scrollbar
  // gutter actually competes for space against the content instead of being
  // absorbed by slack outside the capped container.
  const context = await browser.newContext({ deviceScaleFactor: 1, viewport: { width: 900, height: 700 } });
  const page = await context.newPage();

  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name="email"]', EMAIL);
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type="submit"], input[type="submit"]'),
  ]);

  const failures = [];

  for (const theme of ['dark', 'light']) {
    await page.evaluate((t) => window.localStorage.setItem('osf-theme', t), theme);

    const widths = [];
    for (const p of PAGES) {
      await page.goto(`${BASE}${p.url}`, { waitUntil: 'networkidle' });
      await page.waitForSelector('main.container');
      const width = await containerWidth(page);
      widths.push({ ...p, width });
    }

    const reference = widths[0].width;
    console.log(`\n=== THEME: ${theme} ===`);
    for (const w of widths) {
      const ok = w.width !== null && Math.abs(w.width - reference) <= TOLERANCE;
      console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${w.label.padEnd(10)} ${w.url.padEnd(14)} width=${w.width}px`);
      if (!ok) failures.push({ theme, ...w, reference });
    }
  }

  await browser.close();

  if (failures.length) {
    console.error(`\nFAIL: ${failures.length} page(s) had a content width that drifted from the reference:`);
    for (const f of failures) {
      console.error(`  [${f.theme}] ${f.label} (${f.url}): width=${f.width}px, reference=${f.reference}px`);
    }
    process.exit(1);
  }
  console.log('\nPASS: every checked admin page shares the same main.container width in both themes.');
}

run().catch((e) => { console.error('ERROR:', e.message); process.exit(2); });
