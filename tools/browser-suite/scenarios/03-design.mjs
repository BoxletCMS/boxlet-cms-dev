/*
 * Slices 4 and 4.5: the design layer.
 *
 *   - apply each of the five characters; screenshot the home page under each
 *   - change one section's surface and rhythm: only that section changes
 *   - a colour pair that fails contrast is refused, and the message names the pair
 *   - with pages present, "design only" leaves section styles alone and
 *     "save and reset section styles" rewrites them
 *   - the admin looks identical under all five characters
 *
 * A character is applied in two clicks, which is deliberate: `preset:<name>` only LOADS
 * the preset into the form (DesignController: "Save is the confirmation"), and
 * `action=save` writes it. `action=save_composition` is the second, destructive action
 * that also rewrites every block's layer 2 and 3.
 *
 * Order matters: the section style is hand-tuned first, so that "design only" has
 * something to leave alone and "reset sections" has something to overwrite. The contrast
 * refusal runs last because it deliberately submits a broken palette, and the editorial
 * character is re-applied afterwards so later scenarios start from a sane design.
 */
import { readFileSync } from 'node:fs';
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN, CHECKOUT } from '../config.mjs';
import { login, clickAndWait, alerts, applyCharacter, controlsOnPanels, ensureHeaderMenu, openTab, retype } from '../harness.mjs';

const STYLE_GUIDE = 4;

/** Every front-end section's class attribute, which is where layers 2 and 3 land. */
const sectionClasses = (page) => page.$$eval('section', (els) => els.map((e) => e.className.trim()));

