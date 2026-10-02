/*
 * THE BUILDER (PLAN.md D-175, README 4, `Page Builder Mockup.html`), as the owner meets it:
 * a page built from nothing with the quick inserter and the rail's Add, a band and a block
 * chosen and changed through the inspector, undo and redo over all of it, the draft saving
 * itself, a band hidden on a phone, Publish, and the rail folded on a narrow window.
 *
 * Clicks inside the canvas go through clickInCanvas(): the page is drawn at its device's width
 * and shown scaled, and Puppeteer's own clicks there ignore the scale (harness.mjs).
 *
 * ON THE COPY (the owner's rule of 2026-10-02). It makes its own page and deletes it at the end.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, clickAndWait, clickInCanvas, retype } from '../harness.mjs';

const STAMP = Date.now();
const TITLE = `Zz builder ${STAMP}`;
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const shot = (report, page, name) => report.shot(page, name, { fullPage: false });

/** What the builder holds and shows, read in one go. */
const state = (page) => page.evaluate(() => {
  const frame = document.querySelector('[data-pb-canvas]').contentDocument;
  return {
    sections: window.pb.doc.sections.length,
    // In the order of the page: the document's list of blocks is in the order they were made.
    blocks: window.pb.doc.sections.map((s) => window.pb.blocksIn(s.key).map((b) => b.type).join('+')).join(','),
    drawn: frame.querySelectorAll('main > [data-bx-section]').length,
    selection: window.pb.selection,
    inspector: (document.querySelector('[data-pb-inspector] .inspector-name') || {}).textContent || '',
    trail: document.querySelector('[data-pb-trail]').textContent.replace(/\s+/g, ' ').trim(),
    count: (document.querySelector('[data-pb-count]') || {}).textContent || '',
    save: document.querySelector('[data-pb-save]').getAttribute('data-state'),
    saveText: document.querySelector('[data-pb-save-text]').textContent,
    pill: document.querySelector('[data-pb-state]').textContent.trim(),
  };
});

