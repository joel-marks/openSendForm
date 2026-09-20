/*
 * OpenSendForm — vertical-rhythm eye-proxy check (REAL Firefox).
 *
 * WHY THIS EXISTS
 * ---------------
 * Vertical rhythm is component-owned (Task 2): action rows own a standard top
 * margin from the --osf-space scale, so no page ever hand-adds spacing above a
 * primary action. getComputedStyle over a real render is the proxy for "by
 * eye": this script asserts that the action row on at least one admin page —
 * and, when the app is still uninstalled, an installer step's action row —
 * has a NONZERO computed margin-top, in BOTH themes.
 *
 * Like tests/browser/header-surface-check.mjs it is deliberately NOT wired into
 * `composer test` or CI: it needs a ~90 MB Firefox download and a running app.
 *
 * HOW TO RUN
 * ----------
 *   1. One-time browser download (Firefox specifically):
 *        npm install --save-dev playwright
 *        npx playwright install firefox --with-deps
 *   2. Serve the app with a seeded, logged-in-able admin + at least one form:
 *        composer serve            # http://127.0.0.1:8080
 *   3. Run this check:
 *        node tests/browser/rhythm-check.mjs
 *
 * Environment overrides mirror the header check:
 *   OSF_BASE_URL   base URL of the running app     (default http://127.0.0.1:8080)
 *   OSF_ADMIN_EMAIL / OSF_ADMIN_PASSWORD           (default diag@example.com / diagnostic-pass-123)
 *
 * EXIT CODE: 0 iff every checked action row has a nonzero computed margin-top
 * in both themes; non-zero (with a printed report) on any deviation.
 */

import { firefox } from 'playwright';

const BASE = process.env.OSF_BASE_URL || 'http://127.0.0.1:8080';
const EMAIL = process.env.OSF_ADMIN_EMAIL || 'diag@example.com';
const PASSWORD = process.env.OSF_ADMIN_PASSWORD || 'diagnostic-pass-123';

/** Computed margin-top (px, number) of the first element matching sel, or null. */
async function marginTopOf(page, sel) {
  return page.evaluate((s) => {
    const el = document.querySelector(s);
    if (!el) return null;
    return parseFloat(getComputedStyle(el).marginTop) || 0;
  }, sel);
}

async function run() {
  const browser = await firefox.launch();
  const context = await browser.newContext({ deviceScaleFactor: 1, viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();

  const failures = [];
  const checks = [];

  // --- Installer step action row (only reachable while UNINSTALLED) ---------
  // Best-effort: if the served app is already installed, /install redirects or
  // 404s and there is no step action row to sample — reported, not failed.
  const installResp = await page.goto(`${BASE}/install/database`, { waitUntil: 'networkidle' }).catch(() => null);
  const onInstaller = installResp && installResp.ok() && (await page.$('.osf-step-actions'));
  if (onInstaller) {
    checks.push({ label: 'installer step action row', url: '/install/database', sel: '.osf-step-actions' });
  } else {
    console.log('NOTE: installer not reachable (app already installed) — skipping the installer step action row.');
  }

  // --- Log in for the admin action row --------------------------------------
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name="email"]', EMAIL);
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type="submit"], input[type="submit"]'),
  ]);

  // /admin/forms/new carries a form-level .osf-actions (Create form).
  checks.push({ label: 'admin form action row', url: '/admin/forms/new', sel: '.osf-actions' });

  for (const theme of ['dark', 'light']) {
    for (const c of checks) {
      await page.goto(`${BASE}${c.url}`, { waitUntil: 'networkidle' });
      await page.evaluate((t) => window.localStorage.setItem('osf-theme', t), theme);
      await page.reload({ waitUntil: 'networkidle' });
      await page.waitForSelector(c.sel).catch(() => {});
      const mt = await marginTopOf(page, c.sel);
      const ok = mt !== null && mt > 0;
      console.log(`  ${ok ? 'PASS' : 'FAIL'}  [${theme}] ${c.label.padEnd(26)} ${c.sel}  margin-top=${mt}px`);
      if (!ok) failures.push({ theme, ...c, mt });
    }
  }

  await browser.close();

  if (failures.length) {
    console.error(`\nFAIL: ${failures.length} action row(s) had no top spacing:`);
    for (const f of failures) console.error(`  [${f.theme}] ${f.label} (${f.sel}): margin-top=${f.mt}`);
    process.exit(1);
  }
  console.log('\nPASS: every checked action row owns a nonzero top margin in both themes.');
}

run().catch((e) => { console.error('ERROR:', e.message); process.exit(2); });
