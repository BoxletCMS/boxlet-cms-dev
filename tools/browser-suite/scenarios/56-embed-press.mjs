/*
 * A VIDEO WAITS FOR A PRESS (PLAN.md D-147).
 *
 * The owner, 2026-09-30, from a cookie scanner: "youtube video postavlja ipak neke cookie?"
 * Measured: a youtube-nocookie frame wrote two localStorage keys and an IndexedDB database,
 * and made seven requests to Google, as the page opened. Now a video is a link with the
 * site's own cover until a visitor presses it.
 *
 * AS A VISITOR, in a browser context of its own with no session: the demo's showroom is opened,
 * every request it makes is recorded, and nothing may go anywhere but this site. Then the
 * press, and the player must arrive. Then, as the admin, the cover is taken from the video
 * in the editor — never saved, and the picture it adds is deleted again by its id.
 *
 * ON THE DEVELOPMENT SITE. It needs a YouTube Embed block on the showroom page, /blocks, which
 * the demo has since D-167 (the home page had one before); it finds that page's id in the page
 * list. Without one this is a failure, not NOT CHECKABLE.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

const SHOWROOM = '/blocks';
const SETTLE = 1500;
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const shot = (report, page, name) => report.shot(page, name, { fullPage: false });
const site = new URL(BASE).host;

export default {
  name: 'embed-press',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('embed-press: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    // Cached pages were drawn before the press existed; the cache panel empties them.
    await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle2' });
    await page.evaluate(async (base) => {
      const token = document.querySelector('input[name="_csrf"]').value;
      await fetch(`${base}/admin/settings/cache`, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ _csrf: token, action: 'clear' }) });
    }, BASE);

    // ---- the visitor ------------------------------------------------------------------
    const visitorContext = await page.browser().createBrowserContext();
    const visitor = await visitorContext.newPage();
    await visitor.setViewport({ width: 1300, height: 1000, deviceScaleFactor: 1 });
    const elsewhere = new Set();
    visitor.on('request', (request) => {
      const host = new URL(request.url()).host;
      if (host !== site && !request.url().startsWith('data:')) elsewhere.add(host);
    });
    await visitor.goto(`${BASE}${SHOWROOM}`, { waitUntil: 'networkidle2' });
    const press = await visitor.$('a[data-embed-src]');
    if (press === null) {
      report.fail('the showroom has a video to press', `no a[data-embed-src] on ${SHOWROOM} (is the site in maintenance?)`);
      await visitorContext.close();
      return;
    }
    await press.scrollIntoView();
    await wait(SETTLE * 2);
    const before = await visitor.evaluate(() => ({
      frames: document.querySelectorAll('.embed iframe').length,
      label: (document.querySelector('.embed-play') || {}).textContent.replace(/\s+/g, ' ').trim(),
      // YouTube's own button, drawn: an image with a size, not an empty span (D-149).
      button: (() => {
        const b = document.querySelector('.embed-play-youtube');
        if (!b) return null;
        const r = b.getBoundingClientRect();
        return { width: Math.round(r.width), height: Math.round(r.height), image: getComputedStyle(b).backgroundImage.includes('embed-youtube.svg') };
      })(),
      script: !!document.querySelector('script[src*="site-embed.js"]'),
    }));
    await shot(report, visitor, '01-before-press');
    report.verdict('before a press, nothing of YouTube is framed or asked for',
      before.frames === 0 && elsewhere.size === 0,
      `${before.frames} frame(s); other hosts asked: ${[...elsewhere].join(', ') || 'none'}`);
    report.verdict('the press is YouTube\'s red button, and still says Play video to a screen reader',
      /Play video/.test(before.label) && before.button !== null && before.button.image && before.button.width > 40
        && before.script, JSON.stringify(before));

    await press.click();
    await visitor.waitForSelector('.embed iframe', { timeout: 10000 }).catch(() => {});
    await wait(SETTLE * 3);
    const frame = visitor.frames().find((f) => f.url().includes('youtube-nocookie.com'));
    const played = frame ? await frame.evaluate(() => document.body.innerText.slice(0, 80)).catch(() => '') : '';
    await shot(report, visitor, '02-after-press');
    // The player, not the playing. From this server YouTube answers any playback — the same
    // press on a plain player with no Boxlet in it, measured 2026-09-30 — with "sign in to
    // confirm you are not a bot": a headless browser on a datacentre address. So what is
    // judged is that the frame arrived, from the right address, and is not Error 153.
    report.verdict('the press puts the player where the cover was, and it is not Error 153',
      frame !== undefined && /[?&]autoplay=1/.test(frame.url()) && played !== '' && !/153/.test(played),
      `frame ${frame ? frame.url() : 'none'}; it says "${played.replace(/\s+/g, ' ')}"`);
    await visitorContext.close();

    // ---- the admin: the cover from the video --------------------------------------------
    await page.setViewport({ width: 1500, height: 1000, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
    const PAGE = await page.$$eval('a[href^="/admin/pages/"]', (links) => {
      const link = links.find((a) => a.textContent.trim() === 'Every block' && /\/admin\/pages\/\d+$/.test(a.getAttribute('href')));
      return link ? link.getAttribute('href').split('/').pop() : '';
    });
    await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
    await page.waitForFunction(() => {
      const f = document.querySelector('iframe[data-canvas]');
      return f && f.contentDocument && f.contentDocument.querySelector('[data-bx-index]');
    }, { timeout: 20000 }).catch(() => {});
    await wait(SETTLE);
    const key = await page.evaluate(() => {
      const select = document.querySelector('select[data-poster-url]');
      const group = select && select.closest('[data-block-group]');
      return group ? group.getAttribute('data-block-key') : null;
    });
    if (key === null) {
      report.fail('page 1 has an Embed block', 'no cover field on page 1');
      return;
    }
    const canvas = page.frames().find((f) => f.url().includes('/canvas'));
    /*
     * PRESSED WITH THE MOUSE, WHERE THE OWNER PRESSES (D-148). The owner, 2026-09-30: the
     * block "se ne može ni editirati ni obrisati, na click u editoru ne desi se ništa". A frame
     * of another site's swallows the press, and until D-147 the video was one. Pressed twice:
     * on the link it is now, and on a frame put in its place in this browser only — the map
     * that is still drawn as one — and each press must select the block.
     */
    const canvasBox = await (await page.$('iframe[data-canvas]')).boundingBox();
    const pressBlock = async () => {
      const at = await canvas.evaluate((k) => {
        const el = document.querySelector(`[data-bx-key="${k}"] .embed-frame`);
        el.scrollIntoView({ block: 'center' });
        const r = el.getBoundingClientRect();
        return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
      }, key);
      await wait(500);
      const now = await canvas.evaluate((k) => {
        const r = document.querySelector(`[data-bx-key="${k}"] .embed-frame`).getBoundingClientRect();
        return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
      }, key);
      await page.mouse.click(canvasBox.x + (now.x || at.x), canvasBox.y + (now.y || at.y));
      await wait(SETTLE);
      return canvas.evaluate(() => (document.querySelector('.bx-selected') || { getAttribute: () => null }).getAttribute('data-bx-key'));
    };
    const onLink = await pressBlock();
    const frameHtml = await canvas.evaluate((k) => {
      const holder = document.querySelector(`[data-bx-key="${k}"] .embed-frame`);
      const kept = holder.innerHTML;
      const frame = document.createElement('iframe');
      frame.src = 'https://www.openstreetmap.org/export/embed.html?bbox=15.97,45.81,15.98,45.82&layer=mapnik';
      holder.innerHTML = '';
      holder.appendChild(frame);
      document.querySelector('.bx-selected') && document.querySelector('.bx-selected').classList.remove('bx-selected');
      return kept;
    }, key);
    await wait(SETTLE);
    const onFrame = await pressBlock();
    await canvas.evaluate((k, html) => { document.querySelector(`[data-bx-key="${k}"] .embed-frame`).innerHTML = html; }, key, frameHtml);
    report.verdict('pressing the block in the canvas selects it, on the link and on a frame alike',
      onLink === key && onFrame === key, `on the link: ${onLink}; on a frame: ${onFrame}; wanted ${key}`);
    const field = `[data-block-key="${key}"] select[data-poster-url]`;
    const was = await page.$eval(field, (el) => el.value);
    const known = await page.evaluate(async (base) => {
      const html = await (await fetch(`${base}/admin/media/pick`, { credentials: 'same-origin' })).text();
      return [...html.matchAll(/data-pick="(\d+)"/g)].map((m) => m[1]);
    }, BASE);

    await page.click(`[data-block-key="${key}"] .media-picker-poster`);
    await page.waitForFunction((selector, before) => {
      const select = document.querySelector(selector);
      return select && select.value !== before;
    }, { timeout: 60000 }, field, was).catch(() => {});
    await wait(SETTLE * 2);
    const after = await page.$eval(field, (el) => ({
      value: el.value,
      name: (el.options[el.selectedIndex] || {}).textContent || '',
      status: (el.parentNode.querySelector('.media-picker [role="status"]') || {}).textContent || '',
    }));
    const drawn = await page.frames().find((f) => f.url().includes('/canvas'))
      .evaluate((k) => !!document.querySelector(`[data-bx-key="${k}"] .embed-play picture`), key).catch(() => false);
    await shot(report, page, '03-cover-taken');
    const isNew = after.value !== '' && !known.includes(after.value);
    // A block that already has this cover — the owner took it on 2026-09-30 — gets the same
    // picture back: the library stores the same bytes once. So the field must hold a picture,
    // a changed one only when it held none.
    report.verdict('the cover is taken from the video, named after it, and drawn on the canvas',
      after.value !== '' && (was !== '' || after.value !== was) && drawn && /cover now/.test(after.status),
      `field ${was || '(empty)'} → ${after.value} "${after.name}"; canvas draws it=${drawn}; "${after.status.trim()}"`);

    // ---- cleanup: never saved; the new picture goes, by its id ----------------------------
    await page.goto(`${BASE}/admin/media`, { waitUntil: 'networkidle2' });
    if (isNew) {
      const gone = await page.evaluate(async (base, id) => {
        const token = document.querySelector('input[name="_csrf"]').value;
        await fetch(`${base}/admin/media/${id}/delete`, { method: 'POST', body: new URLSearchParams({ _csrf: token }), credentials: 'same-origin' });
        return (await fetch(`${base}/admin/media/${id}`, { credentials: 'same-origin' })).status;
      }, BASE, after.value);
      report.verdict('cleanup: the cover picture is deleted again', gone === 404, `GET after delete: ${gone}`);
    }
  },
};
