/*
 * The chrome's look under every character, the mobile menu and a submenu (PLAN.md D-032,
 * D-036).
 *
 * The PHP tests prove the classes reach the page. Only a browser shows whether a sticky
 * header stays up, a transparent one is readable over the hero it lies on, and the menu
 * folds under one button on a phone and opens again — so this photographs each character
 * at two widths and drives the buttons with real clicks.
 *
 * COPY ONLY: applying a character rewrites the site's whole design, which on the
 * development site nobody could put back (the owner's own colours are not a preset).
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, applyCharacter, clickAndWait, ensureHeaderMenu } from '../harness.mjs';

const CHARACTERS = ['editorial', 'minimal', 'bold', 'soft', 'brutalist'];
const CHILD = 'Zz child';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** The first menu the chrome uses gets one child item, so there is a submenu to open. */
async function ensureChild(page, report) {
  const menuName = await ensureHeaderMenu(page, BASE);
  if (menuName === '') {
    report.fail('chrome look: its test data', 'the header shows no menu, so there is nothing to fold');
    return false;
  }
  await page.goto(`${BASE}/admin/menus`, { waitUntil: 'networkidle2' });
  const href = await page.evaluate((name) => {
    const link = Array.from(document.querySelectorAll('.row-title a')).find((a) => a.textContent.trim() === name);
    return link ? link.getAttribute('href') : null;
  }, menuName);
  if (href === null) {
    report.fail('chrome look: its test data', `no menu called ${menuName}`);
    return false;
  }
  await page.goto(`${BASE}${href}`, { waitUntil: 'networkidle2' });
  const has = await page.evaluate((label) => document.body.textContent.includes(label), CHILD);
  if (!has) {
    const parent = await page.$$eval('#item-parent option', (options) => options.map((o) => o.value).find((v) => v !== ''));
    await page.type('#item-url', '/#child');
    await page.type('#item-label', CHILD);
    await page.select('#item-parent', parent);
    await clickAndWait(page, 'form[action$="/items"] button[type="submit"]');
  }
  return true;
}


/**
 * The WCAG contrast between two computed colours, as the browser reports them (D-110).
 * Runs inside the page: the values are `rgb(r, g, b)` strings from getComputedStyle.
 */
const CONTRAST = `
  const channel = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
  const luminance = (rgb) => { const [r, g, b] = rgb.match(/[\\d.]+/g).map(Number); return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b); };
  const contrast = (a, b) => { const [x, y] = [luminance(a), luminance(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };
  const painted = (el) => {
    // A header laid over the first section paints nothing: what its words stand on is that
    // section, which is a sibling of the header and not an ancestor — walking up would
    // find the sheet, and white on the sheet read as 1:1 for words that are white on a
    // navy hero (the instrument, not the product).
    const over = el.closest('.behaviour-over') ? document.querySelector('main > .block:first-child') : null;
    if (over) { el = over; }
    while (el && getComputedStyle(el).backgroundColor === 'rgba(0, 0, 0, 0)' && !/gradient/.test(getComputedStyle(el).backgroundImage)) { el = el.parentElement; }
    if (!el) { return ['rgb(255, 255, 255)']; }
    const style = getComputedStyle(el);
    // A gradient surface has no background colour, only an image: what the words stand on
    // is anywhere between its two ends, so both are answered and the worse one counts.
    if (/gradient/.test(style.backgroundImage)) {
      const hex = (name) => { const h = style.getPropertyValue(name).trim().replace('#', ''); return 'rgb(' + [0, 2, 4].map((i) => parseInt(h.substr(i, 2), 16)).join(', ') + ')'; };
      return [hex('--color-gradient-start'), hex('--color-gradient-end')];
    }
    return [style.backgroundColor];
  };
`;

/**
 * Every link in the header and the footer against the surface it stands on.
 *
 * THE MEASUREMENT THAT WOULD HAVE CAUGHT D-076's CYCLE. The PHP tests prove the tokens are
 * compiled and the classes reach the page; only a browser resolves a custom property, and a
 * custom property that names itself resolves to nothing — so the links under a contrast
 * footer took the page's accent at 2.43:1 for three days while every test was green.
 */
