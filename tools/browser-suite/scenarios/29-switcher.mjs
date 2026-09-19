/*
 * The language switcher and hreflang on the front end (PLAN.md D-043, step 4).
 *
 * What a visitor does: on a page that exists in two languages, press the other language in
 * the footer and land on the same page in it — then press back. What a search engine reads
 * is checked beside it: each version names the other.
 *
 * LEAVES THE SITE AS FOUND: the language is added for this run, the home page's translation
 * made and published through the admin, and both removed again at the end. It stops before
 * touching anything if the language is already there.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, clickAndWait } from '../harness.mjs';

const CODE = 'hr';
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
    const free = await page.$$eval('#language-code option', (options, code) => options.some((o) => o.value === code), CODE);
    if (!free) {
      report.skip('switcher: a language to add', `${CODE} is already on the site; not touching the owner's`);
      return;
    }
    await page.select('#language-code', CODE);
    await clickAndWait(page, 'form.language-add button[type="submit"]');

    let made = null;
    try {
      // ---- the home page, translated and published ------------------------------------------
      await page.goto(`${BASE}/admin/pages/${HOME}`, { waitUntil: 'networkidle2' });
      await page.evaluate(() => { window.onbeforeunload = null; });
      await page.click('details.builder-locale > summary');
      await clickAndWait(page, `button[form="translate-${CODE}"]`);
      made = Number((page.url().match(/\/admin\/pages\/(\d+)$/) || [])[1]) || null;
      await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
      await clickAndWait(page, `form[action$="/pages/${made}/status"] button`);

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
      await page.evaluate(() => { window.onbeforeunload = null; }).catch(() => {});
      if (made !== null && made !== HOME) {
        await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
        await page.$eval(`form[action$="/pages/${made}/delete"] button`, (b) => b.removeAttribute('data-confirm')).catch(() => {});
        await clickAndWait(page, `form[action$="/pages/${made}/delete"] button`).catch(() => {});
      }
      await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle2' });
      await page.$eval(`form[action$="/languages/${CODE}/delete"] button`, (b) => b.removeAttribute('data-confirm')).catch(() => {});
      await clickAndWait(page, `form[action$="/languages/${CODE}/delete"] button`).catch(() => {});
      const left = await page.$$eval('#languages code', (codes) => codes.map((c) => c.textContent));
      await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
      const switcher = await page.$('.locale-switcher');
      report.verdict('the translation and the language are gone again, and so is the switcher', !left.includes(CODE) && switcher === null, left.join(', '));
    }
  },
};
