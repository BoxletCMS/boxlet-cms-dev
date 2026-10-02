/*
 * Translating a page from the builder's language menu (PLAN.md D-043, step 2).
 *
 * What a person does: open a page in the editor, open the language menu, press Translate
 * beside a language, and land in that language's version — a draft with the original text
 * in it — whose own menu leads back.
 *
 * LEAVES THE SITE AS FOUND: it adds a language the site does not have, deletes the
 * translation it made through the pages list, and removes the language again, all through
 * the admin. The language is the first one Settings offers that the copy does not have.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, clickAndWait, openBuilder } from '../harness.mjs';

const PAGE = 1;
// A language the copy does not have yet, read off Settings when the run starts (D-174:
// the demo already has hr, which made this skip on every run).
let CODE = '';

export default {
  name: 'translate',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('translate: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }

    // ---- a second language, added for this run ---------------------------------------------
    await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle2' });
    CODE = await page.$$eval('#language-code option', (options) => (options.find((o) => o.value !== '') || {}).value || '');
    if (CODE === '') {
      report.fail('translate: a language to add', 'Settings offers no language the site does not have');
      return;
    }
    await page.select('#language-code', CODE);
    await clickAndWait(page, 'form.language-add button[type="submit"]');

    let made = null;
    try {
      // ---- the menu offers it -------------------------------------------------------------
      await page.setViewport({ width: 1600, height: 1000, deviceScaleFactor: 1 });
      await openBuilder(page, BASE, PAGE);
      await page.click('details.builder-locale > summary');
      const offered = await page.$$eval('.menu-popover-list li', (items) => items.map((li) => li.textContent.replace(/\s+/g, ' ').trim()));
      await report.shot(page, '01-menu', { fullPage: false });
      report.verdict('the language menu lists this page\'s language and offers to translate into the one added',
        /this page/.test(offered[0] || '') && offered.some((o) => /Translate/.test(o)), JSON.stringify(offered));

      // ---- translate ----------------------------------------------------------------------
      await clickAndWait(page, `button[form="translate-${CODE}"]`);
      made = Number((page.url().match(/\/admin\/pages\/(\d+)$/) || [])[1]) || null;
      await page.waitForFunction(() => window.pb && window.pb.canvas && window.pb.canvas.main(), { timeout: 20000 }).catch(() => {});
      const landed = await page.evaluate(() => ({
        flash: Array.from(document.querySelectorAll('[role="status"].notice, .flash')).map((e) => e.textContent.trim()).join(' | '),
        title: (document.querySelector('[data-pb-title]') || {}).textContent || '',
        language: ((document.querySelector('details.builder-locale > summary') || {}).textContent || '').replace(/\s+/g, ' ').trim(),
        bands: document.querySelector('[data-pb-canvas]').contentDocument.querySelectorAll('main > [data-bx-section]').length,
      }));
      await report.shot(page, '02-translation', { fullPage: false });
      report.verdict('Translate opens the new version, says it is a draft to translate, and names its language',
        made !== null && made !== PAGE && /draft/.test(landed.flash) && landed.language.includes(CODE.toUpperCase()) && landed.bands > 0,
        JSON.stringify(landed));

      // ---- and its own menu leads back ------------------------------------------------------
      await page.click('details.builder-locale > summary');
      const back = await page.$$eval('.menu-popover-list a', (links) => links.map((a) => a.getAttribute('href')));
      report.verdict('the translation\'s menu leads back to the original', back.includes(`/admin/pages/${PAGE}`), `links: ${back.join(', ')}`);
    } finally {
      // ---- leave the site as found ----------------------------------------------------------
      await page.evaluate(() => { window.onbeforeunload = null; }).catch(() => {});
      if (made !== null && made !== PAGE) {
        await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
        await page.$eval(`tr[data-page-id="${made}"] details.row-menu`, (d) => { d.open = true; }).catch(() => {});
        await page.$eval(`form[action$="/pages/${made}/delete"] button`, (b) => b.removeAttribute('data-confirm')).catch(() => {});
        await clickAndWait(page, `form[action$="/pages/${made}/delete"] button`).catch(() => {});
      }
      await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle2' });
      await page.$eval(`form[action$="/languages/${CODE}/delete"] button`, (b) => b.removeAttribute('data-confirm')).catch(() => {});
      await clickAndWait(page, `form[action$="/languages/${CODE}/delete"] button`).catch(() => {});
      const left = await page.$$eval('#languages code', (codes) => codes.map((c) => c.textContent));
      report.verdict('the page and the language are gone again', !left.includes(CODE), left.join(', '));
    }
  },
};
