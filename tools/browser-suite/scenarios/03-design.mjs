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
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
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

    // A column too narrow to carry 1280 above the floor opens on a width it CAN carry.
    const wide = page.viewport();
    await page.setViewport({ ...wide, width: 1100 });
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

    // Compare is HELD: the frame shows the published design while the button is down, and
    // the owner's unsaved work the moment it comes up.
    const mine = (await stage()).src;
    await page.hover('[data-compare]');
    await page.mouse.down();
    await new Promise((resolve) => { setTimeout(resolve, 250); });
    const holding = (await stage()).src;
    await page.mouse.up();
    await new Promise((resolve) => { setTimeout(resolve, 250); });
    const released = (await stage()).src;
    report.verdict('Compare shows the published site while it is held',
      !holding.includes('?') && released === mine,
      `held ${holding.slice(-40)}, released ${released.slice(-40)}`);

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

    // A choice is immediate: no click on anything called "update", and no waiting.
    const framedBefore = await page.$eval('iframe[data-design-preview]', (el) => el.src);
    await openTab(page, 'shape');
    // A segment, deliberately: the width is a slider (D-062) and a slider is the one control
    // this screen still waits 250ms for. A closed set is a row of radios now (D-065), and
    // pressing one is a change like any other.
    await page.click('label.segment:has(input[name="spacing"][value="generous"])');
    await page.waitForFunction((was) => document.querySelector('iframe[data-design-preview]').src !== was, {}, framedBefore);
    report.pass('choosing a value refreshes the preview by itself', 'the frame followed the select with no button pressed');

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
    await page.evaluate(() => { document.querySelector('.by-hand').open = true; });
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

    // Put both back, and leave nothing published: this scenario only looked.
    await page.click('[data-by-hand-switch="color_background"]');
    await page.click('[data-by-hand-switch="color_link"]');
    report.verdict('the switches give a colour back to the palette',
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
