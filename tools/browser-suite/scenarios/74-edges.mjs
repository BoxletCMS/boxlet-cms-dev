/*
 * THE PAGE'S EDGES (PLAN.md D-193, the owner), under each of the five characters at 1440, on the
 * home page as a visitor gets it (the draft's preview):
 *
 *   - a split hero widened to the wide measure keeps its words on the page's edge — where the
 *     content of a section in the character's width begins, the header's logo where it stands
 *     at the left — and only its picture reaches out;
 *   - the last band and the footer of one surface are one band: the band's bottom room and the
 *     footer's top room are each half, and no edge is drawn between them. Its control: the same
 *     band on another surface keeps its whole room.
 *
 * On the copy, in a draft that is discarded; the copy's character is put back at the end.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openBuilder, applyCharacter, settle } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const CHARACTERS = ['editorial', 'minimal', 'bold', 'soft', 'brutalist'];

function measured(page) {
  return page.evaluate(() => {
    const main = document.querySelector('main');
    const probe = (style) => { const p = document.createElement('div'); Object.assign(p.style, style); main.appendChild(p); return p; };
    const page = probe({ maxWidth: 'var(--page-content-width, var(--container-width))', blockSize: '0' });
    page.className = 'container';
    const edge = page.getBoundingClientRect().left + parseFloat(getComputedStyle(page).paddingLeft);
    page.remove();
    const text = document.querySelector('main > .block:first-child .hero-text').getBoundingClientRect();
    const media = document.querySelector('main > .block:first-child .hero-media').getBoundingClientRect();
    const container = document.querySelector('main > .block:first-child .container');
    const c = container.getBoundingClientRect();
    const right = c.right - parseFloat(getComputedStyle(container).paddingRight);
    const last = document.querySelector('main > .block:last-child');
    const footer = document.querySelector('footer.block');
    const gap = probe({ blockSize: '0', paddingTop: 'var(--section-gap)' });
    gap.className = 'block';
    const half = parseFloat(getComputedStyle(gap).paddingTop) / 2;
    gap.remove();
    const room = probe({ blockSize: '0', paddingTop: 'var(--space-xl)' });
    const xl = parseFloat(getComputedStyle(room).paddingTop);
    room.remove();
    const fs = getComputedStyle(footer);
    return {
      edge, text: text.left, mediaRight: media.right, right,
      // A footer with a colour of its own is another surface whatever its class says (Brutalist).
      same: (last.className.match(/surface-\S+/) || [''])[0] === (footer.className.match(/surface-\S+/) || [''])[0] && !footer.querySelector('.site-footer.own-colour'),
      own: !!footer.querySelector('.site-footer.own-colour'),
      bottom: parseFloat(getComputedStyle(last).paddingBottom), half,
      footerTop: parseFloat(getComputedStyle(footer.querySelector('.site-footer')).paddingTop), xl,
      footerEdge: fs.borderTopLeftRadius !== '0px' || fs.clipPath !== 'none' || parseFloat(fs.marginTop) !== 0,
    };
  });
}

export default {
  name: 'edges',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('edges: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    const was = await page.$eval('.character-tile.is-current .tile-use', (b) => b.value.replace('preset:', '')).catch(() => '');
    try {
      for (const character of CHARACTERS) {
        await applyCharacter(page, BASE, character, 'save');
        const seen = {};
        for (const pass of ['same', 'other']) {
          await openBuilder(page, BASE, 1);
          await page.evaluate(async (p, base) => {
            const doc = window.pb.doc;
            const hero = doc.blocks.find((b) => b.type === 'hero');
            const last = doc.sections[doc.sections.length - 1];
            // The footer's surface, read off the page a visitor gets — the canvas draws no
            // footer — or another one for the control.
            const html = await (await fetch(`${base}/`, { credentials: 'same-origin' })).text();
            const surface = (html.match(/<footer class="[^"]*surface-(plain|tinted|contrast|gradient)/) || [null, 'contrast'])[1];
            const other = surface === 'contrast' ? 'tinted' : 'contrast';
            window.pb.change(() => { hero.layout = 'split'; last.style.surface = p === 'same' ? surface : other; last.style.pad_bottom = ''; }, { sections: [hero.section, last.key] });
          }, pass, BASE);
          await settle(page);
          await page.goto(`${BASE}/admin/pages/1/preview`, { waitUntil: 'networkidle2' });
          await wait(400);
          seen[pass] = await measured(page);
          if (character === 'soft' && pass === 'same') { await report.shot(page, 'soft-hero-edge', { fullPage: false }); }
        }
        const s = seen.same;
        report.verdict(`${character}: a split hero's words on the page's edge, its picture to the wide edge`,
          Math.abs(s.text - s.edge) <= 1 && Math.abs(s.mediaRight - s.right) <= 1,
          JSON.stringify({ text: Math.round(s.text), edge: Math.round(s.edge), mediaRight: Math.round(s.mediaRight), right: Math.round(s.right) }));
        const o = seen.other;
        const joined = s.own
          // Its own colour: never one band, its room and edge as they were.
          ? !s.same && s.bottom >= s.half * 2 - 1 && Math.abs(s.footerTop - s.xl) <= 1
          : s.same && Math.abs(s.bottom - s.half) <= 1 && Math.abs(s.footerTop - s.xl / 2) <= 1 && !s.footerEdge;
        report.verdict(`${character}: the last band and a footer of its surface are one band, half the room each and no edge (a footer of its own colour apart); on another surface the band keeps its room`,
          joined && !o.same && o.bottom >= o.half * 2 - 1 && Math.abs(o.footerTop - o.xl) <= 1,
          JSON.stringify({ own: s.own, same: { bottom: Math.round(s.bottom), half: Math.round(s.half), footerTop: Math.round(s.footerTop), edge: s.footerEdge }, other: { bottom: Math.round(o.bottom), footerTop: Math.round(o.footerTop) } }));
      }
    } finally {
      await openBuilder(page, BASE, 1);
      if (await page.$('[data-pb-discard]:not([hidden])')) {
        await page.click('[data-pb-discard]');
        await page.waitForFunction(() => document.querySelector('[data-pb-discard]').hidden, { timeout: 10000 }).catch(() => {});
      }
      if (was !== '') { await applyCharacter(page, BASE, was, 'save'); }
      report.verdict('edges: the draft discarded and the copy\'s character back', was !== '', was || 'could not read it');
    }
  },
};
