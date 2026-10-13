/*
 * THE ADMIN IN THE SITE'S LANGUAGE, WHAT A SCRIPT AND A PHONE SEE (PLAN.md D-240).
 *
 * The admin speaks the site's main language; the PHP is the test suite's (admin_lang_test). Two
 * things are only in a browser:
 *   - window.boxletPlural(), admin.js's form for a count a script writes ("9 sekcija · 10
 *     blokova"): English and Croatian, 21 and 22 and 111 included, on any admin page, with the
 *     page's lang set for the question and put back;
 *   - the Pages and Media lists on a phone: D-218's tick box in front moved every column one to
 *     the right, and the phone's rules, which counted columns, hid the media's name and showed a
 *     page's status over its menu. Each row's name has room, nothing stands over the menu, and no
 *     cell without a heading is shown.
 *
 * ON THE COPY; it only reads.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

/** What a phone shows of a list: the shown headings, and per row each shown cell's box. */
const phoneTable = (page, selector) => page.$eval(selector, (table) => {
  const shown = (el) => getComputedStyle(el).display !== 'none';
  const heads = [...table.querySelectorAll('thead th')].map(shown);
  const rows = [...table.querySelectorAll('tbody tr')].slice(0, 6).map((tr) => [...tr.children].map((td, i) => ({
    i, shown: shown(td), left: td.getBoundingClientRect().left, right: td.getBoundingClientRect().right, width: td.getBoundingClientRect().width,
    overflow: td.scrollWidth > td.clientWidth + 1 && getComputedStyle(td).overflow === 'visible',
  })));
  return { heads, rows };
});

export default {
  name: 'admin-language',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('admin-language: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1280, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle2' });
    const forms = await page.evaluate(() => {
      const was = document.documentElement.lang;
      const say = (lang) => { document.documentElement.lang = lang; return [1, 2, 4, 5, 11, 12, 21, 22, 25, 111, 0].map((n) => window.boxletPlural(n)).join(' '); };
      const out = { en: say('en'), hr: say('hr') };
      document.documentElement.lang = was;
      return out;
    });
    report.verdict('a count a script writes takes its form in English: one for 1, other for the rest',
      forms.en === 'one other other other other other other other other other other', forms.en);
    report.verdict('and in Croatian: one for 1, 21; few for 2–4, 22; other for 5, 11, 12, 25, 111, 0',
      forms.hr === 'one few few other other other one few other other other', forms.hr);

    await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 1 });
    for (const [where, path, selector, nameAt] of [['Media', '/admin/media', '.media-table', 2], ['Pages', '/admin/pages', '.page-tree', 2]]) {
      await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle2' });
      const { heads, rows } = await phoneTable(page, selector);
      if (rows.length === 0) {
        report.fail(`admin-language: ${where} on a phone`, 'the list has no rows to measure; the copy should have the demo');
        continue;
      }
      const headless = rows.flatMap((cells) => cells.filter((c) => c.shown && heads[c.i] === false && c.i !== 0 && c.i !== cells.length - 1).map((c) => c.i));
      const names = rows.map((cells) => Math.round(cells[nameAt].width));
      const over = rows.flatMap((cells) => {
        const shownCells = cells.filter((c) => c.shown);
        return shownCells.slice(1).filter((c, k) => c.left < shownCells[k].right - 1).map((c) => c.i);
      });
      report.verdict(`${where} on a phone: no cell is shown whose heading is hidden`, headless.length === 0, headless.length ? `cells ${[...new Set(headless)].join(', ')}` : 'none');
      report.verdict(`${where} on a phone: each name has room to be read (60px or more)`, names.every((w) => w >= 60), `${names.join(', ')}px`);
      report.verdict(`${where} on a phone: no cell starts before the one before it ends`, over.length === 0, over.length ? `cells ${[...new Set(over)].join(', ')}` : 'none');
      await page.evaluate((s) => document.querySelector(s).scrollIntoView(), selector);
      await report.shot(page, `${where.toLowerCase()}-phone`, { fullPage: false });
    }
  },
};
