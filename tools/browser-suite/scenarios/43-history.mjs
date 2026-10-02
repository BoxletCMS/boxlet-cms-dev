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
import { login, clickAndWait, retype, openBuilder, openTab, publish } from '../harness.mjs';

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
    await clickAndWait(page, 'div.editor-actions button[name="action"][value="publish"]', 30000);
    report.verdict('the save went through', await titleNow() === marker,
      `title is now ${JSON.stringify(await titleNow())}`);

    // ---- the list, in the builder's rail, Page tab (D-175) ---------------------------------------
    const history = async () => {
      await openBuilder(page, BASE, PAGE);
      await openTab(page, 'page');
      return page.evaluate(() => {
        const note = document.querySelector('[data-pb-history-note]');
        return {
          rows: [...document.querySelectorAll('.pb-history [data-pb-history-when]')].map((s) => s.textContent.trim()),
          // Hints are off until asked for (D-087); this sentence is not a hint, because it
          // says what Restore does rather than what a field means, and somebody deciding
          // whether to press it must be able to read it.
          notePainted: note ? note.offsetHeight > 0 : false,
          note: note ? note.textContent.trim() : '',
        };
      });
    };
    const list = await history();
    report.verdict('a saved page offers its earlier versions', list.rows.length > 0,
      `${list.rows.length} row(s): ${list.rows.join(' | ')}`);
    report.verdict('what Restore does is on the screen, not behind the hints toggle',
      list.notePainted && list.note.length > 0, JSON.stringify(list.note));
    // Five saves in one working session share a minute, and a list that cannot tell its own
    // rows apart is not a list. The times carry seconds for that reason.
    report.verdict('the rows can be told apart',
      new Set(list.rows).size === list.rows.length && list.rows.every((r) => /:\d\d:\d\d/.test(r)),
      list.rows.join(' | '));
    if (list.rows.length === 0) {
      return;
    }

    // ---- pressing it puts the page back, in the draft ------------------------------------------
    // The FIRST row: newest first, so this is the version the page had before the save above.
    await page.click('.pb-history li:first-child [data-pb-restore]');
    await page.waitForFunction((t) => window.pb.doc.title === t, { timeout: 15000 }, before).catch(() => {});
    const drafted = await page.evaluate(() => ({ title: window.pb.doc.title, state: document.querySelector('[data-pb-state]').textContent.trim() }));
    report.verdict('restoring an earlier version puts it in the draft, the page unchanged until Publish',
      drafted.title === before && /unpublished/i.test(drafted.state),
      `was ${JSON.stringify(before)}, saved as ${JSON.stringify(marker)}, restored to ${JSON.stringify(drafted)}`);

    // Published, the page is back as it was found; and the version it replaced is on the list.
    await publish(page);
    const restored = await titleNow();
    const after = (await history()).rows.length;
    report.verdict('published, the page is as it was found, and the replaced version is kept',
      restored === before && after >= list.rows.length, `title ${JSON.stringify(restored)}; ${list.rows.length} row(s) before, ${after} after`);
  },
};
