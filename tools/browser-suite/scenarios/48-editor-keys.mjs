/*
 * THE CANVAS AND THE FORM ARE PAIRED BY KEY, and what undo takes back (PLAN.md D-117).
 *
 * The owner, 2026-09-25: "ponekad kad ubacim par blokova, ikona za brisanje bloka ne radi
 * sve dok ne spremim stranicu". Measured: a block added fourth on the page stood seventh in
 * the form, and Remove took the page's LAST block — off the screen — while the one pressed
 * stayed. Every tool found its block on the canvas by the FORM's position. The same review
 * measured five more: two new bands with one name, a band copy carrying its original's id,
 * undo losing a band's own fields and everything typed since the last structural change,
 * and four blocks invisible the moment they were added. And "sadržaj izlazi izvan linija
 * bloka", which was the Logos block's pictures.
 *
 * ON THE DEVELOPMENT SITE, AND IT SAVES NOTHING. Every check here happens in the editor
 * before Save, and every one starts from a fresh load of the page, so nothing one leaves
 * behind can decide the next. Leaving the page discards it all.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

const PAGE = 1;
const SETTLE = 1500;
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** The page as the editor holds it: the canvas's blocks, the form's groups, the bands. */
const state = (page) => page.evaluate(() => {
  const doc = document.querySelector('iframe[data-canvas]').contentDocument;

  return {
    canvas: [...doc.querySelectorAll('[data-bx-blocks] > section .section-column > *')]
      .map((b) => b.getAttribute('data-bx-key')),
    form: [...document.querySelectorAll('[data-block-group]')].map((g) => g.getAttribute('data-block-key')),
    bands: [...doc.querySelectorAll('[data-bx-blocks] > section')].map((b) => b.getAttribute('data-bx-section')),
    bandGroups: [...document.querySelectorAll('[data-section-group]')].map((g) => {
      const id = g.querySelector('input[name$="[id]"]');

      return g.getAttribute('data-section-group') + (id ? `=${id.value}` : '');
    }),
    selected: (doc.querySelector('.section-column > .bx-selected') || { getAttribute: () => null })
      .getAttribute('data-bx-key'),
  };
});

const sameSet = (a, b) => a.length === b.length && [...a].sort().join(' ') === [...b].sort().join(' ');

