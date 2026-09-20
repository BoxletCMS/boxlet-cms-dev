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
import { login, clickAndWait, alerts, applyCharacter, controlsOnPanels, ensureHeaderMenu } from '../harness.mjs';

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

    const presets = await page.goto(`${BASE}/admin/design`, { waitUntil: 'networkidle2' })
      .then(() => page.$$eval('button[name="action"][value^="preset:"]',
        (els) => els.map((e) => e.value.slice('preset:'.length))));
    report.verdict('the Design screen offers five characters', presets.length === 5, presets.join(', '));

    // The richest form in the admin, and judged before the loop below starts changing the
    // site's own colours — the guard reads computed backgrounds, and this screen is the one
    // place where a character could plausibly leak into the tool (SPEC §5.4 says it must not).
    await controlsOnPanels(page, report, 'design');

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
        const sheet = document.querySelector('.page');
        const box = sheet.getBoundingClientRect();
        const outside = [];
        for (const el of document.querySelectorAll('.page > *, main > section')) {
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
    await page.goto(`${BASE}/admin/design`, { waitUntil: 'networkidle2' });
    await page.$eval('input[name="seed"]', (el) => {
      el.value = '#ffff00';
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await clickAndWait(page, 'form.design-form button[name="action"][value="save"]');
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
