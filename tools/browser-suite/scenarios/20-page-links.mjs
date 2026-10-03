/*
 * A link points at a page, not at a typed path (PLAN.md D-034).
 *
 * What a person does: in the page editor, choose a page for a block's button, and link a
 * few words of rich text to a page through the link panel. What is checked is what they
 * would see — the address input stepping aside while a page is chosen, the canvas drawing
 * the page's real address, and the panel reopening on the page it was given.
 *
 * NOTHING IS PUBLISHED. Every verdict is read off the inspector and the canvas, which draw
 * links exactly as the site does (the canvas asks the same resolver); the draft the builder
 * saves by itself is discarded at the end (D-175), so the copy is left as it was found. That the SAVED page leads to the right address is asserted by
 * tests/page_links_test.php on the served HTML, on both drivers.
 *
 * Runs against the demo's home page: its first block is a hero with a button, and it has
 * a rich text block further down.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, openBuilder, blockKey, selectBlock, settle } from '../harness.mjs';

const PAGE = 1;
const SETTLE = 2000; // the fields are sent after 400ms, then the band is drawn again

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** The hrefs inside one block on the canvas. */
const canvasHrefs = (page, key) => page.evaluate((k) => {
  const block = document.querySelector('[data-pb-canvas]').contentDocument.querySelector(`[data-bx-key="${k}"]`);
  return block ? Array.from(block.querySelectorAll('a')).map((a) => a.getAttribute('href')) : [];
}, key);

