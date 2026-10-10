/*
 * SEVERAL PAGES, AND SEVERAL PICTURES, DELETED AT ONCE (PLAN.md D-218, the owner): the ticks in
 * Pages' and Media's lists, through real presses.
 *
 *   - Pages: two pages of its own ticked, the bar counts them and Delete comes on, faint
 *     before; the tick
 *     in the head ticks every row and takes them off again; Delete, and both are gone;
 *   - Media: two pictures of its own and one a demo page shows, ticked; Delete deletes the
 *     two and keeps the one in use, naming its page.
 *
 * ON THE COPY. What it makes it deletes; the demo's picture it ticks is kept by the rule it
 * checks, and if it were not, the verdict says so.
 */
import { copyFileSync, appendFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN, PHOTOS } from '../config.mjs';
import { login, clickAndWait } from '../harness.mjs';
import { uploadPhoto } from '../media-helpers.mjs';

const STAMP = Date.now();
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const idFrom = (url) => Number((url.match(/\/admin\/pages\/(\d+)$/) || [])[1]) || null;

/** The bar: what it says, and whether Delete is on. */
const bar = (page, id) => page.evaluate((form) => {
  const f = document.getElementById(form);
  const button = f.querySelector('[data-bulk-delete]');
  return { says: f.querySelector('[data-bulk-count]').textContent.trim(), on: !button.disabled, ink: getComputedStyle(button).color };
}, id);

export default {
  name: 'bulk',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('bulk: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });

    // ---- pages --------------------------------------------------------------------------------
    const made = [];
    for (const name of ['a', 'b']) {
      await page.goto(`${BASE}/admin/pages/new`, { waitUntil: 'networkidle2' });
      await page.type('#page-title', `Zz bulk ${STAMP} ${name}`);
      await page.select('#page-template', '');
      await clickAndWait(page, 'form.panel button[type="submit"]');
      made.push(idFrom(page.url()));
    }
    await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
    const before = await bar(page, 'pages-bulk');
    for (const id of made) {
      await page.$eval(`tr[data-page-id="${id}"]`, (tr) => tr.scrollIntoView({ block: 'center' }));
      await page.click(`tr[data-page-id="${id}"] input[data-bulk-pick]`);
    }
    const ticked = await bar(page, 'pages-bulk');
    await page.$eval('input[data-bulk-all][form="pages-bulk"]', (box) => box.scrollIntoView({ block: 'center' }));
    await page.click('input[data-bulk-all][form="pages-bulk"]');
    const everything = await page.evaluate(() => [...document.querySelectorAll('input[data-bulk-pick][form="pages-bulk"]')].every((b) => b.checked));
    await page.click('input[data-bulk-all][form="pages-bulk"]');
    const nothing = await bar(page, 'pages-bulk');
    // Off, and seen to be off: the danger ink read as ready (D-218, the owner).
    report.verdict('Pages: the bar counts the ticks and Delete is on only then, drawn faint while off; the head\'s tick ticks every row and none',
      !before.on && ticked.on && before.ink !== ticked.ink && /2 selected/.test(ticked.says) && everything && !nothing.on,
      JSON.stringify({ before, ticked, everything, nothing }));

    for (const id of made) {
      await page.click(`tr[data-page-id="${id}"] input[data-bulk-pick]`);
    }
    await page.$eval('#pages-bulk', (f) => f.scrollIntoView({ block: 'center' }));
    await report.shot(page, '01-pages-ticked', { fullPage: false });
    await clickAndWait(page, '#pages-bulk [data-bulk-delete]');
    const pagesLeft = await page.evaluate((ids) => ids.filter((id) => document.querySelector(`tr[data-page-id="${id}"]`)).length, made);
    const pagesSaid = await page.evaluate(() => document.body.textContent.includes('2 pages were deleted.'));
    report.verdict('Pages: Delete deletes both and says so', pagesLeft === 0 && pagesSaid, JSON.stringify({ pagesLeft, pagesSaid }));

    // ---- media --------------------------------------------------------------------------------
    // Two pictures of its own: a photograph with bytes after its end, so its content is new and
    // the library does not answer with one it has.
    const dir = mkdtempSync(join(tmpdir(), 'bulk-'));
    const names = ['a', 'b'].map((n) => `zz-bulk-${STAMP}-${n}`);
    for (const name of names) {
      const file = join(dir, `${name}.jpg`);
      copyFileSync(`${PHOTOS}/desk-wood.jpg`, file);
      appendFileSync(file, `${name}`);
      await uploadPhoto(page, file);
    }
    await page.goto(`${BASE}/admin/media`, { waitUntil: 'networkidle2' });
    const rows = await page.evaluate((wanted) => {
      const all = [...document.querySelectorAll('tr.media-row')];
      const own = all.filter((tr) => wanted.some((w) => tr.querySelector('.media-name').textContent.includes(w))).map((tr) => tr.getAttribute('data-media-id'));
      const used = all.find((tr) => !tr.querySelector('.media-used .media-unused'));
      return { own, used: used ? used.getAttribute('data-media-id') : null, usedName: used ? used.querySelector('.media-name').textContent.trim() : '' };
    }, names);
    if (rows.own.length !== 2 || rows.used === null) {
      report.fail('Media: two pictures of its own and one a page shows', JSON.stringify(rows));
      return;
    }
    for (const id of [...rows.own, rows.used]) {
      await page.$eval(`tr[data-media-id="${id}"]`, (tr) => tr.scrollIntoView({ block: 'center' }));
      await page.click(`tr[data-media-id="${id}"] input[data-bulk-pick]`);
    }
    const mediaTicked = await bar(page, 'media-bulk');
    await page.$eval('#media-bulk', (f) => f.scrollIntoView({ block: 'center' }));
    await report.shot(page, '02-media-ticked', { fullPage: false });
    await clickAndWait(page, '#media-bulk [data-bulk-delete]');
    const after = await page.evaluate((own, used) => ({
      own: own.filter((id) => document.querySelector(`tr[data-media-id="${id}"]`)).length,
      used: !!document.querySelector(`tr[data-media-id="${used}"]`),
      said: (document.querySelector('.flash, [role="status"], .notice') || document.body).textContent.replace(/\s+/g, ' ').trim().slice(0, 300),
    }), rows.own, rows.used);
    await report.shot(page, '03-media-after', { fullPage: false });
    report.verdict('Media: the two of its own deleted, the one a page shows kept and its page named',
      /3 selected/.test(mediaTicked.says) && after.own === 0 && after.used && /2 were deleted\./.test(after.said) && /kept, because a page still shows it/.test(after.said),
      JSON.stringify({ mediaTicked, after, usedName: rows.usedName }));
    await wait(100);
  },
};
