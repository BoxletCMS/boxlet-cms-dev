/*
 * WHAT A PAGE BRINGS INTO ANOTHER LANGUAGE (PLAN.md D-262, the owner).
 *
 * Workshops (in the main menu, with a sign-up form) and Hire the space (in the footer menu,
 * with an enquiry form) are copied into Croatian from the pages list, without AI. Each takes
 * its place in the Croatian menu of the same name, and its form block shows the Croatian
 * version of its form: the demo's own, which remembers its English original, so no second
 * form is made. Navigation offers "Match … with …", and pressing it finds nothing missing.
 * The two copies are deleted through the list in the same run, and their menu items go with
 * them (Menu::pageDeleted), so the copy is left as it was found.
 *
 * ON THE COPY, freshly installed: the demo's links between languages are made at install.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, clickAndWait } from '../harness.mjs';

export default {
  name: 'language-copy',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('language-copy: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1280, height: 900, deviceScaleFactor: 1 });

    const forms = async () => {
      await page.goto(`${BASE}/admin/forms`, { waitUntil: 'networkidle2' });
      return page.$$eval('table tbody tr', (rows) => rows.length).catch(() => -1);
    };
    const before = await forms();
    // Which Croatian menus there are now, so one this run makes is deleted with what it holds.
    await page.goto(`${BASE}/admin/navigation`, { waitUntil: 'networkidle2' });
    const hadMenus = await page.$$eval('.navigation-menus table tbody tr', (rows) => rows
      .filter((row) => row.children[1]?.textContent.trim() === 'hr').map((row) => row.querySelector('td a')?.textContent.trim())).catch(() => []);

    // The two English pages, by their addresses, and that they have no Croatian version yet.
    await page.goto(`${BASE}/admin/pages?lang=en`, { waitUntil: 'networkidle2' });
    const picked = await page.evaluate(() => [...document.querySelectorAll('.page-tree tbody tr')]
      .filter((row) => /^\/(workshops|hire)$/.test(row.querySelector('td.address')?.textContent.trim() || '')
        && row.querySelector('.row-menu form[action$="/translate"]'))
      .map((row) => row.querySelector('input[data-bulk-pick]').value));
    if (picked.length !== 2) {
      report.fail('language-copy: the demo\'s Workshops and Hire the space, with no Croatian version', `found ${picked.length}`);
      return;
    }
    for (const id of picked) {
      await page.click(`input[data-bulk-pick][value="${id}"]`);
    }
    await page.select('#bulk-copy-to', 'hr');
    await clickAndWait(page, '#pages-bulk button[formaction$="/pages/copy"]');

    // The Croatian copies, found through the originals' rows.
    await page.goto(`${BASE}/admin/pages?lang=hr`, { waitUntil: 'networkidle2' });
    const made = await page.evaluate(() => [...document.querySelectorAll('.page-tree tbody tr')]
      .filter((row) => /^\/hr\/(workshops|hire)$/.test(row.querySelector('td.address')?.textContent.trim() || ''))
      .map((row) => ({ id: row.querySelector('input[data-bulk-pick]').value, title: row.querySelector('.row-title, td:nth-child(3)')?.textContent.trim() })));
    report.verdict('both copied into Croatian', made.length === 2, JSON.stringify(made));

    // The Croatian menus: each copy in the menu its original is in.
    await page.goto(`${BASE}/admin/navigation`, { waitUntil: 'networkidle2' });
    const menus = await page.$$eval('.navigation-menus table tbody tr', (rows) => rows.map((row) => ({
      name: row.querySelector('td a')?.textContent.trim(), locale: row.children[1]?.textContent.trim(), href: row.querySelector('td a')?.getAttribute('href'),
    }))).catch(() => []);
    const itemsOf = async (name) => {
      const menu = menus.find((m) => m.name === name && m.locale === 'hr');
      if (!menu) return null;
      await page.goto(`${BASE}${menu.href}`, { waitUntil: 'networkidle2' });
      return page.evaluate(() => document.querySelector('main').textContent.replace(/\s+/g, ' '));
    };
    const main = await itemsOf('Main');
    const footer = await itemsOf('Footer');
    report.verdict('Workshops is in the Croatian Main menu, Hire the space in the Croatian Footer',
      main !== null && /Workshops/.test(main) && footer !== null && /Hire the space/.test(footer),
      `Main ${main ? 'found' : 'missing'}, Footer ${footer ? 'found' : 'missing'}`);
    if (main) {
      await page.goto(`${BASE}${menus.find((m) => m.name === 'Main' && m.locale === 'hr').href}`, { waitUntil: 'networkidle2' });
      await report.shot(page, '01-croatian-main-menu', { fullPage: false });
    }

    // The forms: the demo's Croatian ones, no new one.
    const after = await forms();
    report.verdict('no second form: the copies use the demo\'s Croatian forms', after === before && before > 0, `forms before ${before}, after ${after}`);

    // Navigation's Match: present, and with nothing missing it says so.
    await page.goto(`${BASE}/admin/navigation`, { waitUntil: 'networkidle2' });
    const match = await page.$('form[action$="/admin/navigation/match"] button');
    if (match) {
      await page.$eval('form[action$="/admin/navigation/match"]', (f) => f.scrollIntoView({ block: 'center' }));
      await report.shot(page, '02-match', { fullPage: false });
      await clickAndWait(page, 'form[action$="/admin/navigation/match"] button');
    }
    const said = await page.$eval('.notice', (n) => n.textContent.trim()).catch(() => '');
    report.verdict('Match is offered, and finds nothing missing', match !== null && /already|već/i.test(said), said);

    // Put back: the two copies deleted, their menu items with them.
    await page.goto(`${BASE}/admin/pages?lang=hr`, { waitUntil: 'networkidle2' });
    for (const row of made) {
      await page.click(`input[data-bulk-pick][value="${row.id}"]`);
    }
    if (made.length > 0) {
      await clickAndWait(page, '#pages-bulk [data-bulk-delete]');
    }
    const mainAfter = await itemsOf('Main');
    report.verdict('the copies deleted, and their items gone from the Croatian menus', mainAfter !== null && !/Workshops/.test(mainAfter), '');
    // A Croatian menu this run made (the demo has no Croatian Footer items), deleted.
    for (const menu of menus.filter((m) => m.locale === 'hr' && !hadMenus.includes(m.name))) {
      const id = menu.href.split('/').pop();
      await page.goto(`${BASE}/admin/navigation`, { waitUntil: 'networkidle2' });
      await clickAndWait(page, `form[action$="/admin/menus/${id}/delete"] button`);
    }
  },
};
