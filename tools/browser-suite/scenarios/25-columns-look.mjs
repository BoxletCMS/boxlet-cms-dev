/*
 * The Columns block under every character, on a desktop and a phone (PLAN.md D-041).
 *
 * The PHP tests prove the markup; only a browser shows whether a row of columns lines up,
 * a round portrait is round, four in a row fits, and a phone stacks them. So this puts
 * photographs into the demo's Columns blocks through the plain editor, as a person would,
 * and photographs each page that holds one under all five characters.
 *
 * COPY ONLY: applying a character rewrites the site's whole design, which on the
 * development site nobody could put back. Needs a copy installed with the demo (01-install)
 * and the photographs in PHOTOS.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN, PHOTOS } from '../config.mjs';
import { login, applyCharacter, clickAndWait } from '../harness.mjs';
import { uploadPhoto } from '../media-helpers.mjs';

const CHARACTERS = ['editorial', 'minimal', 'bold', 'soft', 'brutalist'];

/** Which demo page gets which pictures, column by column. The home page's stay words. */
const PICTURES = {
  about: ['hands-working', 'atelier'],
  services: ['tools', 'desk-wood', 'workshop', 'room-light'],
};

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** The admin id of a demo page, read off the pages list by its address. */
const pageId = async (page, slug) => {
  await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
  return page.evaluate((wanted) => {
    const view = Array.from(document.querySelectorAll('a[href]'))
      .find((a) => a.getAttribute('href') === `/${wanted}` || a.getAttribute('href').endsWith(`/${wanted}`));
    const row = view ? view.closest('tr, li') : null;
    const edit = row ? row.querySelector('a[href*="/admin/pages/"]') : null;
    return edit ? Number((edit.getAttribute('href').match(/\/admin\/pages\/(\d+)/) || [])[1]) : null;
  }, slug);
};

export default {
  name: 'columns-look',
  // Runs against the throwaway copy, never the development site (config.mjs).
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('columns look: log in', `could not log in; at ${page.url()}`);
      return;
    }

    // ---- the photographs, once ---------------------------------------------------------------
    const wanted = [...new Set(Object.values(PICTURES).flat())];
    await page.goto(`${BASE}/admin/media`, { waitUntil: 'networkidle2' });
    const have = await page.$$eval('.media-name', (els) => els.map((e) => e.textContent.trim()));
    for (const name of wanted) {
      if (!have.some((h) => h.includes(name))) {
        await uploadPhoto(page, `${PHOTOS}/${name}.jpg`);
      }
    }
    await page.goto(`${BASE}/admin/media`, { waitUntil: 'networkidle2' });
    const library = await page.$$eval('.media-name', (els) => els.map((e) => e.textContent.trim()));
    const missing = wanted.filter((name) => !library.some((h) => h.includes(name)));
    report.verdict('the photographs are in the library', missing.length === 0, missing.length ? `missing ${missing.join(', ')}` : library.join(', '));

    // ---- into the demo's columns, through the plain editor ------------------------------------
    for (const [slug, pictures] of Object.entries(PICTURES)) {
      const id = await pageId(page, slug);
      if (id === null) {
        report.fail(`columns look: the demo's ${slug} page`, 'not in the pages list; install the copy with the demo first');
        return;
      }
      await page.goto(`${BASE}/admin/pages/${id}/form`, { waitUntil: 'networkidle2' });
      const set = await page.evaluate((names) => {
        const selects = Array.from(document.querySelectorAll('select[name$="[image]"]'))
          .filter((s) => /\[items\]\[\d+\]\[image\]$/.test(s.name));
        names.forEach((name, i) => {
          const select = selects[i];
          const option = select ? Array.from(select.options).find((o) => o.textContent.includes(name)) : null;
          if (option) {
            select.value = option.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
          }
        });
        return selects.slice(0, names.length).filter((s) => s.value !== '').length;
      }, pictures);
      await clickAndWait(page, 'div.editor-actions button[name="action"][value="save"]', 30000);
      report.verdict(`${slug}: every column given its picture`, set === pictures.length, `${set} of ${pictures.length}`);
    }

    // ---- every character, desktop and phone ---------------------------------------------------
    for (const character of CHARACTERS) {
      await applyCharacter(page, BASE, character);
      for (const slug of ['', 'about', 'services']) {
        for (const [device, width, height] of [['desktop', 1400, 900], ['phone', 390, 844]]) {
          await page.setViewport({ width, height, deviceScaleFactor: 2 });
          await page.goto(`${BASE}/${slug}`, { waitUntil: 'networkidle2' });
          const found = await page.evaluate(() => {
            const block = document.querySelector('.block-columns');
            if (!block) return null;
            block.scrollIntoView({ block: 'start' });
            const items = Array.from(block.querySelectorAll('.columns-item'));
            return {
              count: items.length,
              rows: new Set(items.map((i) => Math.round(i.getBoundingClientRect().top))).size,
              overflow: document.documentElement.scrollWidth > window.innerWidth,
            };
          });
          await wait(200);
          const name = `${character}-${slug || 'home'}-${device}`;
          await report.shot(page, name, { fullPage: false });
          if (found === null) {
            report.fail(`${name}: a Columns block on the page`, 'none drawn');
            continue;
          }
          // On a phone every column is its own row; nothing ever scrolls sideways.
          const stacked = device === 'phone' ? found.rows === found.count : true;
          report.verdict(`${name}: drawn, ${device === 'phone' ? 'one column per row' : 'in its rows'}, no sideways scroll`,
            stacked && !found.overflow, JSON.stringify(found));
        }
      }
    }
  },
};
