/*
 * PLACING A PAGE UNDER ANOTHER IN THE PAGE LIST (PLAN.md D-133): by dragging it right and
 * left with the pointer, by the → and ← buttons, and back again with Undo; and a drag up
 * or down still only reorders.
 *
 * Driven through real pointer moves (page.mouse), not synthetic events: the level is read
 * from how far the pointer went sideways, which only a moving pointer reports.
 *
 * ON THE COPY, AND IT PUTS THE TREE BACK: every move is undone in the same run, and the
 * last verdict checks the order and levels match what it found.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** The English tree as "title@level" in list order. */
const tree = (page) => page.$$eval('tr[data-page-id]', (rows) => rows.map((row) => `${row.getAttribute('data-title')}@${row.getAttribute('data-depth')}`));

/** Drags row $index's handle $dx pixels sideways and $dy down, in small real moves. */
async function drag(page, index, dx, dy = 0) {
  const handles = await page.$$('tr[data-page-id] [data-page-handle]');
  const box = await handles[index].boundingBox();
  const x = box.x + box.width / 2;
  const y = box.y + box.height / 2;
  await page.mouse.move(x, y);
  await page.mouse.down();
  for (let step = 1; step <= 12; step++) {
    await page.mouse.move(x + (dx * step) / 12, y + (dy * step) / 12 + (step % 2));
    await pause(30);
  }
  await pause(200);
  const hint = await page.$eval('tr.is-placing [data-landing]', (e) => e.textContent).catch(() => '');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 10000 }).catch(() => null), page.mouse.up()]);
  return hint;
}

export default {
  name: 'page-tree',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('page-tree: log in', `could not log in as ${ADMIN.email}`);
      return;
    }
    const list = `${BASE}/admin/pages?lang=en`;
    await page.goto(list, { waitUntil: 'networkidle2' });
    const found = await tree(page);
    // The home page first, then at least three top-level pages to move among.
    if (found.length < 4 || !found.slice(1, 4).every((row) => row.endsWith('@0'))) {
      report.fail('page-tree: three top-level pages under the home page', found.join(' | '));
      return;
    }
    const [, first, second] = found.map((row) => row.split('@')[0]);

    try {
      // Right: the third row goes under the second.
      const said = await drag(page, 2, 48);
      await page.goto(list, { waitUntil: 'networkidle2' });
      let now = await tree(page);
      report.verdict('dragging a page right puts it under the page above, and says so while dragging',
        now[2] === `${second}@1` && said.includes(first), JSON.stringify({ said, now }));

      // Undo, on the list the move lands on — the same language's, since the drag keeps it.
      await drag(page, 3, 48);
      const landed = page.url();
      const undo = await page.$('form.page-undo button');
      if (undo) {
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), undo.click()]);
      }
      await page.goto(list, { waitUntil: 'networkidle2' });
      now = await tree(page);
      report.verdict('Undo puts a dragged page back, on the list in the language it was looking at',
        undo !== null && now[3] === found[3] && landed.endsWith('lang=en'), JSON.stringify({ landed, now }));

      // Left: the nested one comes out again.
      await drag(page, 2, -48);
      await page.goto(list, { waitUntil: 'networkidle2' });
      now = await tree(page);
      report.verdict('dragging it left takes it out a level', now[2] === `${second}@0`, JSON.stringify(now));

      // Nothing goes under the home page, whatever the pointer does.
      await drag(page, 1, 96);
      await page.goto(list, { waitUntil: 'networkidle2' });
      now = await tree(page);
      report.verdict('a page dragged right under the home page stays at the top level', now[1] === `${first}@0`, JSON.stringify(now));

      // The arrows do the same without a drag.
      await page.$eval('tr[data-page-id]:nth-child(3) button[name="to"][value="in"]', (b) => b.click());
      await page.waitForNavigation({ waitUntil: 'networkidle2' });
      await page.goto(list, { waitUntil: 'networkidle2' });
      const arrowed = (await tree(page))[2];
      await page.$eval('tr[data-page-id]:nth-child(3) button[name="to"][value="out"]', (b) => b.click());
      await page.waitForNavigation({ waitUntil: 'networkidle2' });
      await page.goto(list, { waitUntil: 'networkidle2' });
      now = await tree(page);
      report.verdict('→ and ← do the same', arrowed === `${second}@1` && now[2] === `${second}@0`, JSON.stringify({ arrowed, now: now[2] }));

      // Straight up, no sideways: only the order changes, as dragging always did.
      const up = await page.$$eval('tr[data-page-id]', (rows) => rows[3].getBoundingClientRect().top - rows[2].getBoundingClientRect().top);
      await drag(page, 3, 0, -up);
      await page.goto(list, { waitUntil: 'networkidle2' });
      now = await tree(page);
      const swapped = now[2] === found[3] && now[3] === found[2];
      if (swapped) {
        await drag(page, 3, 0, -up);
        await page.goto(list, { waitUntil: 'networkidle2' });
      }
      report.verdict('dragging straight up only reorders', swapped, JSON.stringify(now));
    } finally {
      await page.goto(list, { waitUntil: 'networkidle2' });
      const end = await tree(page);
      report.verdict('the scenario leaves the tree as it found it', end.join('|') === found.join('|'), JSON.stringify({ found, end }));
    }
  },
};
