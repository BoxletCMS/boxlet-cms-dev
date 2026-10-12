/*
 * A PICTURE OPENS LARGER ON A PRESS (PLAN.md D-236, the owner): a gallery of three with "Open
 * larger" on, as a visitor uses it, through real presses and keys.
 *
 *   - the inspector offers the option, with its two answers;
 *   - each picture says at rest that it opens (its mark has a colour of its own);
 *   - a press opens it over the page, the full size, the caption under it, "1 / 3";
 *   - the arrow button and the arrow keys go through the three and round again;
 *   - the buttons are seen at rest (a fill and an edge), and named;
 *   - Escape closes it, and the picture pressed has the focus again;
 *   - without the script the link is the picture itself (an image answers its address);
 *   - on a phone the same press opens it, the arrows under the picture.
 *
 * ON THE COPY, on a page of its own with three of the demo's pictures; the page is deleted.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, deletePage, clickAndWait, openBuilder, addBlock, settle, publish } from '../harness.mjs';

const TITLE = `Zz lightbox ${Date.now()}`;
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const idFrom = (url) => Number((url.match(/\/admin\/pages\/(\d+)$/) || [])[1]) || null;
const state = (page) => page.evaluate(() => {
  const d = document.querySelector('dialog.lightbox');
  const img = d && d.querySelector('.lightbox-figure img');
  return d ? { open: d.open, count: d.querySelector('.lightbox-count').textContent, src: img ? img.currentSrc || img.src : '', caption: d.querySelector('.lightbox-caption').textContent, focus: document.activeElement && document.activeElement.className } : null;
});

export default {
  name: 'lightbox',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('lightbox: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    let id = null;
    try {
      // Three pictures of the copy's library: rows with a thumbnail, not documents.
      await page.goto(`${BASE}/admin/media`, { waitUntil: 'networkidle2' });
      const pictures = await page.$$eval('tr.media-row', (rows) => rows.filter((r) => r.querySelector('img')).slice(0, 3).map((r) => Number(r.getAttribute('data-media-id'))));
      if (pictures.length < 3) {
        report.fail('lightbox: three pictures in the library', JSON.stringify(pictures));
        return;
      }
      await page.goto(`${BASE}/admin/pages/new`, { waitUntil: 'networkidle2' });
      await page.type('#page-title', TITLE);
      await page.select('#page-template', '');
      await clickAndWait(page, 'form.panel button[type="submit"]');
      id = idFrom(page.url());
      await openBuilder(page, BASE, id);
      const key = await addBlock(page, 'gallery');
      // The option, offered in the inspector with its two answers, as the owner finds it.
      const offered = await page.evaluate(() => {
        const ins = document.querySelector('[data-pb-inspector]');
        const text = ins ? ins.textContent.replace(/\s+/g, ' ') : '';
        return { label: text.includes('Open larger'), answers: text.includes('On a press') };
      });
      await report.shot(page, '00-inspector', { fullPage: false });
      report.verdict('the inspector offers "Open larger" with its answers', offered.label && offered.answers, JSON.stringify(offered));
      await page.evaluate((k, ids) => {
        const b = window.pb.doc.blocks.find((x) => x.key === k);
        window.pb.change(() => {
          b.layout = 'three';
          b.options = Object.assign({}, b.options, { enlarge: 'on' });
          b.content.items = ids.map((m, i) => ({ image: m, caption: i === 0 ? 'The first, with words' : '' }));
        }, { sections: [b.section] });
      }, key, pictures);
      await settle(page);
      await publish(page);
      const address = await page.$eval('[data-pb-view-page], a[href*="/zz-lightbox"]', (a) => a.getAttribute('href')).catch(() => null);
      const path = address || `/${TITLE.toLowerCase().replace(/\s+/g, '-')}`;

      // As a visitor would see it: a page of its own, the admin's session makes no difference here.
      await page.goto(`${BASE}${new URL(path, BASE).pathname}`, { waitUntil: 'networkidle2' });
      const links = await page.$$eval('a[data-enlarge]', (as) => as.map((a) => {
        const mark = a.querySelector('.enlarge-mark');
        return { href: a.getAttribute('href'), label: a.getAttribute('aria-label'), mark: mark ? getComputedStyle(mark).backgroundColor : '' };
      }));
      report.verdict('each of the three pictures is a link that says, at rest, that it opens',
        links.length === 3 && links.every((l) => /\/m\/(full|natural)\//.test(l.href) && l.label && l.mark && !/rgba\(0, 0, 0, 0\)|transparent/.test(l.mark)),
        JSON.stringify(links));

      await page.$eval('a[data-enlarge]', (a) => a.scrollIntoView({ block: 'center' }));
      await report.shot(page, '00-at-rest', { fullPage: false });
      // Without the script: the link's address is the picture.
      const fallback = await page.evaluate(async (href) => { const r = await fetch(href); return { status: r.status, type: r.headers.get('content-type') }; }, links[0].href);
      report.verdict('without the script a press opens the picture itself', fallback.status === 200 && /^image\//.test(fallback.type || ''), JSON.stringify(fallback));

      await page.click('a[data-enlarge]');
      await wait(600);
      const opened = await state(page);
      await report.shot(page, '01-opened', { fullPage: false });
      report.verdict('a press opens it over the page: the full size, its caption, "1 / 3", the focus on Close',
        opened && opened.open && /\/m\/(full|natural)\//.test(opened.src) && opened.caption === 'The first, with words' && opened.count === '1 / 3' && /lightbox-close/.test(opened.focus || ''),
        JSON.stringify(opened));

      const buttons = await page.$$eval('dialog.lightbox .lightbox-button', (bs) => bs.filter((b) => !b.hidden).map((b) => {
        const cs = getComputedStyle(b);
        return { name: b.getAttribute('aria-label'), fill: cs.backgroundColor, edge: cs.borderTopColor, width: cs.borderTopWidth };
      }));
      report.verdict('its three buttons are seen at rest, a fill and an edge, and each is named',
        buttons.length === 3 && buttons.every((b) => b.name && b.fill !== 'rgba(0, 0, 0, 0)' && parseFloat(b.width) > 0),
        JSON.stringify(buttons));

      await page.click('dialog.lightbox .lightbox-next');
      await wait(300);
      const second = (await state(page)).count;
      await page.keyboard.press('ArrowRight');
      await wait(300);
      const third = (await state(page)).count;
      await page.keyboard.press('ArrowRight');
      await wait(300);
      const round = (await state(page)).count;
      await page.keyboard.press('ArrowLeft');
      await wait(300);
      const back = (await state(page)).count;
      report.verdict('the arrow and the arrow keys go through the three, and round again',
        second === '2 / 3' && third === '3 / 3' && round === '1 / 3' && back === '3 / 3', JSON.stringify({ second, third, round, back }));

      await page.keyboard.press('Escape');
      await wait(400);
      const closed = await page.evaluate(() => ({ open: document.querySelector('dialog.lightbox').open, focus: document.activeElement && document.activeElement.matches('a[data-enlarge]') }));
      report.verdict('Escape closes it, and the picture pressed has the focus again', !closed.open && closed.focus, JSON.stringify(closed));

      await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
      await page.reload({ waitUntil: 'networkidle2' });
      await page.$eval('a[data-enlarge]', (a) => a.scrollIntoView({ block: 'center' }));
      await page.click('a[data-enlarge]');
      await wait(600);
      const phone = await page.evaluate(() => {
        const d = document.querySelector('dialog.lightbox');
        const img = d.querySelector('.lightbox-figure img').getBoundingClientRect();
        const next = d.querySelector('.lightbox-next').getBoundingClientRect();
        return { open: d.open, below: next.top >= img.bottom - 1, inside: img.left >= 0 && img.right <= innerWidth + 1 };
      });
      await report.shot(page, '02-phone', { fullPage: false });
      report.verdict('on a phone it opens too, the picture within the screen and the arrows under it', phone.open && phone.below && phone.inside, JSON.stringify(phone));
      await page.keyboard.press('Escape');
      await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    } finally {
      if (id !== null) {
        await deletePage(page, BASE, id);
        const left = await page.$(`tr[data-page-id="${id}"]`) !== null;
        report.verdict('lightbox: its page is gone again', !left, `left: ${left}`);
      }
    }
  },
};
