/*
 * NO LAYOUT GIVES WORDS A COLUMN NARROWER THAN 16REM (PLAN.md D-182), measured: on the demo's
 * page with every block, each block in each of its layouts, on desktop, tablet and phone, the
 * width each of its word fields is set in — the block's own words, a heading, a lead, a body;
 * a repeater's items have grids of their own and are left out.
 *
 * And a split hero with no picture: words alone on the visitor's page, the place for one in
 * the canvas.
 *
 * ON THE COPY. Layouts are tried in the builder's draft, which is discarded at the end.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openBuilder, settle, applyCharacter } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const NARROWEST = 256;
const ARRANGEMENTS = ['halves', 'thirds', 'quarters', 'wide-left', 'wide-right', 'sidebar'];

/**
 * SECTIONS OF COLUMNS (D-184): a band of each arrangement added to the draft, a Text in every
 * column, on each device. Every column's words are 16rem or more, or the columns are stacked,
 * one under another — never two words-columns squeezed side by side.
 */
async function columns(page, report, label) {
  const keys = await page.evaluate((arrangements) => {
    const made = [];
    window.pb.change((doc) => {
      arrangements.forEach((layout) => {
        const key = window.pb.mint('m');
        const n = { halves: 2, thirds: 3, quarters: 4, 'wide-left': 2, 'wide-right': 2, sidebar: 2 }[layout];
        doc.sections.push({ key, id: null, layout, stack: 'stack', style: {} });
        for (let c = 0; c < n; c += 1) {
          const item = window.pb.libraryItem('text');
          doc.blocks.push({ key: window.pb.mint('n'), id: null, type: 'text', content: window.pb.copy(item.fresh), style: {}, options: {}, layout: item.layout, section: key, column: c });
        }
        made.push([layout, key]);
      });
    }, { structure: true });
    return made;
  }, ARRANGEMENTS);
  await wait(2500);
  for (const device of ['desktop', 'tablet', 'phone']) {
    await page.click(`[data-device="${device}"]`);
    await wait(1500);
    const seen = await page.evaluate((made, least) => {
      const doc = document.querySelector('[data-pb-canvas]').contentDocument;
      return made.map(([layout, key]) => {
        const band = doc.querySelector(`[data-bx-section="${key}"]`);
        if (!band) { return { layout, missing: true }; }
        const cols = [...band.querySelectorAll('.section-column')].map((c) => c.getBoundingClientRect());
        const stacked = cols.every((r) => Math.abs(r.left - cols[0].left) < 1);
        const narrow = [...band.querySelectorAll('[data-bx-field="body"]')].map((f) => {
          const range = doc.createRange();
          range.selectNodeContents(f);
          const lines = new Set([...range.getClientRects()].filter((r) => r.width > 2).map((r) => Math.round(r.top))).size;
          return { width: Math.round(f.getBoundingClientRect().width), lines };
        }).filter((f) => f.width < least && f.lines > 1);
        return { layout, stacked, width: [...band.classList].find((c) => c.startsWith('width-')), columns: cols.map((r) => Math.round(r.width)), narrow };
      });
    }, keys, NARROWEST);
    const bad = seen.filter((x) => x.missing || x.narrow.length > 0);
    report.verdict(`${label}, ${device}: in every arrangement of columns each column's words are 16rem or more, or the columns stack`,
      seen.length === ARRANGEMENTS.length && bad.length === 0,
      bad.length ? JSON.stringify(bad) : seen.map((x) => `${x.layout} ${x.width}${x.stacked ? ' stacked' : ''} [${x.columns.join(',')}]`).join('; '));
  }
  await page.click('[data-device="desktop"]');
  await wait(800);
  return keys;
}

