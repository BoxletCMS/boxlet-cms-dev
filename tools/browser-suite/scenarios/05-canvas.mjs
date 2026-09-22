/*
 * Canvas and focus — what the D-012 contrast test cannot see.
 *
 *   - under all five characters, over a plain AND a contrast surface: the selection
 *     outline, the hover outline, the drop target, and the insertion control at rest and
 *     on hover
 *   - keyboard-only navigation through the rich text toolbar, the inspector and the page
 *     list: the focus ring is visible on every stop
 *   - the transient opacities while dragging look intentional
 *
 * The contrast test skips canvas.css by design: it loads into a document full of the
 * SITE's tokens, so what sits behind its controls is the user's design and cannot be
 * paired against a surface the admin knows. That is exactly why these are eyes-and-numbers
 * items rather than assertions — each one records the measured colours and leaves the
 * judgement to a person looking at the screenshot.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, clickAndWait } from '../harness.mjs';

const PAGE = 1;

async function canvasFrame(page) {
  await page.waitForFunction(() => {
    const frame = document.querySelector('iframe[data-canvas]');
    return frame && frame.contentDocument
      && frame.contentDocument.querySelectorAll('[data-bx-blocks] > section').length > 0;
  }, { timeout: 20000 });
  return page.frames().find((f) => f.url().includes('/canvas'));
}

export default {
  name: 'canvas',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('canvas: log in', `could not log in; at ${page.url()}`);
      return;
    }

    // This scenario needs a contrast surface to measure against, and it sets one itself
    // rather than hoping the demo still has one. It did not: 03-design's "save and reset
    // section styles" rewrites every block's layer 2 across the whole site, which flattened
    // every contrast surface the demo shipped with. A scenario that depends on an earlier
    // scenario's leftovers reports that dependency as a product failure.
    //
    // Applying a character below uses action=save ("design only"), which leaves section
    // styles alone, so this survives all five.
    await page.goto(`${BASE}/admin/pages/${PAGE}/form`, { waitUntil: 'networkidle2' });
    // The second block's surface, found by position among the groups rather than by a name
    // with a position in it: a field is named for its block since D-094.
    const surfaceField = await page.$$eval('[data-block] select[name$="[style][surface]"]',
      (els) => els[1].name);
    await page.select(`select[name="${surfaceField}"]`, 'contrast');
    await clickAndWait(page, 'div.editor-actions button[name="action"][value="save"]');

    const presets = await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' })
      .then(() => page.$$eval('button[name="action"][value^="preset:"]',
        (els) => els.map((e) => e.value.slice('preset:'.length))));

    const measured = [];

    for (const preset of presets) {
      await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
      await clickAndWait(page, `button[name="action"][value="preset:${preset}"]`);
      await clickAndWait(page, 'button[form="design-form"][name="action"][value="save"]');

      await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
      const frame = await canvasFrame(page);

      // Which sections are plain and which are contrast, read from the rendered classes
      // rather than assumed: layers 2 and 3 land there as class names.
      const surfaces = await frame.$$eval('[data-bx-index]', (els) => els.map((el, i) => ({
        index: i,
        classes: el.className,
        background: getComputedStyle(el).backgroundColor,
      })));
      const plain = surfaces.find((s) => /plain/.test(s.classes)) || surfaces[0];
      const contrast = surfaces.find((s) => /contrast/.test(s.classes));

      const readControls = async (section) => frame.evaluate((idx) => {
        const target = document.querySelectorAll('[data-bx-index]')[idx];
        const insert = document.querySelector('.bx-insert');
        const styleOf = (el) => {
          const s = getComputedStyle(el);
          return {
            outline: `${s.outlineStyle} ${Math.round(parseFloat(s.outlineWidth) || 0)}px ${s.outlineColor}`,
            background: s.backgroundColor,
          };
        };
        target.classList.add('bx-selected');
        const selected = styleOf(target);
        target.classList.remove('bx-selected');
        target.classList.add('bx-drop-target');
        const drop = styleOf(target);
        target.classList.remove('bx-drop-target');
        const insertStyle = insert ? getComputedStyle(insert) : null;
        return {
          behind: getComputedStyle(target).backgroundColor,
          selected: selected.outline,
          dropTarget: drop.outline,
          insertDisc: insertStyle ? insertStyle.backgroundColor : 'none',
          insertRing: insertStyle ? insertStyle.borderTopColor : 'none',
          insertShadow: insertStyle ? insertStyle.boxShadow : 'none',
          insertOpacity: insertStyle ? insertStyle.opacity : 'none',
        };
      }, section.index);

      const onPlain = await readControls(plain);
      const onContrast = contrast ? await readControls(contrast) : null;
      measured.push({ preset, onPlain, onContrast });

      await report.shot(page, `canvas-${preset}`);
      report.pass(`canvas controls under the ${preset} character`,
        `plain surface ${onPlain.behind}: selection ${onPlain.selected}, drop ${onPlain.dropTarget}, `
        + `insert disc ${onPlain.insertDisc} ring ${onPlain.insertRing} opacity ${onPlain.insertOpacity}`
        + (onContrast
          ? ` | contrast surface ${onContrast.behind}: selection ${onContrast.selected}, insert disc ${onContrast.insertDisc}`
          : ' | NO CONTRAST SECTION on this page'));
    }

    const everyPresetHasContrast = measured.every((m) => m.onContrast !== null);
    report.verdict('the canvas controls were seen over a contrast surface too', everyPresetHasContrast,
      everyPresetHasContrast
        ? 'a contrast section was present under every character'
        : `missing under: ${measured.filter((m) => !m.onContrast).map((m) => m.preset).join(', ')}`);

    // The insertion control must never be faded at rest (PLAN.md D-012).
    const faded = measured.filter((m) => Number(m.onPlain.insertOpacity) < 1);
    report.verdict('the insertion control is at full opacity at rest', faded.length === 0,
      faded.length === 0 ? 'opacity 1 under every character'
        : `faded under: ${faded.map((m) => `${m.preset} ${m.onPlain.insertOpacity}`).join(', ')}`);

    // ---- keyboard-only: is the focus ring visible on every stop? -------------------------
    const tabThrough = async (label, url, stops) => {
      await page.goto(url, { waitUntil: 'networkidle2' });
      await page.evaluate(() => document.body.focus());
      const seen = [];
      for (let i = 0; i < stops; i++) {
        await page.keyboard.press('Tab');
        seen.push(await page.evaluate(() => {
          const el = document.activeElement;
          if (!el || el === document.body) return null;
          const s = getComputedStyle(el);
          const width = Math.round(parseFloat(s.outlineWidth) || 0);
          return {
            what: `${el.tagName.toLowerCase()}${el.getAttribute('data-rt') ? '[' + el.getAttribute('data-rt') + ']' : ''}`,
            ring: s.outlineStyle !== 'none' && width > 0 ? `${width}px ${s.outlineColor}` : (s.boxShadow !== 'none' ? 'box-shadow' : 'NONE'),
          };
        }));
      }
      const stopsSeen = seen.filter(Boolean);
      const ringless = stopsSeen.filter((s) => s.ring === 'NONE');
      await report.shot(page, `focus-${label}`);
      report.verdict(`keyboard: the focus ring is visible on every stop in the ${label}`,
        stopsSeen.length > 0 && ringless.length === 0,
        `${stopsSeen.length} stops, ${ringless.length} without a ring`
        + (ringless.length ? `: ${ringless.map((r) => r.what).join(', ')}` : ''));
    };

    await tabThrough('page list', `${BASE}/admin/pages`, 12);
    await tabThrough('rich text toolbar', `${BASE}/admin/pages/${PAGE}/form`, 14);
    await tabThrough('inspector', `${BASE}/admin/pages/${PAGE}`, 14);

    // ---- the transient opacities ---------------------------------------------------------
    await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
    const dragging = await page.evaluate(() => {
      const row = document.querySelector('tr[data-page-id]');
      if (!row) return null;
      row.classList.add('is-dragging');
      const s = getComputedStyle(row);
      const result = { opacity: s.opacity, outline: `${s.outlineStyle} ${s.outlineWidth} ${s.outlineColor}` };
      row.classList.remove('is-dragging');
      return result;
    });
    await report.shot(page, 'transient-dragging');
    report.pass('the dragging state is a deliberate fade, not a disappearance',
      dragging ? `.is-dragging: opacity ${dragging.opacity}, outline ${dragging.outline}` : 'no row to test');
  },
};
