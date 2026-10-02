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
 * deleted through the pages list, and the language it adds — the first one Settings offers
 * that the copy does not have — is removed again.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, clickAndWait, retype, SLOW, openBuilder, addBlock, blockKey, selectBlock, settle, publish } from '../harness.mjs';

// A language the copy does not have yet, read off Settings when the run starts (D-174:
// the demo already has hr, which made this skip on every run).
let CODE = '';
const TITLE = `Zz stale check ${Date.now()}`;
const SETTLE = 1500;
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const idFrom = (url) => Number((url.match(/\/admin\/pages\/(\d+)$/) || [])[1]) || null;

const marks = (page) => page.evaluate(() => document.querySelector('[data-pb-canvas]').contentDocument.querySelectorAll('[data-bx-stale]').length);

/** The text block's words, typed in the inspector's All content (D-175). */
async function words(page, key, heading, body) {
  const form = await selectBlock(page, key);
  await page.$eval('#ins-content', (d) => { d.open = true; });
  await retype(page, `${form} input[name$="[heading]"]`, heading);
  if (body !== null) {
    await page.click(`${form} .ProseMirror`);
    await page.keyboard.type(body, { delay: SLOW });
  }
  await wait(SETTLE);
}

async function deletePage(page, id) {
  await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
  await page.$eval(`tr[data-page-id="${id}"] details.row-menu`, (d) => { d.open = true; }).catch(() => {});
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
    CODE = await page.$$eval('#language-code option', (options) => (options.find((o) => o.value !== '') || {}).value || '');
    if (CODE === '') {
      report.fail('stale: a language to add', 'Settings offers no language the site does not have');
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
      await openBuilder(page, BASE, source);
      const key = await addBlock(page, 'text');
      await words(page, key, 'Original heading', 'Original words.');
      await publish(page);

      // ---- translated -----------------------------------------------------------------------
      await page.click('details.builder-locale > summary');
      await clickAndWait(page, `button[form="translate-${CODE}"]`);
      translation = idFrom(page.url());
      await openBuilder(page, BASE, translation);
      const before = await marks(page);
      report.verdict('a fresh translation has nothing marked', translation !== null && before === 0, `translation ${translation}, marked ${before}`);

      // ---- the original changes ---------------------------------------------------------------
      await openBuilder(page, BASE, source);
      await words(page, await blockKey(page, 'text'), 'Changed heading', null);
      await publish(page);

      // ---- the translation shows it ------------------------------------------------------------
      await openBuilder(page, BASE, translation);
      const marked = await page.evaluate(() => {
        const frame = document.querySelector('[data-pb-canvas]');
        const block = frame.contentDocument.querySelector('[data-bx-stale]');
        return block ? frame.contentWindow.getComputedStyle(block).boxShadow : null;
      });
      const summary = await page.$eval('[data-pb-nothing] .inspector-notice', (n) => n.textContent.replace(/\s+/g, ' ').trim()).catch(() => '');
      await selectBlock(page, await blockKey(page, 'text'));
      await page.$eval('[data-pb-inspector] .stale-original', (d) => { d.open = true; }).catch(() => {});
      const notice = await page.$eval('[data-pb-inspector] .stale-notice', (n) => n.textContent.replace(/\s+/g, ' ').trim()).catch(() => '');
      await report.shot(page, '01-stale-block', { fullPage: false });
      report.verdict('the changed block carries an edge on the page, at rest', marked !== null && marked !== 'none', `box-shadow: ${marked}`);
      report.verdict('the inspector says how many changed, and the block\'s notice says what the original says now',
        /: 1\./.test(summary) && /Changed heading/.test(notice), `summary: ${summary} — notice: ${notice.slice(0, 160)}`);

      // ---- marked as up to date ----------------------------------------------------------------
      await settle(page);
      await clickAndWait(page, '[data-pb-inspector] .stale-notice button');
      await openBuilder(page, BASE, translation);
      const after = await marks(page);
      const noticesLeft = await page.$$eval('.stale-notice, [data-pb-nothing] .inspector-notice', (n) => n.length);
      report.verdict('"Mark as up to date" clears the mark and the notice', after === 0 && noticesLeft === 0, `marked ${after}, notices ${noticesLeft}`);
    } finally {
      // ---- leave the site as found ----------------------------------------------------------
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
