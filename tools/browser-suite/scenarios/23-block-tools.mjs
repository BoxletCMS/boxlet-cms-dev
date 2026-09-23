/*
 * The selected block's controls on the block itself, and Page settings & SEO folded
 * (PLAN.md D-040).
 *
 * What a person does: open a page, see the page settings folded, pick a block on the
 * canvas, and use the icons on its top right corner — duplicate it, remove the copy, move
 * a block down and back up. Real clicks inside the canvas frame.
 *
 * NOTHING IS SAVED: every change is made in the editor and left, so the site is as it was.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

const PAGE = 1;
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export default {
  name: 'block-tools',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('block tools: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }

    await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
    await page.waitForFunction(() => {
      const frame = document.querySelector('iframe[data-canvas]');
      return frame && frame.contentDocument
        && frame.contentDocument.querySelectorAll('[data-bx-blocks] > section').length > 1;
    }, { timeout: 20000 });

    // ---- the page settings start folded, and open ------------------------------------------
    const folded = await page.$eval('[data-page-settings]', (d) => ({ open: d.open, summary: d.querySelector('summary').textContent.trim() }));
    report.verdict('Page settings & SEO starts folded', !folded.open && /SEO/.test(folded.summary), JSON.stringify(folded));
    await page.click('[data-page-settings] > summary');
    const opened = await page.$eval('[data-page-settings]', (d) => d.open && !!d.querySelector('#page-title').offsetParent);
    report.verdict('it opens to its fields', opened, opened ? 'the title field is shown' : 'still folded');
    await page.click('[data-page-settings] > summary');

    // ---- the selected block's own controls ---------------------------------------------------
    const frame = page.frames().find((f) => f.url().includes('/canvas'));
    /* BLOCKS, NOT BANDS (D-103). These counted `> section`, which was one per block while a
       block WAS a band. A copy now stands beside the block it was copied from, in the same
       column, so the number of bands does not change and the number of blocks does. */
    const count = () => frame.$$eval('[data-bx-index]', (list) => list.length);
    const headingOf = (i) => frame.$eval(`[data-bx-index="${i}"]`, (s) => (s.querySelector('h1, h2') || s).textContent.trim().slice(0, 40));

    await (await frame.$('[data-bx-index="0"]')).click();
    /* WAIT FOR THE BAR TO BE THE ONE FOR THIS BLOCK, not for a clock. Selecting travels to
       the parent and back, and the bar is redrawn when it returns — a measurement taken in
       between is of the bar the previous selection left. */
    await frame.waitForFunction(() => {
      const bar = document.querySelector('.bx-tools');
      const block = document.querySelector('[data-bx-index="0"]');

      return bar !== null && block !== null && block.classList.contains('bx-selected');
    }, { timeout: 8000 }).catch(() => {});
    await wait(400);
    const tools = await frame.evaluate(() => {
      const bar = document.querySelector('.bx-tools');
      const section = document.querySelector('[data-bx-index="0"]');
      if (!bar || !section) return null;
      const b = bar.getBoundingClientRect();
      const s = section.getBoundingClientRect();
      return {
        buttons: Array.from(bar.querySelectorAll('button')).map((button) => ({ label: button.getAttribute('aria-label'), disabled: button.disabled })),
        /* It used to be required to sit INSIDE the block, 12px down from its top. That rule
           changed deliberately in D-085: measured against the real line boxes of the text,
           the bar covered the words of two of the demo's seven blocks, so it straddles the
           top edge, in the gap the canvas keeps above.
           WHICH EDGE, since D-103: the BAND's, for a block that is first in its column —
           that is where the gap is, and it is the geometry every page had while a block was
           a band. A block standing under another sits wholly above its own edge instead. */
        straddlesTopEdge: (() => {
          const band = section.closest('[data-bx-section]');
          const edge = section.previousElementSibling === null
            ? band.getBoundingClientRect().top : s.top;

          return b.top < edge && b.bottom > edge;
        })(),
        atTheRight: b.right <= s.right + 1 && b.right > s.right - 60,
        // The thing the position is FOR: no word of the block is under it.
        coversText: (() => {
          const over = (r) => !(r.right <= b.left || r.left >= b.right || r.bottom <= b.top || r.top >= b.bottom);
          const walk = document.createTreeWalker(section, NodeFilter.SHOW_TEXT);
          let node;
          while ((node = walk.nextNode())) {
            if (!node.textContent.trim() || node.parentElement.closest('.bx-tools')) continue;
            const range = document.createRange();
            range.selectNodeContents(node);
            for (const r of range.getClientRects()) {
              if (r.width > 0 && over(r)) return node.textContent.replace(/\s+/g, ' ').trim().slice(0, 30);
            }
          }

          return null;
        })(),
      };
    });
    await report.shot(page, '01-selected-with-tools', { fullPage: false });
    report.verdict('a selected block carries four controls',
      tools !== null && tools.buttons.length === 4,
      JSON.stringify(tools && tools.buttons.map((b) => b.label)));
    // Split from the verdict above, which used to assert both at once: what the controls
    // ARE has not changed, where they SIT has (D-085).
    report.verdict('the controls sit on the block\'s top edge, at its right, over no words',
      tools !== null && tools.straddlesTopEdge && tools.atTheRight && tools.coversText === null,
      tools === null ? 'no toolbar' : `straddles ${tools.straddlesTopEdge}, at the right `
        + `${tools.atTheRight}, covers ${JSON.stringify(tools.coversText)}`);
    /* ALONE IN ITS COLUMN, BOTH ARROWS ARE UNAVAILABLE (D-103). They used to move a block
       one place on the PAGE, which is what a flat list of blocks had; in a tree the next
       place is in the same column, and stepping outside it would drop the block into a
       neighbouring band. Every block of the demo page is alone where it stands, so this is
       what a person sees until a column holds two — and moving the whole band is the band's
       own pair of arrows. */
    report.verdict('alone in its column, a block has nowhere to move to and says so',
      tools !== null && tools.buttons[0].disabled && tools.buttons[1].disabled,
      JSON.stringify(tools && tools.buttons.map((b) => b.disabled)));

    const before = await count();
    await frame.click('.bx-tools [data-block-action="duplicate"]');
    await wait(400);
    const afterCopy = await count();
    report.verdict('duplicate adds a copy', afterCopy === before + 1, `${before} -> ${afterCopy} blocks`);

    // BESIDE THE ORIGINAL, IN THE SAME COLUMN (D-103), which is where a copy of one of
    // several things in a column belongs — and which now gives that column two, so the
    // arrows below have somewhere to go.
    const together = await frame.evaluate(() => {
      const column = document.querySelector('[data-bx-index="0"]').parentNode;

      return column.children.length;
    });
    report.verdict('the copy stands beside the block it was copied from', together === 2, `${together} in the column`);

    const first = await headingOf(0);
    await (await frame.$('[data-bx-index="0"]')).click();
    await wait(200);
    await frame.click('.bx-tools [data-block-action="down"]');
    await wait(400);
    const movedTo = await headingOf(1);
    report.verdict('down moves the block one place down its column', movedTo === first, `"${first}" is now second: "${movedTo}"`);
    await frame.click('.bx-tools [data-block-action="up"]');
    await wait(400);
    report.verdict('up moves it back', (await headingOf(0)) === first, `first is "${await headingOf(0)}"`);

    // The copy has done its work; take it away again.
    await (await frame.$('[data-bx-index="1"]')).click();
    await wait(200);
    await frame.click('.bx-tools [data-block-action="remove"]');
    await wait(400);
    const afterRemove = await count();
    report.verdict('remove takes it away again', afterRemove === before, `${afterCopy} -> ${afterRemove} blocks`);

    /*
     * A BLOCK CAN BE MOVED WITH THE KEYBOARD ALONE (D-085), and it can because pressing a
     * control leaves the keyboard on it. Two things used to take it away: drawInserts()
     * emptied the overlay before anything read where focus was, and the parent's api.show()
     * reached into the panel and focused a field, which took the keyboard out of the iframe
     * altogether. So this presses Move down TWICE without touching the mouse in between.
     */
    /* THREE IN A COLUMN, so there is somewhere to go twice. A block moves within its column
       since D-103, and this check presses Move down TWICE — which needs two places below
       it. Two copies are made and both are taken away again at the foot of this block. */
    const startedWith = await count();
    await (await frame.$('[data-bx-index="0"]')).click();
    await wait(300);
    await frame.click('.bx-tools [data-block-action="duplicate"]');
    await wait(600);
    await (await frame.$('[data-bx-index="0"]')).click();
    await wait(300);
    await frame.click('.bx-tools [data-block-action="duplicate"]');
    await wait(600);

    const moving = await headingOf(0);
    await (await frame.$('[data-bx-index="0"]')).click();
    await wait(300);
    await frame.evaluate(() => document.querySelector('.bx-tools [data-block-action="down"]').focus());
    await page.keyboard.press('Enter');
    await wait(600);
    const afterOne = await frame.evaluate(() => {
      const active = document.activeElement;

      return active && active.getAttribute ? active.getAttribute('data-block-action') : null;
    });
    await page.keyboard.press('Enter');
    await wait(600);
    const twice = await headingOf(2);
    report.verdict('a block can be moved down twice from the keyboard, without the mouse',
      afterOne === 'down' && twice === moving,
      `after one press the keyboard is on ${JSON.stringify(afterOne)}; "${moving}" is now third: "${twice}"`);

    // The two copies have done their work; take them away.
    for (let n = 0; n < 2; n += 1) {
      await (await frame.$('[data-bx-index="0"]')).click();
      await wait(300);
      await frame.click('.bx-tools [data-block-action="remove"]');
      await wait(500);
    }
    report.verdict('the scenario leaves the page as it found it',
      (await count()) === startedWith, `${startedWith} blocks before, ${await count()} after`);

    // Leave without saving.
    await page.evaluate(() => { window.onbeforeunload = null; });
  },
};
