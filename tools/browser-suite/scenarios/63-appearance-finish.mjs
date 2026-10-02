/*
 * APPEARANCE, FINISHED (PLAN.md D-180, D-181; README 5.5–5.9), and the accessibility pass of
 * both screens, measured on the copy:
 *
 *   - the bar counts what is not published, from the first moment and after a character is
 *     loaded, and Discard shows only then;
 *   - undo and redo, one step for a slider dragged through several values, across a
 *     character too, with the keys and the buttons;
 *   - search finds a control by its name, its section and its keywords, and says when
 *     nothing matches;
 *   - pressing the picture opens the section the part pressed answers to, and follows no link;
 *   - the header's and footer's words are on Navigation, and Appearance says so;
 *   - Appearance, Navigation, the builder and its canvas pass a11yProblems().
 *
 * Nothing is published: every change is undone or left unsaved, and the screen is left.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openSection, clickAndWait, openBuilder, a11yProblems } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const state = (page) => page.evaluate(() => ({
  text: document.querySelector('[data-state]').textContent.trim(),
  discard: !document.querySelector('[data-revert]').hidden,
  undo: !document.querySelector('[data-undo]').disabled,
  redo: !document.querySelector('[data-redo]').disabled,
  spacing: document.querySelector('[name="spacing"]').value,
  character: document.querySelector('[name="character"]').value,
}));
/** A slider dragged: several values within a moment, then let go. */
async function drag(page, name, values) {
  for (const value of values) {
    await page.$eval(`[name="${name}"]`, (el, v) => { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); }, value);
    await wait(80);
  }
  await page.$eval(`[name="${name}"]`, (el) => el.dispatchEvent(new Event('change', { bubbles: true })));
  await wait(900);
}
/** A press on the picture where the scaled frame shows `selector`. */
async function pressPicture(page, selector) {
  // Scrolled first and measured after: the page may scroll smoothly, and a box measured
  // mid-scroll sent the press to the band above the footer (the harness, not the screen).
  const there = await page.evaluate((sel) => {
    const el = document.querySelector('[data-design-preview]').contentDocument.querySelector(sel);
    if (el) { el.scrollIntoView({ block: 'center', behavior: 'instant' }); }
    return !!el;
  }, selector);
  if (!there) { return null; }
  await wait(600);
  const point = await page.evaluate((sel) => {
    const frame = document.querySelector('[data-design-preview]');
    const el = frame.contentDocument.querySelector(sel);
    const f = frame.getBoundingClientRect();
    const scale = f.width / frame.offsetWidth;
    const r = el.getBoundingClientRect();
    // Its middle: the band above may reach over a part's top edge (a CTA's over the footer).
    return { x: f.left + (r.left + Math.min(r.width / 2, 40)) * scale, y: f.top + (r.top + r.height / 2) * scale };
  }, selector);
  if (!point) { return null; }
  await page.mouse.click(point.x, point.y);
  await wait(500);
  return page.evaluate(() => ({
    showing: document.querySelector('[data-inspector]').getAttribute('data-showing'),
    at: document.querySelector('[data-design-preview]').contentWindow.location.pathname,
  }));
}

