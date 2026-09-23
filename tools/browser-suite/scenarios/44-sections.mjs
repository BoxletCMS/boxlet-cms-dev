/*
 * A SECTION WITH COLUMNS, from the panel to the visitor's page (PLAN.md D-097, D-098, D-099).
 *
 * What a person does, in the order the owner chose: give a band two columns, see the empty
 * one ask to be filled, press its +, pick a block, type into it, save, and look at the site.
 *
 * WHY IT SAVES, where 24-columns deliberately does not. The defect this exists to catch is
 * one nothing on the screen shows: a block that DRAWS in a column and is STORED somewhere
 * else. The canvas is the server's own drawing of what the form would post, so it agrees
 * with itself whether or not the save is right; only the served page settles it. Three
 * defects were found this way in one evening, and every one of them looked perfect until
 * the served page or the database was read.
 *
 * ON THE COPY, AND IT PUTS THE PAGE BACK. It saves, so it owns what it wrote (D-090): it
 * remembers the KEY of the block it added and removes that block by that key, and it
 * REFUSES to press Remove when it cannot find it. A cleanup that hunts for what it added
 * and presses Remove anyway acts on whatever is still selected — which is how the demo
 * page lost the text block standing beside this one, and cost a reinstall.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

const PAGE = 2; // About: a band of text with room beside it
const SETTLE = 1600; // the canvas redraw is debounced, then a round trip
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** What the canvas is showing of the band with columns. */
const bandOnCanvas = (page) => page.evaluate(() => {
  const frame = document.querySelector('iframe[data-canvas]');
  /* THE BAND WITH TWO COLUMNS, not simply the first band with a column container: since
     D-103 the editor's canvas draws them for every band, so `.section-cols` is now the
     first band on the page and says nothing about the one this check arranged. */
  const cols = frame && frame.contentDocument ? frame.contentDocument.querySelector('.cols-halves') : null;
  /* THE SLOT OF THE EMPTY COLUMN OF THE BAND THIS CHECK ARRANGED, and not simply the first
     one on the page: since D-101 every column has a +, including the ones that already hold
     something, so "the first .bx-slot" is now the first band's and says nothing about this
     one. Found by the band and the column it belongs to, which is what it says about itself. */
  const band = cols ? cols.closest('[data-bx-section]').getAttribute('data-bx-section') : null;
  const slot = band === null ? null : frame.contentDocument
    .querySelector(`.bx-slot[data-insert-into="${band}"][data-insert-column="1"]`);
  return {
    classes: cols ? cols.className : '',
    columns: cols ? cols.children.length : 0,
    filled: cols ? Array.from(cols.children).map((c) => c.children.length) : [],
    slot: slot === null ? null : {
      into: slot.getAttribute('data-insert-into'),
      column: slot.getAttribute('data-insert-column'),
      // A control nobody can see is not a control (CLAUDE.md): both sizes are read, because
      // an empty column has no content and a slot with no height is invisible.
      width: Math.round(slot.getBoundingClientRect().width),
      height: Math.round(slot.getBoundingClientRect().height),
    },
  };
});

const submit = async (page) => {
  await page.evaluate(() => {
    const form = document.querySelector('form[data-builder]');
    form.requestSubmit(form.querySelector('button[name="action"][value="save"]:not(.visually-hidden)'));
  });
  await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => {});

  return page.evaluate(() => Array.from(document.querySelectorAll('.alert, [role="alert"], .field-error'))
    .map((a) => a.textContent.trim().slice(0, 120)));
};

