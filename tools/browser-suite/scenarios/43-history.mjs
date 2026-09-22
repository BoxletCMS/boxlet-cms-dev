/*
 * Earlier versions of a page (PLAN.md D-088).
 *
 * ON THE COPY, because a restore really does replace a page: driving this against the
 * development site would roll its demo back to whatever an earlier run left, and the
 * owner looks at that site. revisions_test.php proves the behaviour on both drivers; what
 * only a browser can say is whether the list is drawn, whether the sentence that makes
 * Restore safe to press is actually on the screen, and whether pressing it works.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, clickAndWait, retype } from '../harness.mjs';

const PAGE = 1;
const wait = (ms = 900) => new Promise((resolve) => { setTimeout(resolve, ms); });

export default {
  name: 'history',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('history: log in', `could not log in; at ${page.url()}`);

      return;
    }

    // Through the PLAIN editor: this scenario is about what a save records, not about the
    // canvas, and the plain form is the shorter road to a save with a known change in it.
    const titleNow = async () => {
      await page.goto(`${BASE}/admin/pages/${PAGE}/form`, { waitUntil: 'networkidle2' });

      return page.$eval('input[name="title"]', (el) => el.value);
    };

    const before = await titleNow();
    const marker = `History ${Date.now()}`;
    await retype(page, 'input[name="title"]', marker);
    await clickAndWait(page, 'div.editor-actions button[name="action"][value="save"]', 30000);
    report.verdict('the save went through', await titleNow() === marker,
      `title is now ${JSON.stringify(await titleNow())}`);

    // ---- the list, in the visual editor's panel ---------------------------------------------
    await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
    await wait(2500);
    const list = await page.evaluate(() => {
      const box = document.querySelector('[data-page-history]');
      if (!box) return null;
      box.open = true;
      const note = box.querySelector('.history-note');

      return {
        rows: [...box.querySelectorAll('.history-item')].map((li) => li.querySelector('.history-when').textContent.trim()),
        // Hints are off until asked for (D-087); this sentence is not a hint, because it
        // says what Restore does rather than what a field means, and somebody deciding
        // whether to press it must be able to read it.
        notePainted: note ? note.offsetHeight > 0 : false,
        note: note ? note.textContent.trim() : '',
      };
    });

    if (list === null) {
      report.fail('a saved page offers its earlier versions', 'no history box in the panel');

      return;
    }
    report.verdict('a saved page offers its earlier versions', list.rows.length > 0,
      `${list.rows.length} row(s): ${list.rows.join(' | ')}`);
    report.verdict('what Restore does is on the screen, not behind the hints toggle',
      list.notePainted && list.note.length > 0, JSON.stringify(list.note));
    // Five saves in one working session share a minute, and a list that cannot tell its own
    // rows apart is not a list. The times carry seconds for that reason.
    report.verdict('the rows can be told apart',
      new Set(list.rows).size === list.rows.length && list.rows.every((r) => /:\d\d:\d\d/.test(r)),
      list.rows.join(' | '));

    // ---- pressing it puts the page back -----------------------------------------------------
    await page.evaluate(() => { document.querySelector('[data-page-history]').open = true; });
    await clickAndWait(page, '[data-page-history] button[name="action"]', 30000);
    const restored = await titleNow();
    report.verdict('restoring an earlier version puts the page back', restored === before,
      `was ${JSON.stringify(before)}, saved as ${JSON.stringify(marker)}, restored to ${JSON.stringify(restored)}`);

    // A restore is itself a save, which is what the note promises: the version it replaced
    // has to be on the list afterwards, or the promise is false.
    await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
    await wait(2500);
    const after = await page.evaluate(() => {
      const box = document.querySelector('[data-page-history]');
      box.open = true;

      return box.querySelectorAll('.history-item').length;
    });
    report.verdict('a restore can itself be undone, as the note promises',
      after >= list.rows.length, `${list.rows.length} row(s) before the restore, ${after} after`);
  },
};
