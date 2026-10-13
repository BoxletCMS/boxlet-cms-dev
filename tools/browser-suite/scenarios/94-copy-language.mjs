/*
 * PAGES INTO ANOTHER LANGUAGE FROM THE PAGES LIST (PLAN.md D-247, the owner).
 *
 * "Copy into language" stays off until a page is ticked; two English pages with no Croatian
 * version are ticked, copied, and said so; each has its Croatian draft, and the row's menu now
 * opens it where it offered Translate before. The drafts are then deleted through the list's
 * own Delete selected, so the copy is left as it was found — after Publish selected has put both
 * on the site (D-254); and the list shown in that language no longer offers to copy into it.
 *
 * And the editor's language menu is a panel over the page, not a bare list: its styles went with
 * the old editor's sheet in D-175 and nobody saw it for two weeks (found with D-252).
 *
 * ON THE COPY. It makes two pages, publishes them and deletes them in the same run.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, clickAndWait, openBuilder } from '../harness.mjs';

export default {
  name: 'copy-language',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('copy-language: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1280, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/pages?lang=en`, { waitUntil: 'networkidle2' });
    const target = await page.$eval('#bulk-copy-to', (s) => ({ value: s.value, label: s.options[s.selectedIndex].textContent.trim() })).catch(() => null);
    if (target === null) {
      report.skip('copy-language: a second language', 'the copy has one language, so nothing can be copied');
      return;
    }
    const COPY = '#pages-bulk button[formaction$="/pages/copy"]';
    const PUBLISH = '#pages-bulk button[formaction$="/pages/publish"]';
    const off = await page.$eval(COPY, (b) => b.disabled) && await page.$eval(PUBLISH, (b) => b.disabled);
    // Two English rows that have no version in that language yet: their menus offer Translate.
    const picked = await page.evaluate(() => [...document.querySelectorAll('.page-tree tbody tr')]
      .filter((row) => row.querySelector('.row-menu form[action$="/translate"]'))
      .slice(0, 2)
      .map((row) => row.querySelector('input[data-bulk-pick]').value));
    if (picked.length < 2) {
      report.skip('copy-language: two pages to copy', 'fewer than two English pages lack a version in the other language');
      return;
    }
    for (const id of picked) {
      await page.click(`input[data-bulk-pick][value="${id}"]`);
    }
    const on = await page.$eval(COPY, (b) => !b.disabled) && await page.$eval(PUBLISH, (b) => !b.disabled);
    report.verdict('Copy into language and Publish selected are off with nothing ticked, and on with two', off && on, `off ${off}, on ${on}`);
    await report.shot(page, '01-ticked', { fullPage: false });

    await clickAndWait(page, COPY);
    const flash = await page.$eval('.notice, [role="status"]', (n) => n.textContent.trim()).catch(() => '');
    report.verdict('it says two were copied', /\b2\b/.test(flash), flash);

    await page.goto(`${BASE}/admin/pages?lang=${target.value}`, { waitUntil: 'networkidle2' });
    const made = await page.evaluate((ids) => {
      const rows = [...document.querySelectorAll('.page-tree tbody tr')];
      return rows.filter((row) => [...row.querySelectorAll('.row-menu a')].some((a) => ids.some((id) => a.getAttribute('href') === `/admin/pages/${id}`)))
        .map((row) => ({ id: row.querySelector('input[data-bulk-pick]').value, draft: /draft/i.test(row.className + row.textContent) }));
    }, picked);
    report.verdict(`each has its ${target.label} version, a draft, whose menu leads back to the original`, made.length === 2, JSON.stringify(made));

    // Shown in that language, the bar copies only into another one (D-254).
    const into = await page.$$eval('#bulk-copy-to option', (options) => options.map((o) => o.value)).catch(() => []);
    report.verdict(`the list shown in ${target.label} does not offer to copy into ${target.label}`, into.length > 0 && !into.includes(target.value), JSON.stringify(into));

    // The two drafts published together, then deleted together: the copy as it was found.
    for (const row of made) {
      await page.click(`input[data-bulk-pick][value="${row.id}"]`);
    }
    if (made.length > 0) {
      await clickAndWait(page, PUBLISH);
      const said = await page.$eval('.notice, [role="status"]', (n) => n.textContent.trim()).catch(() => '');
      const live = await page.evaluate((ids) => ids.map((id) => {
        const row = document.querySelector(`input[data-bulk-pick][value="${id}"]`)?.closest('tr');
        return row ? row.querySelector('.status-cell .status')?.className : null;
      }), made.map((row) => row.id));
      await report.shot(page, '01b-published', { fullPage: false });
      report.verdict('Publish selected publishes both, and says so', /\b2\b/.test(said) && live.every((s) => s && s.includes('status-published')), `${said} ${JSON.stringify(live)}`);
      for (const row of made) {
        await page.click(`input[data-bulk-pick][value="${row.id}"]`);
      }
      await clickAndWait(page, '#pages-bulk [data-bulk-delete]');
    }
    await page.goto(`${BASE}/admin/pages?lang=en`, { waitUntil: 'networkidle2' });
    const back = await page.evaluate((ids) => ids.every((id) => {
      const row = document.querySelector(`input[data-bulk-pick][value="${id}"]`)?.closest('tr');
      return row && row.querySelector('.row-menu form[action$="/translate"]');
    }), picked);
    report.verdict('the drafts are deleted: the originals offer Translate again', back, '');

    // The editor's language menu, opened: a panel standing over the page, its items without bullets.
    await openBuilder(page, BASE, picked[0]);
    await page.click('details.builder-locale summary');
    const menu = await page.$eval('details.builder-locale .menu-popover-list', (list) => {
      const style = getComputedStyle(list);
      return { position: style.position, bullets: style.listStyleType, items: list.querySelectorAll('li').length };
    }).catch(() => null);
    await report.shot(page, '02-language-menu', { fullPage: false });
    report.verdict('the editor\'s language menu is a panel over the page, without bullets',
      menu !== null && menu.position === 'absolute' && menu.bullets === 'none' && menu.items >= 2, JSON.stringify(menu));
  },
};