export default {
  name: 'design',
  // Runs against the throwaway copy: it applies every character and resets section
  // styles, which on the development site would overwrite the owner's design.
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('design: log in', `could not log in; at ${page.url()}`);
      return;
    }
    // The header's width is measured below, and a fresh copy has no header to measure.
    if (await ensureHeaderMenu(page, BASE) === '') {
      report.fail('design: its test data', 'no menu could be put in the header, so there is no header to measure');
    }

    const presets = await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' })
      .then(() => page.$$eval('button[name="action"][value^="preset:"]',
        (els) => els.map((e) => e.value.slice('preset:'.length))));
    report.verdict('the Appearance screen offers five characters', presets.length === 5, presets.join(', '));

    // THE SCREEN IS THE WINDOW (D-064): three columns that scroll on their own, under a bar
    // that does not. A page taller than the window here means the layout has come apart.
    const shell = await page.evaluate(() => ({
      page: document.documentElement.scrollHeight,
      window: window.innerHeight,
      columns: [...document.querySelectorAll('.appearance-rail, .appearance-stage-column, .appearance-inspector')].length,
      railFolded: document.querySelector('.admin-frame.rail-compact') !== null,
    }));
    report.verdict('the screen fills the window and does not scroll as a page',
      shell.page <= shell.window + 1 && shell.columns === 3 && shell.railFolded,
      `page ${shell.page}px in a window of ${shell.window}px, ${shell.columns} columns, admin rail folded: ${shell.railFolded}`);

    /*
     * AND IN A SHORT WINDOW, which is where it broke. Two things made the document taller
     * than the window: a three-row grid whose third row was only used when there was a
     * message, and the clipped radios a segmented control is built on, absolutely positioned
     * against the PAGE from inside a column that scrolls. Both were invisible at the height
     * this suite happened to run at.
     */
    const tall = page.viewport();
    await page.setViewport({ ...tall, height: 620 });
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    await new Promise((resolve) => { setTimeout(resolve, 500); });
    const short = await page.evaluate(() => ({
      page: document.documentElement.scrollHeight,
      window: window.innerHeight,
      sideways: ['.appearance-rail', '.appearance-inspector'].map((where) => {
        const column = document.querySelector(where);
        return column.scrollWidth - column.clientWidth;
      }),
    }));
    report.verdict('a short window gets short columns, not a longer page',
      short.page <= short.window + 1,
      `page ${short.page}px in a window of ${short.window}px`);
    await page.setViewport(tall);
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });

    /*
     * DRAGGED FROM 1600 TO 820 (docs/ispravci.md §A3). Four things went wrong at once and
     * only ever together: the admin rail could be opened as a FOURTH column from a button
     * this screen should not offer; the columns collapsed at 64rem of WINDOW while the admin
     * rail decided at 62.5rem, so between them sat a band with a wide rail and stacked
     * columns; and the picture chose its own width from every resize, so shrinking the
     * window walked it from desktop to phone under the owner's hand.
     *
     * Measured across the sweep rather than at one width, because each of those is a
     * RELATION between two widths and none of them shows at a single one.
     */
    const sweep = [];
    for (const width of [1600, 1440, 1280, 1200, 1100, 1024, 1000, 960, 900, 820]) {
      await page.setViewport({ ...tall, width });
      await new Promise((resolve) => { setTimeout(resolve, 250); });
      sweep.push(await page.evaluate((at) => {
        const body = document.querySelector('.appearance-body');
        const root = document.documentElement;
        /* Asked, not deduced from overflow: a box can report more scrollable content than it
           can be scrolled to, which is exactly how the stage hid a second scrollbar. */
        const scrollers = [...document.querySelectorAll('*')].filter((el) => {
          if (!/(auto|scroll)/.test(getComputedStyle(el).overflowY)) return false;
          const was = el.scrollTop;
          el.scrollTop = 99999;
          const max = el.scrollTop;
          el.scrollTop = was;
          return max > 0;
        }).length;
        return {
          at,
          rail: Math.round(document.querySelector('.admin-rail').getBoundingClientRect().width),
          columns: getComputedStyle(body).gridTemplateColumns.split(' ').length,
          frame: document.querySelector('iframe[data-design-preview]').style.width,
          page: root.scrollHeight,
          window: window.innerHeight,
          sideways: root.scrollWidth - root.clientWidth,
          scrollers,
        };
      }, width));
    }
    await page.setViewport(tall);

    const railWidths = [...new Set(sweep.map((s) => s.rail))];
    report.verdict('the admin rail keeps one width while this screen is resized',
      railWidths.length === 1, `widths seen: ${railWidths.join(', ')}`);
    report.verdict('the picture keeps the width it opened on',
      new Set(sweep.map((s) => s.frame)).size === 1,
      sweep.map((s) => `${s.at}:${s.frame}`).join(' '));
    report.verdict('the columns go three, then two, then one — never three to one',
      [...new Set(sweep.map((s) => s.columns))].join(',') === '3,2,1',
      sweep.map((s) => `${s.at}:${s.columns}`).join(' '));
    const spilling = sweep.filter((s) => s.sideways > 0 || s.page > s.window + 1);
    report.verdict('no width spills sideways or past the bottom of the window',
      spilling.length === 0,
      spilling.map((s) => `${s.at}: ${s.sideways}px sideways, ${s.page}px of page in ${s.window}px`).join('; ') || 'none of ten widths');
    const stacked = sweep.filter((s) => s.columns === 1);
    report.verdict('stacked, there is exactly one vertical scroll',
      stacked.length > 0 && stacked.every((s) => s.scrollers === 1),
      stacked.map((s) => `${s.at}:${s.scrollers}`).join(' ') || 'never stacked');

    // The strip over the picture had the slot for this from the first day and nothing ever
    // wrote into it: the size of the page being judged, and how much of it is on screen.
    const stageSays = await page.$eval('[data-stage-size]', (el) => el.textContent.trim());
    report.verdict('the strip says what size the picture is',
      /^\d+×\d+ · \d+%$/.test(stageSays), stageSays === '' ? 'the slot is empty' : stageSays);

    // The button that could open the admin rail over this screen as a fourth column. It is
    // in the markup for every other screen and hidden here by admin-nav.js.
    const toggle = await page.$eval('[data-rail-toggle]', (b) => b.hidden);
    report.verdict('this screen does not offer to open the admin rail', toggle === true,
      `rail toggle hidden: ${toggle}`);

    // And what takes the characters' place once they stop being a column.
    await page.setViewport({ ...tall, width: 1150 });
    await new Promise((resolve) => { setTimeout(resolve, 250); });
    await page.click('[data-rail-panel]');
    await new Promise((resolve) => { setTimeout(resolve, 200); });
    const panel = await page.evaluate(() => {
      const rail = document.getElementById('appearance-rail');
      return {
        open: document.querySelector('[data-rail-panel]').getAttribute('aria-expanded'),
        shown: getComputedStyle(rail).display !== 'none',
        cards: rail.querySelectorAll('.rail-card').length,
        // One rail, not a copy: two sets of these buttons would be two sets of submits.
        rails: document.querySelectorAll('.appearance-rail').length,
      };
    });
    report.verdict('the characters open as a panel when they are no longer a column',
      panel.open === 'true' && panel.shown && panel.cards === 5 && panel.rails === 1,
      JSON.stringify(panel));
    await page.setViewport(tall);
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });

    // The richest form in the admin, and judged before the loop below starts changing the
    // site's own colours — the guard reads computed backgrounds, and this screen is the one
    // place where a character could plausibly leak into the tool (SPEC §5.4 says it must not).
    //
    // ONCE PER TAB. Four panels in five are hidden, and a hidden control is one this guard
    // counts as unrendered rather than judging: called once, it would have covered a fifth
    // of the screen and said nothing about the rest (D-059).
    for (const tab of ['colour', 'type', 'shape', 'page', 'chrome']) {
      if (await openTab(page, tab)) {
        await controlsOnPanels(page, report, `appearance: ${tab}`);
      }
    }
    await openTab(page, 'colour');

    /*
     * ---- the preview draws the real header and footer (PLAN.md D-057) -------------------
     *
     * Measured INSIDE the frame, not on the screenshot: the picture is 20% of the window's
     * width, and at that size a header and a first section are one band of colour.
     */
    const frame = await page.$('iframe[data-design-preview]').then((el) => el && el.contentFrame());
    const chrome = frame === null ? null : await frame.evaluate(() => ({
      header: document.querySelector('header') !== null,
      footer: document.querySelector('footer') !== null,
      links: [...document.querySelectorAll('header nav a')].map((a) => a.textContent.trim()),
      description: document.querySelector('meta[name="description"]') !== null,
    }));
    report.verdict('the preview draws the site\'s own header and footer',
      chrome !== null && chrome.header && chrome.footer && chrome.links.length > 0,
      chrome === null ? 'no preview frame' : JSON.stringify(chrome));
    await report.shot(page, 'design-screen');

    /*
     * ---- the loop is closed (PLAN.md D-058) --------------------------------------------
     *
     * Three things the owner judges by feel, measured instead: the gauge is there, the
     * button that repeated what already happens is gone, and Save can be reached without
     * scrolling back past every control. The last one is why it moved: the left column is
     * over three thousand pixels tall.
     */
    const loop = await page.evaluate(() => {
      window.scrollTo(0, 1400);
      // Every action, not only the first: the destructive one is the second, and it was the
      // second that a sticky column put out of reach.
      const actions = [...document.querySelectorAll('.preview-actions button')];
      const boxes = actions.map((b) => b.getBoundingClientRect());
      return {
        rows: document.querySelectorAll('.gauge-row').length,
        open: document.querySelectorAll('[data-gauge-open] .gauge-row').length,
        ratios: [...document.querySelectorAll('[data-pair-ratio]')].slice(0, 3).map((e) => e.textContent),
        updateButton: document.querySelector('[data-preview-button]') !== null,
        actions: actions.length,
        saveInView: boxes.length > 0 && boxes.every((r) => r.top >= 0 && r.bottom <= window.innerHeight),
        columnHeight: document.body.scrollHeight,
      };
    });
    report.verdict('every contrast pair is measured on the screen', loop.rows === 12 && loop.open === 6,
      `${loop.rows} rows, ${loop.open} open, first ratios ${loop.ratios.join(', ')}`);
    report.verdict('the "Update preview" button is gone where JavaScript runs', !loop.updateButton,
      loop.updateButton ? 'it is still there' : 'the preview follows every change instead');
    report.verdict('every Save is in reach with the controls scrolled', loop.saveInView,
      `left column ${loop.columnHeight}px tall; ${loop.actions} action(s) ${loop.saveInView ? 'visible' : 'OFF SCREEN'}`);
    await page.evaluate(() => window.scrollTo(0, 0));

    /*
     * THE TABS ARE ONE ROW (docs/ispravci.md §C5). Five of them share about 280px, which
     * fits in English and does not in Croatian: the strip wrapped into two ragged rows, and
     * a strip that wraps unevenly reads as two strips.
     */
    const strip = await page.evaluate(() => {
      const tabs = [...document.querySelectorAll('.tab')];
      return {
        count: tabs.length,
        rows: new Set(tabs.map((t) => Math.round(t.getBoundingClientRect().top))).size,
        widths: [...new Set(tabs.map((t) => Math.round(t.getBoundingClientRect().width)))],
        titled: tabs.every((t) => (t.getAttribute('title') || '') === t.textContent.trim()),
      };
    });
    report.verdict('the five tabs are one row of equal columns, each with its whole name',
      strip.count === 5 && strip.rows === 1 && strip.widths.length === 1 && strip.titled,
      JSON.stringify(strip));

    /*
     * THE SPECIMEN IS THE SIZES, DRAWN (docs/ispravci.md §C2).
     *
     * It was fixed at 1.6rem, so the scale slider moved the number beside each line and the
     * lines themselves did not move — which took away the one thing a specimen is for. What
     * is asserted is the RELATION: every line is the server's own size shrunk by the SAME
     * factor, no two steps land on the same size, and moving the scale moves the picture.
     */
    await openTab(page, 'type');
    await new Promise((resolve) => { setTimeout(resolve, 400); });
    const specimen = () => page.evaluate(() => ({
      face: document.querySelector('.specimen').getAttribute('data-typeface'),
      lines: [...document.querySelectorAll('[data-specimen]')].map((line) => ({
        drawn: Math.round(parseFloat(getComputedStyle(line).fontSize)),
        said: parseFloat(line.querySelector('[data-specimen-size]').textContent),
        family: getComputedStyle(line).fontFamily.split(',')[0].replace(/["']/g, ''),
      })),
    }));
    const drawn = await specimen();
    const factors = drawn.lines.map((l) => l.drawn / l.said);
    const spread = Math.max(...factors) - Math.min(...factors);
    report.verdict('the specimen is the page\'s own sizes, shrunk together to fit',
      drawn.lines.length === 4
        // Rounding to whole pixels is the only thing that may separate the factors.
        && spread < 0.05
        && new Set(drawn.lines.map((l) => l.drawn)).size === 4
        && Math.max(...drawn.lines.map((l) => l.drawn)) <= 40,
      `${drawn.lines.map((l) => `${l.said}→${l.drawn}`).join(' ')}; factors differ by ${spread.toFixed(3)}`);
    /*
     * AND IT IS SET IN THE PAIRING BEING CHOSEN.
     *
     * The pairing is CHOSEN here rather than taken from whatever the screen opened on. This
     * asserted two families, which is a property of `editorial` and not of the specimen:
     * three of the six pairings — modern, rounded, mono — are one family for both halves,
     * so the verdict passed or failed on which character the copy happened to be left on by
     * the run before. The rule it means to state is that the heading lines take the heading
     * face and the body lines take the body face, and `classic` is a pairing where those two
     * can be told apart.
     */
    await page.evaluate(() => {
      var card = document.querySelector('input[name="typography"][value="classic"]');
      card.checked = true;
      card.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await new Promise((resolve) => { setTimeout(resolve, 400); });
    const paired = await specimen();
    report.verdict('the specimen is set in the pairing being chosen',
      paired.face === 'classic'
        && paired.lines[0].family === paired.lines[1].family
        && paired.lines[2].family === paired.lines[3].family
        && paired.lines[0].family !== paired.lines[3].family,
      `${paired.face}: ${paired.lines.map((l) => l.family).join(', ')}`);

    // And it MOVES. Dragging the scale is the case that used to change nothing on screen.
    const scaleWas = await page.$eval('#design-scale', (el) => el.value);
    await page.$eval('#design-scale', (el) => {
      el.value = el.max;
      el.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await new Promise((resolve) => { setTimeout(resolve, 1500); });
    const bigger = await specimen();
    report.verdict('dragging the scale changes the picture, not only the numbers',
      bigger.lines[1].drawn !== drawn.lines[1].drawn,
      `the subhead was ${drawn.lines[1].drawn}px beside a ${drawn.lines[0].drawn}px hero, `
      + `now ${bigger.lines[1].drawn}px beside ${bigger.lines[0].drawn}px`);
    // Put it back where it was: this check only looked.
    await page.$eval('#design-scale', (el, back) => {
      el.value = back;
      el.dispatchEvent(new Event('input', { bubbles: true }));
    }, scaleWas);
    await new Promise((resolve) => { setTimeout(resolve, 800); });
    await openTab(page, 'colour');

    /*
     * ONE LIST OF ROLES (docs/ispravci.md §C1). It used to be two — fifteen colours that
     * could not be touched, and a folded panel holding the seven that could — so the same
     * information sat in two places and the half that can be CHANGED was the half that was
     * closed. What this asserts is that there is ONE list, that every role is in it, and
     * that what can be done to a role is visible on its own row.
     */
    await openTab(page, 'colour');
    const palette = await page.evaluate(() => {
      const rows = [...document.querySelectorAll('.role')];
      const reset = document.querySelector('.palette-reset');
      return {
        rows: rows.length,
        editable: rows.filter((r) => r.querySelector('input[type="color"]')).length,
        said: rows.filter((r) => r.querySelector('.role-derived')).length,
        // The old shapes: a read-only swatch list, and the folded panel beside it.
        oldLists: document.querySelectorAll('.swatches, .by-hand').length,
        freeShown: rows.filter((r) => {
          const button = r.querySelector('.role-free');
          return button && getComputedStyle(button).display !== 'none';
        }).length,
        resetShown: !!reset && getComputedStyle(reset).display !== 'none',
        // A square, not a bar: every field's input carries a 2.5rem floor, and a floor
        // beats a height.
        square: rows.map((r) => {
          const box = (r.querySelector('input[type="color"]') || r.querySelector('svg')).getBoundingClientRect();
          return Math.round(box.width) === Math.round(box.height);
        }).every(Boolean),
      };
    });
    report.verdict('the palette is one list, and every role is in it',
      palette.rows === 16 && palette.editable === 7 && palette.said === 9
        && palette.oldLists === 0 && palette.square,
      JSON.stringify(palette));
    // Nothing is taken over yet, so nothing offers to give anything back.
    report.verdict('a colour offers to go back to the palette only once it is the owner\'s',
      palette.freeShown === 0 && !palette.resetShown,
      `at rest: ${palette.freeShown} revert button(s), reset all shown: ${palette.resetShown}`);

    /*
     * THE RELOAD LIST, KEPT HONEST BY THE SERVER (docs/ispravci.md §B).
     *
     * appearance.js swaps the preview's stylesheet instead of reloading it for any decision
     * that only changes tokens, which is nearly all of them — no white flash, no lost scroll
     * position, no fonts fetched again every 250ms while a slider is dragged. What it may
     * NOT do is take that path for a decision that changes the preview's markup, because
     * then the screen shows something the site will not do.
     *
     * Asked of the server, one control at a time, rather than read off anybody's intent:
     * the preview is fetched with each control moved and the HTML compared. The list is read
     * out of the file itself so there is one copy of it. One-directional on purpose —
     * something listed that turns out to be token-only costs a reload, which is exactly what
     * the screen used to do for everything.
     */
    const reloads = new RegExp(
      readFileSync(`${CHECKOUT}/public/assets/appearance.js`, 'utf8')
        .match(/var RELOADS = \/(.+?)\/;/)[1],
    );
    const escaped = await page.evaluate(async () => {
      const form = document.querySelector('form[data-design-form]');
      const url = form.getAttribute('data-preview-url');
      const serialise = (extra) => {
        const params = new URLSearchParams();
        new FormData(form).forEach((value, key) => {
          if (key !== '_csrf' && key !== 'action') params.append(key, value);
        });
        if (extra) params.set(extra[0], extra[1]);
        return params.toString();
      };
      // The stylesheet's href carries the whole query, so it differs for every variant by
      // construction; that is the one difference that is not markup.
      const html = async (q) => (await fetch(`${url}?${q}`, { credentials: 'same-origin' }).then((r) => r.text()))
        .replace(/<link[^>]*appearance\/stylesheet[^>]*>/g, '<link tokens>');
      const base = await html(serialise());

      const out = [];
      for (const el of form.querySelectorAll('input[name], select[name], textarea[name]')) {
        const name = el.name;
        if (['_csrf', 'action', 'library_name', 'character'].includes(name)) continue;
        let other = null;
        if (el.type === 'radio') {
          const group = [...form.querySelectorAll(`input[name="${CSS.escape(name)}"]`)];
          other = (group.find((r) => !r.checked) || {}).value;
        } else if (el.type === 'checkbox') other = el.checked ? '' : '1';
        else if (el.tagName === 'SELECT') other = ([...el.options].find((o) => o.value !== el.value) || {}).value;
        else if (el.type === 'range') other = el.value === el.max ? el.min : el.max;
        else if (el.type === 'color') other = el.value === '#123456' ? '#654321' : '#123456';
        else if (el.type === 'text') other = 'Zz probe words';
        if (other === null || other === undefined) continue;
        if (await html(serialise([name, other])) !== base) out.push(name);
      }
      return out;
    });
    const missed = escaped.filter((name) => !reloads.test(name));
    report.verdict('every decision that changes the preview\'s markup asks for a reload',
      escaped.length > 0 && missed.length === 0,
      missed.length > 0
        ? `${missed.join(', ')} change the markup and are not in RELOADS`
        : `${escaped.length} of the form's controls change the markup, all listed: ${escaped.join(', ')}`);

    /*
     * AND THE FAST PATH IS REAL. The list above only says what SHOULD reload; this watches
     * whether the document survives. A mark is put on the frame's own <html>, which nothing
     * but a navigation can remove — so it is still there after a token-only change and gone
     * after one that rebuilds the page.
     */
    const mark = () => page.evaluate(() => {
      const inside = document.querySelector('iframe[data-design-preview]').contentDocument;
      if (inside) inside.documentElement.dataset.probe = 'here';
      return inside !== null;
    });
    const marked = () => page.evaluate(() => {
      const inside = document.querySelector('iframe[data-design-preview]').contentDocument;
      return !!inside && inside.documentElement.dataset.probe === 'here';
    });
    const press = async (selector) => {
      await page.click(selector);
      await new Promise((resolve) => { setTimeout(resolve, 1200); });
    };

    await openTab(page, 'shape');
    await mark();
    await press('label.segment:has(input[name="radius"][value="pill"])');
    const survivedTokens = await marked();
    await openTab(page, 'page');
    await press('label.segment:has(input[name="header_bleed"][value="full"])');
    const survivedMarkup = await marked();
    report.verdict('a change that is only tokens does not reload the page in the frame',
      survivedTokens && !survivedMarkup,
      `after a corner radius the document ${survivedTokens ? 'survived' : 'WAS RELOADED'};`
      + ` after a header bleed it ${survivedMarkup ? 'SURVIVED' : 'was reloaded'}`);
    await page.click('label.segment:has(input[name="header_bleed"][value="sheet"])');
    await openTab(page, 'colour');

    /*
     * ---- the toolbar over the picture (PLAN.md D-060) ----------------------------------
     *
     * The one thing worth measuring rather than looking at: the frame must be LAID OUT at
     * the width being judged and then scaled. A frame simply made narrower would hand the
     * page a smaller window, and the page would answer with its phone layout.
     */
    const stage = async () => page.evaluate(() => {
      const frame = document.querySelector('iframe[data-design-preview]');
      const box = frame.getBoundingClientRect();
      const inside = document.querySelector('[data-stage]');
      return {
        laidOutAt: frame.style.width,
        onScreen: Math.round(box.width),
        scaled: frame.style.transform,
        fitsTheStage: Math.round(box.width) <= inside.clientWidth + 1,
        state: document.querySelector('[data-state]').textContent.trim(),
        src: frame.getAttribute('src'),
      };
    });

    const desktop = await stage();
    report.verdict('the desktop width is laid out at 1280 and scaled to fit',
      desktop.laidOutAt === '1280px' && desktop.fitsTheStage && /scale\(0\./.test(desktop.scaled),
      JSON.stringify(desktop));

    await page.click('[data-viewport="390"]');
    await new Promise((resolve) => { setTimeout(resolve, 300); });
    const phone = await stage();
    report.verdict('the phone width really is 390 across', phone.laidOutAt === '390px' && phone.onScreen === 390,
      JSON.stringify(phone));
    await report.shot(page, 'stage-phone');
    await page.click('[data-viewport="1280"]');

    /*
     * The two rules about which width the screen is on, both from the handoff's §2.3 and
     * both the kind of thing that goes quietly wrong: a picture nobody can read, and a zoom
     * that means something different from the width it was chosen for.
     */
    const stageState = () => page.evaluate(() => {
      const frame = document.querySelector('iframe[data-design-preview]');
      return {
        room: Math.round(document.querySelector('[data-stage]').clientWidth),
        pressed: [...document.querySelectorAll('[data-viewport]')]
          .filter((b) => b.getAttribute('aria-pressed') === 'true')
          .map((b) => Number(b.getAttribute('data-viewport')))[0],
        scale: Number((frame.style.transform.match(/scale\(([\d.]+)\)/) || [0, 1])[1]),
        zoom: document.querySelector('[data-zoom]').value,
      };
    });

    /*
     * A column too narrow to carry 1280 above the floor opens on a width it CAN carry.
     *
     * THE WINDOW MOVED, THE RULE DID NOT. This was 1100px, which used to leave the stage
     * 484px — under the 640 that 1280 needs at the floor. With the columns now going three
     * to two rather than three to one, 1100px hands the stage 736px and 1280 fits honestly,
     * so the old window no longer sets up the case at all. 960px does: 596px of stage, and
     * the screen has to choose the tablet. Changing the width the case is built at is not
     * the same as changing what it asserts, which is untouched.
     */
    const wide = page.viewport();
    await page.setViewport({ ...wide, width: 960 });
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    await new Promise((resolve) => { setTimeout(resolve, 600); });
    const narrow = await stageState();
    report.verdict('a column that cannot carry the desktop width opens on one it can',
      narrow.pressed < 1280 && narrow.scale >= 0.5,
      `${narrow.room}px of stage, opened on ${narrow.pressed} at scale ${narrow.scale}`);

    await page.setViewport(wide);
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    await new Promise((resolve) => { setTimeout(resolve, 600); });

    // And a new width comes with Fit: 100% of a desktop page means nothing on a phone.
    await page.select('[data-zoom]', '0.5');
    await new Promise((resolve) => { setTimeout(resolve, 300); });
    await page.click('[data-viewport="390"]');
    await new Promise((resolve) => { setTimeout(resolve, 400); });
    const switched = await stageState();
    report.verdict('changing the width brings the zoom back to Fit',
      switched.zoom === 'fit' && switched.pressed === 390,
      `zoom ${switched.zoom} at ${switched.pressed}, scale ${switched.scale}`);
    await page.click('[data-viewport="1280"]');

    /*
     * Compare is HELD: the frame shows the published design while the button is down, and
     * the owner's unsaved work the moment it comes up.
     *
     * READ OUT OF THE PICTURE, NOT OFF ITS ADDRESS. This used to assert that the frame's src
     * lost its query and got it back, which stood for "the picture changed" only while every
     * change was a reload. Now that a token-only change swaps the stylesheet inside the frame
     * (D-073), the address is deliberately the same before, during and after — and the old
     * assertion passed by comparing two identical strings, which is the worst way for a check
     * to survive. It reads the colour the page is actually painted in instead.
     */
    const accent = () => page.evaluate(() => {
      const inside = document.querySelector('iframe[data-design-preview]').contentDocument;
      return inside ? getComputedStyle(inside.documentElement).getPropertyValue('--color-accent').trim() : '';
    });
    // Something unpublished to compare AGAINST: without it both sides are the same picture
    // and the check says nothing. Put back afterwards, so what this leaves on the screen is
    // what it found — the checks below read the state the bar is in.
    await openTab(page, 'colour');
    const seedWas = await page.$eval('#design-seed', (el) => el.value);
    await page.$eval('#design-seed', (el) => {
      el.value = '#2d6a4f';
      el.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await page.waitForFunction(() => {
      const inside = document.querySelector('iframe[data-design-preview]').contentDocument;
      return inside && getComputedStyle(inside.documentElement).getPropertyValue('--color-accent').trim() === '#2d6a4f';
    }, { timeout: 15000 });
    const mine = await accent();
    await page.hover('[data-compare]');
    await page.mouse.down();
    await new Promise((resolve) => { setTimeout(resolve, 600); });
    const holding = await accent();
    await page.mouse.up();
    await new Promise((resolve) => { setTimeout(resolve, 600); });
    const released = await accent();
    report.verdict('Compare shows the published site while it is held',
      mine === '#2d6a4f' && holding !== mine && released === mine,
      `the screen's colour ${mine}, held ${holding}, released ${released}`);
    await page.$eval('#design-seed', (el, back) => {
      el.value = back;
      el.dispatchEvent(new Event('input', { bubbles: true }));
    }, seedWas);
    await new Promise((resolve) => { setTimeout(resolve, 600); });

    /*
     * ---- the two controls that were coarser than the question (PLAN.md D-062) ----------
     *
     * The width is a slider now, and what it says in rem has to be what the page is actually
     * laid out to — the one place a number on a control can quietly mean nothing.
     */
    await openTab(page, 'shape');
    const widthNow = await page.$eval('#design-container', (el) => el.value);
    await page.$eval('#design-container', (el) => {
      el.value = '44';
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await new Promise((resolve) => { setTimeout(resolve, 900); });
    const measured = await page.evaluate(() => {
      const frame = document.querySelector('iframe[data-design-preview]');
      const inside = frame.contentDocument;
      const container = inside ? inside.querySelector('main section .container') : null;
      // The content BOX, not the border box: a container carries side padding, and measuring
      // that instead was the first answer this check gave — 784px for a 704px measure.
      let content = 0;
      if (container) {
        const box = getComputedStyle(container);
        content = Math.round(container.getBoundingClientRect().width
          - parseFloat(box.paddingLeft) - parseFloat(box.paddingRight));
      }
      return {
        readout: document.querySelector('#design-container-value').textContent.trim(),
        token: inside ? getComputedStyle(inside.documentElement).getPropertyValue('--container-width').trim() : '',
        content,
      };
    });
    // The readout gives both units now (D-066): "44rem · 704px".
    report.verdict('the width slider says what the page is laid out to',
      measured.readout.startsWith('44rem') && measured.token === '44rem' && Math.abs(measured.content - 44 * 16) <= 2,
      `the control says ${measured.readout}, the page's token is ${measured.token}, the content measures ${measured.content}px (44rem is ${44 * 16}px)`);
    await report.shot(page, 'width-slider');
    await page.$eval('#design-container', (el, back) => {
      el.value = back;
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }, widthNow);

    /*
     * A choice is immediate: no click on anything called "update", and no waiting.
     *
     * Waited on inside the FRAME, for the same reason Compare is: the src no longer changes
     * when only the tokens do (D-073), so the address is no evidence either way.
     */
    const spacingBefore = await page.evaluate(() => {
      const inside = document.querySelector('iframe[data-design-preview]').contentDocument;
      return inside ? getComputedStyle(inside.documentElement).getPropertyValue('--space-m').trim() : '';
    });
    await openTab(page, 'shape');
    // A segment, deliberately: the width is a slider (D-062) and a slider is the one control
    // this screen still waits 250ms for. A closed set is a row of radios now (D-065), and
    // pressing one is a change like any other.
    await page.click('label.segment:has(input[name="spacing"][value="generous"])');
    await page.waitForFunction((was) => {
      const inside = document.querySelector('iframe[data-design-preview]').contentDocument;
      return inside && getComputedStyle(inside.documentElement).getPropertyValue('--space-m').trim() !== was;
    }, { timeout: 15000 }, spacingBefore);
    report.pass('choosing a value refreshes the preview by itself',
      `the picture followed the press with no button pressed: --space-m was ${spacingBefore}`);

    // And the screen says what it now is, rather than leaving the owner to remember.
    const said = await page.evaluate(() => ({
      state: document.querySelector('[data-state]').textContent.trim(),
      revert: !document.querySelector('[data-revert]').hidden,
    }));
    report.verdict('an unpublished change says so, and offers a way back', said.revert && said.state.length > 0,
      `the bar says ${JSON.stringify(said.state)}, Discard changes shown: ${said.revert}`);

    // ---- each character, seen on the home page and in the admin ------------------------
    const adminFingerprints = [];
    for (const preset of presets) {
      const errors = await applyCharacter(page, BASE, preset);
      if (errors.length > 0) {
        report.fail(`apply the ${preset} character`, `refused: ${errors.join(' | ')}`);
        continue;
      }

      await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
      await report.shot(page, `home-${preset}`);
      const painted = await page.evaluate(() => {
        const body = getComputedStyle(document.body);
        const heading = document.querySelector('h1');
        return {
          background: body.backgroundColor,
          font: body.fontFamily.split(',')[0].replace(/"/g, ''),
          headingSize: heading ? Math.round(parseFloat(getComputedStyle(heading).fontSize)) : null,
        };
      });
      report.pass(`apply the ${preset} character`, `home page: ${JSON.stringify(painted)}`);

      // The admin's own chrome must not move with the site's design (SPEC §5.4).
      await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
      adminFingerprints.push({
        preset,
        chrome: await page.evaluate(() => {
          const shell = getComputedStyle(document.querySelector('.admin') || document.body);
          const button = document.querySelector('.button');
          return [shell.backgroundColor, shell.color, shell.fontFamily.split(',')[0],
            button ? getComputedStyle(button).backgroundColor : 'none'].join(' | ');
        }),
      });
    }
    await report.shot(page, 'admin-under-last-character');

    const distinct = [...new Set(adminFingerprints.map((f) => f.chrome))];
    report.verdict('the admin looks identical under all five characters', distinct.length === 1,
      distinct.length === 1
        ? `same chrome under all ${adminFingerprints.length}: ${distinct[0]}`
        : `DIFFERS: ${JSON.stringify(adminFingerprints)}`);

    /*
     * ---- the page as a sheet, and the header's own width (PLAN.md D-031) ---------------
     *
     * Geometry rather than a screenshot. Twice today a downscaled image showed me something
     * the DOM then disproved — a second language switcher, and sections bleeding past the
     * sheet — because at a shrunk width an inset of seventy pixels is fifteen.
     *
     * The second verdict is the one SPEC §5.4 leans on when it exempts the page background
     * from the contrast pairs: nothing renders outside the sheet, so nothing has to be
     * legible against what surrounds it. And the third catches a rule that silently lost:
     * a full-width header measured the content's width, because two selectors of equal
     * specificity met and the other stylesheet was linked second.
     */
    for (const [preset, boxed, fullHeader] of [['soft', true, false], ['bold', false, true]]) {
      const refusedPage = await applyCharacter(page, BASE, preset);
      if (refusedPage.length > 0) {
        report.fail(`the ${preset} character applies`, refusedPage.join(' | '));
        continue;
      }

      await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
      const seen = await page.evaluate(() => {
        // The sheet is .page-sheet now; .page is what surrounds it (D-067).
        const sheet = document.querySelector('.page-sheet');
        const box = sheet.getBoundingClientRect();
        const outside = [];
        for (const el of document.querySelectorAll('.page-sheet > *, main > section')) {
          const r = el.getBoundingClientRect();
          if (r.width > 0 && (r.left < box.left - 0.5 || r.right > box.right + 0.5)) {
            outside.push(el.className.toString().slice(0, 30));
          }
        }
        const headerBox = document.querySelector('header.block-header > .container');
        const contentBox = document.querySelector('main section .container');

        return {
          around: getComputedStyle(document.body).backgroundColor,
          sheetColour: getComputedStyle(sheet).backgroundColor,
          sheetWidth: Math.round(box.width),
          viewport: window.innerWidth,
          header: headerBox ? Math.round(headerBox.getBoundingClientRect().width) : 0,
          content: contentBox ? Math.round(contentBox.getBoundingClientRect().width) : 0,
          outside,
        };
      });

      const inset = seen.sheetWidth < seen.viewport;
      report.verdict(`${preset}: the page is ${boxed ? 'a sheet with a margin around it' : 'the whole window'}`,
        boxed ? inset && seen.around !== seen.sheetColour : !inset,
        `sheet ${seen.sheetWidth} of ${seen.viewport}; around ${seen.around}, sheet ${seen.sheetColour}`);

      report.verdict(`${preset}: nothing renders outside the sheet`, seen.outside.length === 0,
        seen.outside.length === 0 ? 'every section stays within the page' : JSON.stringify(seen.outside));

      report.verdict(`${preset}: the header is ${fullHeader ? 'wider than the content' : 'the content\'s width'}`,
        fullHeader ? seen.header > seen.content : seen.header === seen.content,
        `header ${seen.header}, content ${seen.content}`);

      await report.shot(page, `page-${preset}`);
    }

    /*
     * ---- a colour set by hand (PLAN.md D-063) ------------------------------------------
     *
     * Nothing is published here: the screen is set, measured and put back. What is measured
     * is the thing that would go wrong quietly — every colour that DEPENDS on the one set by
     * hand has to follow it, on the screen and in the picture.
     */
    // Back to the screen: the checks above left the browser on the site itself.
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    await openTab(page, 'colour');
    // No panel to open any more: the seven roles that can be the owner's are rows in the one
    // palette list, in the open (D-074).
    const inkBefore = await page.$eval('#design-color_text', (el) => el.value);
    // Choosing a colour is taking the role over, so no switch is pressed here: that is the
    // product's rule now (D-065), and it exists because pressing them separately lost the
    // owner's colour to the next refresh.
    for (const [field, colour] of [['color_background', '#0d0d10'], ['color_link', '#8ab4f8']]) {
      await page.$eval(`#design-${field}`, (el, value) => {
        el.value = value;
        el.dispatchEvent(new Event('input', { bubbles: true }));
      }, colour);
    }
    /*
     * WAITED FOR, NOT SLEPT THROUGH. A fixed 1500ms passed once and failed once, on a check
     * whose whole subject is whether the dependent colours were worked out again: the answer
     * would have depended on how busy the machine was. The frame's address carries both
     * switches, and the ink changing is the server's answer having landed.
     */
    // The server's answer about THE LINK — the second of the two changes, and the one a
    // wait on the first would have missed, which is how this check passed once and failed
    // once with the same code.
    await page.waitForFunction(
      () => (document.querySelector('[data-swatch-value="link"]') || {}).textContent?.trim().toLowerCase() === '#8ab4f8',
      { timeout: 15000 },
    );
    // And the picture, once the frame it is drawn in has actually loaded it.
    await page.waitForFunction(
      () => {
        const inside = document.querySelector('iframe[data-design-preview]').contentDocument;
        return inside !== null
          && getComputedStyle(inside.documentElement).getPropertyValue('--color-background').trim() === '#0d0d10';
      },
      { timeout: 15000 },
    );

    const byHand = await page.evaluate(() => {
      const inside = document.querySelector('iframe[data-design-preview]').contentDocument;
      const page_ = inside ? getComputedStyle(inside.documentElement).getPropertyValue('--color-background').trim() : '';
      return {
        pageColour: page_,
        text: document.querySelector('#design-color_text').value,
        surface: document.querySelector('#design-color_surface').value,
        link: document.querySelector('#design-color_link').value,
        switches: [...document.querySelectorAll('[data-by-hand-switch]')]
          .filter((s) => s.checked).map((s) => s.getAttribute('data-by-hand-switch')),
        failing: [...document.querySelectorAll('.gauge-fails .gauge-name')].map((e) => e.textContent.trim()),
      };
    });
    report.verdict('a page colour set by hand carries the palette with it',
      byHand.pageColour === '#0d0d10' && byHand.text !== inkBefore && byHand.failing.length === 0,
      `the page is ${byHand.pageColour}, the text it works out is ${byHand.text} (was ${inkBefore}), `
      + `the tinted surface ${byHand.surface}, the link ${byHand.link} (${JSON.stringify(byHand.switches)}), `
      + `failing pairs: ${JSON.stringify(byHand.failing)}`);
    await report.shot(page, 'colours-by-hand');

    /*
     * Put both back, and leave nothing published: this scenario only looked.
     *
     * THROUGH THE CONTROL THE OWNER PRESSES, not the switch behind it. This used to click
     * the two checkboxes; they are the mechanism the form needs and are clipped to a pixel
     * now (D-074), and a check that reaches for something nobody can see stops being a check
     * of the screen. The rule it asserts — a colour can be given back — is unchanged.
     */
    await clickAndWait(page, '.palette-reset');
    await openTab(page, 'colour');
    report.verdict('the palette takes its colours back when asked',
      await page.$$eval('[data-by-hand-switch]', (boxes) => boxes.every((b) => !b.checked)),
      'every role is the palette\'s again');

    /*
     * ---- the designs the owner keeps (PLAN.md D-061) ------------------------------------
     *
     * The whole point is that work survives: keep what is on the screen, load a character
     * over it, bring the kept one back, and find the same values. Done through the admin as
     * the owner would, and cleaned up at the end by its own Delete.
     */
    const KEPT = 'Suite kept design';
    await applyCharacter(page, BASE, 'bold', 'save');
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    await openTab(page, 'colour');
    await retype(page, '#design-seed', '#123456').catch(() => {});
    const keptSeed = await page.$eval('#design-seed', (el) => el.value);
    await retype(page, '#library_name', KEPT);
    await clickAndWait(page, 'button[form="design-form"][value="library:save"]', 40000);

    const afterKeeping = await page.evaluate((name) => ({
      listed: [...document.querySelectorAll('.appearance-rail .rail-card-name')].map((h) => h.textContent.trim()),
      said: (document.querySelector('.notice') || {}).textContent?.trim() ?? '',
      stillOnScreen: document.querySelector('#design-seed').value,
    }), KEPT);
    report.verdict('a design can be kept, and the screen keeps what was kept',
      afterKeeping.listed.includes(KEPT) && afterKeeping.stillOnScreen === keptSeed,
      `${JSON.stringify(afterKeeping.listed)}; screen still ${afterKeeping.stillOnScreen}; said ${JSON.stringify(afterKeeping.said.slice(0, 70))}`);
    await report.shot(page, 'library');

    // Now throw the screen away with a character, and bring the kept design back.
    await applyCharacter(page, BASE, 'brutalist', 'save');
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    const useButton = await page.$$eval('.appearance-rail .rail-card', (cards, name) => {
      const card = cards.find((c) => c.querySelector('.rail-card-name').textContent.trim() === name);
      return card ? card.querySelector('[value^="library:use:"]').value : '';
    }, KEPT);
    await clickAndWait(page, `button[form="design-form"][value="${useButton}"]`, 40000);
    await openTab(page, 'colour');
    const broughtBack = await page.$eval('#design-seed', (el) => el.value);
    report.verdict('a kept design comes back exactly as it was kept', broughtBack === keptSeed,
      `kept ${keptSeed}, came back ${broughtBack}`);

    // And deleting it takes only itself.
    const deleteButton = useButton.replace('library:use:', 'library:delete:');
    await clickAndWait(page, `button[form="design-form"][value="${deleteButton}"]`, 40000);
    const left = await page.$$eval('.appearance-rail .rail-card-name', (hs) => hs.map((h) => h.textContent.trim()));
    report.verdict('the scenario takes its kept design away again', !left.includes(KEPT),
      `left in the library: ${JSON.stringify(left)}`);

    await applyCharacter(page, BASE, presets[0], 'save');

    // ---- one section's surface and rhythm ----------------------------------------------
    await page.goto(`${BASE}/style-guide`, { waitUntil: 'networkidle2' });
    const before = await sectionClasses(page);

    await page.goto(`${BASE}/admin/pages/${STYLE_GUIDE}/form`, { waitUntil: 'networkidle2' });
    const target = 1;
    await page.select(`select[name="blocks[${target}][style][surface]"]`, 'contrast');
    await page.select(`select[name="blocks[${target}][style][rhythm]"]`, 'airy');
    await clickAndWait(page, 'div.editor-actions button[name="action"][value="save"]');

    await page.goto(`${BASE}/style-guide`, { waitUntil: 'networkidle2' });
    const after = await sectionClasses(page);
    await report.shot(page, 'section-style-changed');

    const changed = before.map((cls, i) => cls !== after[i] ? i : null).filter((i) => i !== null);
    report.verdict('changing one section\'s surface and rhythm changes only that section',
      changed.length === 1 && changed[0] === target,
      `sections that changed: ${JSON.stringify(changed)}; section ${target} is now "${after[target]}"`);

    // ---- design only, then reset sections ------------------------------------------------
    const handTuned = after[target];
    await applyCharacter(page, BASE, presets[0], 'save');
    await page.goto(`${BASE}/style-guide`, { waitUntil: 'networkidle2' });
    const afterDesignOnly = await sectionClasses(page);
    report.verdict('"design only" leaves section styles alone',
      afterDesignOnly[target] === handTuned,
      `section ${target}: "${handTuned}" -> "${afterDesignOnly[target]}"`);

    await applyCharacter(page, BASE, presets[0], 'save_composition');
    await page.goto(`${BASE}/style-guide`, { waitUntil: 'networkidle2' });
    const afterReset = await sectionClasses(page);
    await report.shot(page, 'after-reset-sections');
    report.verdict('"save and reset section styles" rewrites them',
      afterReset[target] !== handTuned,
      `section ${target}: "${handTuned}" -> "${afterReset[target]}"`);

    // ---- the four things the owner saw (D-078) -------------------------------------------
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    const seen = await page.evaluate(() => {
      const stage = document.querySelector('.preview-stage');
      const select = document.querySelector('.zoom select');
      const name = document.querySelector('#library_name');
      const rail = name && name.closest('.appearance-rail');
      const toggle = document.querySelector('[data-hints-toggle]');
      return {
        // The frame is scaled but laid out at full size, so the stage used to report a
        // width it could not use: a scrollbar under a picture that fitted.
        stage: { client: stage.clientWidth, scroll: stage.scrollWidth },
        // Both of these were the browser's own controls, not the admin's.
        zoomGround: select ? getComputedStyle(select).backgroundColor : null,
        nameGround: name ? getComputedStyle(name).backgroundColor : null,
        nameBox: name ? getComputedStyle(name).boxSizing : null,
        // And the name reached the edge of the column it sits in.
        nameGap: name && rail ? Math.round(rail.getBoundingClientRect().right - name.getBoundingClientRect().right) : null,
        // What a control of this admin looks like, taken from two that already are: the chip
        // beside the zoom, and a field input on the same screen. The question is whether the
        // two in the report match their neighbours, which is the question the owner asked.
        // An UNPRESSED chip: the pressed one carries --ui-accent-soft to say it is the current
        // width, which is a state and not what a control of this admin is made of.
        chipGround: getComputedStyle(document.querySelector('.viewport:not([aria-pressed="true"])')).backgroundColor,
        fieldGround: getComputedStyle(document.querySelector('.appearance-inspector .field input, .appearance-inspector .field select')).backgroundColor,
        hints: {
          shown: toggle ? !toggle.hidden : false,
          state: document.querySelector('[data-inspector]').getAttribute('data-hints'),
          visible: [...document.querySelectorAll('.appearance-inspector .hint')].filter((h) => h.getClientRects().length > 0).length,
          total: document.querySelectorAll('.appearance-inspector .hint').length,
        },
      };
    });
    report.verdict('the picture does not scroll sideways when it fits',
      seen.stage.scroll <= seen.stage.client,
      `stage ${seen.stage.client}px wide reports ${seen.stage.scroll}px of scrollable width`);
    /*
     * EACH ONE LOOKS LIKE THE CONTROLS BESIDE IT, which is what "the browser's, not the
     * admin's" means on a screen.
     *
     * THREE EARLIER SHAPES OF THIS VERDICT WERE WRONG ABOUT THE INSTRUMENT and not about the
     * screen, which is worth the note: comparing with .appearance's own background, which
     * paints nothing; reading --ui-panel off documentElement, where it is not, because the
     * admin's tokens are declared on .admin; and then comparing with the FIRST viewport chip,
     * which is the pressed one and carries the accent. The screen measured the same each
     * time. A verdict that fails is a claim about the instrument until the instrument is
     * checked (CLAUDE.md).
     */
    report.verdict('the zoom and the design name are the admin\'s own controls',
      seen.zoomGround === seen.chipGround && seen.nameGround === seen.fieldGround && seen.nameBox === 'border-box',
      `zoom ${seen.zoomGround} beside a chip's ${seen.chipGround}; name ${seen.nameGround} (${seen.nameBox}) beside a field's ${seen.fieldGround}`);
    report.verdict('the design name keeps clear of the edge of its column',
      seen.nameGap !== null && seen.nameGap > 0, `${seen.nameGap}px`);
    report.verdict('the hints are off until they are asked for, and can be',
      seen.hints.shown && seen.hints.state === 'off' && seen.hints.visible === 0 && seen.hints.total > 10,
      JSON.stringify(seen.hints));

    await page.click('[data-hints-toggle]');
    const asked = await page.evaluate(() => ({
      state: document.querySelector('[data-inspector]').getAttribute('data-hints'),
      visible: [...document.querySelectorAll('.appearance-inspector .hint')].filter((h) => h.getClientRects().length > 0).length,
    }));
    // Only the OPEN tab's hints are rendered — the other four panels are closed — so what
    // this asserts is that asking brought some back, against none while it was off.
    report.verdict('asking for the hints brings them back',
      asked.state === 'on' && asked.visible > 0 && seen.hints.visible === 0,
      `${seen.hints.visible} shown while off -> ${asked.visible} when asked, of ${seen.hints.total} in the column`);
    await page.click('[data-hints-toggle]');

    // ---- or a colour of your own (D-076) -------------------------------------------------
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    await openTab(page, 'page');
    const ownAtRest = await page.evaluate(() => {
      const field = document.querySelector('.own-colour');
      const input = field && field.querySelector('input[type="color"]');
      const free = field && field.querySelector('.own-colour-free');
      return {
        there: field !== null,
        // No control is ever invisible at rest (CLAUDE.md): the picker is a real square.
        picker: input === null ? null : Math.round(input.getBoundingClientRect().width),
        // And the one that undoes it is NOT there until there is something to undo.
        freeShown: free !== null && getComputedStyle(free).display !== 'none',
      };
    });
    report.verdict('the page offers a colour of its own, and nothing to undo yet',
      ownAtRest.there && ownAtRest.picker >= 40 && !ownAtRest.freeShown,
      JSON.stringify(ownAtRest));
    await report.shot(page, 'own-colour-page');

    // Choosing is what makes it the owner's: the switch is mechanism and flips under the
    // hand, exactly as a hand-set palette role does (D-065).
    await page.$eval('input[name="page_background_colour"]', (el) => {
      el.value = '#101010';
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    const afterPicking = await page.evaluate(() => ({
      on: document.querySelector('input[name="page_background_colour_on"]').checked,
      freeShown: getComputedStyle(document.querySelector('.own-colour-free')).display !== 'none',
    }));
    report.verdict('choosing a colour takes the place over, and the way back appears',
      afterPicking.on && afterPicking.freeShown, JSON.stringify(afterPicking));

    // The header's own colour, published, and what the page then really draws.
    await openTab(page, 'chrome');
    await page.$eval('input[name="header_colour"]', (el) => {
      el.value = '#1b3a2f';
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await report.shot(page, 'own-colour-header');
    await clickAndWait(page, 'button[form="design-form"][name="action"][value="save"]');

    await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
    const headerDrawn = await page.evaluate(() => {
      const header = document.querySelector('header.block');
      if (header === null) {
        return null;
      }
      const link = header.querySelector('a');
      const seen = getComputedStyle(header);
      return {
        background: seen.backgroundColor,
        text: seen.color,
        // What a word inside is REALLY set in, computed the same way as the two above:
        // reading --section-link would compare "#fefbfb" with "rgb(254, 251, 251)" and
        // call two names for one colour a difference.
        link: link === null ? null : getComputedStyle(link).color,
        bodyBackground: getComputedStyle(document.body).backgroundColor,
      };
    });
    await report.shot(page, 'own-colour-drawn');
    report.verdict('the header on the site is drawn in the colour that was chosen',
      headerDrawn !== null && headerDrawn.background === 'rgb(27, 58, 47)',
      JSON.stringify(headerDrawn));
    // The ink is DERIVED from that colour, so it is one the page can be read in rather
    // than whatever the palette happened to hold.
    report.verdict('and the ink on it was worked out for it, not taken from the palette',
      headerDrawn !== null && headerDrawn.text !== headerDrawn.background && headerDrawn.link === headerDrawn.text,
      headerDrawn === null ? 'no header' : `text ${headerDrawn.text}, links ${headerDrawn.link}`);

    // Give it back, and the site returns to the palette's shade with nothing left behind.
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    await openTab(page, 'chrome');
    await clickAndWait(page, 'button[form="design-form"][name="action"][value="colour:free:header_colour"]');
    await clickAndWait(page, 'button[form="design-form"][name="action"][value="save"]');
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
    const givenBack = await page.evaluate(() => {
      const header = document.querySelector('header.block');
      return header === null ? null : getComputedStyle(header).backgroundColor;
    });
    report.verdict('giving the colour back leaves the header on the palette\'s shade',
      givenBack !== null && givenBack !== 'rgb(27, 58, 47)', String(givenBack));

    // ---- a palette that fails contrast ---------------------------------------------------
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    await page.$eval('input[name="seed"]', (el) => {
      el.value = '#ffff00';
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await clickAndWait(page, 'button[form="design-form"][name="action"][value="save"]');
    const refusal = await alerts(page);
    await report.shot(page, 'contrast-refused');

    const names = refusal.join(' ');
    const namesPair = /WCAG AA needs at least/i.test(names) && /\bis\s[\d.]+:1/.test(names);
    report.verdict('a colour pair that fails contrast is refused, and the message names the pair',
      refusal.length > 0 && namesPair,
      refusal.length === 0
        ? 'ACCEPTED a yellow seed with no error'
        : `refused with: "${refusal.join(' | ').slice(0, 240)}"`);

    // Leave the site on a sane design for the scenarios that follow.
    await applyCharacter(page, BASE, presets[0], 'save');
  },
};
