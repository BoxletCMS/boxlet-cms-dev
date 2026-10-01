/*
 * THE APPEARANCE INSPECTOR (PLAN.md D-157 to D-160): the home and its sections, Back from a
 * section, the dot on a changed control and the reset done in place, Quick start's mirrors
 * in both directions, the layout diagram following a slider, and the question Publish asks
 * after a character is loaded — at the top of the inspector, not across the screen.
 *
 * ON THE DEVELOPMENT SITE, and it publishes nothing: every change here is made on the screen
 * and left there, and the one question Publish asks is photographed and not answered. The
 * page is left by navigating away.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, openSection } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const shot = (report, page, name) => report.shot(page, name, { fullPage: false });

export default {
  name: 'appearance-inspector',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('appearance-inspector: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1500, height: 1000, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    await wait(800);

    // ---- the home --------------------------------------------------------------------------
    const home = await page.evaluate(() => {
      const inspector = document.querySelector('.appearance-inspector');
      return {
        shown: !document.querySelector('[data-view="home"]').hidden,
        sections: [...document.querySelectorAll('[data-view]')].filter((v) => v.getAttribute('data-view') !== 'home' && !v.hidden).length,
        tiles: document.querySelectorAll('.character-tile').length,
        mirrors: !document.querySelector('[data-quick]').hidden,
        links: document.querySelectorAll('.section-link').length,
        sideways: inspector.scrollWidth - inspector.clientWidth,
      };
    });
    await shot(report, page, '01-home');
    // Seven since D-164 gave the buttons a section of their own.
    report.verdict('the inspector opens on the home: characters, Quick start, seven sections, no section shown',
      home.shown && home.sections === 0 && home.tiles >= 5 && home.mirrors && home.links === 7 && home.sideways <= 0,
      JSON.stringify(home));
    // As the server sends it: the scripts measure sizes onto the frame and the specimen through
    // the CSSOM, which is allowed; the markup itself carries none (the admin's CSP).
    const styled = await page.evaluate(async () => ((await (await fetch(window.location.pathname, { credentials: 'same-origin' })).text()).match(/\sstyle="/g) || []).length);
    report.verdict('the screen\'s markup carries no style attribute', styled === 0, `${styled} found`);

    // ---- the hints as an icon in the top row (D-161) ---------------------------------------
    const hints = await page.evaluate(() => {
      const icon = document.querySelector('.hints-icon');
      const first = document.querySelector('[data-view="home"] .control-group-head');
      const a = icon.getBoundingClientRect();
      const b = first.getBoundingClientRect();
      // Clear of what the row already holds at its end: the count and the fold's − sign.
      const toggle = first.querySelector('.control-group-toggle').getBoundingClientRect();
      return { shown: !icon.hidden, sameRow: a.top < b.bottom && a.bottom > b.top, clear: toggle.right <= a.left, title: icon.title };
    });
    report.verdict('the hints are an icon in the inspector\'s first row, not a row of their own',
      hints.shown && hints.sameRow && hints.clear && hints.title.length > 0, JSON.stringify(hints));

    // ---- a section, and Back --------------------------------------------------------------
    await openSection(page, 'header');
    await wait(300);
    await shot(report, page, '02-header');
    const head = await page.evaluate(() => {
      const view = document.querySelector('[data-view="header"]');
      const title = view.querySelector('.section-title').getBoundingClientRect();
      const reset = view.querySelector('.section-reset');
      const box = reset.getBoundingClientRect();
      const tiles = view.querySelectorAll('[data-control="header_arrangement"] .tile-option');
      return {
        resetShown: getComputedStyle(reset).display !== 'none',
        oneRow: getComputedStyle(reset).display === 'none' || (box.top < title.bottom && box.bottom > title.top),
        atTheEnd: getComputedStyle(reset).display === 'none' || Math.round(box.right) >= Math.round(view.getBoundingClientRect().right) - 2,
        tiles: tiles.length,
        perRow: tiles.length >= 4 && new Set([...tiles].slice(0, 3).map((t) => Math.round(t.getBoundingClientRect().top))).size === 1
          && Math.round(tiles[3].getBoundingClientRect().top) > Math.round(tiles[0].getBoundingClientRect().top),
        pictures: view.querySelectorAll('[data-control="header_arrangement"] .tile-option svg').length,
        over: [...view.querySelectorAll('input[name="look_header_behaviour"]')].map((i) => i.closest('label').textContent.trim()),
      };
    });
    report.verdict('the section\'s reset stands on its title\'s row, at the end',
      head.oneRow && head.atTheEnd, JSON.stringify(head));
    report.verdict('the header\'s arrangement is tiles with a drawing each, three to a row',
      head.tiles === 5 && head.pictures === 5 && head.perRow, JSON.stringify(head));
    report.verdict('the header lies "Over the hero", not "over the top"',
      head.over.includes('Over the hero') && !head.over.some((w) => /over the top/i.test(w)), JSON.stringify(head.over));
    const header = await page.evaluate(() => ({
      url: window.location.hash,
      home: !document.querySelector('[data-view="home"]').hidden,
      groups: [...document.querySelectorAll('[data-view="header"] .control-group-title')].map((t) => t.textContent.trim()),
      follow: document.querySelectorAll('.segment-follow').length,
    }));
    report.verdict('a section opens in the home\'s place, in groups, with no "follow" button',
      header.url === '#view-header' && !header.home && header.groups.length >= 4 && header.follow === 0,
      JSON.stringify(header));
    await page.goBack();
    await wait(300);
    const back = await page.evaluate(() => ({ url: window.location.pathname + window.location.hash, home: !document.querySelector('[data-view="home"]').hidden }));
    report.verdict('Back from a section is the home, still on this screen',
      back.home && back.url.endsWith('/admin/appearance'), JSON.stringify(back));

    // ---- the dot and the reset, in place ----------------------------------------------------
    await openSection(page, 'space');
    // A slider since D-164, moved the way a hand moves it from the keyboard: two steps right,
    // or left at the top of the range.
    const row = '[data-view="space"] [data-control="spacing"]';
    const slider = `${row} input[type="range"]`;
    const start = await page.$eval(slider, (el) => ({ value: el.value, max: el.max, fallback: el.closest('[data-control]').getAttribute('data-default') }));
    await page.evaluate(() => { window.__sameDocument = true; });
    await page.focus(slider);
    await page.keyboard.press(start.value === start.max ? 'ArrowLeft' : 'ArrowRight');
    await page.keyboard.press(start.value === start.max ? 'ArrowLeft' : 'ArrowRight');
    await wait(300);
    const dotted = await page.evaluate((selector) => ({
      changed: document.querySelector(selector).classList.contains('is-changed'),
      resetShown: getComputedStyle(document.querySelector(`${selector} .control-reset`)).display !== 'none',
      count: document.querySelector('[data-section-count="space"]').textContent.trim(),
      banner: !document.querySelector('[data-overrides]').hidden,
    }), row);
    await shot(report, page, '03-changed');
    report.verdict('a control moved away from the character shows its dot and its reset at once',
      dotted.changed && dotted.resetShown && Number(dotted.count) >= 1 && dotted.banner, JSON.stringify(dotted));
    await page.click(`${row} .control-reset`);
    await wait(400);
    const reset = await page.evaluate((selector) => ({
      value: document.querySelector(`${selector} input[type="range"]`).value,
      changed: document.querySelector(selector).classList.contains('is-changed'),
      sameDocument: window.__sameDocument === true,
    }), row);
    report.verdict('its reset puts the character\'s value back where it stands, without a reload',
      reset.value === start.fallback && !reset.changed && reset.sameDocument, JSON.stringify(reset));

    // ---- Quick start mirrors, both ways -------------------------------------------------------
    await openSection(page, 'home');
    // Corners are a slider in both places since D-164; each is moved from the keyboard.
    const quickCorners = '[data-quick] [data-control="radius"] input[type="range"]';
    const fieldCorners = '[data-view="space"] [data-control="radius"] input[type="range"]';
    const corners = await page.$eval(quickCorners, (el) => el.value);
    await page.focus(quickCorners);
    await page.keyboard.press('ArrowRight');
    await wait(200);
    const otherCorners = await page.$eval(quickCorners, (el) => el.value);
    const fromQuick = await page.$eval('#design-form', (form) => form.elements.namedItem('radius').value);
    await openSection(page, 'space');
    await page.focus(fieldCorners);
    await page.keyboard.press('ArrowLeft');
    await wait(200);
    const toQuick = await page.$eval(quickCorners, (el) => el.value);
    report.verdict('Quick start and its section are one control: each follows the other',
      otherCorners !== corners && fromQuick === otherCorners && toQuick === corners, `quick ${corners} → ${otherCorners}, the field ${fromQuick}; the field back, quick ${toQuick}`);

    // ---- the diagram follows the width ---------------------------------------------------------
    await openSection(page, 'layout');
    const widthWas = await page.$eval('#design-container', (el) => el.value);
    await page.$eval('#design-container', (el) => {
      el.value = '40';
      el.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await page.waitForFunction(() => document.querySelector('[data-diagram="content"]').getAttribute('width') === '128', { timeout: 10000 }).catch(() => {});
    const drawn = await page.evaluate(() => ({
      content: document.querySelector('[data-diagram="content"]').getAttribute('width'),
      legend: document.querySelector('[data-readout="layout.content"]').textContent.trim(),
    }));
    report.verdict('the diagram and its legend follow the content width as it moves',
      drawn.content === '128' && /640/.test(drawn.legend), JSON.stringify(drawn));
    await page.$eval('#design-container', (el, back) => {
      el.value = back;
      el.dispatchEvent(new Event('input', { bubbles: true }));
    }, widthWas);
    await wait(900);
    await shot(report, page, '04-layout');

    // ---- the question Publish asks, where the answer is given ----------------------------------
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    const other2 = await page.$$eval('.character-tile', (tiles) => {
      const free = tiles.find((t) => !t.classList.contains('is-current'));
      return free ? free.querySelector('.tile-use').value : '';
    });
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click(`button[value="${other2}"]`)]);
    const loadAsked = await page.$('.load-confirm');
    if (loadAsked !== null) {
      const top = await page.evaluate(() => ({
        scrolled: document.querySelector('.appearance-inspector').scrollTop,
        inView: document.querySelector('.load-confirm').getBoundingClientRect().top >= document.querySelector('.appearance-inspector').getBoundingClientRect().top,
      }));
      report.verdict('the question a load asks is in view at the top of the inspector', top.scrolled === 0 && top.inView, JSON.stringify(top));
      await shot(report, page, '05-load-question');
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click(`.load-confirm button[value^="load:"]`)]);
    }
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('button[form="design-form"][name="action"][value="save"]')]);
    const asked = await page.evaluate(() => {
      const panel = document.querySelector('.publish-confirm');
      const stage = document.querySelector('.appearance-stage-column').getBoundingClientRect();
      return panel ? {
        inInspector: !!panel.closest('.appearance-inspector'),
        pictureAtTop: Math.round(stage.top) <= Math.round(document.querySelector('.appearance-bar').getBoundingClientRect().bottom) + 1,
        answers: panel.querySelectorAll('button[value="save_design"], button[value="save_composition"]').length,
        cancel: (panel.querySelector('a[data-apply-cancel]') || {}).getAttribute?.('href') ?? null,
        replaces: /of your changes/.test(panel.textContent),
        warns: getComputedStyle(panel).backgroundColor !== getComputedStyle(document.querySelector('.appearance-inspector')).backgroundColor,
      } : null;
    });
    await shot(report, page, '06-apply-question');
    report.verdict('Publish after a character asks at the top of the inspector, and the picture stays where it is',
      asked !== null && asked.inInspector && asked.pictureAtTop && asked.answers === 2 && asked.warns, JSON.stringify(asked));
    // The development site holds the owner's own changes over its character, so the question
    // has a loss to name (D-161) — measured at seventeen over Bold when it said nothing.
    report.verdict('it says how many of the site\'s own changes it replaces, and offers Cancel',
      asked !== null && asked.replaces && asked.cancel !== null && /\/admin\/appearance$/.test(asked.cancel), JSON.stringify(asked));
    // Not answered: leaving the screen publishes nothing.
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle2' });
  },
};
