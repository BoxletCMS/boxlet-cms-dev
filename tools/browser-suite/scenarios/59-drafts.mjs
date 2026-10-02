/*
 * DRAFTS, PUBLISH AND DISCARD (PLAN.md D-173), as the owner meets them.
 *
 * The demo's "How we work" page: its heading changed and saved as a draft — the site still
 * shows the old one, the editors show the new one and say there are unpublished changes —
 * then published, then changed again and discarded. A design set's pattern is put in and
 * discarded too. What the PHP tests cannot answer is whether the buttons are where an owner
 * looks and whether the canvas draws the draft; this answers that.
 *
 * ON THE COPY (the owner's rule of 2026-10-02). It leaves the page as it found it, through
 * the editor: the heading put back and published.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, clickAndWait, retype, alerts } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const shot = (report, page, name) => report.shot(page, name, { fullPage: false });
const HEADING = '.block-list input[name$="[heading]"]';

export default {
  name: 'drafts',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('drafts: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1400, height: 1000, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
    const id = await page.$$eval('a[href^="/admin/pages/"]', (links) => {
      const link = links.find((a) => a.textContent.trim() === 'How we work' && /\/admin\/pages\/\d+$/.test(a.getAttribute('href')));
      return link ? link.getAttribute('href').split('/').pop() : '';
    });
    if (id === '') {
      report.fail('drafts: the demo\'s How we work page', 'not in the page list');
      return;
    }
    const publicHeading = async () => {
      await page.goto(`${BASE}/how-we-work`, { waitUntil: 'networkidle2' });
      return page.$eval('main h1, main h2', (h) => h.textContent.trim()).catch(() => '');
    };
    const editor = async () => {
      await page.goto(`${BASE}/admin/pages/${id}/form`, { waitUntil: 'networkidle2' });
      return {
        heading: await page.$eval(HEADING, (el) => el.value),
        state: await page.$eval('.page-header .status', (el) => el.textContent.trim()),
        discard: await page.$('.editor-actions button[value="discard"]') !== null,
      };
    };

    // A draft an earlier run left behind is discarded first, through the editor.
    if ((await editor()).discard) {
      await clickAndWait(page, '.editor-actions button[value="discard"]');
    }
    const original = (await editor()).heading;
    const before = await publicHeading();

    // ---- Save draft: the site does not change -----------------------------------------------
    await editor();
    await retype(page, HEADING, 'How we work, in draft');
    await clickAndWait(page, '.editor-actions button[value="save"]');
    const drafted = await editor();
    await shot(report, page, '01-editor-draft');
    const afterDraft = await publicHeading();
    report.verdict('Save draft keeps the change out of the site, and the editor says so',
      drafted.heading === 'How we work, in draft' && /unpublished/i.test(drafted.state) && drafted.discard && afterDraft === before,
      JSON.stringify({ drafted, before, afterDraft }));

    // ---- The canvas draws the draft ------------------------------------------------------------
    await page.goto(`${BASE}/admin/pages/${id}`, { waitUntil: 'networkidle2' });
    await wait(1200);
    const frame = page.frames().find((f) => /\/canvas$/.test(f.url()));
    const canvasHeading = frame ? await frame.$eval('section h1, section h2', (h) => h.textContent.trim()).catch(() => '') : '';
    const bar = await page.evaluate(() => ({
      state: (document.querySelector('[data-page-state]') || {}).textContent || '',
      publish: document.querySelector('.builder-bar button[value="publish"]') !== null,
      saveDraft: document.querySelector('.builder-bar button[value="save"]') !== null,
    }));
    await shot(report, page, '02-builder-draft');
    report.verdict('the builder\'s canvas draws the draft, and its bar offers Save draft and Publish',
      canvasHeading === 'How we work, in draft' && /unpublished/i.test(bar.state) && bar.publish && bar.saveDraft, JSON.stringify({ canvasHeading, bar }));

    // ---- Publish -------------------------------------------------------------------------------
    await editor();
    await clickAndWait(page, '.editor-actions button[value="publish"]');
    const published = await editor();
    const afterPublish = await publicHeading();
    report.verdict('Publish puts the draft on the site, and the page is Published again',
      afterPublish === 'How we work, in draft' && /^published$/i.test(published.state) && !published.discard, JSON.stringify({ published, afterPublish }));

    // ---- Discard, with a pattern put in ---------------------------------------------------------
    await editor();
    await retype(page, HEADING, 'A change to throw away');
    const offered = await page.$$eval('#pattern option', (options) => options.map((o) => o.value).filter(Boolean));
    if (offered.length > 0) {
      // The panel is folded, as an owner finds it: opened first.
      await page.click('.editor-patterns > summary');
      await page.select('#pattern', offered.find((v) => v.startsWith('set:')) || offered[0]);
      await clickAndWait(page, 'button[value="pattern-insert"]');
    } else {
      await clickAndWait(page, '.editor-actions button[value="save"]');
    }
    // Counted on the form: a save from either editor goes back to the builder.
    await editor();
    const typesOf = () => page.$$eval('.block-list [name$="[type]"]', (fields) => fields.map((f) => f.value));
    const withPattern = (await typesOf()).length;
    await shot(report, page, '03-editor-pattern');
    await page.goto(`${BASE}/admin/pages/${id}`, { waitUntil: 'networkidle2' });
    await wait(1200);
    await shot(report, page, '04-builder-pattern');
    await editor();
    await clickAndWait(page, '.editor-actions button[value="discard"]');
    const discarded = await editor();
    const afterDiscard = (await typesOf()).length;
    report.verdict('a pattern goes into the draft, and Discard takes the draft back to the published page',
      offered.length > 0 && withPattern > afterDiscard && discarded.heading === 'How we work, in draft' && !discarded.discard,
      JSON.stringify({ offered, withPattern, afterDiscard, discarded }));

    // ---- As it was --------------------------------------------------------------------------------
    await retype(page, HEADING, original);
    await clickAndWait(page, '.editor-actions button[value="publish"]');
    const restored = await publicHeading();
    report.verdict('the page is left as it was found', restored === before, `${restored} (was ${before})`);
    const shown = await alerts(page);
    report.verdict('no error was shown on the way', shown.length === 0, JSON.stringify(shown));
  },
};
