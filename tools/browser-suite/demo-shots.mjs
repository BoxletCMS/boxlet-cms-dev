/*
 * THE DEMO IN EVERY DESIGN SET (PLAN.md D-213): Home, Exhibitions and Workshops at 1440 px in
 * each of the 21 sets, and Home at 390 px in four, written to tools/demo-images/_shots/ for the
 * owner's look. A tool, not a scenario: it judges nothing.
 *
 *   node demo-shots.mjs [set ...]
 *
 * On the COPY only, installed with the demo (01-install does that). It adds the library's sets,
 * applies each set through Appearance as an owner would, and puts the copy back afterwards:
 * the library's sets deleted again and Soft, the demo's own character, applied.
 *
 * A page is shot one window at a time and the pieces are joined (stitch.py beside the shots):
 * a full-page shot resizes the window, and a hero a screen tall becomes as tall as the page.
 * Each page is scrolled to its end first, so every lazy picture has loaded, and a header that
 * stays on screen is hidden after the first piece, so it is not drawn over every later one.
 */
import { mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { COPY_BASE as BASE, ADMIN, CHECKOUT } from './config.mjs';
import { openBrowser, login, applyCharacter } from './harness.mjs';

const OUT = `${CHECKOUT}/tools/demo-images/_shots`;
const CORE = ['bold', 'brutalist', 'clinic', 'commons', 'couture', 'editorial', 'gallery', 'launch', 'minimal', 'riso', 'soft', 'terra', 'zine'];
const LIBRARY = ['coast', 'event', 'mono', 'playground', 'portfolio', 'retreat', 'terminal', 'workshop'];
const PAGES = { home: '/', exhibitions: '/exhibitions', workshops: '/workshops' };
// Nocturne in the brief is the admin's dark palette, not a set: Terminal stands for it (D-213).
const PHONE = ['terminal', 'clinic', 'zine', 'gallery'];

const only = process.argv.slice(2);
const sets = [...CORE, ...LIBRARY].filter((s) => only.length === 0 || only.includes(s));
mkdirSync(OUT, { recursive: true });

const { browser, page } = await openBrowser({ width: 1440, height: 900, scale: 1 });

/** Every lazy picture in, then each window's worth shot, top to bottom; the pieces joined. */
async function shoot(name, width, height) {
  await page.setViewport({ width, height, deviceScaleFactor: 1 });
  const total = await page.evaluate(async () => {
    const step = window.innerHeight;
    for (let y = 0; y < document.documentElement.scrollHeight; y += step) {
      window.scrollTo(0, y);
      await new Promise((r) => setTimeout(r, 120));
    }
    await Promise.all(Array.from(document.images).map((img) => (img.complete ? null : new Promise((r) => { img.onload = img.onerror = r; }))));
    window.scrollTo(0, 0);
    return document.documentElement.scrollHeight;
  });
  const pieces = [];
  for (let y = 0, n = 0; y < total; y += height, n++) {
    await page.evaluate((top) => window.scrollTo(0, top), y);
    await new Promise((r) => setTimeout(r, 150));
    const at = await page.evaluate(() => window.scrollY);
    const file = `${OUT}/.piece-${n}.png`;
    await page.screenshot({ path: file });
    // A header that stays on screen is in the first piece only, not laid over every later one.
    // Hidden, not moved: its place in the layout stays as it was.
    if (n === 0) {
      await page.evaluate(() => document.querySelectorAll('body *').forEach((el) => {
        const position = getComputedStyle(el).position;
        if (position === 'fixed' || position === 'sticky') el.style.setProperty('visibility', 'hidden');
      }));
    }
    pieces.push(`${file}:${at}`);
  }
  execFileSync(`${process.env.HOME}/boxlet-build/venv-faces/bin/python`, [`${CHECKOUT}/tools/browser-suite/stitch.py`, `${OUT}/${name}.jpg`, String(total), ...pieces]);
}

try {
  if (!await login(page, BASE, ADMIN.email, ADMIN.password)) throw new Error('could not log in to the copy');
  for (const slug of LIBRARY.filter((s) => sets.includes(s))) {
    await page.goto(`${BASE}/admin/appearance/browse`, { waitUntil: 'networkidle2' });
    const add = await page.$(`form.library-add[action$="/browse/${slug}"] button`);
    if (add) await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), add.click()]);
  }
  for (const slug of sets) {
    const problems = await applyCharacter(page, BASE, slug);
    if (problems.length) console.log(`${slug}: ${problems.join(' | ')}`);
    for (const [key, path] of Object.entries(PAGES)) {
      await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle2' });
      await shoot(`${slug}-${key}-1440`, 1440, 900);
    }
    if (PHONE.includes(slug)) {
      await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
      await shoot(`${slug}-home-390`, 390, 844);
    }
    console.log(`${slug}: shot`);
  }
} finally {
  // The copy as it was: the demo's own character, the library's sets gone again.
  await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
  await applyCharacter(page, BASE, 'soft');
  for (const slug of LIBRARY) {
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    await page.evaluate(async (base, s) => {
      if (!document.querySelector(`button[value="character:delete:${s}"]`)) return;
      const form = new FormData(document.getElementById('design-form'));
      form.set('action', `character:delete:${s}`);
      await fetch(`${base}/admin/appearance`, { method: 'POST', body: form, credentials: 'same-origin' });
    }, BASE, slug);
  }
  await browser.close();
}
