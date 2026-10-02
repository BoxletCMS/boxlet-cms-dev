/*
 * The language switcher and hreflang on the front end (PLAN.md D-043, step 4).
 *
 * What a visitor does: on a page that exists in two languages, press the other language in
 * the footer and land on the same page in it — then press back. What a search engine reads
 * is checked beside it: each version names the other.
 *
 * LEAVES THE SITE AS FOUND: the language is added for this run, the home page's translation
 * made and published through the builder, and both removed again at the end. The language
 * is the first one Settings offers that the copy does not have.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, clickAndWait, openBuilder, publish } from '../harness.mjs';

// A language the copy does not have yet, read off Settings when the run starts (D-174:
// the demo already has hr, which made this skip on every run).
let CODE = '';
const HOME = 1;

const alternates = (page) => page.$$eval('link[rel="alternate"]', (links) => links.map((l) => `${l.hreflang} ${new URL(l.href).pathname}`));

export default {
  name: 'switcher',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('switcher: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle2' });
    CODE = await page.$$eval('#language-code option', (options) => (options.find((o) => o.value !== '') || {}).value || '');
    if (CODE === '') {
      report.fail('switcher: a language to add', 'Settings offers no language the site does not have');
      return;
    }
    await page.select('#language-code', CODE);
    await clickAndWait(page, 'form.language-add button[type="submit"]');

    let made = null;
    try {
      // ---- the home page, translated and published ------------------------------------------
      await openBuilder(page, BASE, HOME);
      await page.click('details.builder-locale > summary');
      await clickAndWait(page, `button[form="translate-${CODE}"]`);
      made = Number((page.url().match(/\/admin\/pages\/(\d+)$/) || [])[1]) || null;
      await openBuilder(page, BASE, made);
      await publish(page);

      // ---- a visitor switches ---------------------------------------------------------------
      await page.setViewport({ width: 1400, height: 900, deviceScaleFactor: 2 });
      await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
      const onEnglish = await alternates(page);
      const link = await page.$eval(`.locale-switcher a[hreflang="${CODE}"]`, (a) => a.getAttribute('href')).catch(() => null);
      await page.$eval('.locale-switcher', (nav) => nav.scrollIntoView({ block: 'center' }));
      await report.shot(page, '01-footer-switcher', { fullPage: false });
      report.verdict('the footer offers the other language, leading to this page in it', link === `/${CODE}/`, `href ${link}`);
      report.verdict('the page names both versions for search engines, the main one as the default',
        onEnglish.includes(`en /`) && onEnglish.includes(`${CODE} /${CODE}/`) && onEnglish.includes('x-default /'), onEnglish.join(', '));

      await clickAndWait(page, `.locale-switcher a[hreflang="${CODE}"]`);
      const there = await page.evaluate(() => ({ path: location.pathname, lang: document.documentElement.lang }));
      const back = await page.$eval('.locale-switcher a[hreflang="en"]', (a) => a.getAttribute('href')).catch(() => null);
      report.verdict('pressing it lands on the same page in that language, which leads back', there.path === `/${CODE}/` && there.lang === CODE && back === '/',
        `${JSON.stringify(there)}, back to ${back}`);
    } finally {
      // ---- leave the site as found ----------------------------------------------------------
      if (made !== null && made !== HOME) {
        await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
        await page.$eval(`tr[data-page-id="${made}"] details.row-menu`, (d) => { d.open = true; }).catch(() => {});
        await page.$eval(`form[action$="/pages/${made}/delete"] button`, (b) => b.removeAttribute('data-confirm')).catch(() => {});
        await clickAndWait(page, `form[action$="/pages/${made}/delete"] button`).catch(() => {});
      }
      await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle2' });
      await page.$eval(`form[action$="/languages/${CODE}/delete"] button`, (b) => b.removeAttribute('data-confirm')).catch(() => {});
      await clickAndWait(page, `form[action$="/languages/${CODE}/delete"] button`).catch(() => {});
      const left = await page.$$eval('#languages code', (codes) => codes.map((c) => c.textContent));
      await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
      // The demo has other languages, so the switcher stays; the one added is gone from it.
      const switcher = await page.$(`.locale-switcher a[hreflang="${CODE}"]`);
      report.verdict('the translation and the language are gone again, and so is its link in the switcher', !left.includes(CODE) && switcher === null, left.join(', '));
    }
  },
};
