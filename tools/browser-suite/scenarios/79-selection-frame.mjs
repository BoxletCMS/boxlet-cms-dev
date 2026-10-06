/*
 * A SELECTED BLOCK'S FRAME (PLAN.md D-199, the owner): the accent, two pixels, past everything
 * the block draws by its reach (D-190), with one pixel of white outside it (D-176), drawn in the
 * editor's layer. The room between the block and the frame is the page's own colour — on a
 * dark page it was filled white, a thick white frame — and the controls in the selected block
 * keep their dark ground: "+ Card" lost it to the reach, which shared the dark colour's name.
 *
 * Under Soft (a light page) and Launch (a dark one), the home page's Cards block selected, at
 * 1440. Measured in pixels for the room, in colours for the button. Its control: the old rule
 * put back on the block through the CSSOM is caught on the dark page, and the reach under the
 * old name takes the button's ground.
 *
 * Nothing is saved; the copy's character is put back at the end.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openBuilder, applyCharacter, blockKey, clickAndWait } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const luminance = ([r, g, b]) => {
  const f = (v) => { const c = v / 255; return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
  return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
};
const ratio = (a, b) => { const x = luminance(a); const y = luminance(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
const rgb = (s) => (s.match(/\d+(\.\d+)?/g) || []).slice(0, 3).map(Number);

/** The frame, the block, the room between them in pixels, and the "+ Card" button. */
async function measured(page, key) {
  const m = await page.evaluate((k) => {
    const iframe = document.querySelector('[data-pb-canvas]');
    const doc = iframe.contentDocument;
    const win = doc.defaultView;
    const block = doc.querySelector(`[data-bx-key="${k}"]`);
    const frame = doc.querySelector('.bx-layer > .bx-frame');
    const add = block.querySelector('.bx-add-item');
    const band = block.closest('.block') || block;
    const bs = win.getComputedStyle(block);
    const b = block.getBoundingClientRect();
    const f = frame ? frame.getBoundingClientRect() : null;
    const fs = frame ? win.getComputedStyle(frame) : null;
    const ir = iframe.getBoundingClientRect();
    const scale = window.pb.canvas.scale || 1;
    // The middle of the room on the block's left, between its edge and the frame's inner edge,
    // a third of the way down, in the window's pixels.
    const room = f ? { x: ir.left + ((b.left + f.left + 2) / 2) * scale, y: ir.top + (b.top + b.height / 3) * scale, width: (b.left - f.left - 2) * scale } : null;
    const a = add ? win.getComputedStyle(add) : null;
    return {
      blockOutline: bs.outlineStyle, blockShadow: bs.boxShadow,
      frame: fs ? { border: `${fs.borderTopWidth} ${fs.borderTopColor}`, shadow: fs.boxShadow } : null,
      room, page: win.getComputedStyle(band).backgroundColor,
      add: a ? { bg: a.backgroundColor, color: a.color } : null,
    };
  }, key);
  if (m.room && m.room.width >= 2) {
    const shot = await page.screenshot({ clip: { x: Math.round(m.room.x), y: Math.round(m.room.y), width: 1, height: 1 }, encoding: 'base64' });
    m.roomColour = await page.evaluate(async (data) => {
      const img = new Image();
      img.src = `data:image/png;base64,${data}`;
      await img.decode();
      const c = document.createElement('canvas');
      c.width = 1;
      c.height = 1;
      const x = c.getContext('2d');
      x.drawImage(img, 0, 0);
      return [...x.getImageData(0, 0, 1, 1).data.slice(0, 3)];
    }, shot);
  }
  return m;
}

