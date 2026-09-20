/*
 * 4d: PICTURES ON THE PAGE A VISITOR GETS, under all five characters.
 *
 * What the PHP tests cannot answer. tests/media_picture_test.php proves the MARKUP — the
 * format order, the recorded dimensions, the focal class, the alt, the lazy flag. It
 * cannot know whether those URLs are files the web server hands over, whether the browser
 * decodes them, or whether a photograph behind a section leaves the words on top legible
 * in a character built on pale cream.
 *
 * naturalWidth > 0 is the check, never "the <img> is in the DOM". A broken image is still
 * an element with a src, so presence is precisely the assertion that passes while the
 * screen shows a broken-image icon (the same reasoning as 10-media.mjs).
 *
 * Every response is recorded as well, because a missing variant is INVISIBLE in a
 * screenshot: the layout simply closes up and looks deliberate. A 404 on /m/ is the one
 * failure this whole slice was built to make impossible, so it is asserted, not eyeballed.
 *
 * It borrows the demo's first page, puts pictures in it, and puts it back as it found it.
 */
import { existsSync } from 'node:fs';
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, applyCharacter, fixtures } from '../harness.mjs';
import {
  PHOTOS, CONTENT_FIELD, SURFACE_FIELD, uploadPhoto, pick, save, firstPageId, photographs,
} from '../media-helpers.mjs';

/** Forces lazy images to decode: loading="lazy" means nothing arrives until it is near. */
const settle = (page) => page.evaluate(async () => {
  window.scrollTo(0, document.body.scrollHeight);
  await new Promise((resolve) => setTimeout(resolve, 400));
  window.scrollTo(0, 0);
  await new Promise((resolve) => setTimeout(resolve, 400));
});

const onPage = (page) => page.evaluate(() => {
  const images = Array.from(document.querySelectorAll('section img')).map((img) => ({
    src: img.getAttribute('src'),
    decoded: img.naturalWidth,
    width: img.getAttribute('width'),
    height: img.getAttribute('height'),
    loading: img.getAttribute('loading'),
    inPicture: img.parentElement !== null && img.parentElement.tagName === 'PICTURE',
    backdrop: img.closest('.section-picture') !== null,
    focal: /focal-x-\d+/.test(img.className),
  }));

  // Per section, because the lazy rule is about sections: the first drawn one is eager,
  // and a hero can hold two pictures (its own and its D-024 backdrop) that are both eager.
  const sections = Array.from(document.querySelectorAll('section')).map((section, i) => ({
    i,
    loadings: Array.from(section.querySelectorAll('img')).map((img) => img.getAttribute('loading')),
  }));

  // Is the heading actually ON TOP of the backdrop, or behind it? Hit-testing, because
  // every cheaper check passes while the words are invisible: the heading is laid out, is
  // visible, has opacity 1 and a colour — and the picture is painted over it. Counting
  // decoded images said this page was perfect while its hero showed no text at all.
  const overBackdrop = Array.from(document.querySelectorAll('section')).map((section, i) => {
    const heading = section.querySelector('.hero-heading, .image-text-heading');
    const backdrop = section.querySelector('.section-picture');
    if (heading === null || backdrop === null) return null;
    const r = heading.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) return { i, covered: true, by: 'the heading has no box' };
    const at = document.elementFromPoint(Math.round(r.x + r.width / 2), Math.round(r.y + r.height / 2));
    return {
      i,
      covered: at === null || !heading.contains(at) && at !== heading,
      by: at === null ? 'nothing' : `${at.tagName.toLowerCase()}.${(at.className || '').toString().split(' ')[0]}`,
    };
  }).filter((entry) => entry !== null);

  return {
    images,
    sections,
    overBackdrop,
    pictures: document.querySelectorAll('section picture').length,
    backdrops: document.querySelectorAll('.section-picture').length,
    sources: Array.from(document.querySelectorAll('section picture source')).map((s) => s.getAttribute('type')),
    placeholders: document.querySelectorAll('.media-placeholder').length,
  };
});