export default {
  name: 'appearance-finish',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('appearance-finish: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    await wait(800);

    // ---- the count, and undo / redo -------------------------------------------------------------
    const open = await state(page);
    report.verdict('the bar opens on "Published", with nothing to undo', open.text === 'Published' && !open.discard && !open.undo && !open.redo, JSON.stringify(open));
    await openSection(page, 'space');
    const before = open.spacing;
    await drag(page, 'spacing', ['1.3', '1.4', '1.5', '1.6']);
    const moved = await state(page);
    report.verdict('a slider dragged is one unpublished change, and Discard shows', moved.text === '1 unpublished change' && moved.discard && moved.undo, JSON.stringify(moved));
    await page.click('[data-undo]');
    await wait(900);
    const undone = await state(page);
    report.verdict('undo takes the whole drag back in one step, and the count with it', undone.spacing === before && undone.text === 'Published' && undone.redo && !undone.undo, JSON.stringify(undone));
    await page.focus('[data-redo]');
    await page.keyboard.down('Control');
    await page.keyboard.down('Shift');
    await page.keyboard.press('KeyZ');
    await page.keyboard.up('Shift');
    await page.keyboard.up('Control');
    await wait(900);
    const redone = await state(page);
    report.verdict('⇧⌘Z / Ctrl+Shift+Z puts it back', redone.spacing === '1.6' && redone.text === '1 unpublished change', JSON.stringify(redone));
    await report.shot(page, '01-count-undo', { fullPage: false });

    // ---- a character: counted at once, and undone across the post ------------------------------------
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    const other = await page.evaluate(() => {
      const tile = [...document.querySelectorAll('button[name="action"][value^="preset:"]')].find((b) => !/in use/i.test(b.textContent));
      return tile ? tile.value : null;
    });
    await clickAndWait(page, `button[name="action"][value="${other}"]`);
    await wait(900);
    const loaded = await state(page);
    const counted = Number((loaded.text.match(/^(\d+) unpublished/) || [])[1] || 0);
    report.verdict('a character loaded is counted as changes before anything is touched', counted > 3 && loaded.character !== '' && loaded.undo, `${other}: ${JSON.stringify(loaded)}`);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 20000 }).catch(() => {}), page.click('[data-undo]')]);
    await wait(900);
    const back = await state(page);
    report.verdict('undo goes back across the character', back.character === '' && back.text === 'Published' && back.redo, JSON.stringify(back));

    // ---- search ---------------------------------------------------------------------------------------
    const find = async (words) => {
      await page.$eval('#appearance-search', (el) => { el.value = ''; });
      await page.click('#appearance-search');
      await page.keyboard.type(words, { delay: 20 });
      await wait(300);
      return page.evaluate(() => ({
        rows: [...document.querySelectorAll('.inspector-section:not([hidden]) .control-row:not([data-search-miss])')].map((r) => r.getAttribute('data-control')),
        none: !document.querySelector('[data-search-none]').hidden,
        state: document.querySelector('[data-state]').textContent.trim(),
      }));
    };
    const corners = await find('corners');
    report.verdict('search finds a control by its name, in every section it is in', corners.rows.includes('radius') && corners.rows.includes('button_radius') && !corners.none, JSON.stringify(corners));
    await report.shot(page, '02-search', { fullPage: false });
    const sticky = await find('sticky');
    report.verdict('and by the words of its choices', sticky.rows.includes('header_behaviour'), JSON.stringify(sticky));
    const dark = await find('dark');
    report.verdict('and by its keywords', dark.rows.includes('mode'), JSON.stringify(dark));
    const nothing = await find('zzqq');
    report.verdict('a search that finds nothing says so, and typing in it changes nothing', nothing.none && nothing.rows.length === 0 && nothing.state === 'Published', JSON.stringify(nothing));
    await page.keyboard.press('Escape');
    await wait(300);
    const home = await page.evaluate(() => document.querySelector('[data-inspector]').getAttribute('data-showing'));
    report.verdict('Escape ends the search where it began', home === 'home', home);

    // ---- pressing the picture -------------------------------------------------------------------------
    const header = await pressPicture(page, '[data-bx-region="header"] a');
    report.verdict('pressing the header opens Header, and its link is not followed', header && header.showing === 'header' && header.at === '/admin/appearance/preview', JSON.stringify(header));
    await report.shot(page, '03-press-header', { fullPage: false });
    const button = await pressPicture(page, '[data-bx-region="section"] .button');
    report.verdict('a button opens Buttons', button && button.showing === 'buttons', JSON.stringify(button));
    const heading = await pressPicture(page, '[data-bx-region="section"] h1, [data-bx-region="section"] h2');
    report.verdict('a heading opens Typography', heading && heading.showing === 'typography', JSON.stringify(heading));
    const footer = await pressPicture(page, '[data-bx-region="footer"]');
    report.verdict('the footer opens Footer', footer && footer.showing === 'footer', JSON.stringify(footer));
    const marks = await page.evaluate(() => [...document.querySelector('[data-design-preview]').contentDocument.querySelectorAll('[data-bx-region]')].map((n) => n.getAttribute('data-bx-region')));
    report.verdict('the picture marks the header, each band and the footer, and nothing else', marks[0] === 'header' && marks[marks.length - 1] === 'footer' && marks.filter((m) => m === 'section').length >= 3, JSON.stringify(marks));

    // ---- Navigation, and both screens read aloud --------------------------------------------------------
    await openSection(page, 'header');
    const where = await page.evaluate(() => ({
      links: document.querySelectorAll('.navigation-where a[href$="/admin/navigation"]').length,
      words: !!document.querySelector('[name="header_menu"], [name^="header_button_label_"], [name^="footer_text_"]'),
    }));
    report.verdict('Appearance keeps no word or menu of the header and footer, and says they are in Navigation', where.links === 2 && !where.words, JSON.stringify(where));
    const appearance = await a11yProblems(page);
    report.verdict('Appearance: every control has a name, no id twice, one h1', appearance.length === 0, appearance.join('; ') || 'none');
    await page.goto(`${BASE}/admin/navigation`, { waitUntil: 'networkidle2' });
    const navigation = await a11yProblems(page);
    report.verdict('Navigation: every control has a name, no id twice, one h1', navigation.length === 0, navigation.join('; ') || 'none');
    await report.shot(page, '04-navigation', { fullPage: false });
    await openBuilder(page, BASE, 1);
    await page.evaluate(() => window.pb.select('block', window.pb.doc.blocks[0].key));
    await wait(1200);
    const builder = await a11yProblems(page);
    const canvas = await a11yProblems(page, { frame: '[data-pb-canvas]' });
    report.verdict('the builder and its canvas: every control has a name, no id twice', builder.length === 0 && canvas.length === 0, [...builder, ...canvas].join('; ') || 'none');
    await page.evaluate(() => window.pb.select(null));
  },
};
