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
import { login, openBuilder, clickInCanvas, selectItem, blockKey, settle } from '../harness.mjs';

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

      // ---- every link of the page: its own words, a popover beside it, no block toolbar (D-183) ----
      {
        const links = await page.evaluate(() => {
          const doc = document.querySelector('[data-pb-canvas]').contentDocument;
          return window.pb.doc.blocks.flatMap((b) => [...doc.querySelectorAll(`[data-bx-key="${b.key}"] [data-bx-field]`)]
            .filter((e) => (window.pb.inline.spec(b.type, e.getAttribute('data-bx-field')) || {}).type === 'link' && e.textContent.trim() !== '')
            .map((e) => ({ key: b.key, type: b.type, path: e.getAttribute('data-bx-field') })));
        });
        const bad = [];
        for (const l of links) {
          await clickInCanvas(page, `[data-bx-key="${l.key}"] [data-bx-field="${l.path}"]`);
          await page.waitForSelector('[data-pb-link]', { timeout: 5000 }).catch(() => {});
          await wait(500);
          const seen = await page.evaluate((k, path) => {
            const frame = document.querySelector('[data-pb-canvas]');
            const doc = frame.contentDocument;
            const el = doc.querySelector(`[data-bx-key="${k}"] [data-bx-field="${path}"]`);
            const panel = document.querySelector('[data-pb-link]');
            if (!panel) { return { panel: false }; }
            const f = frame.getBoundingClientRect();
            const s = window.pb.canvas.scale;
            const r = el.getBoundingClientRect();
            const a = { left: f.left + r.left * s, right: f.left + r.right * s, top: f.top + r.top * s, bottom: f.top + r.bottom * s };
            const p = panel.getBoundingClientRect();
            const bar = doc.querySelector('.bx-toolbar-block');
            return {
              panel: true,
              words: panel.querySelector('[data-pb-link-text]').value,
              shown: el.textContent.replace(/\s+/g, ' ').trim(),
              over: p.left < a.right && a.left < p.right && p.top < a.bottom && a.top < p.bottom,
              bar: bar ? getComputedStyle(bar).visibility : 'none',
            };
          }, l.key, l.path);
          if (!seen.panel) { bad.push(`${l.type} ${l.path}: no popover`); } else {
            if (seen.words !== seen.shown) { bad.push(`${l.type} ${l.path}: "${seen.words}" for "${seen.shown}"`); }
            if (seen.over) { bad.push(`${l.type} ${l.path}: the popover over its words`); }
            if (seen.bar === 'visible') { bad.push(`${l.type} ${l.path}: the block's toolbar shown`); }
          }
          if (l.type === 'hero') { await shot(report, page, '03e-hero-button'); }
          await page.keyboard.press('Escape');
          await page.mouse.click(10, 10);
          await wait(400);
        }
        // A link at the foot of the window: no room under it, so the popover stands above it.
        const low = links[links.length - 1];
        const point = await page.evaluate((k, path) => {
          const frame = document.querySelector('[data-pb-canvas]');
          const el = frame.contentDocument.querySelector(`[data-bx-key="${k}"] [data-bx-field="${path}"]`);
          el.scrollIntoView({ block: 'end' });
          const f = frame.getBoundingClientRect();
          const r = el.getBoundingClientRect();
          const s = window.pb.canvas.scale;
          return { x: f.left + (r.left + r.width / 2) * s, y: f.top + (r.top + r.height / 2) * s };
        }, low.key, low.path);
        await page.mouse.click(point.x, point.y);
        await page.waitForSelector('[data-pb-link]', { timeout: 5000 }).catch(() => {});
        await wait(500);
        const foot = await page.evaluate((k, path) => {
          const frame = document.querySelector('[data-pb-canvas]');
          const r = frame.contentDocument.querySelector(`[data-bx-key="${k}"] [data-bx-field="${path}"]`).getBoundingClientRect();
          const f = frame.getBoundingClientRect();
          const s = window.pb.canvas.scale;
          const panel = document.querySelector('[data-pb-link]');
          if (!panel) { return null; }
          const p = panel.getBoundingClientRect();
          return { above: p.bottom <= f.top + r.top * s, panelBottom: Math.round(p.bottom), wordsTop: Math.round(f.top + r.top * s), window: window.innerHeight };
        }, low.key, low.path);
        await shot(report, page, '03f-link-at-the-foot');
        report.verdict('a link at the foot of the window has its popover above it', foot !== null && foot.above, JSON.stringify(foot));
        await page.keyboard.press('Escape');
        await page.mouse.click(10, 10);
        await wait(400);
        const back = await page.evaluate((k) => { window.pb.select('block', k); return null; }, links[0] ? links[0].key : null);
        await wait(400);
        const bar = await page.evaluate(() => { const b = document.querySelector('[data-pb-canvas]').contentDocument.querySelector('.bx-toolbar-block'); return b ? getComputedStyle(b).visibility : 'none'; });
        report.verdict(`every link on the page: the popover has the element's own words, stands beside them, and the block's toolbar is away while it is open (${links.length}: ${links.map((l) => l.type).join(', ')})`,
          links.length >= 3 && bad.length === 0 && back === null, bad.join(' | ') || 'all as said');
        report.verdict('the block\'s toolbar is back once the popover closes', bar === 'visible', bar);
        await page.evaluate(() => window.pb.select(null));
      }

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
      // Its words in the popover too (D-182): a page fills them only when there are none, and
      // Done waits for words and an address.
      const words = async () => page.evaluate(() => ({ text: document.querySelector('[data-pb-link] [data-pb-link-text]').value, done: !document.querySelector('[data-pb-link-done]').disabled }));
      const kept = await words();
      await page.$eval('[data-pb-link] [data-pb-link-text]', (el) => { el.value = ''; el.dispatchEvent(new Event('input', { bubbles: true })); });
      const emptied = await words();
      const third = pages.find((v) => v.startsWith('page:') && v !== about && v !== pages[1]) || about;
      await page.select('[data-pb-link] select', third);
      await wait(300);
      const refilled = await words();
      // The button on the page says what the popover says (D-183): the title filled in stood
      // only in the popover while the button kept its old words.
      const onPage = await page.evaluate((k) => document.querySelector('[data-pb-canvas]').contentDocument.querySelector(`[data-bx-key="${k}"] [data-bx-field="cta"]`).textContent.replace(/\s+/g, ' ').trim(), hero);
      const title = await page.evaluate((ref) => (window.pb.data.inline.pages.find((p) => p.ref === ref) || {}).title, third);
      await shot(report, page, '03b-link-text');
      // And never over words it holds, even a title an earlier page filled in (D-183).
      await page.select('[data-pb-link] select', about);
      await wait(300);
      const again = await words();
      report.verdict('the popover has the link\'s words; a page fills them only when empty, never over words, and Done waits for words',
        kept.text !== '' && kept.done && !emptied.done && refilled.text === title && refilled.done && again.text === title, JSON.stringify({ kept, emptied, refilled, title, again }));
      report.verdict('the words filled in from a page are the button\'s words on the page too', onPage === title, JSON.stringify({ onPage, title }));
      await page.keyboard.press('Escape');
      await page.mouse.click(10, 10);
      await wait(400);

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
      await selectItem(page, cards, before);
      const last = await page.evaluate(() => !!document.querySelector('[data-pb-canvas]').contentDocument.querySelector('.bx-toolbar-item [data-bx-action="item-remove"]'));
      await clickInCanvas(page, '.bx-toolbar-item [data-bx-action="item-remove"]');
      await wait(1500);
      const removed = await page.evaluate((k) => window.pb.block(k).content.items.length, cards);
      report.verdict('a card is taken away by its actions in the block\'s bar', last && removed === before, `${added.items} → ${removed}`);

      // ---- a picture opens the picker ---------------------------------------------------------------------
      await clickInCanvas(page, `[data-bx-key="${imageText}"] [data-bx-field="image"]`);
      await page.waitForSelector('dialog[data-browser][open]', { timeout: 10000 }).catch(() => {});
      const picker = await page.$('dialog[data-browser][open]') !== null;
      report.verdict('pressing a picture opens the picker', picker, String(picker));
      await page.keyboard.press('Escape');
      await wait(400);

      // ---- the caret goes where a press lands, a double press selects a word (D-182) ---------------
      // In five blocks, rich and plain: a first press starts the editing where it lands, a
      // second press inside the words being written moves the caret, a double press selects
      // a word. A rich field once kept the caret at its end through every press.
      const place = (s, line, along) => page.evaluate((q, n, a) => {
        const frame = document.querySelector('[data-pb-canvas]');
        const doc = frame.contentDocument;
        const el = doc.querySelector(q);
        if (!el) { return null; }
        const f = frame.getBoundingClientRect();
        const sc = window.pb.canvas.scale;
        const range = doc.createRange();
        range.selectNodeContents(el);
        const lines = [...range.getClientRects()].filter((r) => r.width > 4);
        const r = lines[Math.min(n, lines.length - 1)];
        const y = r.top + r.height / 2;
        // On a letter with a letter either side, so a double press there is on a word, not a
        // space between two (the point is the harness's to choose well).
        let x = r.left + r.width * a;
        for (let step = 0; step < 40; step += 1) {
          const at = doc.caretRangeFromPoint(x, y);
          const text = at && at.startContainer.nodeType === 3 ? at.startContainer.textContent : '';
          if (at && /\w\w/.test(text.slice(Math.max(0, at.startOffset - 1), at.startOffset + 1))) { break; }
          x += r.width / 80;
        }
        return { x: f.left + x * sc, y: f.top + y * sc };
      }, s, line, along);
      const caret = () => page.evaluate(() => {
        const s = document.querySelector('[data-pb-canvas]').contentDocument.getSelection();
        return { at: s.anchorOffset, of: s.anchorNode ? (s.anchorNode.textContent || '').length : -1, words: s.toString() };
      });
      for (const [type, field] of [['cards', 'items.0.body'], ['image_text', 'body'], ['text', 'body'], ['quote', 'quote'], ['hero', 'subheading']]) {
        const s = `[data-bx-key="${await blockKey(page, type)}"] [data-bx-field="${field}"]`;
        await page.evaluate((q) => document.querySelector('[data-pb-canvas]').contentDocument.querySelector(q).scrollIntoView({ block: 'center' }), s);
        await wait(300);
        let p = await place(s, 0, 0.3);
        await page.mouse.click(p.x, p.y);
        await wait(600);
        const first = await caret();
        // Further along than the first: words on one line (Editorial's cards) put both presses
        // in one word, at one offset.
        p = await place(s, 1, 0.75);
        await page.mouse.click(p.x, p.y);
        await wait(300);
        const second = await caret();
        // Apart from the press before, or the browser counts three presses and takes a paragraph.
        await wait(800);
        p = await place(s, 1, 0.4);
        // A double press is `count: 2`; clickCount sends one press, and selected nothing even
        // on the admin's own words (the harness).
        await page.mouse.click(p.x, p.y, { count: 2 });
        await wait(300);
        const word = await caret();
        if (type === 'image_text') { await shot(report, page, '06b-word-selected'); }
        report.verdict(`${type}: the caret goes where each press lands, and a double press selects a word`,
          first.at > 0 && first.at < first.of && second.at > 0 && second.at < second.of && second.at !== first.at && /^\S+$/.test(word.words.trim()) && word.words.trim().length > 1,
          JSON.stringify({ first, second, word: word.words }));
        await page.keyboard.press('Escape');
        await page.mouse.click(10, 10);
        await wait(400);
      }

      // ---- a link made in rich text: the selected word is its words (D-182) ------------------------
      {
        const textKey = await blockKey(page, 'text');
        const s = `[data-bx-key="${textKey}"] [data-bx-field="body"]`;
        await page.evaluate((q) => document.querySelector('[data-pb-canvas]').contentDocument.querySelector(q).scrollIntoView({ block: 'center' }), s);
        await wait(300);
        let p = await place(s, 0, 0.3);
        await page.mouse.click(p.x, p.y);
        await wait(900);
        p = await place(s, 0, 0.3);
        await page.mouse.click(p.x, p.y, { count: 2 });
        await wait(400);
        const chosen = (await caret()).words.trim();
        await clickInCanvas(page, '.bx-rich-tools [data-rt="link"]');
        await wait(400);
        const offered = await page.$eval('[data-pb-link] [data-pb-link-text]', (el) => el.value).catch(() => null);
        const target = await page.evaluate(() => window.pb.data.inline.pages.find((x) => x.depth === 0 && x.url !== '/').ref);
        await page.select('[data-pb-link] select', target);
        const kept = await page.$eval('[data-pb-link] [data-pb-link-text]', (el) => el.value).catch(() => null);
        await shot(report, page, '03a-canvas-link-kept');
        await page.click('[data-pb-link-done]');
        await wait(600);
        const body = await page.evaluate((k) => window.pb.block(k).content.body, textKey);
        // The link just made, opened again from inside it: its own words (D-183).
        const inside = await page.evaluate((q) => {
          const frame = document.querySelector('[data-pb-canvas]');
          const a = frame.contentDocument.querySelector(`${q} a`);
          if (!a) { return null; }
          const f = frame.getBoundingClientRect();
          const r = a.getBoundingClientRect();
          const sc = window.pb.canvas.scale;
          return { x: f.left + (r.left + r.width / 2) * sc, y: f.top + (r.top + r.height / 2) * sc, words: a.textContent };
        }, s);
        let reopened = null;
        if (inside) {
          await wait(800);
          await page.mouse.click(inside.x, inside.y);
          await wait(500);
          // A caret alone has no toolbar (D-186): Ctrl+K opens the link it is in.
          await page.keyboard.down('Control');
          await page.keyboard.press('k');
          await page.keyboard.up('Control');
          await wait(400);
          reopened = await page.$eval('[data-pb-link] [data-pb-link-text]', (el) => el.value).catch(() => null);
        }
        report.verdict('in rich text Link text is the selected word, a page chosen keeps it, it becomes the link, and the link opens again on its own words',
          chosen.length > 1 && offered === chosen && kept === chosen && body.includes(`<a href="${target}">${chosen}</a>`) && inside !== null && reopened === inside.words,
          JSON.stringify({ chosen, offered, kept, reopened, body: body.slice(0, 200) }));
        await page.keyboard.press('Escape');
        await page.mouse.click(10, 10);
        await wait(500);
      }

      // ---- the same in the inspector's rich text (D-182) -------------------------------------------
      {
        const textKey = await blockKey(page, 'text');
        await page.evaluate((k) => window.pb.select('block', k), textKey);
        await page.waitForSelector(`[data-pb-inspector] [data-block-fields="${textKey}"]`, { timeout: 10000 });
        await page.$$eval('[data-pb-inspector] details', (all) => all.forEach((d) => { d.open = true; }));
        await wait(400);
        // Nothing of the field, the link panel open or not, widens the inspector (D-183).
        const wide = [];
        const width = async (state) => {
          const w = await page.evaluate(() => [...document.querySelectorAll('[data-pb-inspector], [data-pb-inspector] *')]
            .filter((n) => !n.matches('.visually-hidden') && n.scrollWidth > n.clientWidth + 1 && n.clientWidth > 0 && getComputedStyle(n).overflowX !== 'visible')
            .map((n) => `${n.className || n.tagName} ${n.scrollWidth}>${n.clientWidth}`)
            .concat((() => { const i = document.querySelector('[data-pb-inspector]'); return i.scrollWidth > i.clientWidth ? [`inspector ${i.scrollWidth}>${i.clientWidth}`] : []; })()));
          if (w.length) { wide.push(`${state}: ${[...new Set(w)].join(', ')}`); }
        };
        await width('closed');
        const editor = '[data-pb-inspector] [data-richtext] .ProseMirror';
        const at = await page.$eval(editor, (el) => {
          el.scrollIntoView({ block: 'center' });
          const range = document.createRange();
          range.selectNodeContents(el);
          const r = [...range.getClientRects()].filter((x) => x.width > 4)[0];
          return { x: r.left + 6, y: r.top + r.height / 2 };
        });
        await page.mouse.click(at.x, at.y, { count: 2 });
        await wait(300);
        const chosen = await page.evaluate(() => document.getSelection().toString().trim());
        // One selection on screen (D-185): the page's own let go when the inspector took it.
        const onPage = await page.evaluate(() => document.querySelector('[data-pb-canvas]').contentDocument.getSelection().toString());
        await page.click('[data-pb-inspector] [data-richtext] [data-rt="link"]');
        await wait(300);
        const panel = await page.evaluate(() => {
          const link = document.querySelector('[data-pb-inspector] [data-richtext-link]:not([hidden])');
          return link ? { text: link.querySelector('.rt-link-text').value, apply: !link.querySelector('[data-rt-link="apply"]').disabled, labels: [...link.querySelectorAll('.rt-link-label')].map((l) => l.textContent) } : null;
        });
        await width('open, another address');
        // A page chosen never changes the words selected (D-183, the owner: Link text is the
        // selection's, always).
        const target = await page.$eval('[data-pb-inspector] [data-richtext-link]:not([hidden]) .rt-link-page option:nth-child(2)', (o) => ({ value: o.value, title: o.getAttribute('data-title') }));
        await page.select('[data-pb-inspector] [data-richtext-link]:not([hidden]) .rt-link-page', target.value);
        const linkNow = () => page.evaluate(() => {
          const link = document.querySelector('[data-pb-inspector] [data-richtext-link]:not([hidden])');
          return { text: link.querySelector('.rt-link-text').value, apply: !link.querySelector('[data-rt-link="apply"]').disabled };
        });
        const kept = await linkNow();
        await width('open, a page');
        await page.$eval('[data-pb-inspector] [data-richtext-link]:not([hidden]) .rt-link-page', (el) => el.scrollIntoView({ block: 'center' }));
        await shot(report, page, '03c-inspector-link');
        const longest = await page.$eval('[data-pb-inspector] [data-richtext-link]:not([hidden]) .rt-link-page', (sel) => [...sel.options].filter((o) => o.value !== '').sort((a, b) => b.text.length - a.text.length)[0].value);
        await page.select('[data-pb-inspector] [data-richtext-link]:not([hidden]) .rt-link-page', longest);
        const again = await linkNow();
        await width('open, the longest page');
        await page.setViewport({ width: 1100, height: 900, deviceScaleFactor: 1 });
        await wait(600);
        await width('open, a narrow window');
        await shot(report, page, '03d-inspector-link-narrow');
        await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
        await wait(400);
        // Emptied, a page still does not fill them: they are the selection's.
        await page.$eval('[data-pb-inspector] [data-richtext-link]:not([hidden]) .rt-link-text', (el) => { el.value = ''; el.dispatchEvent(new Event('input', { bubbles: true })); });
        await page.select('[data-pb-inspector] [data-richtext-link]:not([hidden]) .rt-link-page', target.value);
        const emptied = await linkNow();
        await page.$eval('[data-pb-inspector] [data-richtext-link]:not([hidden]) .rt-link-text', (el, v) => { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); }, chosen);
        await page.click('[data-pb-inspector] [data-richtext-link]:not([hidden]) [data-rt-link="apply"]');
        await wait(400);
        // An existing link opened again: its own words.
        const inLink = await page.$eval(`${editor} a`, (a) => { a.scrollIntoView({ block: 'center' }); const r = a.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2, words: a.textContent }; }).catch(() => null);
        let reopened = null;
        if (inLink) {
          await page.mouse.click(inLink.x, inLink.y);
          await wait(300);
          await page.click('[data-pb-inspector] [data-richtext] [data-rt="link"]');
          await wait(300);
          reopened = await linkNow().catch(() => null);
        }
        report.verdict('the inspector\'s rich text link: Link text is the selection, a page never changes it, even emptied; an existing link opens on its own words; the fields are named',
          panel !== null && chosen.length > 1 && panel.text === chosen && !panel.apply && panel.labels.join('|') === 'Link text|A page|Address'
            && kept.text === chosen && kept.apply && again.text === chosen && emptied.text === '' && !emptied.apply
            && inLink !== null && reopened !== null && reopened.text === inLink.words, JSON.stringify({ chosen, panel, kept, again, emptied, inLink: inLink && inLink.words, reopened }));
        report.verdict('one selection on screen: a word selected in the inspector leaves none on the page', onPage === '', JSON.stringify({ chosen, onPage }));
        // And back on the page: the inspector's selection is let go.
        await page.keyboard.press('Escape');
        const textKey2 = await blockKey(page, 'text');
        await clickInCanvas(page, `[data-bx-key="${textKey2}"] [data-bx-field="heading"]`);
        await wait(500);
        const inInspector = await page.evaluate(() => { const s = document.getSelection(); return s.rangeCount > 0 && s.anchorNode && (s.anchorNode.nodeType === 1 ? s.anchorNode : s.anchorNode.parentElement).closest('[data-pb-inspector]') ? s.toString() || '(a caret)' : ''; });
        report.verdict('one selection on screen: pressing into the page lets the inspector\'s go', inInspector === '', JSON.stringify({ inInspector }));
        report.verdict('the rich text field and its link panel never widen the inspector (closed, open, a page, the longest page, a narrow window)', wide.length === 0, wide.join(' | ') || 'none wider');
        await page.keyboard.press('Escape');
        await page.evaluate(() => window.pb.select(null));
        await wait(400);
      }

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
