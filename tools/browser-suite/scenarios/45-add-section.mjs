/*
 * ADDING A SECTION, which until now was not a thing anybody could do (PLAN.md D-101).
 *
 * The owner asked it plainly — "imamo li sad opciju dodavanja sekcija u koje onda možemo
 * dodavati blokove?" — and the answer was no: the + between bands added a BLOCK, which then
 * quietly brought a band with it. A section existed in the database and nowhere on the
 * screen. This walks the flow the design artifact draws and the owner chose (D-099): add the
 * band, give it a shape, then fill its columns.
 *
 * ON THE COPY, AND IT PUTS THE PAGE BACK. It saves, so it owns what it wrote (D-090): it
 * removes the block it added and the now-empty band goes with it on the next save, which is
 * what Sections::prune() is for. It refuses to press Remove unless what it is about to
 * remove is what it added — a cleanup that presses on a failed lookup acts on whatever is
 * still selected, which cost the demo page a block twice.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

const PAGE = 2;
const SETTLE = 1600;
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const MARKER = 'A band of my own.';

const submit = async (page) => {
  await page.evaluate(() => {
    const form = document.querySelector('form[data-builder]');
    form.requestSubmit(form.querySelector('button[name="action"][value="save"]:not(.visually-hidden)'));
  });
  await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => {});

  return page.evaluate(() => Array.from(document.querySelectorAll('.alert, [role="alert"], .field-error'))
    .map((a) => a.textContent.trim().slice(0, 120)));
};

const ready = async (page) => {
  await page.waitForFunction(() => {
    const frame = document.querySelector('iframe[data-canvas]');
    const bands = frame && frame.contentDocument
      ? frame.contentDocument.querySelectorAll('[data-bx-blocks] > section') : [];

    return bands.length > 0 && [...bands].every((b) => b.hasAttribute('data-bx-index') || b.querySelector('[data-bx-index]'));
  }, { timeout: 20000 }).catch(() => {});
  await wait(SETTLE);
};

export default {
  name: 'add-section',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('add-section: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1700, height: 1100, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
    await ready(page);
    const frame = page.frames().find((f) => f.url().includes('/canvas'));

    // ---- the controls say what they add ----------------------------------------------
    const controls = await frame.evaluate(() => ({
      sections: [...document.querySelectorAll('.bx-insert')].map((b) => b.textContent.replace(/\s+/g, '')),
      blocks: [...document.querySelectorAll('.bx-slot')].map((b) => b.textContent.replace(/\s+/g, '')),
    }));
    report.verdict('the page offers a way to add a section, and a way to add a block in a column',
      controls.sections.length > 0 && controls.sections.every((t) => t.includes('Section'))
        && controls.blocks.length > 0 && controls.blocks.every((t) => t.includes('Block')),
      JSON.stringify(controls));
    await report.shot(page, '01-controls');

    // ---- a band's own four, before anything is added ---------------------------------
    //
    // The same four a block has, acting on the band and everything standing in it. Nothing
    // is saved here: what these move is the editor's own three views of the page, and if
    // they part the save cannot be right whatever it writes.
    const order = () => page.evaluate(() => ({
      canvas: [...document.querySelector('iframe[data-canvas]').contentDocument
        .querySelectorAll('[data-bx-section]')].map((b) => b.getAttribute('data-bx-section')).join(' '),
      groups: [...document.querySelectorAll('[data-section-group]')]
        .map((g) => g.getAttribute('data-section-group')).join(' '),
      blocks: document.querySelectorAll('[data-block-group]').length,
      outline: [...document.querySelectorAll('[data-outline-section]')]
        .map((r) => r.getAttribute('data-outline-section')).join(' '),
    }));
    await page.evaluate(() => document.querySelectorAll('[data-outline-section]')[2].click());
    await wait(SETTLE);
    const tools = await frame.evaluate(() => [...document.querySelectorAll('.bx-tools [data-block-action]')]
      .map((b) => b.getAttribute('data-block-action')));
    report.verdict('a selected band has its own move, copy and remove',
      tools.join(' ') === 'band-up band-down band-duplicate band-remove', JSON.stringify(tools));

    const start = await order();
    await frame.click('[data-block-action="band-up"]');
    await wait(SETTLE);
    const moved = await order();
    await frame.click('[data-block-action="band-duplicate"]');
    await wait(SETTLE * 2);
    const copied = await order();
    await frame.click('[data-block-action="band-remove"]');
    await wait(SETTLE);
    const gone = await order();

    report.verdict('moving a band moves it in all three views at once',
      moved.canvas !== start.canvas && moved.canvas === moved.groups && moved.canvas === moved.outline,
      JSON.stringify(moved));
    report.verdict('copying a band copies what stands in it, beside it',
      copied.blocks === start.blocks + 1 && copied.canvas === copied.groups
        && copied.canvas.split(' ').length === start.canvas.split(' ').length + 1,
      JSON.stringify(copied));
    report.verdict('removing a band takes everything in it and leaves the rest',
      gone.canvas === moved.canvas && gone.groups === moved.groups && gone.blocks === start.blocks,
      JSON.stringify(gone));

    // Put the order back, so what follows starts from the page as it is stored.
    await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
    await ready(page);

    const after = page.frames().find((f) => f.url().includes('/canvas'));
    // COUNTED, NOT WRITTEN DOWN: this scenario said "6" until D-105 put four more blocks on
    // the About page, and went red with nothing wrong. What it is checking is a DIFFERENCE
    // — one band more, then none — so the number it starts from is the page's own.
    const before = await after.evaluate(() => document.querySelectorAll('[data-bx-blocks] > section').length);

    /* ---- add one IN THE MIDDLE, which is where the order can go wrong ----------------
       Page::update() writes the bands in the order they are submitted, and that is the
       order their field groups stand in the form. Adding at the END cannot tell a right
       answer from a wrong one; adding in the middle can, and did: the canvas said middle,
       the outline said middle and the save said last (D-102). */
    await after.click('.bx-insert[data-insert-at="2"]');
    await wait(SETTLE * 2);

    const placed = await order();
    report.verdict('the canvas and the form agree where the new band stands',
      placed.canvas === placed.groups && placed.canvas === placed.outline, JSON.stringify(placed));

    const added = await page.evaluate((was) => {
      const doc = document.querySelector('iframe[data-canvas]').contentDocument;
      const shown = [...document.querySelectorAll('[data-section-group]')].filter((g) => !g.hidden);

      return {
        bands: doc.querySelectorAll('[data-bx-blocks] > section').length - was,
        // The band's own fields are what is on the screen, on the Section tab: a band holds
        // no block yet, so there is nothing else it could be showing.
        panel: shown.length,
        tab: document.querySelector('form[data-builder]').getAttribute('data-panel-tab'),
        outlined: doc.querySelectorAll('.bx-band-selected').length,
        // And it is in the outline, which is the thing that says a page HAS sections.
        marked: (document.querySelector('.outline-row[aria-current="true"]') || {}).getAttribute
          ? document.querySelector('.outline-row[aria-current="true"]').hasAttribute('data-outline-section')
          : false,
      };
    }, before);
    report.verdict('adding a section adds one, selects it, and shows its own fields',
      added.bands === 1 && added.panel === 1 && added.tab === 'section' && added.outlined === 1 && added.marked,
      JSON.stringify(added));

    /* ---- AND IT IS A PLACE, NOT A LINE ----------------------------------------------
       The owner's first words on seeing a band he had just added: "dodavanje sekcije i
       blok prostor prelaze izvan granice sekcije". An empty column had no height at all —
       nought pixels — so everything belonging to it was drawn outside the band's own edge.
       Both halves are measured: the column has room, and what is drawn for it stays inside
       the band. A tolerance of one pixel, because a border is drawn on the boundary. */
    const room = await page.evaluate(() => {
      const doc = document.querySelector('iframe[data-canvas]').contentDocument;
      const band = [...doc.querySelectorAll('[data-bx-section]')]
        .find((b) => /^m[0-9]+$/.test(b.getAttribute('data-bx-section')));
      if (!band) { return null; }
      const column = band.querySelector('.section-column');
      const slot = [...doc.querySelectorAll('.bx-slot')]
        .find((s) => s.getAttribute('data-insert-into') === band.getAttribute('data-bx-section'));
      const b = band.getBoundingClientRect();

      return {
        column: column ? Math.round(column.getBoundingClientRect().height) : null,
        band: Math.round(b.height),
        slotInside: slot === undefined ? null
          : slot.getBoundingClientRect().top >= b.top - 1 && slot.getBoundingClientRect().bottom <= b.bottom + 1,
      };
    });
    report.verdict('an empty column is a place with room in it, and its + Block stays inside the band',
      room !== null && room.column > 0 && room.band > room.column && room.slotInside === true,
      JSON.stringify(room));

    /* ---- THE PANEL SHOWS THE BAND, NOT THE LIBRARY (PLAN.md D-106) -----------------
       The owner added a section and was shown a wall of blocks: the library stayed open and
       the band's own fields were rendered UNDER it, measured at 3,393px down a panel nobody
       scrolls that far. He read it twice over — "umjesto postavki sekcije ... imamo blokove
       desno", and then "sekcija se nakon dodavanja ne može označiti da bi se vidjele njene
       postavke". It could; they were past the horizon. */
    const shows = await page.evaluate(() => {
      const group = [...document.querySelectorAll('[data-section-group]')].find((g) => !g.hidden);
      const lib = document.querySelector('.panel-library');
      const panel = group ? group.closest('form') : null;

      return {
        library: lib ? !lib.hidden : null,
        // Where the band's fields sit down the panel, from the panel's own top.
        fieldsAt: group && panel
          ? Math.round(group.getBoundingClientRect().top - panel.getBoundingClientRect().top) : null,
        columns: group ? group.querySelectorAll('select[name$="[layout]"]').length : 0,
      };
    });
    report.verdict('a selected band shows its own settings, in reach, and not the block library',
      shows.library === false && shows.fieldsAt !== null && shows.fieldsAt < 1200 && shows.columns === 1,
      JSON.stringify(shows));

    // ---- and the page fills the pane it is shown in ---------------------------------
    //
    // Also reported on sight — "preview layout je kratak po visini". The canvas grid left
    // both its rows at `auto`, and a grid's align-content behaves as stretch, so the spare
    // height was SHARED between the page and the one-line breadcrumb under it: measured at
    // 708px of page and 242px of words. The page takes what is left; the trail is a line.
    const fills = await page.evaluate(() => {
      const frame = document.querySelector('iframe[data-canvas]');
      const pane = frame.parentElement;
      const trail = pane.querySelector('.builder-trail');

      return {
        pane: Math.round(pane.getBoundingClientRect().height),
        frame: Math.round(frame.getBoundingClientRect().height),
        trail: trail ? Math.round(trail.getBoundingClientRect().height) : 0,
      };
    });
    report.verdict('the page fills the pane, and the trail under it is one line',
      fills.frame > fills.pane * 0.8 && fills.trail > 0 && fills.trail < 60,
      JSON.stringify(fills));

    // ---- give it two columns, then fill one ------------------------------------------
    await page.evaluate(() => {
      const select = [...document.querySelectorAll('[data-section-group]')].find((g) => !g.hidden)
        .querySelector('select[name$="[layout]"]');
      select.value = 'halves';
      select.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await wait(SETTLE * 2);

    const band = await after.evaluate(() => {
      // The band this check added, by its key: it is the third on the page, and saying
      // "the third" would stop being true the moment anything else moved.
      const mine = [...document.querySelectorAll('[data-bx-section]')]
        .find((b) => /^m[0-9]+$/.test(b.getAttribute('data-bx-section')));
      const slot = [...document.querySelectorAll('.bx-slot')]
        .find((s) => s.getAttribute('data-insert-into') === mine.getAttribute('data-bx-section'));
      if (slot) { slot.click(); }

      return { key: mine.getAttribute('data-bx-section'), columns: mine.querySelectorAll('.section-column').length };
    });
    report.verdict('the new section takes the shape it is given', band.columns === 2, JSON.stringify(band));
    await wait(900);
    // By what the card IS: "text" also names a shelf since D-104, and picking by words took
    // the filter instead of the block (see the note in 44-sections).
    await page.$$eval('.panel-library [data-add-type="text"]', (cards) => {
      if (cards.length === 1) { cards[0].click(); }
    });
    await wait(SETTLE * 2);

    await page.evaluate(() => {
      const group = [...document.querySelectorAll('[data-block-group]')].find((g) => !g.hidden);
      const rich = group && group.querySelector('[contenteditable="true"]');
      if (rich) { rich.focus(); }
    });
    await page.keyboard.type(MARKER);
    await wait(900);
    await report.shot(page, '02-filled');

    /* ---- AND A CLICK ON THE PAGE OPENS THAT BLOCK'S FIELDS (D-106) ------------------
       Checked HERE, with a block added and nothing saved, because that is the only state in
       which it was ever wrong. The canvas draws a new block where it stands on the page; its
       field group is appended at the END of the form. Selection travelled as a POSITION, so
       the two counted different orders and every click opened the next block's fields — the
       owner pressed a Questions block and was given an Image and text one. The key was on
       both sides all along and agreed; only the counting did not. */
    const paired = await page.evaluate(async () => {
      const doc = document.querySelector('iframe[data-canvas]').contentDocument;
      const blocks = [...doc.querySelectorAll('.section-column > *')];
      const wrong = [];
      for (const block of blocks.slice(0, 6)) {
        block.click();
        await new Promise((r) => setTimeout(r, 400));
        const group = [...document.querySelectorAll('[data-block-group]')].find((g) => !g.hidden);
        const key = group ? group.getAttribute('data-block-key') : null;
        if (key !== block.getAttribute('data-bx-key')) {
          wrong.push(`${block.getAttribute('data-bx-key')} opened ${key}`);
        }
      }

      return { checked: blocks.slice(0, 6).length, wrong: wrong };
    });
    report.verdict('a block pressed on the page opens its own fields, not its neighbour\'s',
      paired.checked > 1 && paired.wrong.length === 0, JSON.stringify(paired));

    // Put the selection back on the block this check added, so what follows is unchanged.
    await page.evaluate((words) => {
      const doc = document.querySelector('iframe[data-canvas]').contentDocument;
      const mine = [...doc.querySelectorAll('.section-column > *')].find((b) => b.textContent.includes(words));
      if (mine) { mine.click(); }
    }, MARKER);
    await wait(900);

    const notices = await submit(page);
    report.verdict('a section added in the editor saves', notices.length === 0, JSON.stringify(notices));

    // ---- and the visitor gets it -----------------------------------------------------
    const live = await page.goto(`${BASE}/about`, { waitUntil: 'networkidle2' })
      .then(() => page.evaluate((words) => {
        const bands = [...document.querySelectorAll('main > section')];

        const mine = bands.findIndex((b) => b.textContent.includes(words));

        return {
          bands: bands.length,
          at: mine,
          words: mine >= 0,
          columns: mine >= 0 ? bands[mine].querySelectorAll('.section-column').length : 0,
        };
      }, MARKER));
    report.verdict('the visitor gets the section that was added, where it was added',
      live.words && live.columns === 2 && live.at === 2 && live.bands === before + 1, JSON.stringify(live));

    // ---- and the page is put back ----------------------------------------------------
    await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
    await ready(page);
    const found = await page.evaluate((words) => {
      const doc = document.querySelector('iframe[data-canvas]').contentDocument;
      const mine = [...doc.querySelectorAll('.section-column > *')].find((b) => b.textContent.includes(words));
      if (!mine) { return false; }
      mine.click();

      return true;
    }, MARKER);
    if (!found) {
      report.fail('add-section: the scenario puts the page back',
        'the block it added is not where it put it, so it will not press Remove on anything else');

      return;
    }
    await wait(900);
    await page.frames().find((f) => f.url().includes('/canvas')).click('[data-block-action="remove"]');
    await wait(900);
    const cleaned = await submit(page);

    // The block goes, and the band it stood in goes with it on the save: an empty band has
    // no place on a page, which is what Sections::prune() is for.
    const back = await page.goto(`${BASE}/about`, { waitUntil: 'networkidle2' })
      .then(() => page.evaluate((words) => ({
        bands: document.querySelectorAll('main > section').length,
        words: document.body.textContent.includes(words),
      }), MARKER));
    report.verdict('the scenario puts the page back',
      cleaned.length === 0 && !back.words && back.bands === before,
      `${JSON.stringify(back)} ${JSON.stringify(cleaned)}`);
  },
};
