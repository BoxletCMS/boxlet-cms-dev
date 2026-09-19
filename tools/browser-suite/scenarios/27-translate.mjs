/*
 * Translating a page from the builder's language menu (PLAN.md D-043, step 2).
 *
 * What a person does: open a page in the editor, open the language menu, press Translate
 * beside a language, and land in that language's version — a draft with the original text
 * in it — whose own menu leads back.
 *
 * LEAVES THE SITE AS FOUND: it adds a language the site does not have, deletes the
 * translation it made through the pages list, and removes the language again, all through
 * the admin. It stops before touching anything if the language is already there.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, clickAndWait } from '../harness.mjs';

const PAGE = 1;
const CODE = 'hr';

export default {
  name: 'translate',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('translate: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }

    // ---- a second language, added for this run ---------------------------------------------
    await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle2' });
    const free = await page.$$eval('#language-code option', (options, code) => options.some((o) => o.value === code), CODE);
    if (!free) {
      report.skip('translate: a language to add', `${CODE} is already on the site; not touching the owner's`);
      return;
    }
    await page.select('#language-code', CODE);
    await clickAndWait(page, 'form.language-add button[type="submit"]');

    let made = null;
    try {
      // ---- the menu offers it -------------------------------------------------------------
      await page.setViewport({ width: 1600, height: 1000, deviceScaleFactor: 2 });
      await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
      await page.click('details.builder-locale > summary');
      const offered = await page.$$eval('.menu-popover-list li', (items) => items.map((li) => li.textContent.replace(/\s+/g, ' ').trim()));
      await report.shot(page, '01-menu', { fullPage: false });
      report.verdict('the language menu lists this page\'s language and offers to translate into the other',
        offered.length === 2 && /this page/.test(offered[0]) && /Translate/.test(offered[1]), JSON.stringify(offered));

      // ---- translate ----------------------------------------------------------------------
      await page.evaluate(() => { window.onbeforeunload = null; });
      await clickAndWait(page, `button[form="translate-${CODE}"]`);
      made = Number((page.url().match(/\/admin\/pages\/(\d+)$/) || [])[1]) || null;
      const landed = await page.evaluate(() => ({
        flash: Array.from(document.querySelectorAll('[role="status"], .flash, .notice')).map((e) => e.textContent.trim()).join(' | '),
        title: (document.querySelector('[data-title-echo]') || {}).textContent || '',
        language: ((document.querySelector('details.builder-locale > summary') || {}).textContent || '').replace(/\s+/g, ' ').trim(),
      }));
      await page.waitForFunction(() => {
        const frame = document.querySelector('iframe[data-canvas]');
        return frame && frame.contentDocument && frame.contentDocument.querySelectorAll('[data-bx-blocks] > section').length > 0;
      }, { timeout: 20000 }).catch(() => {});
      await report.shot(page, '02-translation', { fullPage: false });
      report.verdict('Translate opens the new version, says it is a draft to translate, and names its language',
        made !== null && made !== PAGE && /draft/.test(landed.flash) && landed.language.includes(CODE.toUpperCase()),
        JSON.stringify(landed));

      // ---- and its own menu leads back ------------------------------------------------------
      await page.click('details.builder-locale > summary');
      const back = await page.$eval('.menu-popover-list a', (a) => a.getAttribute('href')).catch(() => null);
      report.verdict('the translation\'s menu leads back to the original', back === `/admin/pages/${PAGE}`, `link: ${back}`);
    } finally {
      // ---- leave the site as found ----------------------------------------------------------
      await page.evaluate(() => { window.onbeforeunload = null; }).catch(() => {});
      if (made !== null && made !== PAGE) {
        await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
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
