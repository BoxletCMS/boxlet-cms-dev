/*
 * THE MEDIA BROWSER A PICTURE FIELD OPENS (PLAN.md D-145).
 *
 * The owner, 2026-09-30: the page editor could only pick from the library — no upload, no
 * crop — in a column a few hundred pixels wide ("to je trenutno slaba točka u page
 * editoru"). This opens the browser from a block in the visual editor, looks at it, sends a
 * photograph through the crop step at 1:1, and checks that the field, the canvas and the
 * library all hold the cut.
 *
 * ON THE DEVELOPMENT SITE. Nothing is saved: the page is left by reloading it. The one
 * picture the upload adds is deleted by its exact id at the end, through the app, and only
 * if it is new — a picture that was already in the library is never touched.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, controlsOnPanels, fixtures } from '../harness.mjs';
import { PHOTOS, photographs } from '../media-helpers.mjs';

const PAGE = 1;
const SETTLE = 1500;
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const OPEN = 'dialog[data-browser][open]';

/*
 * THE VIEWPORT, NEVER THE FULL PAGE. A fullPage capture resizes the viewport under the page
 * for a moment, and Cropper answers the resize: measured 2026-09-30, the crop box came back
 * 0×0 after one such shot, while a real resize of the window (900×600 and back) kept it at
 * 571×571. The shot then made the upload fail. The dialog is fixed to the viewport anyway,
 * so the viewport is the whole of what there is to see.
 */
const shot = (report, page, name) => report.shot(page, name, { fullPage: false });

