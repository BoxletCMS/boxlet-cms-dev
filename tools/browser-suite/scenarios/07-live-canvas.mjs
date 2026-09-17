/*
 * 2h: the canvas follows the text as it is typed.
 *
 * The server side already existed: builder-blocks.js redraw() posts the selected block's
 * current fields to the insert endpoint and swaps the returned section into the canvas,
 * with a per-block-key ticket so a late answer cannot overwrite newer text. What was
 * missing was one event — richtext.js assigned hidden.value and dispatched nothing, so a
 * rich text edit reached neither the canvas nor the unsaved-changes warning, while every
 * other field type worked because a person typing into a real control fires its own event.
 *
 * So this checks every field type, not just the one that was broken: a heading (text
 * input), rich text (TipTap), and a select. Nothing is saved until the last item says so.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, clickAndWait, SLOW } from '../harness.mjs';

const PAGE = 1;
const SETTLE = 1200; // the debounce is 300ms; this is the "within about a second" bar

const canvasText = (page) => page.evaluate(() => {
  const frame = document.querySelector('iframe[data-canvas]');
  return frame && frame.contentDocument ? frame.contentDocument.body.textContent : '';
});

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export default {
  name: 'live-canvas',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('live canvas: log in', `could not log in; at ${page.url()}`);
      return;
    }

    await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
    const ready = await page.waitForFunction(() => {
      const frame = document.querySelector('iframe[data-canvas]');
      return frame && frame.contentDocument
        && frame.contentDocument.querySelectorAll('[data-bx-blocks] > section').length > 0;
    }, { timeout: 20000 }).then(() => true).catch(() => false);

    if (!ready) {
      report.fail('the canvas loads', 'no sections after 20s');
      return;
    }

    // Select the first block through the canvas, the way a person does.
    const frame = page.frames().find((f) => f.url().includes('/canvas'));
    await (await frame.$('[data-bx-blocks] > section:nth-of-type(1)')).click();
    await page.waitForFunction(() => {
      const group = document.querySelector('[data-block-group="0"]');
      return group && !group.hidden;
    }, { timeout: 8000 });
    await report.shot(page, '01-selected');

    const stamp = Date.now().toString(36).slice(-4);

    // ---- a heading: a plain text input -------------------------------------------------
    const headingField = await page.$('[data-block-group="0"] input[name$="[heading]"]');
    if (headingField) {
      const typed = `Heading ${stamp}`;
      // Select-all through the keyboard, not click({clickCount: 3}). The triple click
      // selects nothing here, so typing INSERTS into the middle of the existing value:
      // that is how the demo's h1 became "Small studio, carefully mSaved 1x01Dirty save
      // probeade websites". The substring check below still passed, which is worse —
      // a green verdict over mangled text.
      await headingField.click();
      await page.keyboard.down('Control');
      await page.keyboard.press('KeyA');
      await page.keyboard.up('Control');
      await page.keyboard.press('Backspace');
      await headingField.type(typed, { delay: SLOW });
      await wait(SETTLE);
      const shown = (await canvasText(page)).includes(typed);
      await report.shot(page, '02-heading-typed');
      report.verdict('typing in a heading shows in the canvas within a second', shown,
        shown ? `"${typed}" is on the canvas` : `"${typed}" never reached the canvas`);
    } else {
      report.skip('typing in a heading shows in the canvas', 'the first block has no heading field');
    }

    // ---- rich text: the case that was broken ---------------------------------------------
    //
    // Not block 0. Only `text` and `image_text` carry a rich text body; the demo's first
    // block is a `hero`, which is heading, subheading, image and cta. Selecting block 0
    // for every field type skipped the one field this part exists to prove.
    const richIndex = await page.evaluate(() => {
      const groups = Array.from(document.querySelectorAll('[data-block-group]'));
      const found = groups.find((g) => g.querySelector('textarea[data-richtext-source], .ProseMirror'));
      return found ? found.getAttribute('data-block-group') : null;
    });

    if (richIndex !== null) {
      const frameNow = page.frames().find((f) => f.url().includes('/canvas'));
      await (await frameNow.$(`[data-bx-index="${richIndex}"]`)).click();
      await page.waitForFunction((i) => {
        const group = document.querySelector(`[data-block-group="${i}"]`);
        return group && !group.hidden;
      }, { timeout: 8000 }, richIndex);
    }

    const editor = richIndex === null ? null : await page.$(`[data-block-group="${richIndex}"] .ProseMirror`);
    if (editor) {
      const typed = `Rich ${stamp}`;
      await editor.click();
      await page.keyboard.down('Control');
      await page.keyboard.press('KeyA');
      await page.keyboard.up('Control');
      await page.keyboard.type(typed, { delay: SLOW });
      await wait(SETTLE);
      const shown = (await canvasText(page)).includes(typed);
      await report.shot(page, '03-richtext-typed');
      report.verdict('typing rich text shows in the canvas within a second', shown,
        shown ? `"${typed}" is on the canvas` : `"${typed}" never reached the canvas — the editor is not announcing its edits`);
    } else {
      report.skip('typing rich text shows in the canvas', 'the first block has no rich text field');
    }

    // ---- a select: layout or section style -------------------------------------------------
    //
    // Whichever block is selected NOW, not block 0. The rich text step above moves the
    // selection to a block that has a body, and a select changed inside a hidden group
    // redraws nothing — the first version of this item edited one block and then measured
    // another, and reported the untouched one as a failure.
    const current = await page.evaluate(() => {
      const shown = Array.from(document.querySelectorAll('[data-block-group]')).find((g) => !g.hidden);
      return shown ? shown.getAttribute('data-block-group') : null;
    });

    const select = current === null ? null : await page.$(
      `[data-block-group="${current}"] select[name$="[layout]"], [data-block-group="${current}"] select[name*="[style]"]`,
    );

    if (select) {
      const { name, from, to } = await page.evaluate((el) => {
        const options = Array.from(el.options).map((o) => o.value);
        return { name: el.name, from: el.value, to: options.find((v) => v !== el.value) };
      }, select);
      const classesOf = (index) => page.evaluate((i) => {
        const frame = document.querySelector('iframe[data-canvas]');
        const section = frame.contentDocument.querySelector(`[data-bx-index="${i}"]`);
        return section ? section.className : '';
      }, index);

      const before = await classesOf(current);
      await page.select(`[data-block-group="${current}"] [name="${name}"]`, to);
      await wait(SETTLE);
      const after = await classesOf(current);

      await report.shot(page, '04-select-changed');
      report.verdict('changing a select redraws the section in the canvas', before !== after,
        `block ${current}, ${name}: ${from} -> ${to}; classes "${before}" -> "${after}"`);
    } else {
      report.skip('changing a select redraws the section',
        current === null ? 'no block is selected' : `block ${current} has no layout or style select`);
    }

    // ---- nothing was saved ------------------------------------------------------------------
    const onFrontEnd = await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' })
      .then(() => page.evaluate(() => document.body.textContent));
    report.verdict('none of that was saved', !onFrontEnd.includes(stamp),
      onFrontEnd.includes(stamp) ? `the typed text ${stamp} is on the live page` : 'the live page is unchanged');

    // ---- and after a real save, the page matches what the canvas showed -----------------------
    await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
    await page.waitForFunction(() => {
      const frame = document.querySelector('iframe[data-canvas]');
      return frame && frame.contentDocument
        && frame.contentDocument.querySelectorAll('[data-bx-blocks] > section').length > 0;
    }, { timeout: 20000 });
    await (await (page.frames().find((f) => f.url().includes('/canvas')))
      .$('[data-bx-blocks] > section:nth-of-type(1)')).click();
    await page.waitForFunction(() => {
      const group = document.querySelector('[data-block-group="0"]');
      return group && !group.hidden;
    }, { timeout: 8000 });

    const saveMark = `Saved ${stamp}`;
    const field = await page.$('[data-block-group="0"] input[name$="[heading]"]');

    // What the demo said before this scenario overwrote it. Earlier runs typed into the
    // home page's heading, saved, and never put it back — the demo's h1 became "Small
    // studio, carefully mSaved 1x01Dirty save probeade websites", and every screenshot
    // taken afterwards was judged against damaged content.
    const original = field ? await page.evaluate((el) => el.value, field) : null;

    if (field) {
      await field.click();
      await page.keyboard.down('Control');
      await page.keyboard.press('KeyA');
      await page.keyboard.up('Control');
      await page.keyboard.press('Backspace');
      await field.type(saveMark, { delay: SLOW });
      await wait(SETTLE);
      const inCanvas = (await canvasText(page)).includes(saveMark);

      // The builder has TWO save buttons with the same name and value: a visually-hidden
      // one first (so Enter in a field saves) and the visible one in the toolbar. Clicking
      // by :not(.visually-hidden) matched, but the click raced the in-flight redraw and the
      // navigation never settled. Submit the form itself and wait for the load.
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }),
        page.evaluate(() => {
          const form = document.querySelector('form[data-builder]');
          const action = document.createElement('input');
          action.type = 'hidden';
          action.name = 'action';
          action.value = 'save';
          form.appendChild(action);
          form.submit();
        }),
      ]);

      const live = await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' })
        .then(() => page.evaluate(() => document.body.textContent));
      await report.shot(page, '05-after-save');
      report.verdict('after saving, the page matches what the canvas showed',
        inCanvas && live.includes(saveMark),
        `canvas showed it: ${inCanvas}; the saved page has it: ${live.includes(saveMark)}`);

      // Put the demo's heading back. This scenario saves into the home page, and without
      // this the damage is permanent and cumulative: every later screenshot, and the
      // owner's own look at the demo, is judged against text these checks mangled.
      if (original !== null) {
        await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
        await page.waitForFunction(() => {
          const frame = document.querySelector('iframe[data-canvas]');
          return frame && frame.contentDocument
            && frame.contentDocument.querySelectorAll('[data-bx-blocks] > section').length > 0;
        }, { timeout: 20000 }).catch(() => {});
        const frameNow = page.frames().find((f) => f.url().includes('/canvas'));
        if (frameNow) {
          await (await frameNow.$('[data-bx-blocks] > section:nth-of-type(1)')).click();
          await page.waitForFunction(() => {
            const group = document.querySelector('[data-block-group="0"]');
            return group && !group.hidden;
          }, { timeout: 8000 }).catch(() => {});
        }
        const restore = await page.$('[data-block-group="0"] input[name$="[heading]"]');
        if (restore) {
          // Set directly, then fire the events the builder listens for. A restore that
          // typed would be subject to the same insert-into-the-middle problem it is here
          // to undo, and would leave the demo worse each run.
          await page.$eval('[data-block-group="0"] input[name$="[heading]"]', (el, value) => {
            el.value = value;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
          }, original);
          await wait(SETTLE);
          await clickAndWait(page, 'form[data-builder] button[name="action"][value="save"]:not(.visually-hidden)');
        }
        const back = await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' })
          .then(() => page.evaluate(() => document.body.textContent));
        report.verdict('the demo heading is left as it was found', back.includes(original),
          back.includes(original) ? `restored to "${original}"` : `NOT restored; expected "${original}"`);
      }
    } else {
      report.skip('after saving, the page matches what the canvas showed', 'no heading field to save');
    }
  },
};