async function linkContrast(page) {
  return page.evaluate(`(() => { ${CONTRAST}
    return [...document.querySelectorAll('.site-nav a, .site-footer-nav a, .site-footer-text, .site-small-print')]
      .filter((el) => el.getClientRects().length > 0)
      .map((el) => ({ where: el.closest('header') ? 'header' : 'footer', text: el.textContent.trim().slice(0, 20),
        colour: getComputedStyle(el).color, on: painted(el).join(' / '),
        ratio: Math.round(Math.min(...painted(el).map((bg) => contrast(getComputedStyle(el).color, bg))) * 100) / 100 }));
  })()`);
}

export default {
  name: 'chrome-look',
  // Runs against the throwaway copy, never the development site (config.mjs).
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('chrome look: log in', `could not log in; at ${page.url()}`);
      return;
    }
    if (!await ensureChild(page, report)) {
      return;
    }

    for (const character of CHARACTERS) {
      await applyCharacter(page, BASE, character);

      await page.setViewport({ width: 1400, height: 900, deviceScaleFactor: 2 });
      await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
      await report.shot(page, `${character}-desktop`, { fullPage: false });

      // Every word in the chrome reads on the surface it stands on — measured in the
      // browser, which is the only place a custom property is ever resolved (D-110).
      const inks = await linkContrast(page);
      const faint = inks.filter((ink) => ink.ratio < 4.5);
      report.verdict(`${character}: every link and line in the header and footer reads on its surface`,
        inks.length > 0 && faint.length === 0,
        faint.length > 0 ? faint.map((f) => `${f.where} "${f.text}" ${f.colour} on ${f.on} ${f.ratio}:1`).join('; ') : `${inks.length} measured, lowest ${Math.min(...inks.map((i) => i.ratio))}:1`);

      // A submenu opens from its own button, and only then.
      const more = await page.$('[data-site-nav-more]');
      if (more === null) {
        report.fail(`${character}: a submenu has a button`, 'no submenu button on a menu with a child');
      } else {
        const before = await page.$eval('.site-nav-children', (list) => getComputedStyle(list).display);
        await more.click();
        await wait(150);
        const after = await page.$eval('.site-nav-children', (list) => getComputedStyle(list).display);
        const expanded = await page.$eval('[data-site-nav-more]', (button) => button.getAttribute('aria-expanded'));
        await report.shot(page, `${character}-submenu`, { fullPage: false });
        report.verdict(`${character}: a submenu opens from its button`,
          before === 'none' && after !== 'none' && expanded === 'true',
          `before ${before}, after ${after}, aria-expanded ${expanded}`);
        await page.keyboard.press('Escape');
        // And under a resting mouse (D-114), on the bar's own surface with no border; and it
        // closes when the mouse leaves the parent, which holds the panel.
        // The mouse is still on the button from the click above, and a mouse that is
        // already inside the parent does not enter it: it is moved away first.
        await page.mouse.move(5, 700);
        await wait(100);
        const shut = await page.$eval('.site-nav-children', (list) => getComputedStyle(list).display);
        const parent = await page.$('.site-nav > ul > li:has([data-site-nav-more])');
        await parent.hover();
        await wait(150);
        const hovered = await page.$eval('.site-nav-children', (list) => ({ display: getComputedStyle(list).display, border: getComputedStyle(list).borderTopWidth }));
        await page.mouse.move(5, 700);
        await wait(150);
        const left = await page.$eval('.site-nav-children', (list) => getComputedStyle(list).display);
        report.verdict(`${character}: a submenu opens under a resting mouse and closes when it leaves`,
          shut === 'none' && hovered.display !== 'none' && hovered.border === '0px' && left === 'none',
          `shut ${shut}, hovered ${hovered.display} with a ${hovered.border} border, left ${left}`);
      }

      // A sticky header is still on screen after scrolling. Soft is the sticky one (D-112).
      if (character === 'soft') {
        await page.evaluate(() => window.scrollTo(0, 1200));
        await wait(150);
        const top = await page.$eval('header.block-header', (header) => header.getBoundingClientRect().top);
        await report.shot(page, 'soft-scrolled', { fullPage: false });
        report.verdict('soft: the sticky header stays at the top', Math.abs(top) < 2, `header top at ${top}px`);
      }

      // On a phone the navigation folds under one button and opens from it.
      await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 2 });
      await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
      const folded = await page.$eval('.site-nav', (nav) => getComputedStyle(nav).display);
      // A boxed page's frame once took 144px of this screen and left the header 150px.
      const room = await page.$eval('.site-header', (header) => header.getBoundingClientRect().width);
      report.verdict(`${character}: on a phone the header has the width of the screen to use`,
        room >= 390 * 0.65, `${Math.round(room)}px of 390`);
      await report.shot(page, `${character}-phone`, { fullPage: false });
      const toggle = await page.$('[data-site-nav-toggle]');
      const shown = toggle ? await toggle.boundingBox() : null;
      if (toggle === null || shown === null) {
        report.fail(`${character}: the phone menu has a button`, 'no visible Menu button');
        continue;
      }
      await toggle.click();
      await wait(150);
      const opened = await page.$eval('.site-nav', (nav) => getComputedStyle(nav).display);
      await report.shot(page, `${character}-phone-open`, { fullPage: false });
      report.verdict(`${character}: on a phone the menu folds under a button and opens from it`,
        folded === 'none' && opened !== 'none',
        `folded ${folded}, opened ${opened}`);
      // An open menu stands on a painted bar, whatever the header's arrangement: laid over
      // the first section it used to grow down over the hero with nothing behind its
      // items (D-110).
      const bar = await page.$eval('header.block-header', (header) => getComputedStyle(header).backgroundColor);
      report.verdict(`${character}: the open menu stands on a painted bar`, bar !== 'rgba(0, 0, 0, 0)', `header paints ${bar}`);
    }

    /*
     * EVERY ARRANGEMENT AND BEHAVIOUR (D-112), tried through the preview with the screen's
     * own form as the query, so nothing is published: photographed at desktop width, and on
     * a phone with the menu open, where every arrangement has to fold into the one shape.
     */
    await page.setViewport({ width: 1400, height: 900, deviceScaleFactor: 2 });
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    const baseQuery = await page.evaluate(() => {
      const data = new FormData(document.getElementById('design-form'));
      data.delete('action');
      return new URLSearchParams(data).toString();
    });
    const tryLook = (look) => {
      const params = new URLSearchParams(baseQuery);
      Object.entries(look).forEach(([k, v]) => params.set(`look_${k}`, v));
      return `${BASE}/admin/appearance/preview?${params.toString()}`;
    };
    for (const arrangement of ['left', 'inline', 'centred', 'split', 'masthead']) {
      const url = tryLook({ header_arrangement: arrangement, header_behaviour: 'static', header_surface: 'tinted', brand: 'both' });
      await page.setViewport({ width: 1400, height: 900, deviceScaleFactor: 2 });
      await page.goto(url, { waitUntil: 'networkidle2' });
      const desk = await page.evaluate(() => {
        const header = document.querySelector('header.block-header');
        const logo = document.querySelector('.site-logo');
        const links = [...document.querySelectorAll('.site-nav > ul > li > a')];
        const box = (el) => el.getBoundingClientRect();
        return {
          drawn: !!header && box(header).height > 0,
          logoLeftOfEveryLink: !!logo && links.every((a) => box(a).left > box(logo).right - 1),
          logoBetween: !!logo && links.some((a) => box(a).right <= box(logo).left + 1) && links.some((a) => box(a).left >= box(logo).right - 1),
          menuBelowLogo: !!logo && links.every((a) => box(a).top >= box(logo).bottom - 1),
          centred: !!logo && Math.abs((box(logo).left + box(logo).right) / 2 - window.innerWidth / 2) < 40,
          height: header ? Math.round(box(header).height) : 0,
        };
      });
      await report.shot(page, `arrangement-${arrangement}`, { fullPage: false });
      const shape = {
        left: desk.logoLeftOfEveryLink,
        inline: desk.logoLeftOfEveryLink,
        centred: desk.centred && desk.menuBelowLogo,
        split: desk.logoBetween,
        masthead: desk.menuBelowLogo && !desk.centred,
      }[arrangement];
      report.verdict(`${arrangement}: the header is arranged as its name says`, desk.drawn && shape, JSON.stringify(desk));

      await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 2 });
      await page.goto(url, { waitUntil: 'networkidle2' });
      const toggle = await page.$('[data-site-nav-toggle]');
      if (toggle === null) {
        report.fail(`${arrangement}: the phone menu has a button`, 'no Menu button');
        continue;
      }
      await toggle.click();
      await wait(150);
      const phone = await page.evaluate(() => {
        const nav = document.querySelector('.site-nav');
        const links = [...document.querySelectorAll('.site-nav > ul > li > a')];
        const toggle = document.querySelector('[data-site-nav-toggle]');
        return {
          open: !!nav && getComputedStyle(nav).display !== 'none',
          stacked: links.length > 1 && links.every((a, i) => i === 0 || a.getBoundingClientRect().top > links[i - 1].getBoundingClientRect().top),
          inside: links.every((a) => a.getBoundingClientRect().right <= window.innerWidth + 1),
          toggleOnTop: !!toggle && toggle.getBoundingClientRect().top < 120,
        };
      });
      await report.shot(page, `arrangement-${arrangement}-phone-open`, { fullPage: false });
      report.verdict(`${arrangement}: on a phone the menu folds into one stacked list under the button`,
        phone.open && phone.stacked && phone.inside && phone.toggleOnTop, JSON.stringify(phone));
    }
    // And a centred header over the first section — a pair the old single choice could not make.
    await page.setViewport({ width: 1400, height: 900, deviceScaleFactor: 2 });
    await page.goto(tryLook({ header_arrangement: 'centred', header_behaviour: 'over' }), { waitUntil: 'networkidle2' });
    const overCentred = await page.evaluate(() => {
      const header = document.querySelector('header.block-header');
      return { paints: getComputedStyle(header).backgroundColor, positioned: getComputedStyle(header).position, centred: header.querySelector('.layout-centred, .site-header') !== null && document.querySelector('header.layout-centred') !== null };
    });
    await report.shot(page, 'centred-over', { fullPage: false });
    report.verdict('a centred header can lie over the first section', overCentred.paints === 'rgba(0, 0, 0, 0)' && overCentred.positioned === 'absolute' && overCentred.centred, JSON.stringify(overCentred));

    /*
     * A COLOUR OF THE OWNER'S OWN reaches the links (D-076, D-110). Tried through the
     * preview with the screen's own form as the query, exactly as appearance.js sends it,
     * so nothing is published.
     */
    await page.setViewport({ width: 1400, height: 900, deviceScaleFactor: 2 });
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    const query = await page.evaluate(() => {
      const data = new FormData(document.getElementById('design-form'));
      data.set('footer_colour', '#222222');
      data.set('footer_colour_on', '1');
      data.set('header_colour', '#f4f1e8');
      data.set('header_colour_on', '1');
      data.delete('action');
      return new URLSearchParams(data).toString();
    });
    await page.goto(`${BASE}/admin/appearance/preview?${query}`, { waitUntil: 'networkidle2' });
    const own = await page.evaluate(() => ({
      footer: getComputedStyle(document.querySelector('footer.block')).backgroundColor,
      header: getComputedStyle(document.querySelector('header.block')).backgroundColor,
      classes: [...document.querySelectorAll('.own-colour')].length,
    }));
    const ownInks = await linkContrast(page);
    const ownFaint = ownInks.filter((ink) => ink.ratio < 4.5);
    await report.shot(page, 'own-colours', { fullPage: false });
    report.verdict('a colour of the owner\'s own paints the bar and its words read on it',
      own.footer === 'rgb(34, 34, 34)' && own.classes >= 1 && ownInks.length > 0 && ownFaint.length === 0,
      `footer ${own.footer}, header ${own.header}, ${own.classes} bar(s) with own-colour, `
        + (ownFaint.length > 0 ? ownFaint.map((f) => `${f.where} "${f.text}" ${f.colour} on ${f.on} ${f.ratio}:1`).join('; ') : `lowest ${Math.min(...ownInks.map((i) => i.ratio))}:1`));
  },
};
