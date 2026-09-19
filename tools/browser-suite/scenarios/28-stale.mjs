/*
 * A translation's block marked when its original changes (PLAN.md D-043, step 3; SPEC §8
 * Slice 6's acceptance in the browser).
 *
 * What a person does: make a page with one text block, translate it, change the block's
 * heading in the original and save, then open the translation — where that block carries
 * an amber edge on the page and, once selected, a notice with what the original says now —
 * and press "Mark as up to date", after which both are gone.
 *
 * LEAVES THE SITE AS FOUND: the page is a new one made for this run, both versions are
 * deleted through the pages list, and the language it adds is removed again. It stops
 * before touching anything if that language is already on the site.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, clickAndWait, retype, SLOW } from '../harness.mjs';

const CODE = 'hr';
const TITLE = `Zz stale check ${Date.now()}`;
const SETTLE = 1500;
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const idFrom = (url) => Number((url.match(/\/admin\/pages\/(\d+)$/) || [])[1]) || null;

const canvasReady = (page) => page.waitForFunction(() => {
  const frame = document.querySelector('iframe[data-canvas]');
  return frame && frame.contentDocument && frame.contentDocument.querySelectorAll('[data-bx-blocks] > section').length > 0;
}, { timeout: 20000 });

const leaveUnsaved = (page) => page.evaluate(() => { window.onbeforeunload = null; }).catch(() => {});

async function deletePage(page, id) {
  await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
  await page.$eval(`form[action$="/pages/${id}/delete"] button`, (b) => b.removeAttribute('data-confirm')).catch(() => {});
  await clickAndWait(page, `form[action$="/pages/${id}/delete"] button`).catch(() => {});
}

export default {
  name: 'stale',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('stale: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1600, height: 1000, deviceScaleFactor: 2 });

    await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle2' });
    const free = await page.$$eval('#language-code option', (options, code) => options.some((o) => o.value === code), CODE);
    if (!free) {
      report.skip('stale: a language to add', `${CODE} is already on the site; not touching the owner's`);
      return;
    }
    await page.select('#language-code', CODE);
    await clickAndWait(page, 'form.language-add button[type="submit"]');

    let source = null;
    let translation = null;
    try {
      // ---- a page of one text block ---------------------------------------------------------
      await page.goto(`${BASE}/admin/pages/new`, { waitUntil: 'networkidle2' });
      await page.type('#page-title', TITLE);
      await page.select('#page-template', '');
      await clickAndWait(page, 'form.panel button[type="submit"]');
      source = idFrom(page.url());
      await canvasReady(page).catch(() => {});
      await page.click('[data-add-type="text"]');
      await wait(SETTLE);
      const group = await page.evaluate(() => {
        const shown = Array.from(document.querySelectorAll('[data-block-group]')).find((g) => !g.hidden);
        return shown ? shown.getAttribute('data-block-group') : null;
      });
      await page.type(`[data-block-group="${group}"] input[name$="[heading]"]`, 'Original heading', { delay: SLOW });
      await page.type(`[data-block-group="${group}"] .ProseMirror`, 'Original words.', { delay: SLOW });
      await wait(400);
      await leaveUnsaved(page);
      await clickAndWait(page, '.builder-bar button[value="save"]');

      // ---- translated -----------------------------------------------------------------------
      await canvasReady(page).catch(() => {});
      await leaveUnsaved(page);
      await page.click('details.builder-locale > summary');
      await clickAndWait(page, `button[form="translate-${CODE}"]`);
      translation = idFrom(page.url());
      await canvasReady(page).catch(() => {});
      const before = await page.evaluate(() => document.querySelector('iframe[data-canvas]').contentDocument.querySelectorAll('[data-bx-stale]').length);
      report.verdict('a fresh translation has nothing marked', translation !== null && before === 0, `translation ${translation}, marked ${before}`);

      // ---- the original changes ---------------------------------------------------------------
      await page.goto(`${BASE}/admin/pages/${source}`, { waitUntil: 'networkidle2' });
      await canvasReady(page);
      const frame = page.frames().find((f) => f.url().includes('/canvas'));
      await (await frame.$('[data-bx-index="0"]')).click();
      await wait(300);
      const heading = '[data-block-group="0"] input[name$="[heading]"]';
      await retype(page, heading, 'Changed heading');
      await wait(400);
      await leaveUnsaved(page);
      await clickAndWait(page, '.builder-bar button[value="save"]');

      // ---- the translation shows it ------------------------------------------------------------
      await page.goto(`${BASE}/admin/pages/${translation}`, { waitUntil: 'networkidle2' });
      await canvasReady(page);
      const marked = await page.evaluate(() => {
        const frame = document.querySelector('iframe[data-canvas]');
        const section = frame.contentDocument.querySelector('[data-bx-stale]');
        return section ? frame.contentWindow.getComputedStyle(section).boxShadow : null;
      });
      const summary = await page.$eval('.builder-panel > .notice', (n) => n.textContent.replace(/\s+/g, ' ').trim()).catch(() => '');
      const tFrame = page.frames().find((f) => f.url().includes('/canvas'));
      await (await tFrame.$('[data-bx-index="0"]')).click();
      await wait(300);
      await page.$eval('[data-block-group="0"] .stale-original', (d) => { d.open = true; }).catch(() => {});
      const notice = await page.$eval('[data-block-group="0"] .stale-notice', (n) => n.textContent.replace(/\s+/g, ' ').trim()).catch(() => '');
      await report.shot(page, '01-stale-block', { fullPage: false });
      report.verdict('the changed block carries an edge on the page, at rest', marked !== null && marked !== 'none', `box-shadow: ${marked}`);
      report.verdict('the panel says how many changed, and the block\'s notice says what the original says now',
        /: 1\./.test(summary) && /Changed heading/.test(notice), `summary: ${summary} — notice: ${notice.slice(0, 160)}`);

      // ---- marked as up to date ----------------------------------------------------------------
      await leaveUnsaved(page);
      await clickAndWait(page, '[data-block-group="0"] .stale-notice button');
      await canvasReady(page);
      const after = await page.evaluate(() => document.querySelector('iframe[data-canvas]').contentDocument.querySelectorAll('[data-bx-stale]').length);
      const noticesLeft = await page.$$eval('.stale-notice', (n) => n.length);
      report.verdict('"Mark as up to date" clears the mark and the notice', after === 0 && noticesLeft === 0, `marked ${after}, notices ${noticesLeft}`);
    } finally {
      // ---- leave the site as found ----------------------------------------------------------
      await leaveUnsaved(page);
      if (translation !== null) await deletePage(page, translation);
      if (source !== null) await deletePage(page, source);
      await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle2' });
      await page.$eval(`form[action$="/languages/${CODE}/delete"] button`, (b) => b.removeAttribute('data-confirm')).catch(() => {});
      await clickAndWait(page, `form[action$="/languages/${CODE}/delete"] button`).catch(() => {});
      const left = await page.$$eval('#languages code', (codes) => codes.map((c) => c.textContent));
      await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
      const stray = await page.evaluate((title) => document.body.textContent.includes(title), TITLE);
      report.verdict('the pages and the language are gone again', !left.includes(CODE) && !stray, `languages ${left.join(', ')}; test page still listed: ${stray}`);
    }
  },
};
