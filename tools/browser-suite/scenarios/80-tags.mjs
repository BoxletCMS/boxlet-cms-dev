/*
 * REPLACEMENT TAGS AND SNIPPETS (PLAN.md D-201, the owner's choices): a snippet made on its own
 * screen in Content; put in a text block's words with Insert in the inspector's toolbar; a
 * chip with its words, in the inspector, on the canvas, and while the words are written on
 * the page; the tag kept in the document, never its words; the page drawn with the words.
 *
 *   - the Snippets screen: in the rail, a new snippet made by typing its words, its tag shown;
 *   - Insert lists the year, the language switcher and the snippet, and puts a chip where the
 *     caret is; the block's words hold {{snippet:…}} and {{year}};
 *   - the canvas draws each as a chip with its words; writing on the page keeps the chips as
 *     chips and the document keeps the tags;
 *   - the draft's preview has the words and the year, and no {{ at all.
 *
 * Its control: the editor's turning a chip back into its tag switched off, an edit on the page
 * is caught putting the chip's words into the document.
 *
 * The snippet's name carries this run's marker; it is deleted through its screen, by that
 * name, and the draft is discarded.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openBuilder, blockKey, selectBlock, addBlock, settle, clickInCanvas, a11yProblems } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const WORDS = 'Mon–Fri 9–17';

export default {
  name: 'tags',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('tags: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    const name = `hours-${Date.now().toString(36)}`;
    const tag = `snippet:${name}`;
    let made = false;
    let drafted = false;

    try {
      /* ---- The Snippets screen ---------------------------------------------------------- */
      await page.goto(`${BASE}/admin/snippets`, { waitUntil: 'networkidle2' });
      report.verdict('the rail has Snippets, under Content, and it is the screen shown',
        await page.$eval('a[href$="/admin/snippets"]', (a) => a.getAttribute('aria-current') === 'page').catch(() => false));
      await page.type('#snippet-name', name, { delay: 10 });
      await page.click('.snippet-new [data-tag-locale="en"] .ProseMirror');
      await page.keyboard.type(WORDS, { delay: 15 });
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('.snippet-new form button[type="submit"]')]);
      made = true;
      const shown = await page.evaluate((n) => {
        const s = document.getElementById(`snippet-${n}`);
        return s ? { tag: s.querySelector('.snippet-tag').value, words: s.querySelector('[data-tag-locale="en"] .ProseMirror').textContent } : null;
      }, name);
      report.verdict('a snippet is made from its name and its typed words, its tag shown to copy',
        shown && shown.tag === `{{${tag}}}` && shown.words === WORDS, JSON.stringify(shown));
      report.verdict('a snippet\'s words offer no Insert: they are not read for tags',
        await page.$eval(`#snippet-${name}`, (s) => s.querySelector('[data-rt="tag"]') === null));
      const problems = await a11yProblems(page);
      report.verdict('the Snippets screen: every control named, every reference real', problems.length === 0, problems.join('; '));
      await page.evaluate((n) => document.getElementById(`snippet-${n}`).scrollIntoView({ block: 'start' }), name);
      await report.shot(page, 'snippets-screen', { fullPage: false });

      /* ---- Insert, in the inspector ----------------------------------------------------- */
      await openBuilder(page, BASE, 1);
      let key = await blockKey(page, 'text');
      if (!key) {
        key = await addBlock(page, 'text');
      } else {
        await selectBlock(page, key);
      }
      drafted = true;
      const field = `[data-pb-inspector] [data-block-fields="${key}"] .richtext`;
      await page.waitForSelector(`${field} .ProseMirror`, { timeout: 10000 });
      // The words are in All content, folded until it is opened, as the owner opens it.
      if (!await page.$eval('#ins-content', (d) => d.open)) {
        await page.click('#ins-content > summary');
      }
      await page.$eval(field, (el) => el.scrollIntoView({ block: 'start' }));
      await page.click(`${field} .ProseMirror`);
      await page.keyboard.press('End');
      await page.keyboard.down('Control');
      await page.keyboard.press('End');
      await page.keyboard.up('Control');
      await page.keyboard.type(' Open ', { delay: 15 });
      await page.click(`${field} [data-rt="tag"]`);
      await page.waitForSelector('.rt-insert-menu', { timeout: 5000 });
      const offered = await page.$$eval('.rt-insert-menu [data-insert-tag]', (b) => b.map((x) => x.getAttribute('data-insert-tag')));
      report.verdict('Insert lists the year, the language switcher and the snippet',
        offered[0] === 'year' && offered[1] === 'lang:switcher' && offered.includes(tag), JSON.stringify(offered));
      await report.shot(page, 'insert-list', { fullPage: false });
      await page.click(`.rt-insert-menu [data-insert-tag="${tag}"]`);
      await page.keyboard.type(', since ', { delay: 15 });
      await page.click(`${field} [data-rt="tag"]`);
      await page.waitForSelector('.rt-insert-menu', { timeout: 5000 });
      await page.click('.rt-insert-menu [data-insert-tag="year"]');
      // The inspector writes the document after a pause in the typing: read it after that.
      await settle(page);
      const year = String(await page.evaluate(() => JSON.parse(document.getElementById('boxlet-tags').textContent).year));
      const inspector = await page.evaluate((f, t) => {
        const chips = [...document.querySelectorAll(`${f} .ProseMirror [data-tag]`)].map((c) => `${c.getAttribute('data-tag')}=${c.textContent}`);
        return { chips, body: window.pb.block(window.pb.selection.key).content.body };
      }, field, tag);
      report.verdict('in the inspector each is a chip with its words',
        inspector.chips.join() === `${tag}=${WORDS},year=${year}`, JSON.stringify(inspector.chips));
      report.verdict('and the block\'s words hold the tags, not the words',
        inspector.body.includes(`Open {{${tag}}}, since {{year}}`) && !inspector.body.includes(WORDS) && !inspector.body.includes('data-tag'), inspector.body);

      /* ---- On the canvas ---------------------------------------------------------------- */
      await settle(page);
      const canvasChips = () => page.evaluate((k) => [...document.querySelector('[data-pb-canvas]').contentDocument.querySelectorAll(`[data-bx-key="${k}"] .bx-tag`)]
        .map((c) => `${c.getAttribute('data-tag')}=${c.textContent}`), key);
      report.verdict('the canvas draws each as a chip with its words', (await canvasChips()).join() === `${tag}=${WORDS},year=${year}`, JSON.stringify(await canvasChips()));
      await page.evaluate((k) => document.querySelector('[data-pb-canvas]').contentDocument.querySelector(`[data-bx-key="${k}"] .bx-tag`).scrollIntoView({ block: 'center' }), key);
      await wait(300);
      await report.shot(page, 'canvas-chips', { fullPage: false });

      // Written on the page: the chips stay chips, the document keeps the tags.
      const writeOnPage = async (letters) => {
        await clickInCanvas(page, `[data-bx-key="${key}"] [data-bx-field="body"]`, { at: 'top', side: 'left' });
        await wait(500);
        await page.keyboard.down('Control');
        await page.keyboard.press('End');
        await page.keyboard.up('Control');
        await page.keyboard.type(letters, { delay: 20 });
        await wait(300);
        const during = await page.evaluate((k) => [...document.querySelector('[data-pb-canvas]').contentDocument.querySelectorAll(`[data-bx-key="${k}"] .ProseMirror .bx-tag`)].length, key);
        await page.keyboard.press('Escape');
        await wait(600);
        return { during, body: await page.evaluate((k) => window.pb.block(k).content.body, key) };
      };
      const written = await writeOnPage(' Q');
      report.verdict('written on the page, the chips stay chips', written.during === 2, String(written.during));
      // Each space beside a chip a plain one: Chrome types it as a no-break space there.
      report.verdict('and the document keeps the tags, with the new words and plain spaces',
        written.body.includes(`Open {{${tag}}}, since {{year}} Q`) && !written.body.includes('&nbsp;') && !written.body.includes('data-tag') && !written.body.includes(WORDS), written.body);

      /* ---- As a visitor will get it ----------------------------------------------------- */
      await settle(page);
      const preview = await page.evaluate(async (b) => (await fetch(`${b}/admin/pages/1/preview`)).text(), BASE);
      report.verdict('the draft\'s preview has the snippet\'s words and the year',
        preview.includes(`Open ${WORDS}, since ${year}`), (preview.match(/Open [^<]{0,60}/) || [''])[0]);
      report.verdict('and no tag reaches the visitor', !preview.includes('{{'), (preview.match(/.{0,40}\{\{.{0,40}/) || [''])[0]);

      // THE CONTROL: the chips no longer turned back into tags on the way out of the editor.
      const from = await page.evaluate(() => { const t = window.boxletRichText.tags; window.__from = t.from; t.from = (h) => h; return true; });
      const broken = await writeOnPage('Z');
      await page.evaluate(() => { window.boxletRichText.tags.from = window.__from; });
      report.verdict('the control: without the chips turned back, the document is caught holding their words',
        from && (broken.body.includes(WORDS) || broken.body.includes('data-tag')), broken.body);
      // The draft it spoiled is discarded below.
    } finally {
      /* ---- Cleanup: the draft discarded, the snippet deleted by its name ------------------ */
      if (drafted) {
        await openBuilder(page, BASE, 1).catch(() => {});
        if (await page.$('[data-pb-discard]:not([hidden])')) {
          await page.click('[data-pb-discard]');
          await page.waitForFunction(() => document.querySelector('[data-pb-discard]').hidden, { timeout: 10000 }).catch(() => {});
        }
      }
      if (made) {
        await page.goto(`${BASE}/admin/snippets`, { waitUntil: 'networkidle2' });
        const button = `#snippet-${name} .snippet-head form button[type="submit"]`;
        if (await page.$(button)) {
          await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click(button)]);
        }
        report.verdict('cleanup: the snippet is deleted', (await page.$(`#snippet-${name}`)) === null);
      }
    }
  },
};