export default {
  name: 'builder',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('builder: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    let pageId = null;
    let slug = '';
    try {
      // ---- a page of nothing ------------------------------------------------------------------
      await page.goto(`${BASE}/admin/pages/new`, { waitUntil: 'networkidle2' });
      await page.type('#page-title', TITLE);
      await page.select('#page-template', '');
      await clickAndWait(page, 'form.panel button[type="submit"]');
      pageId = Number((page.url().match(/\/admin\/pages\/(\d+)$/) || [])[1]) || null;
      await page.waitForFunction(() => window.pb && window.pb.canvas && window.pb.canvas.main(), { timeout: 20000 });
      await wait(800);
      const empty = await state(page);
      const shell = await page.evaluate(() => ({
        tabs: [...document.querySelectorAll('[data-pb-tab]')].map((b) => b.getAttribute('data-pb-tab')).join(','),
        saveButton: [...document.querySelectorAll('button')].some((b) => /save draft/i.test(b.textContent)),
        // The buttons with words; the icon buttons' words are for a screen reader.
        bar: [...document.querySelectorAll('.pb-bar-end .button:not([hidden])')].map((b) => b.textContent.trim()).join(' | '),
        nothing: document.querySelector('[data-pb-nothing]') !== null,
      }));
      report.verdict('the builder opens with Structure, Add and Page, nothing selected, and no Save button',
        shell.tabs === 'structure,add,page' && shell.nothing && !shell.saveButton && shell.bar === 'Publish',
        JSON.stringify({ ...shell, empty }));

      // ---- built with the inserter and the rail -------------------------------------------------
      await clickInCanvas(page, '.bx-add-end');
      await wait(600);
      const offered = await page.evaluate(() => document.querySelector('[data-pb-canvas]').contentDocument.querySelectorAll('.bx-inserter [data-bx-add-block]').length);
      await clickInCanvas(page, '.bx-inserter [data-bx-add-block="hero"]');
      await wait(1500);
      await page.click('[data-pb-tab="add"]');
      const where = await page.$eval('[data-pb-add-where]', (p) => p.textContent.trim());
      await page.click('[data-add-block="text"]');
      await wait(1500);
      await clickInCanvas(page, '.bx-plus', { index: 1 });
      await wait(600);
      await shot(report, page, '01-inserter');
      const pattern = await page.evaluate(() => {
        const b = document.querySelector('[data-pb-canvas]').contentDocument.querySelector('.bx-inserter [data-bx-add-pattern]');
        return b ? b.getAttribute('data-bx-add-pattern') : null;
      });
      if (pattern) {
        await clickInCanvas(page, `.bx-inserter [data-bx-add-pattern="${pattern}"]`);
      }
      await wait(2000);
      const built = await state(page);
      report.verdict('the quick inserter offers every block and the set\'s patterns, and puts them where it was opened',
        offered >= 10 && pattern !== null && built.sections === 3 && built.drawn === 3 && built.blocks.startsWith('hero,') && built.blocks.endsWith(',text') && built.selection && built.selection.kind === 'section' && built.inspector === 'Section 2',
        JSON.stringify({ offered, pattern, where, built }));

      // ---- undo and redo over structure ----------------------------------------------------------
      await page.click('[data-pb-tab="structure"]');
      await page.keyboard.down('Control');
      await page.keyboard.press('z');
      await page.keyboard.up('Control');
      await wait(1500);
      const undone = await state(page);
      await page.keyboard.down('Control');
      await page.keyboard.down('Shift');
      await page.keyboard.press('z');
      await page.keyboard.up('Shift');
      await page.keyboard.up('Control');
      await wait(1500);
      const redone = await state(page);
      report.verdict('undo takes the pattern back out and redo puts it back, on the canvas too',
        undone.sections === 2 && undone.drawn === 2 && redone.sections === 3 && redone.drawn === 3,
        JSON.stringify({ undone: [undone.sections, undone.drawn], redone: [redone.sections, redone.drawn] }));

      // ---- the draft saves itself ------------------------------------------------------------------
      await page.waitForFunction(() => document.querySelector('[data-pb-save]').getAttribute('data-state') === 'saved', { timeout: 15000 }).catch(() => {});
      const saved = await state(page);
      report.verdict('the draft saves itself and the bar says when', saved.save === 'saved' && /\d{2}:\d{2}/.test(saved.saveText), `${saved.save}: ${saved.saveText}`);

      // ---- a block: the inspector, the trail, the layout from the toolbar ---------------------------
      await clickInCanvas(page, '[data-bx-key]', { index: 0 });
      await wait(1500);
      const block = await state(page);
      await shot(report, page, '02-block');
      const layoutBefore = await page.evaluate(() => window.pb.doc.blocks[0].layout);
      const layouts = await page.evaluate(() => [...document.querySelector('[data-pb-canvas]').contentDocument.querySelectorAll('[data-bx-layout] option')].map((o) => o.value));
      await page.evaluate((value) => {
        const select = document.querySelector('[data-pb-canvas]').contentDocument.querySelector('[data-bx-layout]');
        select.value = value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }, layouts.find((l) => l !== layoutBefore));
      await wait(1500);
      const layoutAfter = await page.evaluate(() => ({ doc: window.pb.doc.blocks[0].layout, drawn: document.querySelector('[data-pb-canvas]').contentDocument.querySelector('[data-bx-key]').className }));
      report.verdict('a block selected on the canvas opens its inspector and the trail Page › Section › Block',
        block.selection && block.selection.kind === 'block' && block.inspector === 'Hero' && /^Page › .+ › Hero$/.test(block.trail),
        JSON.stringify(block));
      report.verdict('the toolbar\'s layout menu changes the block, and the canvas draws it',
        layoutAfter.doc !== layoutBefore && layoutAfter.drawn.includes(layoutAfter.doc),
        `${layoutBefore} → ${JSON.stringify(layoutAfter)}`);

      // ---- its words through All content ---------------------------------------------------------
      await page.$eval('#ins-content', (d) => { d.open = true; });
      await retype(page, '[data-pb-inspector] input[name$="[heading]"]', 'Built in the builder');
      await wait(2000);
      const typed = await page.evaluate(() => document.querySelector('[data-pb-canvas]').contentDocument.body.textContent.includes('Built in the builder'));
      report.verdict('words typed in All content reach the canvas', typed, String(typed));

      // ---- the shortcut inside a field stays with the field ---------------------------------------
      const undoBefore = await page.evaluate(() => window.pb.doc.sections.length);
      await page.focus('[data-pb-inspector] input[name$="[heading]"]');
      await page.keyboard.down('Control');
      await page.keyboard.press('z');
      await page.keyboard.up('Control');
      await wait(1200);
      const inField = await page.evaluate(() => ({ sections: window.pb.doc.sections.length, typed: document.querySelector('[data-pb-canvas]').contentDocument.body.textContent.includes('Built in the builder') }));
      report.verdict('⌘Z inside a field is the field\'s own, and leaves the page alone', inField.sections === undoBefore, JSON.stringify({ undoBefore, inField }));
      // The field's own undo took the typing back, as it should: typed again for what follows.
      await retype(page, '[data-pb-inspector] input[name$="[heading]"]', 'Built in the builder');
      await wait(2000);

      // ---- the block's toolbar: a copy beside it, and undo ---------------------------------------
      const blocksBefore = await page.evaluate(() => window.pb.doc.blocks.length);
      await clickInCanvas(page, '[data-bx-key]', { index: 0 });
      await wait(1200);
      await clickInCanvas(page, '.bx-toolbar-block [data-bx-action="block-copy"]');
      await wait(1500);
      const copied = await page.evaluate(() => ({ blocks: window.pb.doc.blocks.length, inBand: window.pb.blocksIn(window.pb.doc.sections[0].key).length }));
      await page.click('[data-pb-undo]');
      await wait(1500);
      const uncopied = await page.evaluate(() => window.pb.doc.blocks.length);
      report.verdict('the block\'s toolbar copies it into its own band, and undo takes the copy back',
        copied.blocks === blocksBefore + 1 && copied.inBand === 2 && uncopied === blocksBefore, JSON.stringify({ blocksBefore, copied, uncopied }));
      await clickInCanvas(page, '[data-bx-key]', { index: 0 });
      await wait(1200);

      // ---- a band: surface, hidden on a phone -----------------------------------------------------
      await page.click('[data-trail="section"]');
      await wait(1500);
      const sectionName = await page.$eval('[data-pb-inspector] .inspector-name', (h) => h.textContent.trim());
      await page.click('[data-pb-inspector] label.tile-option:has(input[name="s.style.surface"][value="contrast"])');
      await wait(1500);
      const surface = await page.evaluate(() => document.querySelector('[data-pb-canvas]').contentDocument.querySelector('main > [data-bx-section]').className);
      await page.$eval('#ins-advanced', (d) => { d.open = true; });
      await page.click('[data-pb-inspector] label.toggle-chip:has(input[name="s.style.hide_mobile"])');
      await wait(1500);
      await shot(report, page, '03-section');
      await page.click('[data-device="phone"]');
      await wait(1500);
      const striped = await page.evaluate(() => {
        const s = document.querySelector('[data-pb-canvas]').contentDocument.querySelector('.bx-hidden-here');
        return s ? s.textContent : null;
      });
      await shot(report, page, '04-phone');
      await page.click('[data-device="desktop"]');
      report.verdict('a band selected through the trail takes a surface, and the canvas draws it', /^Section 1$/.test(sectionName) && /surface-contrast/.test(surface), `${sectionName}: ${surface}`);
      report.verdict('a band hidden on a phone is striped over when the phone is looked at', striped !== null && /phone/i.test(striped), String(striped));

      // ---- two columns, from the band's inspector ----------------------------------------------------
      await clickInCanvas(page, '[data-bx-section]', { index: 2, at: 'bottom', side: 'left' });
      await wait(1500);
      await page.click('[data-pb-inspector] label.tile-option:has(input[name="s.layout"][value="halves"])');
      await wait(2000);
      const halves = await page.evaluate(() => {
        const band = document.querySelector('[data-pb-canvas]').contentDocument.querySelectorAll('main > [data-bx-section]')[2];
        return { classes: band ? band.className : '', columns: band ? band.querySelectorAll('.section-column').length : 0, layout: window.pb.doc.sections[2].layout };
      });
      report.verdict('two columns chosen in the band\'s inspector are drawn at once', halves.layout === 'halves' && halves.columns === 2, JSON.stringify(halves));

      // ---- delete and undo ---------------------------------------------------------------------------
      const before = await state(page);
      await clickInCanvas(page, '.bx-toolbar-section [data-bx-action="section-delete"]');
      await wait(1500);
      const deleted = await state(page);
      await page.click('[data-pb-undo]');
      await wait(1500);
      const back = await state(page);
      report.verdict('a band deleted from its toolbar comes back with undo', deleted.sections === before.sections - 1 && back.sections === before.sections && back.drawn === before.sections,
        JSON.stringify([before.sections, deleted.sections, back.sections, back.drawn]));

      // ---- no internal key anywhere a person reads ----------------------------------------------------
      const keys = await page.evaluate(() => {
        const words = document.body.innerText + ' ' + document.querySelector('[data-pb-canvas]').contentDocument.body.innerText;
        return (words.match(/\b[bnsm]\d+\b/g) || []).join(',');
      });
      report.verdict('no internal key (b13, n5, s4, m2) is shown', keys === '', keys);

      // ---- Publish ------------------------------------------------------------------------------------
      await page.click('[data-pb-publish]');
      await page.waitForFunction(() => document.querySelector('[data-pb-state]').textContent.trim() === 'Published', { timeout: 15000 }).catch(() => {});
      const published = await state(page);
      slug = await page.evaluate(() => window.pb.doc.slug);
      const visitor = await page.browser().createBrowserContext();
      const tab = await visitor.newPage();
      const answer = await tab.goto(`${BASE}/${slug}`, { waitUntil: 'networkidle2' });
      const live = await tab.evaluate(() => document.body.textContent.includes('Built in the builder'));
      const visitorColumns = await tab.evaluate(() => Math.max(0, ...[...document.querySelectorAll('main > section')].map((b) => b.querySelectorAll(':scope .section-column').length)));
      await visitor.close();
      report.verdict('Publish puts the page on the site, its words and its two columns', published.pill === 'Published' && answer.status() === 200 && live && visitorColumns === 2,
        `${published.pill}; /${slug}: ${answer.status()}, words: ${live}, columns: ${visitorColumns}`);

      // ---- a narrow window folds the rail -------------------------------------------------------------
      await page.setViewport({ width: 1100, height: 900, deviceScaleFactor: 1 });
      await page.reload({ waitUntil: 'networkidle2' });
      await wait(1200);
      const folded = await page.evaluate(() => document.querySelector('[data-pb-body]').classList.contains('pb-folded'));
      await shot(report, page, '05-narrow');
      report.verdict('under 1200px the rail starts folded', folded, String(folded));
    } finally {
      await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
      if (pageId !== null) {
        await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
        await page.$eval(`tr[data-page-id="${pageId}"] details.row-menu`, (d) => { d.open = true; }).catch(() => {});
        await page.$eval(`form[action$="/pages/${pageId}/delete"] button`, (b) => b.removeAttribute('data-confirm')).catch(() => {});
        await clickAndWait(page, `form[action$="/pages/${pageId}/delete"] button`).catch(() => {});
        const stray = await page.evaluate((t) => document.body.textContent.includes(t), TITLE);
        report.verdict('the page is gone again', !stray, `left: ${stray}`);
      }
    }
  },
};