export default {
  name: 'media-browser',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('media-browser: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    const photos = fixtures(report, 'media-browser', photographs(1), `a photograph in ${PHOTOS}`);
    if (photos === null) {
      return;
    }
    await page.setViewport({ width: 1500, height: 1000, deviceScaleFactor: 1 });

    /** The editor, fresh, with the first block that has a picture field selected. */
    const open = async () => {
      await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
      await page.waitForFunction(() => {
        const frame = document.querySelector('iframe[data-canvas]');
        return frame && frame.contentDocument && frame.contentDocument.querySelector('[data-bx-index]');
      }, { timeout: 20000 }).catch(() => {});
      await wait(SETTLE);
      const key = await page.evaluate(() => {
        const group = [...document.querySelectorAll('[data-block-group]')]
          .find((g) => g.querySelector('.media-picker-current'));
        return group ? group.getAttribute('data-block-key') : null;
      });
      if (key !== null) {
        const frame = page.frames().find((f) => f.url().includes('/canvas'));
        await frame.click(`[data-bx-key="${key}"]`);
        await wait(SETTLE);
      }
      return key;
    };
    const field = (key) => `[data-block-key="${key}"] select[data-media-field]`;
    const press = (key) => page.click(`[data-block-key="${key}"] .media-picker-current`);

    const key = await open();
    if (key === null) {
      report.fail('media-browser: a block with a picture', 'no block on page 1 has a picture field');
      return;
    }
    const before = await page.$eval(field(key), (el) => el.value);

    // ---- it is a dialog over the page, not a column ---------------------------------------
    await press(key);
    await page.waitForSelector(`${OPEN} [data-pick]`, { timeout: 15000 }).catch(() => {});
    await wait(500);
    await shot(report, page, '01-browser');
    // The other palette, for the eye: set on this page only, never saved.
    const theme = await page.evaluate(() => document.body.getAttribute('data-ui-theme'));
    // Waited for: the controls fade between palettes, and a shot taken at once caught the
    // drop zone and a button still dark on the light dialog.
    await page.evaluate(() => document.body.setAttribute('data-ui-theme', 'light'));
    await wait(600);
    await shot(report, page, '01-browser-light');
    await page.evaluate((was) => document.body.setAttribute('data-ui-theme', was), theme);
    const look = await page.evaluate((selector) => {
      const dialog = document.querySelector(selector);
      if (!dialog) return null;
      const box = dialog.getBoundingClientRect();
      const drop = dialog.querySelector('[data-browser-drop]');
      return {
        width: Math.round(box.width),
        height: Math.round(box.height),
        modal: dialog.matches(':modal'),
        cards: dialog.querySelectorAll('[data-pick]').length,
        chosen: (dialog.querySelector('[data-pick].is-chosen') || { getAttribute: () => null }).getAttribute('data-pick'),
        dropEdge: drop ? getComputedStyle(drop).borderTopStyle : null,
        focused: document.activeElement && document.activeElement.hasAttribute('data-browser-search'),
      };
    }, OPEN);
    report.verdict('the picture field opens a modal dialog, wide and tall, over the editor',
      look !== null && look.modal && look.width >= 900 && look.height >= 600, JSON.stringify(look));
    report.verdict('it offers the library\'s pictures, and says which one the field holds',
      look !== null && look.cards > 0 && (before === '' || look.chosen === before),
      look === null ? 'no dialog' : `${look.cards} cards, field holds "${before}", chosen card ${look.chosen}`);
    report.verdict('the drop zone is visible at rest and the search has the focus',
      look !== null && look.dropEdge === 'dashed' && look.focused, JSON.stringify(look));
    await controlsOnPanels(page, report, 'media browser, open', OPEN);

    // ---- the search narrows, by the server's own search -----------------------------------
    const word = await page.$eval(`${OPEN} [data-pick]`, (card) => (card.getAttribute('data-pick-name') || '').split('-')[0]);
    await page.type(`${OPEN} [data-browser-search]`, 'zzqq-nothing-by-this-name');
    await wait(SETTLE);
    const none = await page.$$eval(`${OPEN} [data-pick]`, (els) => els.length);
    await page.$eval(`${OPEN} [data-browser-search]`, (el) => { el.value = ''; });
    await page.type(`${OPEN} [data-browser-search]`, word);
    await wait(SETTLE);
    const some = await page.$$eval(`${OPEN} [data-pick]`, (els) => els.length);
    report.verdict('the search narrows the pictures, and a search for nothing offers none',
      none === 0 && some > 0, `"zzqq…" ${none}, "${word}" ${some}`);

    // ---- Escape in the crop step goes back; Escape again closes ---------------------------
    const input = await page.$(`${OPEN} [data-browser-file]`);
    await input.uploadFile(photos[0]);
    await page.waitForSelector(`${OPEN} [data-browser-crop]:not([hidden]) .cropper-container`, { timeout: 15000 }).catch(() => {});
    await wait(SETTLE);
    const cropShown = await page.$(`${OPEN} [data-browser-crop]:not([hidden]) .cropper-container`) !== null;
    await page.keyboard.press('Escape');
    await wait(500);
    const back = await page.evaluate((selector) => {
      const dialog = document.querySelector(selector);
      return dialog ? { crop: !dialog.querySelector('[data-browser-crop]').hidden, pick: !dialog.querySelector('[data-browser-pick]').hidden } : null;
    }, OPEN);
    await page.keyboard.press('Escape');
    await wait(500);
    const closed = await page.$(OPEN) === null;
    const blockStillShown = await page.$eval(`[data-block-key="${key}"]`, (el) => !el.hidden).catch(() => false);
    report.verdict('Escape takes the crop step back to the pictures, and then closes the dialog alone',
      cropShown && back !== null && !back.crop && back.pick && closed && blockStillShown,
      JSON.stringify({ cropShown, back, closed, blockStillShown }));

    // ---- a photograph cropped square on the way in ----------------------------------------
    await press(key);
    await page.waitForSelector(`${OPEN} [data-browser-file]`, { timeout: 15000 });
    const known = await page.evaluate(async (base) => {
      const html = await (await fetch(`${base}/admin/media/pick?page=1`, { credentials: 'same-origin' })).text();
      return [...html.matchAll(/data-pick="(\d+)"/g)].map((m) => m[1]);
    }, BASE);
    await (await page.$(`${OPEN} [data-browser-file]`)).uploadFile(photos[0]);
    await page.waitForSelector(`${OPEN} [data-browser-crop]:not([hidden]) .cropper-container`, { timeout: 15000 }).catch(() => {});
    await wait(SETTLE);
    const preset = await page.$eval(`${OPEN} [data-crop-ratio][aria-pressed="true"]`, (b) => b.getAttribute('data-crop-name')).catch(() => null);
    await page.click(`${OPEN} [data-crop-name="thumb"]`);
    await wait(500);
    await shot(report, page, '02-crop');
    await page.click(`${OPEN} [data-browser-crop-confirm]`);
    await page.waitForFunction((selector) => !document.querySelector(selector), { timeout: 90000 }, OPEN).catch(() => {});
    await wait(SETTLE * 2);

    const after = await page.$eval(field(key), (el) => ({
      value: el.value,
      thumb: (el.parentNode.querySelector('img.media-picker-thumb') || { getAttribute: () => null }).getAttribute('src'),
    }));
    const isNew = after.value !== '' && !known.includes(after.value);
    const canvasShows = await page.frames().find((f) => f.url().includes('/canvas'))
      .evaluate((id) => [...document.querySelectorAll('img, source')]
        .some((el) => (el.getAttribute('src') || el.getAttribute('srcset') || '').includes(`/${id}-`)), after.value)
      .catch(() => false);
    await shot(report, page, '03-chosen');
    report.verdict('the cropped upload is chosen at once: the field, its thumbnail and the canvas',
      isNew && after.thumb !== null && canvasShows,
      `field ${before} → ${after.value} (new=${isNew}), thumbnail ${after.thumb}, canvas shows it=${canvasShows}; block's shape ${preset}`);

    // What the library stored is the cut: square.
    const stored = isNew ? await page.evaluate(async (base, id) => {
      const html = await (await fetch(`${base}/admin/media/${id}`, { credentials: 'same-origin' })).text();
      const m = html.match(/(\d+)\s*×\s*(\d+)/);
      return m ? { width: Number(m[1]), height: Number(m[2]) } : null;
    }, BASE, after.value) : null;
    report.verdict('the library holds only the cut, and it is square',
      stored !== null && stored.width === stored.height && stored.width >= 200, JSON.stringify(stored));

    // ---- a phone ---------------------------------------------------------------------------
    await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 2 });
    await open();
    await press(key);
    await page.waitForSelector(`${OPEN} [data-pick]`, { timeout: 15000 }).catch(() => {});
    await wait(500);
    await shot(report, page, '04-phone');
    const phone = await page.evaluate((selector) => {
      const dialog = document.querySelector(selector);
      const box = dialog ? dialog.getBoundingClientRect() : null;
      return box ? { wide: Math.round(box.width), tall: Math.round(box.height), scrolls: dialog.scrollWidth > dialog.clientWidth, view: [window.innerWidth, window.innerHeight] } : null;
    }, OPEN);
    report.verdict('on a phone the dialog is the screen, with nothing wider than it',
      phone !== null && phone.wide === phone.view[0] && phone.tall === phone.view[1] && !phone.scrolls, JSON.stringify(phone));
    await page.keyboard.press('Escape');
    await page.setViewport({ width: 1500, height: 1000, deviceScaleFactor: 1 });

    // ---- cleanup: the page was never saved; the new picture goes, by its id ---------------
    await page.goto(`${BASE}/admin/media`, { waitUntil: 'networkidle2' });
    if (isNew) {
      const gone = await page.evaluate(async (base, id) => {
        const token = document.querySelector('input[name="_csrf"]').value;
        const body = new URLSearchParams({ _csrf: token });
        await fetch(`${base}/admin/media/${id}/delete`, { method: 'POST', body, credentials: 'same-origin' });
        return (await fetch(`${base}/admin/media/${id}`, { credentials: 'same-origin' })).status;
      }, BASE, after.value);
      report.verdict('cleanup: the uploaded picture is deleted again', gone === 404, `GET after delete: ${gone}`);
    }
  },
};
