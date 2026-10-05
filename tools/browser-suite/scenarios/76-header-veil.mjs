/*
 * A HEADER LAID OVER A PICTURE IS READ OVER THE VEIL (PLAN.md D-194, the owner): over a cover
 * hero whose veil clears away from its words — low, on the left, on the right — the veil also
 * reaches the top under the header, so the header's words stand at 4.5:1 or more over the
 * picture beneath. Under each of the five characters, the header set "over the first
 * section", on the home page as a visitor gets it (the draft's preview), at 1440.
 *
 * MEASURED IN PIXELS, not in colours: the header hidden, the picture under each of its words
 * shot, and the words' colour against the lightest and the darkest pixel there. Its control:
 * the same page with the top of the veil taken away is caught where the picture is light.
 *
 * On the copy, in a draft that is discarded; the copy's character is put back at the end.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openBuilder, applyCharacter, clickAndWait, settle, heroPicture, ensureHeaderMenu } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const CHARACTERS = ['editorial', 'minimal', 'bold', 'soft', 'brutalist'];
const LAYOUTS = ['cover-low', 'cover-left', 'cover-right'];

const luminance = ([r, g, b]) => {
  const f = (v) => { const c = v / 255; return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
  return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
};
const ratio = (a, b) => { const x = luminance(a); const y = luminance(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };

/** The worst contrast of the header's words over the picture under them. */
async function worst(page) {
  const words = await page.evaluate(() => [...document.querySelectorAll('.block-header .site-nav > ul > li > a, .block-header .site-name')]
    .filter((n) => n.getBoundingClientRect().width > 0)
    .map((n) => { const r = n.getBoundingClientRect(); return { word: n.textContent.trim(), x: r.left, y: r.top, w: r.width, h: r.height, ink: getComputedStyle(n).color.match(/\d+/g).slice(0, 3).map(Number) }; }));
  const hidden = await page.addStyleTag({ content: '.block-header { visibility: hidden !important; }' });
  let least = { ratio: 99, word: '' };
  for (const w of words) {
    const shot = await page.screenshot({ clip: { x: w.x, y: w.y, width: Math.max(1, w.w), height: Math.max(1, w.h) }, encoding: 'base64' });
    const ends = await page.evaluate(async (data) => {
      const img = new Image();
      img.src = `data:image/png;base64,${data}`;
      await img.decode();
      const canvas = document.createElement('canvas');
      canvas.width = img.width;
      canvas.height = img.height;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0);
      const d = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
      let light = [0, 0, 0];
      let dark = [255, 255, 255];
      for (let i = 0; i < d.length; i += 4) {
        const px = [d[i], d[i + 1], d[i + 2]];
        if (px[0] + px[1] + px[2] > light[0] + light[1] + light[2]) { light = px; }
        if (px[0] + px[1] + px[2] < dark[0] + dark[1] + dark[2]) { dark = px; }
      }
      return [light, dark];
    }, shot);
    const r = Math.min(...ends.map((px) => ratio(w.ink, px)));
    if (r < least.ratio) { least = { ratio: r, word: w.word }; }
  }
  await hidden.evaluate((n) => n.remove());
  return { ...least, words: words.length };
}

/** The header laid over the first section, published with the rest of the design. */
async function headerOver(page) {
  await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
  await page.evaluate(() => {
    const r = [...document.querySelectorAll('input[name="look_header_behaviour"][value="over"]')].find((x) => x.form && x.form.id === 'design-form');
    r.click();
  });
  await wait(800);
  await clickAndWait(page, 'button[form="design-form"][name="action"][value="save"]');
  if (await page.$('.publish-confirm button[value="save_design"]')) { await clickAndWait(page, '.publish-confirm button[value="save_design"]'); }
}

export default {
  name: 'header-veil',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('header veil: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    const was = await page.$eval('.character-tile.is-current .tile-use', (b) => b.value.replace('preset:', '')).catch(() => '');
    // The hero's picture, put in the draft: the page's own may have been taken away, and a
    // header over a band with no picture reads anyway — measured: a run that passed on that.
    const picture = await heroPicture(page, BASE);
    if (picture === null) {
      report.fail('header veil: a picture for the hero', 'the library holds none');
      return;
    }
    // The menu's words are what lie over the picture; the full suite reached here with none.
    await ensureHeaderMenu(page, BASE);
    try {
      for (const character of CHARACTERS) {
        await applyCharacter(page, BASE, character, 'save');
        await headerOver(page);
        const seen = [];
        let ok = true;
        for (const layout of LAYOUTS) {
          await openBuilder(page, BASE, 1);
          await page.evaluate((l, pic) => {
            const hero = window.pb.doc.blocks.find((b) => b.type === 'hero');
            window.pb.change(() => { hero.layout = l; hero.content.image = pic; hero.options = { ...hero.options, height: 'tall', veil: 'light' }; }, { sections: [hero.section] });
          }, layout, picture);
          await settle(page);
          await page.goto(`${BASE}/admin/pages/1/preview`, { waitUntil: 'networkidle2' });
          await page.evaluate(() => document.fonts.ready);
          await wait(500);
          // What is measured has to be there: the header over the first band, that band a
          // cover hero with its picture, and the menu's words as well as the name.
          const there = await page.evaluate(() => ({
            over: getComputedStyle(document.querySelector('header.block-header')).position === 'absolute',
            cover: !!document.querySelector('main > .block:first-child .hero.is-cover .hero-cover-picture img'),
            links: document.querySelectorAll('.block-header .site-nav > ul > li > a').length,
          }));
          const w = await worst(page);
          seen.push(`${layout} ${w.ratio.toFixed(2)} (${w.word}${there.cover ? '' : ', NO COVER'}${there.links ? '' : ', NO MENU'})`);
          if (!there.over || !there.cover || there.links === 0 || w.ratio < 4.5) { ok = false; }
          if (character === 'editorial' && layout === 'cover-low') {
            await report.shot(page, 'editorial-cover-low-over', { fullPage: false });
            // THE CONTROL: the top of the veil taken away.
            await page.addStyleTag({ content: '.hero.is-cover { --hero-veil-top: none !important; }' });
            const bare = await worst(page);
            report.verdict('the control: the header\'s words over the picture with the top of the veil taken away are caught under 4.5:1',
              bare.ratio < 4.5, `${bare.ratio.toFixed(2)} (${bare.word}) without, ${w.ratio.toFixed(2)} with`);
          }
        }
        report.verdict(`${character}: a header laid over a cover hero, low, left or right, reads at 4.5:1 or more over the picture under it`, ok, seen.join('; '));
      }
    } finally {
      await openBuilder(page, BASE, 1);
      if (await page.$('[data-pb-discard]:not([hidden])')) {
        await page.click('[data-pb-discard]');
        await page.waitForFunction(() => document.querySelector('[data-pb-discard]').hidden, { timeout: 10000 }).catch(() => {});
      }
      if (was !== '') { await applyCharacter(page, BASE, was, 'save'); }
      report.verdict('header veil: the draft discarded and the copy\'s character back', was !== '', was || 'could not read it');
    }
  },
};