export default {
  name: 'layouts',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('layouts: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await openBuilder(page, BASE, 1);
    const every = await page.evaluate(() => (window.pb.data.inline.pages.find((p) => /\/blocks\/?$/.test(p.url)) || {}).ref || '');
    if (!/^page:\d+$/.test(every)) {
      report.fail('layouts: the page with every block', `the demo has no /blocks page: ${every}`);
      return;
    }
    const id = Number(every.slice(5));
    await openBuilder(page, BASE, id);
    try {
      for (const device of ['desktop', 'tablet', 'phone']) {
        await page.click(`[data-device="${device}"]`);
        await wait(1200);
        const plan = await page.evaluate(() => window.pb.doc.blocks.map((b) => ({ key: b.key, type: b.type, was: b.layout, layouts: ((window.pb.data.layouts || {})[b.type] || []).map((l) => l.value) })));
        const narrow = [];
        let tried = 0;
        for (const block of plan) {
          for (const layout of block.layouts) {
            await page.evaluate((k, l) => window.pb.setLayout(k, l), block.key, layout);
            await page.waitForFunction((k, l) => {
              const el = document.querySelector('[data-pb-canvas]').contentDocument.querySelector(`[data-bx-key="${k}"]`);
              return el && (el.className.includes(`layout-${l}`) || !!el.querySelector(`.layout-${l}`) || !!el.closest(`.layout-${l}`));
            }, { timeout: 8000 }, block.key, layout).catch(() => {});
            await wait(250);
            tried += 1;
            const found = await page.evaluate((k, type, least) => {
              const doc = document.querySelector('[data-pb-canvas]').contentDocument;
              const host = doc.querySelector(`[data-bx-key="${k}"]`);
              if (!host) { return []; }
              const out = [];
              host.querySelectorAll('[data-bx-field]').forEach((field) => {
                if (field.closest('[data-bx-item]')) { return; }
                const spec = window.pb.inline.spec(type, field.getAttribute('data-bx-field'));
                if (!spec || !['text', 'textarea', 'richtext'].includes(spec.type)) { return; }
                // The column it is set in: itself, or the first box around an inline element.
                let box = field;
                while (box.parentElement && doc.defaultView.getComputedStyle(box).display.startsWith('inline')) { box = box.parentElement; }
                const width = box.getBoundingClientRect().width;
                // Narrow and its words broken over lines: squeezed. A short line in a box sized
                // to it (an attribution, "Ana Perić") is not a column at all.
                const range = doc.createRange();
                range.selectNodeContents(field);
                const lines = new Set([...range.getClientRects()].filter((r) => r.width > 2).map((r) => Math.round(r.top))).size;
                if (width > 0 && width < least && lines > 1) { out.push(`${field.getAttribute('data-bx-field')} ${Math.round(width)}px, ${lines} lines`); }
              });
              return out;
            }, block.key, block.type, NARROWEST);
            found.forEach((f) => narrow.push(`${block.type}/${layout}: ${f}`));
          }
          await page.evaluate((k, l) => window.pb.setLayout(k, l), block.key, block.was);
        }
        report.verdict(`${device}: no layout of any block sets its words narrower than 16rem (${tried} block × layout)`,
          tried > 30 && narrow.length === 0, narrow.slice(0, 12).join('; ') || 'none narrower');
      }
      await page.click('[data-device="desktop"]');
      await wait(800);

      // ---- sections of columns (D-184), under the copy's character -------------------------
      await columns(page, report, 'the copy\'s character');

      // ---- a split hero with no picture -------------------------------------------------------------
      const hero = await page.evaluate(() => (window.pb.doc.blocks.find((b) => b.type === 'hero' && b.content.image === null) || {}).key || null);
      if (hero === null) {
        report.fail('layouts: a hero with no picture', 'the every-block page has none');
      } else {
        await page.evaluate((k) => { window.pb.setLayout(k, 'split'); window.pb.select('block', k); }, hero);
        await wait(1500);
        const canvas = await page.evaluate((k) => {
          const doc = document.querySelector('[data-pb-canvas]').contentDocument;
          const media = doc.querySelector(`[data-bx-key="${k}"] .hero-media.is-empty`);
          if (media) { media.scrollIntoView({ block: 'center' }); }
          return { place: !!media, says: media ? media.textContent.trim() : '' };
        }, hero);
        await report.shot(page, '01-split-no-picture-canvas', { fullPage: false });
        report.verdict('in the canvas a split hero with no picture keeps the place for one, saying so', canvas.place && /Add picture/.test(canvas.says), JSON.stringify(canvas));
        await settle(page);
        // The visitor's view of the same draft: the builder's preview, as a page.
        const preview = await page.evaluate(() => document.querySelector('a[href$="/preview"]').href);
        const visitor = await browserPage(page, preview);
        report.verdict('on the page a split hero with no picture is words alone, as wide as Left\'s', visitor.alone && !visitor.media && visitor.ratio > 0.9, JSON.stringify(visitor));
      }
    } finally {
      await settle(page).catch(() => {});
      await openBuilder(page, BASE, id);
      if (await page.$('[data-pb-discard]:not([hidden])')) {
        await page.click('[data-pb-discard]');
        await page.waitForFunction(() => document.querySelector('[data-pb-discard]').hidden, { timeout: 10000 }).catch(() => {});
      }
      const state = await page.$eval('[data-pb-state]', (p) => p.className);
      report.verdict('layouts: the draft is discarded', /status-published/.test(state), state);
    }

    // ---- under Editorial, the narrowest content of the five (42rem), and back -------------------
    // The owner's case (D-184): Editorial's home, text and quote side by side in a wide-left
    // band whose narrow column was 13.2rem; it stays side by side, at 16rem or more.
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    const was = await page.$eval('.character-tile.is-current .tile-use', (b) => b.value.replace('preset:', '')).catch(() => '');
    await applyCharacter(page, BASE, 'editorial', 'save_composition');
    try {
      await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
      const home = await page.evaluate(() => {
        const quote = document.querySelector('.cols-wide-left .quote-words, .cols-wide-left blockquote');
        const band = quote ? quote.closest('.block') : null;
        if (!band) { return null; }
        band.scrollIntoView({ block: 'center' });
        const cols = [...band.querySelectorAll('.section-column')].map((c) => c.getBoundingClientRect());
        return { width: [...band.classList].find((c) => c.startsWith('width-')), quote: Math.round((quote.getBoundingClientRect().width / 16) * 10) / 10, side: cols.length === 2 && cols[1].left > cols[0].right };
      });
      await new Promise((r) => setTimeout(r, 600));
      await report.shot(page, '03-editorial-experience', { fullPage: false });
      report.verdict('Editorial\'s home: text and quote side by side, the quote 16rem or more', home !== null && home.side && home.quote >= 15.95, JSON.stringify(home));
      await openBuilder(page, BASE, id);
      await columns(page, report, 'Editorial');
    } finally {
      await settle(page).catch(() => {});
      await openBuilder(page, BASE, id);
      if (await page.$('[data-pb-discard]:not([hidden])')) {
        await page.click('[data-pb-discard]');
        await page.waitForFunction(() => document.querySelector('[data-pb-discard]').hidden, { timeout: 10000 }).catch(() => {});
      }
      if (was !== '' && was !== 'editorial') { await applyCharacter(page, BASE, was, 'save_composition'); }
      report.verdict('layouts: the copy\'s character is back', was !== '', `${was || 'could not read it'}`);
    }

    /** The draft's preview in a page of its own: the split hero's parts, and its words' width against its band's. */
    async function browserPage(p, url) {
      const other = await p.browser().newPage();
      await other.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
      await other.goto(url, { waitUntil: 'networkidle2' });
      const seen = await other.evaluate(() => {
        const hero = [...document.querySelectorAll('.layout-split .hero')].find((h) => h.classList.contains('is-alone')) || document.querySelector('.layout-split .hero');
        if (!hero) { return { alone: false }; }
        const text = hero.querySelector('.hero-text').getBoundingClientRect().width;
        return { alone: hero.classList.contains('is-alone'), media: !!hero.querySelector('.hero-media'), ratio: Math.round((text / hero.getBoundingClientRect().width) * 100) / 100 };
      });
      await report.shot(other, '02-split-no-picture-page', { fullPage: false });
      await other.close();
      return seen;
    }
  },
};
