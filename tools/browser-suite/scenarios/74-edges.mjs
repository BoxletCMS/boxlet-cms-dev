/*
 * THE PAGE'S EDGES (PLAN.md D-193, the owner), under each of the five characters at 1440, on the
 * home page as a visitor gets it (the draft's preview):
 *
 *   - a split hero widened to the wide measure keeps its words on the page's edge — where the
 *     content of a section in the character's width begins, the header's logo where it stands
 *     at the left — and only its picture reaches out;
 *   - the last band and the footer of one surface are one band: the band's bottom room and the
 *     footer's top room are each half, and no edge is drawn between them. Its control: the same
 *     band on another surface keeps its whole room;
 *   - two bands of one surface are one band whatever the second's edge (D-194): a curve
 *     between them is not drawn and a line stands in the middle, each with the gap of two
 *     bands with no edge between them — half each side. Its control: the curved band on
 *     another surface keeps its edge and its depth.
 *
 * On the copy, in a draft that is discarded; the copy's character is put back at the end.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openBuilder, applyCharacter, settle, heroPicture } from '../harness.mjs';

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
    // The bands after the hero, two by two: the room between their words, and the edge drawn.
    const bands = [...main.querySelectorAll(':scope > .block')];
    const joins = [1, 2].map((i) => {
      const a = bands[i].querySelector('.container').getBoundingClientRect();
      const b = bands[i + 1].querySelector('.container').getBoundingClientRect();
      const bs = getComputedStyle(bands[i + 1]);
      return { room: b.top - a.bottom, curve: bs.borderTopLeftRadius !== '0px', line: parseFloat(bs.borderTopWidth) };
    });
    return {
      joins, gap: half * 2,
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
    // The split hero's picture, put in the draft: the page's own may have been taken away.
    const picture = await heroPicture(page, BASE);
    if (picture === null) {
      report.fail('edges: a picture for the hero', 'the library holds none');
      return;
    }
    try {
      for (const character of CHARACTERS) {
        await applyCharacter(page, BASE, character, 'save');
        const seen = {};
        for (const pass of ['same', 'other']) {
          await openBuilder(page, BASE, 1);
          await page.evaluate(async (p, base, picture) => {
            const doc = window.pb.doc;
            const hero = doc.blocks.find((b) => b.type === 'hero');
            const last = doc.sections[doc.sections.length - 1];
            // The footer's surface, read off the page a visitor gets — the canvas draws no
            // footer — or another one for the control.
            const html = await (await fetch(`${base}/`, { credentials: 'same-origin' })).text();
            const surface = (html.match(/<footer class="[^"]*surface-(plain|tinted|contrast|gradient)/) || [null, 'contrast'])[1];
            const other = surface === 'contrast' ? 'tinted' : 'contrast';
            // The three bands after the hero: one surface, the second's edge a curve and the
            // third's a line — or, for the control, the second on another surface.
            const [, one, two, three] = doc.sections;
            window.pb.change(() => {
              hero.layout = 'split';
              hero.content.image = picture;
              last.style.surface = p === 'same' ? surface : other;
              last.style.pad_bottom = '';
              [one, two, three].forEach((s) => { s.style.surface = 'tinted'; s.style.divider = 'none'; s.style.pad_top = ''; s.style.pad_bottom = ''; });
              two.style.divider = 'curve';
              three.style.divider = 'line';
              if (p === 'other') { two.style.surface = 'contrast'; }
            }, { sections: [hero.section, last.key, one.key, two.key, three.key] });
          }, pass, BASE, picture);
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
        const [curve, line] = s.joins;
        const [apart] = o.joins;
        report.verdict(`${character}: two bands of one surface are one band — a curve between them not drawn, a line in the middle, the room of two bands with no edge; on another surface the curve keeps its edge and depth`,
          !curve.curve && Math.abs(curve.room - s.gap) <= 2 && line.line > 0 && Math.abs(line.room - line.line - s.gap) <= 2 && apart.curve && apart.room > s.gap + 8,
          JSON.stringify({ gap: Math.round(s.gap), curve: { room: Math.round(curve.room), drawn: curve.curve }, line: { room: Math.round(line.room), width: line.line }, other: { room: Math.round(apart.room), drawn: apart.curve } }));
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