export default {
  name: 'front-pictures',
  // Runs against the throwaway copy: it applies characters, which rewrites the whole design.
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('front-pictures: log in', `could not log in; at ${page.url()}`);
      return;
    }

    // Read from the directory, not named here — see media-helpers.mjs.
    const photos = photographs(2);
    // A FAILURE, not NOT CHECKABLE: missing data means this scenario measured nothing.
    if (fixtures(report, 'front-pictures', photos.length >= 2 ? photos : [], `two photographs in ${PHOTOS}`) === null) {
      return;
    }
    for (const photo of photos) {
      await uploadPhoto(page, photo);
    }

    const pageId = await firstPageId(page);
    if (!pageId) {
      report.fail('front-pictures: a page to put pictures in', 'no page link in the tree');
      return;
    }
    const formUrl = `${BASE}/admin/pages/${pageId}/form`;

    // ---- a content picture and a background picture, in DIFFERENT sections ---------------
    //
    // Different sections on purpose. pick()'s second argument chooses which PICTURE to take,
    // not which field, and every selector here resolves to the first match — so picking
    // both through CONTENT_FIELD and SURFACE_FIELD puts them both in block 0. Then both are
    // in the first drawn section, both are correctly eager, and the lazy rule below is never
    // exercised at all: the check passed the product and failed itself.
    //
    // So the backdrop goes behind the first section and the content picture into a later
    // image_text, which is also the block whose card/wide mapping (SPEC §5.5) is worth
    // seeing on a real page.
    await page.goto(formUrl, { waitUntil: 'networkidle2' });

    // Any block but the first. The index comes from the field NAME — blocks[3][image] — and
    // not from a data-block-group attribute: those belong to the visual builder at
    // /admin/pages/{id}, and on this plain form there are none at all, so every lookup
    // through them returns null and quietly falls back to block 0. Which is the bug this is
    // here to avoid: with both pictures in section 0 the lazy rule below is never tested.
    const target = await page.$$eval(CONTENT_FIELD, (els) => {
      const indexed = els
        .map((el) => ({ name: el.name, index: Number((el.name.match(/^blocks\[(\d+)\]/) || [])[1]) }))
        .filter((f) => Number.isInteger(f.index) && f.index > 0)
        .sort((a, b) => a.index - b.index);
      return indexed[0] || null;
    });

    if (target === null) {
      report.fail('front-pictures: a later section to put a picture in',
        'every content media field on this page belongs to the first block');
      return;
    }
    const content = await pick(page, `select[name="${target.name}"]`, 0);

    let backdrop = null;
    let surfaceSelect = null;
    let originalSurface = null;
    const surfaceField = await page.$$eval(SURFACE_FIELD, (els) => (els[0] ? els[0].name : null));

    if (surfaceField !== null) {
      // The section style panel is a <details>; it must be open before a click reaches in.
      await page.$$eval('details.block-style', (els) => els.forEach((el) => { el.open = true; }));

      // AND the surface has to BE image. D-024: the sixth key "is used only when surface is
      // image", so choosing a picture while the section is still a gradient stores the id
      // and draws nothing — correct, and it would leave every check below judging a page
      // with no backdrop on it while reporting that the backdrop is fine.
      surfaceSelect = surfaceField.replace('[image]', '[surface]');
      originalSurface = await page.$eval(`select[name="${surfaceSelect}"]`, (el) => el.value).catch(() => null);
      await page.select(`select[name="${surfaceSelect}"]`, 'image');

      backdrop = await pick(page, `select[name="${surfaceField}"]`, 1);
    }
    await save(page);

    await page.goto(formUrl, { waitUntil: 'networkidle2' });
    const slug = await page.$eval('input[name="slug"]', (el) => el.value).catch(() => '');

    // 404s are collected per character: a variant that is missing under one design and
    // present under another would otherwise average itself away.
    const misses = [];
    page.on('response', (response) => {
      if (response.status() >= 400) {
        misses.push(`${response.status()} ${response.url().replace(BASE, '')}`);
      }
    });

    const characters = await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' })
      .then(() => page.$$eval('button[name="action"][value^="preset:"]',
        (els) => els.map((e) => e.value.slice('preset:'.length))));

    let structure = null;

    for (const character of characters) {
      const refused = await applyCharacter(page, BASE, character);
      if (refused.length > 0) {
        report.fail(`pictures under the ${character} character`, `the design was refused: ${refused.join(' | ')}`);
        continue;
      }

      misses.length = 0;
      const response = await page.goto(`${BASE}/${slug}`, { waitUntil: 'networkidle2' });
      await settle(page);
      const seen = await onPage(page);
      // Not fullPage: a stitched capture misreports what was on screen, and what the owner
      // is judging here is the words over the photograph at the top of the page.
      await report.shot(page, `${character}`, { fullPage: false });

      // The words first. A backdrop that covers its own heading is the failure this whole
      // decision is about, and it is invisible to every other check on this page.
      const buried = seen.overBackdrop.filter((entry) => entry.covered);
      report.verdict(`the words stay above the picture under the ${character} character`,
        seen.overBackdrop.length > 0 && buried.length === 0,
        seen.overBackdrop.length === 0
          ? 'no section had both a heading and a backdrop to judge'
          : `${seen.overBackdrop.length} judged, topmost at each heading: `
            + JSON.stringify(seen.overBackdrop.map((e) => [e.i, e.by])));

      const broken = seen.images.filter((img) => img.decoded === 0);
      report.verdict(`every picture decodes under the ${character} character`,
        response.status() === 200 && seen.images.length > 0 && broken.length === 0 && misses.length === 0,
        `${seen.images.length} image(s), ${seen.pictures} <picture>, ${seen.backdrops} backdrop; `
        + `${broken.length} undecoded${broken.length ? ' ' + JSON.stringify(broken.slice(0, 3)) : ''}; `
        + `${misses.length} request(s) 400+${misses.length ? ' ' + JSON.stringify(misses.slice(0, 5)) : ''}`);

      structure = structure || seen;
    }

    // ---- the contract, measured once on a real page -------------------------------------
    if (structure === null) {
      report.fail('the front end emits <picture> with real dimensions', 'no character rendered the page');
    } else {
      report.verdict('a chosen picture reaches the visitor as a <picture>, not a placeholder',
        structure.pictures > 0 && structure.images.every((img) => img.inPicture)
          && structure.images.some((img) => img.src.includes(`-`) && img.src.startsWith('/m/')),
        `${structure.pictures} <picture>, ${structure.placeholders} placeholder(s) left, `
        + `srcs ${JSON.stringify(structure.images.map((i) => i.src).slice(0, 3))}`);

      report.verdict('AVIF is offered before WebP, and the original format is the <img>',
        structure.sources.length > 0 && structure.sources[0] === 'image/avif'
          && !structure.sources.includes('image/jpeg'),
        `sources in order: ${JSON.stringify(structure.sources)}`);

      const sized = structure.images.filter((img) => img.width && img.height);
      report.verdict('every picture carries the width and height that stop the page reflowing',
        sized.length === structure.images.length,
        `${sized.length} of ${structure.images.length} sized: `
        + JSON.stringify(structure.images.map((i) => `${i.width}x${i.height}`)));

      // The rule is about SECTIONS, not about a count: every picture in the first drawn
      // section is eager (a hero can hold two — its own and its backdrop), and everything
      // in a later section is lazy.
      const bySection = structure.sections.filter((s) => s.loadings.length > 0);
      const firstDrawn = bySection[0];
      const later = bySection.slice(1);
      report.verdict('the first section is eager and every later one is lazy',
        firstDrawn !== undefined && later.length > 0
          && firstDrawn.loadings.every((l) => l !== 'lazy')
          && later.every((s) => s.loadings.every((l) => l === 'lazy')),
        `section ${firstDrawn ? firstDrawn.i : '?'} (first drawn) ${JSON.stringify(firstDrawn ? firstDrawn.loadings : [])}, `
        + `later ${JSON.stringify(later.map((s) => [s.i, s.loadings]))}`);

      if (backdrop === null) {
        report.skip('a section background picture renders behind the words (D-024)', 'no section style image field');
      } else {
        const behind = structure.images.filter((img) => img.backdrop);
        report.verdict('a section background picture renders behind the words (D-024)',
          structure.backdrops > 0 && behind.length > 0 && behind.every((img) => img.decoded > 0),
          `${structure.backdrops} backdrop layer(s), decoded ${JSON.stringify(behind.map((i) => i.decoded))}`);
      }
    }

    // ---- leave the demo as the seed ships it ---------------------------------------------
    await page.goto(formUrl, { waitUntil: 'networkidle2' });
    await page.$$eval('select[data-media-field]', (els) => {
      for (const el of els) {
        el.value = '';
        el.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });
    // The surface too: this scenario turned a section into surface: image, and leaving it
    // that way would hand every later check — and the owner's own look at the demo — a
    // section the seed never had.
    if (surfaceSelect !== null && originalSurface !== null) {
      await page.$$eval('details.block-style', (els) => els.forEach((el) => { el.open = true; }));
      await page.select(`select[name="${surfaceSelect}"]`, originalSurface);
    }
    await save(page);

    await page.goto(formUrl, { waitUntil: 'networkidle2' });
    const leftBehind = await page.$$eval('select[data-media-field]',
      (els) => els.map((el) => el.value).filter((value) => value !== ''));
    report.verdict('the demo page is left as the seed ships it',
      leftBehind.length === 0,
      leftBehind.length === 0 ? `neither picture is stored (was ${content.chosen}`
        + `${backdrop ? ' and ' + backdrop.chosen : ''})` : `still stored: ${JSON.stringify(leftBehind)}`);

    // And on the character the scenarios before this one expect.
    if (characters.length > 0) {
      await applyCharacter(page, BASE, characters[0]);
    }
  },
};
