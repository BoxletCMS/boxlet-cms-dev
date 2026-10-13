/*
 * PAGES INTO ANOTHER LANGUAGE FROM THE PAGES LIST (PLAN.md D-247, the owner).
 *
 * "Copy into language" stays off until a page is ticked; two English pages with no Croatian
 * version are ticked, copied, and said so; each has its Croatian draft, and the row's menu now
 * opens it where it offered Translate before. The drafts are then deleted through the list's
 * own Delete selected, so the copy is left as it was found.
 *
 * ON THE COPY. It makes two pages and deletes them in the same run.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, clickAndWait } from '../harness.mjs';

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
    const off = await page.$eval('[data-bulk-also]', (b) => b.disabled);
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
    const on = await page.$eval('[data-bulk-also]', (b) => !b.disabled);
    report.verdict('Copy into language is off with nothing ticked, and on with two', off && on, `off ${off}, on ${on}`);
    await report.shot(page, '01-ticked', { fullPage: false });

    await clickAndWait(page, '#pages-bulk [data-bulk-also]');
    const flash = await page.$eval('.notice, [role="status"]', (n) => n.textContent.trim()).catch(() => '');
    report.verdict('it says two were copied', /\b2\b/.test(flash), flash);

    await page.goto(`${BASE}/admin/pages?lang=${target.value}`, { waitUntil: 'networkidle2' });
    const made = await page.evaluate((ids) => {
      const rows = [...document.querySelectorAll('.page-tree tbody tr')];
      return rows.filter((row) => [...row.querySelectorAll('.row-menu a')].some((a) => ids.some((id) => a.getAttribute('href') === `/admin/pages/${id}`)))
        .map((row) => ({ id: row.querySelector('input[data-bulk-pick]').value, draft: /draft/i.test(row.className + row.textContent) }));
    }, picked);
    report.verdict(`each has its ${target.label} version, a draft, whose menu leads back to the original`, made.length === 2, JSON.stringify(made));

    // Put back: the two drafts deleted through Delete selected.
    for (const row of made) {
      await page.click(`input[data-bulk-pick][value="${row.id}"]`);
    }
    if (made.length > 0) {
      await clickAndWait(page, '#pages-bulk [data-bulk-delete]');
    }
    await page.goto(`${BASE}/admin/pages?lang=en`, { waitUntil: 'networkidle2' });
    const back = await page.evaluate((ids) => ids.every((id) => {
      const row = document.querySelector(`input[data-bulk-pick][value="${id}"]`)?.closest('tr');
      return row && row.querySelector('.row-menu form[action$="/translate"]');
    }), picked);
    report.verdict('the drafts are deleted: the originals offer Translate again', back, '');
  },
};
