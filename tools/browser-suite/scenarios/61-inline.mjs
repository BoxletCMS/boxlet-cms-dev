/*
 * TYPING ON THE PAGE (PLAN.md D-178, README 4.4), as the owner meets it on the demo's home
 * page: a line typed where it is shown, rich text with its toolbar, a link's words and where it
 * leads, an empty field's placeholder, a card added and taken away, a picture pressed to open
 * the picker, an error shown on the element, and undo taking back what was typed.
 *
 * ON THE COPY (the owner's rule of 2026-10-02). Nothing is published: the draft the builder
 * saves by itself is discarded at the end, and the page is checked to be as it was.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openBuilder, clickInCanvas, blockKey, settle } from '../harness.mjs';

const PAGE = 1;
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const shot = (report, page, name) => report.shot(page, name, { fullPage: false });
const inCanvas = (page, fn, ...args) => page.evaluate(fn, ...args);

export default {
  name: 'inline',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('inline: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await openBuilder(page, BASE, PAGE);
    // What selected and deselected, kept for a failure to say (a full run lost the selection
    // after an error once, and the screen could not be asked afterwards).
    await page.evaluate(() => { window.__selections = []; window.pb.on('select', (sel) => window.__selections.push(JSON.stringify(sel || null) + '@' + Math.round(performance.now()))); window.pb.on('replace', () => window.__selections.push('replace@' + Math.round(performance.now()))); });
    const hero = await blockKey(page, 'hero');
    const cards = await blockKey(page, 'cards');
    const imageText = await blockKey(page, 'image_text');
    const cta = await blockKey(page, 'cta');
    try {
      // ---- a line ---------------------------------------------------------------------------------
      await clickInCanvas(page, `[data-bx-key="${hero}"] [data-bx-field="subheading"]`);
      await wait(300);
      await page.keyboard.down('Control');
      await page.keyboard.press('End');
      await page.keyboard.up('Control');
      await page.keyboard.type(' Every room.', { delay: 35 });
      await wait(500);
      const line = await page.evaluate((k) => ({
        doc: window.pb.block(k).content.subheading,
        editable: document.querySelector('[data-pb-canvas]').contentDocument.activeElement.getAttribute('contenteditable'),
        selected: (window.pb.selection || {}).key,
      }), hero);
      await shot(report, page, '01-text');
      report.verdict('a line is typed where it is shown, and the document has it at once',
        /Every room\.$/.test(line.doc) && line.editable === 'plaintext-only' && line.selected === hero, JSON.stringify(line));

      // ---- undo takes it back ---------------------------------------------------------------------
      await page.keyboard.press('Escape');
      await wait(300);
      await page.click('[data-pb-undo]');
      await wait(1500);
      const undone = await page.evaluate((k) => window.pb.block(k).content.subheading, hero);
      report.verdict('undo takes back what was typed on the page', !/Every room\./.test(undone), undone);

      // ---- rich text, its toolbar ------------------------------------------------------------------
      await clickInCanvas(page, `[data-bx-key="${imageText}"] [data-bx-field="body"]`);
      await wait(500);
      await page.keyboard.type(' Typed', { delay: 35 });
      await wait(300);
      // Slowly: pressed at the driver's pace, four of five selections were lost (the harness).
      await page.keyboard.down('Shift');
      for (let i = 0; i < 5; i += 1) {
        await page.keyboard.press('ArrowLeft');
        await wait(80);
      }
      await page.keyboard.up('Shift');
      await clickInCanvas(page, '.bx-rich-tools [data-rt="bold"]');
      await wait(500);
      const rich = await page.evaluate((k) => ({
        doc: window.pb.block(k).content.body,
        tools: [...document.querySelector('[data-pb-canvas]').contentDocument.querySelectorAll('.bx-rich-tools [data-rt]')].map((b) => b.getAttribute('data-rt')),
      }), imageText);
      await shot(report, page, '02-rich');
      report.verdict('rich text is written in place, with a toolbar of what the field allows',
        /<strong>Typed<\/strong>/.test(rich.doc) && rich.tools.includes('bold') && rich.tools.includes('link'), JSON.stringify(rich));
      // Taken back at once, so nothing typed here is on the page the later pictures show.
      let richBack = rich.doc;
      for (let i = 0; i < 6 && /Typed/.test(richBack); i += 1) {
        await page.click('[data-pb-undo]');
        await wait(700);
        richBack = await page.evaluate((k) => window.pb.block(k).content.body, imageText);
      }
      report.verdict('undo takes back rich text written on the page', !/Typed/.test(richBack), richBack);

      // ---- a link: its words and where it leads ------------------------------------------------------
      await clickInCanvas(page, `[data-bx-key="${hero}"] [data-bx-field="cta"]`);
      await wait(600);
      // The admin's own popover, over the canvas (D-179).
      const pages = await page.$$eval('[data-pb-link] select option', (os) => os.map((o) => o.value)).catch(() => []);
      await shot(report, page, '03-link');
      const about = pages.find((v) => v.startsWith('page:') && v !== pages[1]) || pages[1];
      await page.select('[data-pb-link] select', about).catch(() => {});
      await wait(400);
      const led = await page.evaluate((k) => window.pb.block(k).content.cta.url, hero);
      report.verdict('a link opens where it leads, and a page chosen is kept as its reference', pages.length > 2 && led === about, `${led} of ${JSON.stringify(pages)}`);
      await page.keyboard.press('Escape');

      // ---- an empty field, a card added and taken away ---------------------------------------------------
      await clickInCanvas(page, `[data-bx-key="${cards}"] .cards-heading`);
      await page.keyboard.press('Escape');
      await wait(700);
      const empty = await inCanvas(page, (k) => {
        const el = document.querySelector('[data-pb-canvas]').contentDocument.querySelector(`[data-bx-key="${k}"] [data-bx-field="intro"]`);
        return el ? { shown: el.getBoundingClientRect().height > 0, says: getComputedStyle(el, '::before').content } : null;
      }, cards);
      await shot(report, page, '04-placeholder');
      report.verdict('an empty field says what goes there, in the selected block', empty !== null && empty.shown && /Add introduction/.test(empty.says), JSON.stringify(empty));
      const before = await page.evaluate((k) => window.pb.block(k).content.items.length, cards);
      await clickInCanvas(page, `[data-bx-key="${cards}"] [data-bx-add-item]`);
      await wait(1500);
      const added = await page.evaluate((k) => ({ items: window.pb.block(k).content.items.length, drawn: document.querySelector('[data-pb-canvas]').contentDocument.querySelectorAll(`[data-bx-key="${k}"] [data-bx-item]`).length }), cards);
      await shot(report, page, '05-card');
      report.verdict('"+ Card" adds a card on the page', added.items === before + 1 && added.drawn === before + 1, `${before} → ${JSON.stringify(added)}`);
      await page.mouse.move(10, 10);
      const last = await inCanvas(page, (k, n) => {
        const el = document.querySelector('[data-pb-canvas]').contentDocument.querySelector(`[data-bx-key="${k}"] [data-bx-item="items.${n}"]`);
        el.dispatchEvent(new MouseEvent('mousemove', { bubbles: true }));
        return !!el;
      }, cards, before);
      await wait(400);
      await clickInCanvas(page, '.bx-item-tools [data-bx-action="item-remove"]');
      await wait(1500);
      const removed = await page.evaluate((k) => window.pb.block(k).content.items.length, cards);
      report.verdict('a card is taken away by its own tools', last && removed === before, `${added.items} → ${removed}`);

      // ---- a picture opens the picker ---------------------------------------------------------------------
      await clickInCanvas(page, `[data-bx-key="${imageText}"] [data-bx-field="image"]`);
      await page.waitForSelector('dialog[data-browser][open]', { timeout: 10000 }).catch(() => {});
      const picker = await page.$('dialog[data-browser][open]') !== null;
      report.verdict('pressing a picture opens the picker', picker, String(picker));
      await page.keyboard.press('Escape');
      await wait(400);

      // ---- an error on the element ---------------------------------------------------------------------
      await clickInCanvas(page, `[data-bx-key="${cta}"] [data-bx-field="heading"]`);
      await page.keyboard.down('Control');
      await page.keyboard.press('KeyA');
      await page.keyboard.up('Control');
      await page.keyboard.press('Backspace');
      await page.keyboard.press('Enter');
      await page.waitForFunction((k) => window.pb.errors[k] && window.pb.errors[k].heading, { timeout: 10000 }, cta).catch(() => {});
      await wait(500);
      const error = await page.evaluate(() => {
        const doc = document.querySelector('[data-pb-canvas]').contentDocument;
        return { marked: doc.querySelector('[data-bx-error]') !== null, note: (doc.querySelector('[data-bx-note]') || {}).textContent || '', inspector: (document.querySelector('[data-pb-inspector] .field-error') || {}).textContent || '', selection: JSON.stringify(window.pb.selection), lately: (window.__selections || []).slice(-6) };
      });
      await shot(report, page, '06-error');
      report.verdict('an error is shown on the element and in the inspector', error.marked && /required/.test(error.note) && /required/.test(error.inspector), JSON.stringify(error));
    } finally {
      // ---- as it was ---------------------------------------------------------------------------------
      await settle(page).catch(() => {});
      await openBuilder(page, BASE, PAGE);
      if (await page.$('[data-pb-discard]:not([hidden])')) {
        await page.click('[data-pb-discard]');
        await page.waitForFunction(() => document.querySelector('[data-pb-discard]').hidden, { timeout: 10000 }).catch(() => {});
      }
      const state = await page.$eval('[data-pb-state]', (p) => p.className);
      // Nothing typed here is left anywhere: not in the draft, not on the page (D-179).
      const left = await page.evaluate((base) => fetch(`${base}/`).then((r) => r.text()).then((t) => ['Every room', 'Typed'].filter((w) => t.includes(w))), BASE);
      report.verdict('the draft is discarded and the page is as it was', /status-published/.test(state) && left.length === 0, `${state}; left on the page: ${JSON.stringify(left)}`);
    }
  },
};
