/*
 * THE PRINTWORKS, AS A VISITOR SEES IT (PLAN.md D-213): the demo 01-install put on the copy.
 *
 *   - every page answers, the three beneath others at their nested addresses, each with its
 *     pictures loaded (none broken);
 *   - the header's menu holds the six pages, the footer the address, the hours and the rest;
 *   - a list in centred words is one centred block, its items aligned at their start (D-216);
 *   - this week's events, cards with no picture area, use the whole card: the words start at
 *     its edge, not after an empty column (the list's picture column stood empty before D-213);
 *   - on a phone the workshop sign-up comes before how it works (the section's order reversed);
 *   - the workshop form offers the six crafts; Visit is there in Croatian too;
 *   - on a phone the footer's columns stand one under another (they stayed side by side at
 *     390 px and ran into each other before D-213).
 *
 * Read-only: it changes nothing on the copy.
 */
import { COPY_BASE as BASE } from '../config.mjs';

const PAGES = ['/', '/whats-on', '/exhibitions', '/exhibitions/floating-world', '/workshops', '/workshops/linocut', '/cafe-bookshop', '/membership', '/hire', '/about', '/journal', '/journal/restoring-the-press-hall', '/visit', '/hr/', '/hr/posjet'];

export default {
  name: 'demo',
  copy: true,

  async run({ page, report }) {
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });

    // ---- every page, every picture ------------------------------------------------------------
    const pages = [];
    for (const path of PAGES) {
      const response = await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle2' });
      const pictures = await page.evaluate(async () => {
        for (let y = 0; y < document.documentElement.scrollHeight; y += window.innerHeight) {
          window.scrollTo(0, y);
          await new Promise((r) => setTimeout(r, 80));
        }
        await Promise.all(Array.from(document.images).map((img) => (img.complete ? null : new Promise((r) => { img.onload = img.onerror = r; }))));
        return { all: document.images.length, broken: Array.from(document.images).filter((img) => img.naturalWidth === 0).map((img) => img.currentSrc || img.src) };
      });
      pages.push({ path, status: response.status(), ...pictures });
    }
    const failing = pages.filter((p) => p.status !== 200 || p.broken.length > 0);
    report.verdict('every page of the demo answers, nested pages at their nested addresses, and no picture is broken',
      failing.length === 0 && pages.reduce((n, p) => n + p.all, 0) > 40,
      failing.length === 0 ? `${pages.length} pages, ${pages.reduce((n, p) => n + p.all, 0)} pictures` : JSON.stringify(failing));

    // ---- the menus ------------------------------------------------------------------------------
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
    // In whatever set the copy is in (03-design leaves its own): the first footer column is drawn
    // by every set, and it holds the address and the menu; the hours only where a second is.
    const chrome = await page.evaluate(() => ({
      header: Array.from(document.querySelectorAll('header nav a')).map((a) => a.textContent.trim()),
      footer: (document.querySelector('footer') || {}).innerText || '',
      columns: Array.from(document.querySelectorAll('.site-footer > :not(.site-footer-foot)')).filter((el) => el.getBoundingClientRect().height > 0).length,
    }));
    report.verdict('the header holds the six pages, the footer the address, Hire, About and Journal, and the hours where it has a second column',
      ['What\'s on', 'Exhibitions', 'Workshops', 'Café & Bookshop', 'Membership', 'Visit'].every((t) => chrome.header.includes(t))
        && ['14 Foundry Lane', 'Hire the space', 'About', 'Journal'].every((t) => chrome.footer.includes(t))
        && (chrome.columns < 2 || chrome.footer.includes('Opening hours')),
      JSON.stringify({ header: chrome.header, columns: chrome.columns, footer: chrome.footer.replace(/\s+/g, ' ').slice(0, 200) }));

    // ---- a list of cards without pictures ------------------------------------------------------
    const events = await page.evaluate(() => {
      const item = document.querySelector('.layout-list .shape-none .cards-item');
      const heading = item && item.querySelector('.cards-item-heading');
      if (!item || !heading) return null;
      // Inside its edge and its padding: Brutalist draws a 3px edge, which an earlier
      // scenario may have left the copy in (measured: the words 3px in, at the padding).
      const padding = parseFloat(getComputedStyle(item).paddingLeft);
      return { card: Math.round(item.getBoundingClientRect().left + item.clientLeft + padding), words: Math.round(heading.getBoundingClientRect().left), width: Math.round(heading.getBoundingClientRect().width), inner: Math.round(item.clientWidth - 2 * padding) };
    });
    report.verdict('this week\'s events use the whole card: the words start at its edge, no empty picture column',
      !!events && Math.abs(events.words - events.card) <= 1 && events.width >= events.inner - 2,
      JSON.stringify(events));

    // ---- a list in centred words (D-216, the owner) ---------------------------------------------
    // Membership's levels: in a centred section the list stands in the middle as one block, its
    // markers in a column and its items aligned at their start. Centred here in the page if the
    // copy's character does not centre it (an earlier scenario may leave Brutalist), nothing
    // written: it is the stylesheet that is measured.
    await page.goto(`${BASE}/membership`, { waitUntil: 'networkidle2' });
    const list = await page.evaluate(() => {
      const band = document.querySelector('section.block-cards');
      const ul = band && band.querySelector('.cards-item ul');
      if (!ul) return null;
      const centred = band.classList.contains('align-center');
      band.classList.add('align-center');
      const box = ul.getBoundingClientRect();
      const item = ul.closest('.cards-item');
      const inner = item.getBoundingClientRect();
      const pad = parseFloat(getComputedStyle(item).paddingLeft);
      return {
        centred,
        left: Math.round(box.left - inner.left - item.clientLeft - pad),
        right: Math.round(inner.right - item.clientLeft - pad - box.right),
        narrower: box.width < item.clientWidth - 2 * pad - 8,
        items: getComputedStyle(ul.querySelector('li')).textAlign,
        markers: getComputedStyle(ul).listStylePosition,
      };
    });
    report.verdict('a list in centred words stands in the middle as one block, its items aligned at their start',
      !!list && list.narrower && Math.abs(list.left - list.right) <= 2 && ['start', 'left'].includes(list.items) && list.markers === 'outside',
      JSON.stringify(list));

    // ---- the workshop form, and its place on a phone -------------------------------------------
    await page.goto(`${BASE}/workshops`, { waitUntil: 'networkidle2' });
    const crafts = await page.$$eval('select option', (options) => options.map((o) => o.textContent.trim()));
    await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/workshops`, { waitUntil: 'networkidle2' });
    const order = await page.evaluate(() => {
      const form = document.querySelector('.block-form');
      const how = Array.from(document.querySelectorAll('h2')).find((h) => h.textContent.trim() === 'How to sign up');
      return form && how ? { form: Math.round(form.getBoundingClientRect().top), how: Math.round(how.getBoundingClientRect().top) } : null;
    });
    report.verdict('the workshop form offers the six crafts, and on a phone it comes before how to sign up',
      ['Linocut', 'Screen printing', 'Ceramics', 'Sewing', 'Paper & binding', 'Letterpress'].every((c) => crafts.includes(c)) && !!order && order.form < order.how,
      JSON.stringify({ crafts, order }));
    const footer = await page.evaluate(() => {
      const columns = Array.from(document.querySelectorAll('.site-footer > :not(.site-footer-foot)')).filter((el) => el.getBoundingClientRect().height > 0);
      return { count: columns.length, lefts: columns.map((el) => Math.round(el.getBoundingClientRect().left)), layout: (document.querySelector('footer') || {}).className || '' };
    });
    report.verdict('on a phone the footer\'s columns stand one under another',
      new Set(footer.lefts).size <= 1, JSON.stringify(footer));
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });

    // ---- Croatian -------------------------------------------------------------------------------
    await page.goto(`${BASE}/hr/posjet`, { waitUntil: 'networkidle2' });
    const hr = await page.evaluate(() => ({ lang: document.documentElement.lang, h: (document.querySelector('h2') || {}).textContent || '', send: (document.querySelector('form button[type="submit"]') || {}).textContent || '' }));
    report.verdict('Visit is there in Croatian, with its form in Croatian', hr.lang === 'hr' && /Posjetite nas/.test(hr.h) && /Pošalji/.test(hr.send), JSON.stringify(hr));
  },
};