export default {
  name: 'sections',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('sections: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1920, height: 1100, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
    await page.waitForFunction(() => {
      const frame = document.querySelector('iframe[data-canvas]');
      return frame && frame.contentDocument
        && frame.contentDocument.querySelectorAll('[data-bx-blocks] > section').length > 0;
    }, { timeout: 20000 });

    /* ---- the page outline, which is how you see the tree at all ---------------------
       COUNTED FROM THE CANVAS, NOT WRITTEN DOWN. This said "6 and 6" until D-105 put four
       more blocks on the About page and the check went red with nothing wrong. A literal
       here was never the rule anyway: "the outline lists EVERY band and EVERY block" is a
       statement about two views agreeing, and comparing them says it exactly. */
    const outline = await page.evaluate(() => {
      const rail = document.querySelector('[data-outline]');
      const doc = document.querySelector('iframe[data-canvas]').contentDocument;
      if (!rail) { return null; }

      return {
        sections: rail.querySelectorAll('[data-outline-section]').length,
        blocks: rail.querySelectorAll('[data-outline-block]').length,
        count: (rail.querySelector('[data-outline-count]') || {}).textContent,
        painted: rail.getBoundingClientRect().width > 100,
        onCanvas: doc.querySelectorAll('[data-bx-section]').length,
        blocksOnCanvas: doc.querySelectorAll('.section-column > *').length,
      };
    });
    report.verdict('the page outline lists every band and every block',
      outline !== null && outline.sections > 1 && outline.sections === outline.onCanvas
        && outline.blocks === outline.blocksOnCanvas
        && outline.count.trim() === `${outline.blocks} / ${outline.sections}` && outline.painted,
      JSON.stringify(outline));
    // What the page holds before anything is added, for the checks below and the cleanup.
    const started = outline === null ? 0 : outline.sections;

    /* ---- NO TWO CONTROLS IN THE SAME PLACE (PLAN.md D-106) --------------------------
       "+ Section" lies across a band's bottom edge and "+ Block" sits under the last block
       in a column, so a band with little room under its content put one on top of the
       other — measured at a slot of 809..839 inside a band ending at 835, with the seam at
       819..851. The owner saw the "+" of one poking out from behind the other: "traka za
       dodavanje bloka je ispod trake za dodavanje sekcija".

       Checked over EVERY pair on the page rather than one: whether they collide depends on
       how much room a band leaves under its last block, which is the design layer's
       business and differs band by band. */
    const overlaps = await page.evaluate(() => {
      const doc = document.querySelector('iframe[data-canvas]').contentDocument;
      const box = (el) => { const b = el.getBoundingClientRect(); return { top: b.top, bottom: b.bottom }; };
      const seams = [...doc.querySelectorAll('.bx-insert')].map(box);
      const clashes = [];
      for (const slot of [...doc.querySelectorAll('.bx-slot')]) {
        const s = box(slot);
        for (const seam of seams) {
          if (s.top < seam.bottom && seam.top < s.bottom) {
            clashes.push(`slot ${Math.round(s.top)}..${Math.round(s.bottom)} over seam ${Math.round(seam.top)}..${Math.round(seam.bottom)}`);
          }
        }
      }

      return { slots: doc.querySelectorAll('.bx-slot').length, seams: seams.length, clashes: clashes };
    });
    report.verdict('no + Block strip lies under a + Section seam',
      overlaps.slots > 0 && overlaps.seams > 0 && overlaps.clashes.length === 0,
      JSON.stringify(overlaps));

    // Pressing a row reaches the block: the thing an outline is FOR on a long page.
    const reached = await page.evaluate(() => {
      const rows = [...document.querySelectorAll('[data-outline-block]')];
      const last = rows[rows.length - 1];
      const wanted = last.getAttribute('data-outline-block');
      last.click();

      return wanted;
    });
    await wait(SETTLE);
    report.verdict('pressing a row in the outline selects that block',
      await page.evaluate((key) => {
        const shown = [...document.querySelectorAll('[data-block-group]')].filter((g) => !g.hidden);
        const field = shown[0] && shown[0].querySelector('input[type="hidden"][name$="[type]"]');
        const marked = document.querySelector('.outline-row[aria-current="true"]');

        return shown.length === 1
          && String(field.name).startsWith('blocks[' + key + ']')
          && marked !== null && marked.getAttribute('data-outline-block') === key;
      }, reached),
      `pressed ${reached}`);

    // ---- what the Section tab does to the canvas, BEFORE anything is saved -----------
    //
    // Both of these were broken the day the band's fields moved into a group of their own
    // (D-099) and neither showed up in any check: the panel listened for changes on the
    // BLOCK groups, so nothing chosen in the Section tab reached the canvas — and a block
    // redraw stopped carrying the band's style, so typing one letter drew the block with
    // the character's composition and a tinted, airy, wide band went plain on the screen.
    // The owner found both by opening the editor.
    const frameNow = page.frames().find((f) => f.url().includes('/canvas'));
    const bandClasses = () => frameNow.evaluate(() => {
      const el = document.querySelectorAll('[data-bx-blocks] > section')[1];

      return el ? el.className : '';
    });
    await (await frameNow.$('[data-bx-index="1"]')).click();
    await wait(1200);
    await page.click('[data-panel-tab="section"]');
    await wait(500);
    const was = await bandClasses();
    await page.evaluate(() => {
      const select = [...document.querySelectorAll('[data-section-group]')].find((g) => !g.hidden)
        .querySelector('select[name$="[style][surface]"]');
      select.value = select.value === 'contrast' ? 'tinted' : 'contrast';
      select.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await wait(SETTLE);
    const afterStyle = await bandClasses();
    report.verdict('a surface chosen in the Section tab reaches the canvas at once',
      afterStyle !== was && /surface-(contrast|tinted)/.test(afterStyle),
      `${was} -> ${afterStyle}`);

    await page.click('[data-panel-tab="content"]');
    await wait(400);
    await page.evaluate(() => {
      const group = [...document.querySelectorAll('[data-block-group]')].find((g) => !g.hidden);
      const rich = group && group.querySelector('[contenteditable="true"]');
      if (rich) { rich.focus(); }
    });
    await page.keyboard.type('x');
    await wait(SETTLE * 2);
    const afterTyping = await bandClasses();
    report.verdict('typing in a block does not take the band\'s look off the canvas',
      afterTyping.replace(' bx-selected', '') === afterStyle.replace(' bx-selected', ''),
      `${afterStyle} -> ${afterTyping}`);

    // AND THE NUMBER OF COLUMNS, which no class swap can show: it is the markup AROUND
    // every block in the band. The server draws the band — the same SectionRender the page
    // uses — so this is the one choice here that costs a round trip, and until it did, the
    // canvas caught up only on save.
    await page.click('[data-panel-tab="section"]');
    await wait(400);
    await page.evaluate(() => {
      const select = [...document.querySelectorAll('[data-section-group]')].find((g) => !g.hidden)
        .querySelector('select[name$="[layout]"]');
      select.value = 'halves';
      select.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await wait(SETTLE * 2);
    const rearranged = await frameNow.evaluate(() => {
      const cols = document.querySelector('.cols-halves');
      const band = cols ? cols.closest('[data-bx-section]').getAttribute('data-bx-section') : null;

      return {
        // The band that was just given two columns, and only that one (D-103).
        cols: document.querySelectorAll('.cols-halves').length,
        // The editor's own marks do not come back from the server and have to be put back.
        keys: document.querySelectorAll('[data-bx-key]').length,
        selected: document.querySelectorAll('.bx-selected').length,
        // The + of the column that was just made, which is the point of the rearrangement.
        // Not "how many + are on the page": since D-101 every column has one.
        slot: band === null ? 0
          : document.querySelectorAll(`.bx-slot[data-insert-into="${band}"][data-insert-column="1"]`).length,
      };
    });
    report.verdict('choosing two columns rearranges the canvas without a save',
      rearranged.cols === 1 && rearranged.slot === 1 && rearranged.selected === 1 && rearranged.keys === started,
      JSON.stringify(rearranged));

    // Nothing above is saved; the page is reloaded so the rest starts from what is stored.
    await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
    await page.waitForFunction(() => {
      const f = document.querySelector('iframe[data-canvas]');
      return f && f.contentDocument && f.contentDocument.querySelectorAll('[data-bx-blocks] > section').length > 0;
    }, { timeout: 20000 });

    // ---- give the second band two columns --------------------------------------------
    const chosen = await page.evaluate(() => {
      const selects = Array.from(document.querySelectorAll('select[name^="sections"][name$="[layout]"]'));
      const target = selects[1];
      if (!target) { return null; }
      target.value = 'halves';
      target.dispatchEvent(new Event('change', { bubbles: true }));
      return target.name;
    });
    if (chosen === null) {
      report.fail('sections: a band to arrange', 'the panel offers no section layout control');
      return;
    }
    let notices = await submit(page);
    report.verdict('choosing two columns saves', notices.length === 0, JSON.stringify(notices));

    await page.waitForFunction(() => {
      const frame = document.querySelector('iframe[data-canvas]');
      // The BAND THAT WAS ARRANGED, not any column container: every band draws one now.
      return frame && frame.contentDocument && frame.contentDocument.querySelector('.cols-halves');
    }, { timeout: 20000 }).catch(() => {});
    await wait(SETTLE);

    const arranged = await bandOnCanvas(page);
    report.verdict('the band draws two columns, one filled and one waiting',
      arranged.classes.includes('cols-halves') && arranged.columns === 2
        && arranged.filled[0] === 1 && arranged.filled[1] === 0,
      JSON.stringify(arranged));

    // ---- and the empty one asks to be filled -----------------------------------------
    report.verdict('the empty column is visible and says where it is',
      arranged.slot !== null && arranged.slot.column === '1'
        && arranged.slot.width > 40 && arranged.slot.height > 40,
      JSON.stringify(arranged.slot));
    await report.shot(page, '01-empty-column');
    if (arranged.slot === null) {
      return;
    }

    // ---- press it, and take a block from the library ---------------------------------
    const frame = page.frames().find((f) => f.url().includes('/canvas'));
    await frame.click(`.bx-slot[data-insert-into="${arranged.slot.into}"][data-insert-column="1"]`);
    await wait(600);
    /* BY WHAT THE CARD IS, NOT BY WHAT IT SAYS. This read the library's buttons and took the
       first whose words began with "text" — which was the Text card until D-104 put a row of
       SHELVES above the cards, one of them named Text, and D-105 gave that shelf a block so
       it is always offered. The scenario then pressed the filter, no block was added, and
       the failure appeared three verdicts later as "the block landed in the wrong column".
       data-add-type is what the card is; the words are what it is called. */
    const took = await page.$$eval('.panel-library [data-add-type="text"]', (cards) => {
      if (cards.length !== 1) { return false; }
      cards[0].click();

      return true;
    });
    if (!took) {
      report.fail('sections: a block to put in the column', 'the library offers no Text block');
      return;
    }
    await wait(SETTLE * 2);

    const filled = await bandOnCanvas(page);
    report.verdict('the block lands in the column that was pressed',
      filled.columns === 2 && filled.filled[0] === 1 && filled.filled[1] === 1,
      JSON.stringify(filled));
    await report.shot(page, '02-filled-column');

    // A NEW BLOCK HAS EMPTY REQUIRED FIELDS and the save refuses it, correctly. Three
    // attempts at this check read that refusal as a defect in the insert before the page
    // was asked what it actually said.
    await page.evaluate(() => {
      const group = Array.from(document.querySelectorAll('[data-block-group]')).find((g) => !g.hidden);
      const rich = group && group.querySelector('[contenteditable="true"]');
      if (rich) { rich.focus(); }
    });
    await page.keyboard.type('Beside it.');
    await wait(900);

    notices = await submit(page);
    report.verdict('a block added to a column saves', notices.length === 0, JSON.stringify(notices));

    // ---- and the visitor gets it -----------------------------------------------------
    //
    // THE ONLY VERDICT THAT SETTLES IT. Everything above is the editor agreeing with
    // itself: the canvas is drawn by the server from what the form would post, so a block
    // stored in the wrong place still draws in the right one.
    const live = await page.goto(`${BASE}/about`, { waitUntil: 'networkidle2' })
      .then(() => page.evaluate(() => {
        const cols = document.querySelector('.section-cols');
        if (!cols) { return null; }
        const boxes = Array.from(cols.children).map((c) => Math.round(c.getBoundingClientRect().left));

        return {
          classes: cols.className,
          filled: Array.from(cols.children).map((c) => c.children.length),
          words: cols.textContent.includes('Beside it.'),
          // Side by side and not stacked: two columns whose left edges differ.
          sideBySide: boxes.length === 2 && boxes[1] > boxes[0],
        };
      }));
    report.verdict('the visitor sees two columns, both filled, side by side',
      live !== null && live.filled[0] === 1 && live.filled[1] === 1 && live.words && live.sideBySide,
      JSON.stringify(live));

    // ---- and on a phone they stack ---------------------------------------------------
    await page.setViewport({ width: 390, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/about`, { waitUntil: 'networkidle2' });
    const narrow = await page.evaluate(() => {
      const cols = document.querySelector('.section-cols');
      const boxes = Array.from(cols.children).map((c) => c.getBoundingClientRect());

      return { tops: boxes.map((b) => Math.round(b.top)), lefts: boxes.map((b) => Math.round(b.left)) };
    });
    report.verdict('on a narrow screen the columns become rows, in order',
      narrow.lefts[0] === narrow.lefts[1] && narrow.tops[1] > narrow.tops[0],
      JSON.stringify(narrow));
    await report.shot(page, '03-narrow');

    // ---- and the page is put back ----------------------------------------------------
    await page.setViewport({ width: 1920, height: 1100, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
    await page.waitForFunction(() => {
      const f = document.querySelector('iframe[data-canvas]');
      return f && f.contentDocument && f.contentDocument.querySelector('.cols-halves');
    }, { timeout: 20000 }).catch(() => {});
    await wait(SETTLE);

    /*
     * THE BLOCK THIS CHECK ADDED, AND NOTHING ELSE.
     *
     * Not by the key it was minted with: that was `n0` while it was new, and a saved block
     * is named by its id (D-094), so after the save it is somebody else entirely. It is
     * found by the place this check put it — the second column of the band this check
     * arranged — and the words this check typed into it, both of which have to agree.
     * If they do not, this stops: pressing Remove without knowing what is selected is how
     * the demo page lost the block standing beside this one.
     */
    const found = await page.evaluate(() => {
      const f = document.querySelector('iframe[data-canvas]');
      const cols = f.contentDocument.querySelector('.cols-halves');
      const column = cols && cols.children[1];
      const block = column && column.children.length === 1 ? column.children[0] : null;
      if (!block || !block.textContent.includes('Beside it.')) { return false; }
      block.click();

      return true;
    });
    if (!found) {
      report.fail('sections: the scenario puts the page back',
        'the block it added is not where it put it, so it will not press Remove on anything else');

      return;
    }
    await wait(900);
    await page.frames().find((f) => f.url().includes('/canvas')).click('[data-block-action="remove"]');
    await wait(900);
    await page.evaluate((name) => {
      const select = document.querySelector(`select[name="${name}"]`);
      if (select) {
        select.value = 'one';
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }, chosen);
    await wait(600);
    notices = await submit(page);

    const back = await page.goto(`${BASE}/about`, { waitUntil: 'networkidle2' })
      .then(() => page.evaluate(() => ({
        columns: document.querySelectorAll('.section-cols').length,
        words: document.body.textContent.includes('Beside it.'),
        sections: document.querySelectorAll('main > section').length,
      })));
    report.verdict('the scenario puts the page back',
      notices.length === 0 && back.columns === 0 && !back.words && back.sections === started,
      `${JSON.stringify(back)} ${JSON.stringify(notices)}`);
  },
};
