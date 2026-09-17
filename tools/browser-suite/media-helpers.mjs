/*
 * What the media scenarios share.
 *
 * Scenario files are ES modules, each imported on its own — unlike tests/*.php, which
 * share their helpers because run.php requires every one of them into a single scope. So
 * splitting the media scenarios by concern (CLAUDE.md rule 4 reaches this suite too) means
 * extracting what they have in common into a module, not copying it into four files.
 */
import { readdirSync, existsSync, statSync } from 'node:fs';
import { BASE, SITE_DIR, PHOTOS } from './config.mjs';
import { submitVia, clickAndWait } from './harness.mjs';

/** Outside the repository, so it comes from configuration (D-029). Re-exported because
 *  six scenarios import it from here rather than reaching past this module. */
export { PHOTOS };

/**
 * The photographs to upload, READ FROM THE DIRECTORY rather than named here.
 *
 * They were landscape.jpg and office.jpg until slice 4f replaced them with CC0 photographs
 * under other names, and nothing said so: every media scenario checks existsSync and
 * reports NOT CHECKABLE, which in a summary of thirty PASS lines reads like a pass. Six
 * scenarios had been measuring nothing since. A filename written into the suite is a fact
 * about someone else's directory, so it is asked for instead — the next rename cannot
 * silently switch the media checks off.
 *
 * Sorted, so "the first" and "the second" mean the same two pictures on every run.
 */
export const photographs = (howMany = 1) => (existsSync(PHOTOS) ? readdirSync(PHOTOS) : [])
  .filter((name) => /\.(jpe?g|png|webp)$/i.test(name))
  .sort()
  .slice(0, howMany)
  .map((name) => `${PHOTOS}/${name}`);

/** What the library will call a photograph: its filename without the extension. */
export const markerFor = (file) => (file || '').split('/').pop().replace(/\.[^.]+$/, '');

/** A path that does not exist when the directory is empty, so existsSync still guards. */
export const PHOTO = photographs(1)[0] ?? `${PHOTOS}/(no photographs)`;

/** A block's own picture field, and the one a section style holds (D-024). */
export const CONTENT_FIELD = 'select[data-media-field][name$="[image]"]:not([name*="[style]"])';
export const SURFACE_FIELD = 'select[data-media-field][name*="[style]"]';

export const text = (page) => page.evaluate(() => document.body.textContent.replace(/\s+/g, ' ').trim());

export const notice = (page) => page
  .$eval('.notice, [role="status"]', (el) => el.textContent.replace(/\s+/g, ' ').trim())
  .catch(() => '');

/** Every file under public/m/<preset>/, with its size. */
export function variantFiles(preset) {
  const dir = `${SITE_DIR}/public/m/${preset}`;
  if (!existsSync(dir)) return [];
  return readdirSync(dir).map((name) => ({ name, bytes: statSync(`${dir}/${name}`).size }));
}

/** Puts a photograph in the library and returns how many cards are on the screen. */
export async function uploadPhoto(page, file) {
  await page.goto(`${BASE}/admin/media`, { waitUntil: 'networkidle2' });
  const input = await page.$('input[name="files[]"]');
  if (!input) return 0;
  await input.uploadFile(file);
  await submitVia(page, 'input[name="files[]"]', 60000);
  return page.$$eval('.media-card', (els) => els.length).catch(() => 0);
}

/** The card for a named photograph, or null: never merely the first one, which a leftover
 *  from an earlier run would otherwise supply. */
export const cardFor = (page, name) => page.$$eval('.media-card', (els, wanted) => {
  const el = els.find((c) => new RegExp(wanted).test((c.querySelector('.media-name') || {}).textContent || ''));
  if (!el) return null;
  const link = el.querySelector('.media-card-link');
  const img = el.querySelector('img.media-thumb');
  return {
    href: link ? link.getAttribute('href') : null,
    name: ((el.querySelector('.media-name') || {}).textContent || '').trim(),
    facts: ((el.querySelector('.media-facts') || {}).textContent || '').replace(/\s+/g, ' ').trim(),
    src: img ? img.getAttribute('src') : null,
    naturalWidth: img ? img.naturalWidth : 0,
    naturalHeight: img ? img.naturalHeight : 0,
  };
}, name).catch(() => null);

/** The pages the picture screen says are using this picture. */
export const claimants = (page) => page.$$eval('.media-used a', (els) => els.map((a) => ({
  title: a.textContent.trim(),
  id: Number((a.getAttribute('href').match(/\/admin\/pages\/(\d+)/) || [])[1]),
}))).catch(() => []);

/**
 * Clears every reference to a picture through the editor, the way an owner would.
 *
 * The field is a SELECT since 4c. This cleared input[type="number"] until the split, which
 * could no longer match anything — dormant only because the demo seed stopped shipping
 * media ids, so nothing ever claims a picture. Corrected here rather than carried across.
 */
export async function clearReferences(page, mediaId, claimedBy) {
  for (const claimer of claimedBy) {
    if (!Number.isInteger(claimer.id)) continue;
    await page.goto(`${BASE}/admin/pages/${claimer.id}/form`, { waitUntil: 'networkidle2' });
    await page.$$eval('select[data-media-field]', (els, id) => {
      for (const el of els) {
        if (el.value === String(id)) {
          el.value = '';
          el.dispatchEvent(new Event('change', { bubbles: true }));
        }
      }
    }, mediaId);
    await save(page);
  }
}

/** Presses Delete on the picture screen and returns what the site said, or null. */
export async function attemptDelete(page) {
  const submitted = await page.evaluate(() => {
    const form = document.querySelector('form.media-delete');
    if (!form) return false;
    const button = form.querySelector('[data-confirm]');
    if (button) button.removeAttribute('data-confirm');
    form.submit();
    return true;
  });
  if (!submitted) return null;
  await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 20000 }).catch(() => {});
  return notice(page);
}

/** The value the form would post for a field, read from the select the picker writes to. */
export const posted = (page, selector) => page.$eval(selector, (el) => el.value).catch(() => null);

/** Opens the picker attached to `selector` and waits for the cards to arrive. */
export async function openPicker(page, selector) {
  await page.$eval(selector, (el) => {
    el.parentNode.querySelector('.media-picker-current').click();
  });
  await page.waitForSelector('.media-picker-panel:not([hidden]) [data-pick]', { timeout: 15000 });
}

/** Chooses the nth offered picture and returns the id it wrote into the select. */
export async function pick(page, selector, nth) {
  await openPicker(page, selector);
  const id = await page.$$eval('.media-picker-panel:not([hidden]) [data-pick]',
    (els, index) => {
      const card = els[index];
      card.click();
      return card.getAttribute('data-pick');
    }, nth);
  return { chosen: id, value: await posted(page, selector) };
}

export const save = (page) => clickAndWait(page, 'div.editor-actions button[name="action"][value="save"]', 30000);

/** The first page in the tree, as an id. */
export const firstPageId = (page) => page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' })
  .then(() => page.$eval('.page-tree a[href*="/admin/pages/"]',
    (el) => (el.getAttribute('href').match(/\/admin\/pages\/(\d+)/) || [])[1]))
  .catch(() => null);