/** What is wrong with the frame, the room and the button, if anything. */
function wrong(m) {
  const bad = [];
  if (m.blockOutline !== 'none' || m.blockShadow !== 'none') { bad.push(`the block draws ${m.blockOutline} ${m.blockShadow}`); }
  if (!m.frame || !/^2px rgb\(109, 93, 211\)/.test(m.frame.border) || !/rgb\(255, 255, 255\) 0px 0px 0px 1px/.test(m.frame.shadow)) { bad.push(`frame ${JSON.stringify(m.frame)}`); }
  const page = rgb(m.page);
  if (m.roomColour && page.length === 3 && m.roomColour.some((v, i) => Math.abs(v - page[i]) > 6)) { bad.push(`the room is ${m.roomColour.join(',')} on a page of ${page.join(',')}`); }
  if (!m.add) { bad.push('no "+ Card"'); } else if (rgb(m.add.bg).length < 3 || / 0\)$/.test(m.add.bg) || ratio(rgb(m.add.color), rgb(m.add.bg)) < 4.5) { bad.push(`"+ Card" ${m.add.color} on ${m.add.bg}`); }
  return bad;
}

/** The site's mode, published: a character's load leaves it as the site has it. */
async function mode(page, value) {
  await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
  await page.evaluate((m) => { [...document.querySelectorAll(`input[name="mode"][value="${m}"]`)].find((x) => x.form && x.form.id === 'design-form').click(); }, value);
  await wait(800);
  await clickAndWait(page, 'button[form="design-form"][name="action"][value="save"]');
  if (await page.$('.publish-confirm button[value="save_design"]')) { await clickAndWait(page, '.publish-confirm button[value="save_design"]'); }
}

export default {
  name: 'selection-frame',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('selection frame: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    const was = await page.$eval('.character-tile.is-current .tile-use', (b) => b.value.replace('preset:', '')).catch(() => '');
    try {
      for (const [character, ground] of [['soft', 'light'], ['launch', 'dark']]) {
        await applyCharacter(page, BASE, character, 'save');
        await mode(page, ground);
        await openBuilder(page, BASE, 1);
        const key = await blockKey(page, 'cards');
        await page.evaluate((k) => window.pb.select('block', k), key);
        await wait(900);
        await page.evaluate((k) => document.querySelector('[data-pb-canvas]').contentDocument.querySelector(`[data-bx-key="${k}"]`).scrollIntoView({ block: 'center' }), key);
        await wait(500);
        const m = await measured(page, key);
        await report.shot(page, `${character}-cards-selected`, { fullPage: false });
        const bad = wrong(m);
        report.verdict(`${character} (${ground}): the selected block's frame is the accent with a white hairline, the room inside it the page's own, and "+ Card" keeps its dark ground`,
          bad.length === 0 && !!m.roomColour, bad.join(' | ') || JSON.stringify({ room: m.roomColour, page: m.page, add: m.add }));
        if (ground === 'dark') {
          // THE CONTROL, through the CSSOM: the block's old outline and shadow, and the reach
          // under the dark colour's name.
          await page.evaluate((k) => {
            const block = document.querySelector('[data-pb-canvas]').contentDocument.querySelector(`[data-bx-key="${k}"]`);
            const reach = parseFloat(block.getAttribute('data-bx-reach')) || 0;
            block.style.setProperty('--bx-ink', `${reach}px`);
            block.style.setProperty('outline', '2px solid #6d5dd3');
            block.style.setProperty('outline-offset', `${reach}px`);
            block.style.setProperty('box-shadow', `0 0 0 ${reach + 3}px #ffffff`);
          }, key);
          await wait(200);
          const old = wrong(await measured(page, key));
          report.verdict('the control: the old outline, its white shadow and the reach under --bx-ink are caught on the dark page',
            old.some((w) => /the room is 2[45]\d,2[45]\d,2[45]\d/.test(w)) && old.some((w) => /"\+ Card"/.test(w)), old.join(' | '));
          await openBuilder(page, BASE, 1);
        }
      }
    } finally {
      await mode(page, 'light');
      if (was !== '') { await applyCharacter(page, BASE, was, 'save'); }
      report.verdict('selection frame: the copy\'s character back', was !== '', was || 'could not read it');
    }
  },
};