export default {
  name: 'page-links',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('page links: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}; at ${page.url()}`);
      return;
    }

    await openBuilder(page, BASE, PAGE);

    // ---- a link field: the hero's button ------------------------------------------------
    const heroKey = await blockKey(page, 'hero');
    if (heroKey === null) {
      report.fail('a link field offers pages', 'no hero on the home page');
      return;
    }
    const field = await selectBlock(page, heroKey);
    const choices = await page.$$eval(`${field} select[name$="[cta][page]"] option`,
      (options) => options.map((o) => ({ value: o.value, text: o.textContent.trim() })));
    const about = choices.find((c) => c.value !== '' && /about/i.test(c.text));
    report.verdict('a link field offers the site\'s pages, with "another address" first',
      choices.length > 1 && choices[0].value === '' && about !== undefined,
      JSON.stringify(choices.map((c) => c.text)));
    if (about === undefined) {
      return;
    }

    const address = () => page.$eval(`${field} input[name$="[cta][url]"]`,
      (input) => ({ value: input.value, readOnly: input.readOnly, shown: getComputedStyle(input).display !== 'none' }));

    await page.select(`${field} select[name$="[cta][page]"]`, about.value);
    await wait(SETTLE);
    const withPage = await address();
    const heroHrefs = await canvasHrefs(page, heroKey);
    await page.$eval(`${field} .link-field`, (el) => el.scrollIntoView({ block: 'center' }));
    await report.shot(page, '01-button-to-a-page', { fullPage: false });
    // The owner's review (D-038): the chosen page's address is shown, read-only, rather
    // than the field disappearing.
    report.verdict('choosing a page shows its address, read-only',
      withPage.shown && withPage.readOnly && withPage.value === '/about',
      JSON.stringify(withPage));
    report.verdict('the canvas draws the button with the page\'s address',
      heroHrefs.includes('/about') && !heroHrefs.some((h) => h.startsWith('page:')),
      `hrefs in the hero: ${JSON.stringify(heroHrefs)}`);

    await page.select(`${field} select[name$="[cta][page]"]`, '');
    const again = await address();
    report.verdict('"another address" makes the address typeable again',
      again.shown && !again.readOnly && again.value === '', JSON.stringify(again));

    // ---- rich text: the link panel ------------------------------------------------------
    // A block whose words carry a rich text field with a page chooser, in All content.
    let rich = null;
    let richKey = null;
    for (const type of ['text', 'image_text']) {
      const key = await blockKey(page, type);
      if (key === null) { continue; }
      const form = await selectBlock(page, key);
      await page.$eval('#ins-content', (d) => { d.open = true; }).catch(() => {});
      await page.waitForSelector(`${form} .ProseMirror`, { timeout: 5000 }).catch(() => {});
      if (await page.$(`${form} .ProseMirror`) && await page.$(`${form} .rt-link-page`)) {
        rich = form;
        richKey = key;
        break;
      }
    }
    if (rich === null) {
      report.fail('the rich text link panel offers pages', 'no rich text field with a page chooser on the home page');
      return;
    }

    // Select the whole text with Ctrl+A, which the editor handles as its own command.
    // Measured through this driver: Shift+ArrowRight, Ctrl+Shift+ArrowRight and a double
    // click all move the BROWSER's selection while the editor's stays empty (from = to), so
    // they linked nothing and read as the product refusing the link. The earlier link
    // checks selected with Ctrl+A for the same reason.
    await page.click(`${rich} .ProseMirror`);
    await page.keyboard.down('Control');
    await page.keyboard.press('KeyA');
    await page.keyboard.up('Control');
    const selected = await page.$eval(`${rich} .ProseMirror`, (el) => {
      const { from, to } = el.editor.state.selection;
      return to - from;
    });
    if (selected === 0) {
      report.fail('the rich text link panel links a page', 'the editor holds no selection, so nothing could be linked');
      return;
    }
    await page.keyboard.down('Control');
    await page.keyboard.press('KeyK');
    await page.keyboard.up('Control');

    const panelOpen = await page.$eval(`${rich} [data-richtext-link]`, (p) => !p.hidden);
    report.verdict('Ctrl+K opens the link panel with a page chooser', panelOpen, panelOpen ? 'open' : 'closed');
    if (!panelOpen) {
      return;
    }

    const option = await page.$$eval(`${rich} .rt-link-page option`,
      (options) => options.map((o) => ({ value: o.value, text: o.textContent.trim() })).find((o) => /services/i.test(o.text)));
    if (!option) {
      report.fail('the link panel offers the services page', 'no such option');
      return;
    }
    // Services, not About: the demo's image and text block already carries a typed /about
    // in its own link field, and a check for /about passed on that before any rich text
    // link existed.
    const before = await canvasHrefs(page, richKey);
    await page.select(`${rich} .rt-link-page`, option.value);
    // Not drawn, whichever element hides it: its labelled field does since D-183.
    const inputHidden = await page.$eval(`${rich} .rt-link-input`, (input) => input.getClientRects().length === 0);
    await page.$eval(`${rich} [data-richtext-link]`, (el) => el.scrollIntoView({ block: 'center' }));
    await report.shot(page, '02-panel-with-a-page', { fullPage: false });
    await page.click(`${rich} [data-rt-link="apply"]`);
    await wait(SETTLE);

    const stored = await page.$eval(`${rich} input[type="hidden"][name$="[body]"]`, (input) => input.value).catch(() => '');
    const richHrefs = await canvasHrefs(page, richKey);
    await report.shot(page, '03-rich-text-linked');
    report.verdict('the address input steps aside in the panel too', inputHidden, inputHidden ? 'hidden' : 'shown');
    report.verdict('the rich text stores a page reference', stored.includes(`href="${option.value}"`),
      `stored: ${stored.slice(0, 160)}`);
    /* MORE THAN BEFORE, NOT EXACTLY ONE MORE — changed deliberately, and the old rule was
       wrong rather than merely stale. Ctrl+A selects the whole field, and a link applied
       across a selection spanning two paragraphs is TWO anchors, which is what TipTap should
       do and does: an <a> cannot contain a <p>. The block this scenario finds has a
       two-paragraph body, so "+1" could never hold for it. What the check is actually for is
       the line below it: the canvas draws the RESOLVED ADDRESS and never the stored
       `page:n`. That is asserted over every anchor rather than over a count. */
    const added = richHrefs.filter((h) => h === '/services').length
      - before.filter((h) => h === '/services').length;
    report.verdict('the canvas draws the rich text link with the page\'s address',
      added > 0 && !richHrefs.some((h) => h.startsWith('page:')),
      `${added} link(s) to /services added; before ${JSON.stringify(before)}, after ${JSON.stringify(richHrefs)}`);

    // Reopen on the link: the panel should come back on the page, not on "page:n" typed.
    await page.click(`${rich} .ProseMirror a`);
    await page.keyboard.down('Control');
    await page.keyboard.press('KeyK');
    await page.keyboard.up('Control');
    const reopened = await page.$eval(`${rich} .rt-link-page`, (select) => select.value);
    const typed = await page.$eval(`${rich} .rt-link-input`, (input) => input.value);
    report.verdict('the panel reopens on the page it was given', reopened === option.value && typed === '',
      `page ${JSON.stringify(reopened)}, address ${JSON.stringify(typed)}`);

    // Discard the draft the builder saved: nothing on the site changes (see the header).
    await settle(page);
    if (await page.$('[data-pb-discard]:not([hidden])')) {
      await page.click('[data-pb-discard]');
      await page.waitForFunction(() => document.querySelector('[data-pb-discard]').hidden, { timeout: 10000 }).catch(() => {});
    }
    report.verdict('the draft is discarded, and the home page is as it was', await page.$eval('[data-pb-state]', (p) => p.className.includes('status-published')), await page.$eval('[data-pb-state]', (p) => p.textContent.trim()));
  },
};