export default {
  name: 'editor-keys',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('editor-keys: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1700, height: 1100, deviceScaleFactor: 1 });
    const open = async () => {
      await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
      await page.waitForFunction(() => {
        const frame = document.querySelector('iframe[data-canvas]');
        return frame && frame.contentDocument && frame.contentDocument.querySelector('[data-bx-index]');
      }, { timeout: 20000 }).catch(() => {});
      await wait(SETTLE);

      return page.frames().find((f) => f.url().includes('/canvas'));
    };
    /** A new band at `at`, then the block of `card` (a library card's own word) put in it. */
    const addInNewBand = async (frame, at, card) => {
      await frame.click(`.bx-insert[data-insert-at="${at}"]`);
      await wait(SETTLE * 2);
      const band = await frame.evaluate(() => [...document.querySelectorAll('[data-bx-section]')]
        .map((b) => b.getAttribute('data-bx-section')).find((k) => /^m/.test(k)));
      await frame.click(`.bx-slot[data-insert-into="${band}"]`);
      await wait(SETTLE);
      const cards = await page.$$('.library-card');
      let pressed = false;
      for (const one of cards) {
        if ((await one.evaluate((c) => c.textContent.trim().split('\n')[0].trim())) === card) {
          await one.click();
          pressed = true;
          break;
        }
      }
      await wait(SETTLE * 2);

      return { band, pressed };
    };

    // ---- the two sides carry the same names from the first render ------------------
    let frame = await open();
    const start = await state(page);
    report.verdict('every block on the canvas carries the key its field group has, from the server',
      start.canvas.length > 0 && start.canvas.every((k) => /^b[0-9]+$/.test(k)) && sameSet(start.canvas, start.form),
      JSON.stringify({ canvas: start.canvas, form: start.form }));

    // ---- remove, after a block was added where the two orders part -----------------
    //
    // A new band in the middle, and a block in it: the canvas has it in the middle and the
    // form at the end. This is the owner's report, and it failed as "the last block went".
    const added = await addInNewBand(frame, 1, 'Text');
    const withNew = await state(page);
    const fresh = withNew.selected;
    await frame.click('.bx-tools [data-block-action="remove"]');
    await wait(SETTLE);
    const removed = await state(page);
    report.verdict('Remove takes the block that was pressed, on the canvas and in the form alike',
      added.pressed && fresh !== null && withNew.canvas.indexOf(fresh) !== withNew.form.indexOf(fresh)
        && !removed.canvas.includes(fresh) && !removed.form.includes(fresh)
        && sameSet(removed.canvas, start.canvas) && sameSet(removed.form, start.form),
      JSON.stringify({ fresh, before: withNew, after: removed }));

    // ---- a new block that draws nothing still has a place --------------------------
    frame = await open();
    await addInNewBand(frame, 1, 'Call to action');
    const empty = await frame.evaluate(() => {
      const block = document.querySelector('.section-column > .bx-selected');
      return block ? { marked: block.hasAttribute('data-bx-empty'), height: Math.round(block.getBoundingClientRect().height) } : null;
    });
    report.verdict('a block added empty is drawn as a place with room, not as nothing',
      empty !== null && empty.marked && empty.height >= 70, JSON.stringify(empty));
    await report.shot(page, '01-empty-block');
    const heading = await page.evaluate(() => {
      const group = [...document.querySelectorAll('[data-block-group]')].find((g) => !g.hidden);
      const field = group && group.querySelector('input[name$="[heading]"]');
      return field ? field.name : null;
    });
    if (heading) {
      await page.click(`[name="${heading}"]`);
      await page.keyboard.type('Talk to us', { delay: 30 });
      await wait(SETTLE * 2);
    }
    const filled = await frame.evaluate(() => {
      const block = document.querySelector('.section-column > .bx-selected');
      return block ? { marked: block.hasAttribute('data-bx-empty'), text: block.innerText.trim().slice(0, 40) } : null;
    });
    report.verdict('and the place gives way to the block the moment there is something to draw',
      // innerText is what is DRAWN, and a character may set headings in capitals:
      // measured, "TALK TO US". The words are the check, not their case.
      heading !== null && filled !== null && !filled.marked && filled.text.toLowerCase().includes('talk to us'),
      JSON.stringify(filled));

    // ---- two new bands in a row are two names --------------------------------------
    frame = await open();
    await frame.click('.bx-insert[data-insert-at="1"]');
    await wait(SETTLE * 2);
    await frame.click('.bx-insert[data-insert-at="1"]');
    await wait(SETTLE * 2);
    const two = await state(page);
    const made = two.bands.filter((k) => /^m/.test(k));
    report.verdict('two sections added one after the other have two different keys',
      made.length === 2 && made[0] !== made[1], JSON.stringify(two.bands));

    // ---- a band's copy is a new band ---------------------------------------------------
    frame = await open();
    await page.evaluate(() => document.querySelectorAll('[data-outline-section]')[1].click());
    await wait(SETTLE);
    const original = await state(page);
    await frame.click('[data-block-action="band-duplicate"]');
    await wait(SETTLE * 2);
    const copied = await state(page);
    const copy = copied.bandGroups.find((g) => /^m/.test(g));
    report.verdict('a copied section carries no id, so a save cannot write it into its original',
      copy !== undefined && !copy.includes('='), JSON.stringify(copied.bandGroups));
    report.verdict('and every block in the copy is paired with its own fields',
      sameSet(copied.canvas, copied.form) && copied.canvas.length > original.canvas.length,
      JSON.stringify({ canvas: copied.canvas, form: copied.form }));

    // ---- undo puts a removed band's own fields back --------------------------------
    frame = await open();
    await page.evaluate(() => document.querySelectorAll('[data-outline-section]')[1].click());
    await wait(SETTLE);
    const whole = await state(page);
    await frame.click('[data-block-action="band-remove"]');
    await wait(SETTLE);
    await page.click('[data-undo-button]');
    await wait(SETTLE * 2);
    const back = await state(page);
    report.verdict('undoing a removed section brings back its settings as well as its blocks',
      back.bandGroups.join(' ') === whole.bandGroups.join(' ') && sameSet(back.canvas, whole.canvas),
      JSON.stringify({ before: whole.bandGroups, after: back.bandGroups }));

    // ---- a section's surface is an edit, and undo takes it back ---------------------
    //
    // It was painted straight onto the band with no step at all, so there was nothing to
    // undo; now any change among the fields is recorded before it happens.
    frame = await open();
    await page.evaluate(() => document.querySelectorAll('[data-outline-section]')[1].click());
    await wait(SETTLE);
    const surface = () => page.evaluate(() => {
      const group = [...document.querySelectorAll('[data-section-group]')].find((g) => !g.hidden);
      const key = group.getAttribute('data-section-group');
      const band = document.querySelector('iframe[data-canvas]').contentDocument
        .querySelector(`[data-bx-section="${key}"]`);
      const checked = group.querySelector('input[name$="[style][surface]"]:checked');

      return {
        field: checked ? checked.value : null,
        drawn: [...band.classList].find((c) => c.indexOf('surface-') === 0) || null,
      };
    });
    const was = await surface();
    const other = was.field === 'contrast' ? 'tinted' : 'contrast';
    await page.evaluate((value) => {
      const group = [...document.querySelectorAll('[data-section-group]')].find((g) => !g.hidden);
      const radio = group.querySelector(`input[name$="[style][surface]"][value="${value}"]`);
      (radio.closest('label') || radio).id = 'zz-surface';
    }, other);
    await page.click('#zz-surface');
    await wait(SETTLE);
    const changed = await surface();
    await page.click('[data-undo-button]');
    await wait(SETTLE * 2);
    await page.evaluate(() => document.querySelectorAll('[data-outline-section]')[1].click());
    await wait(SETTLE);
    const undone = await surface();
    report.verdict('changing a section\'s surface can be undone, on the canvas and in its field',
      changed.field === other && changed.drawn === `surface-${other}`
        && undone.field === was.field && undone.drawn === was.drawn,
      JSON.stringify({ was, changed, undone }));

    // ---- undo takes back the writing first, and the structure after it -------------
    frame = await open();
    const keys = (await state(page)).canvas;
    let target = null;
    for (const key of keys.slice(1)) {
      const name = await page.evaluate((k) => {
        const group = document.querySelector(`[data-block-key="${k}"]`);
        const field = group && group.querySelector('input[name$="[heading]"]');
        return field ? field.name : null;
      }, key);
      if (name) {
        target = { key, name };
        break;
      }
    }
    if (target === null) {
      report.fail('undo order', 'no block after the first has a heading to type into');
    } else {
      await frame.click(`[data-bx-key="${keys[0]}"]`);
      await wait(SETTLE);
      await frame.click('.bx-tools [data-block-action="remove"]');
      await wait(SETTLE);
      await frame.click(`[data-bx-key="${target.key}"]`);
      await wait(SETTLE);
      const was = await page.$eval(`[name="${target.name}"]`, (e) => e.value);
      await page.click(`[name="${target.name}"]`);
      await page.keyboard.press('End');
      await page.keyboard.type(' PROBE', { delay: 30 });
      await wait(SETTLE);
      await page.click('[data-undo-button]');
      await wait(SETTLE * 2);
      const first = await page.evaluate((n, k) => ({
        value: (document.querySelector(`[name="${n}"]`) || {}).value,
        removedBack: !!document.querySelector('iframe[data-canvas]').contentDocument.querySelector(`[data-bx-key="${k}"]`),
      }), target.name, keys[0]);
      await page.click('[data-undo-button]');
      await wait(SETTLE * 2);
      const second = await page.evaluate((n, k) => ({
        value: (document.querySelector(`[name="${n}"]`) || {}).value,
        removedBack: !!document.querySelector('iframe[data-canvas]').contentDocument.querySelector(`[data-bx-key="${k}"]`),
      }), target.name, keys[0]);
      report.verdict('the first undo takes back what was typed, and leaves the removal',
        first.value === was && !first.removedBack, JSON.stringify({ was, first }));
      report.verdict('the second takes back the removal, and keeps the text as it was then',
        second.value === was && second.removedBack, JSON.stringify(second));
    }

    // ---- a drag can be undone ----------------------------------------------------------
    //
    // It never could: the form was compared with the drag's result after builder.js had
    // already replayed the drag onto it, so the two always agreed. Driven the way Sortable
    // drives it — the start reported, a moment, then the move and the end — because the
    // editor reads the page when the start ARRIVES, and a start and a move sent in one
    // breath would measure a drag no pointer can make.
    frame = await open();
    const placeOf = () => frame.evaluate(() => [...document.querySelectorAll('[data-bx-section]')]
      .map((b) => [...b.querySelectorAll('.section-column > *')].map((x) => x.getAttribute('data-bx-key')).join(','))
      .join(' | '));
    const startPlaces = await placeOf();
    const undoAtLoad = await page.evaluate(() => !document.querySelector('[data-undo-button]').disabled);
    await frame.evaluate(() => document.querySelectorAll('.section-column')[0].bxSortable.options.onStart());
    await wait(400);
    await frame.evaluate(() => {
      const columns = document.querySelectorAll('.section-column');
      columns[columns.length - 1].appendChild(columns[0].firstElementChild);
      columns[columns.length - 1].bxSortable.options.onEnd();
    });
    await wait(SETTLE);
    const draggedPlaces = await placeOf();
    const undoAfterDrag = await page.evaluate(() => !document.querySelector('[data-undo-button]').disabled);
    await page.click('[data-undo-button]');
    await wait(SETTLE * 2);
    const undonePlaces = await placeOf();
    report.verdict('a block dragged to another band can be undone, and comes back where it was',
      !undoAtLoad && draggedPlaces !== startPlaces && undoAfterDrag && undonePlaces === startPlaces,
      JSON.stringify({ undoAtLoad, undoAfterDrag, startPlaces, draggedPlaces, undonePlaces }));

    // ---- Escape that closes the picker leaves the block selected --------------------
    frame = await open();
    const withPicture = await page.evaluate(() => {
      const group = [...document.querySelectorAll('[data-block-group]')]
        .find((g) => g.querySelector('.media-picker-current'));
      return group ? group.getAttribute('data-block-key') : null;
    });
    if (withPicture === null) {
      report.fail('escape', 'no block on the page has a picture field');
    } else {
      await frame.click(`[data-bx-key="${withPicture}"]`);
      await wait(SETTLE);
      await page.click(`[data-block-key="${withPicture}"] .media-picker-current`);
      await wait(SETTLE);
      const opened = await page.evaluate(() => !!document.querySelector('.media-picker-panel:not([hidden])'));
      await page.keyboard.press('Escape');
      await wait(SETTLE);
      const after = await page.evaluate((k) => ({
        panelOpen: !!document.querySelector('.media-picker-panel:not([hidden])'),
        groupShown: !document.querySelector(`[data-block-key="${k}"]`).hidden,
      }), withPicture);
      report.verdict('Escape closes the picture picker and nothing else: the block stays selected',
        opened && !after.panelOpen && after.groupShown, JSON.stringify({ opened, after }));
    }

    // ---- a logo stays inside its mark ------------------------------------------------
    //
    // The Logos block's own markup, with a real <picture> from this page, measured in the
    // canvas under the site's stylesheets. Put in and taken out in one evaluate.
    frame = await open();
    const logo = await frame.evaluate(async () => {
      const source = document.querySelector('picture');
      const column = document.querySelector('.section-column');
      if (!source || !column) {
        return null;
      }
      const box = document.createElement('div');
      box.className = 'block-logos layout-row';
      box.innerHTML = `<div class="logos"><ul class="logos-row"><li class="logos-item"><span class="logos-mark">${source.outerHTML}</span></li></ul></div>`;
      box.querySelectorAll('img').forEach((img) => img.removeAttribute('loading'));
      column.appendChild(box);
      await new Promise((resolve) => setTimeout(resolve, 2000));
      const mark = box.querySelector('.logos-mark').getBoundingClientRect();
      const img = box.querySelector('img').getBoundingClientRect();
      box.remove();

      return { mark: Math.round(mark.height), img: Math.round(img.height), top: Math.round(img.top - mark.top) };
    });
    report.verdict('a logo is drawn inside its mark, never over the blocks around it',
      logo !== null && logo.img > 0 && logo.img <= logo.mark + 1 && logo.top >= -1, JSON.stringify(logo));
  },
};
