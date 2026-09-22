/*
 * The block library in the page editor's panel (PLAN.md D-083).
 *
 * Every verdict here is a measurement of the rendered cards, because every defect this
 * area had looked fine in the source and only showed itself on the screen: a card that
 * was a fixed window onto a block of any height, five cards that said the same sentence,
 * a Form card with no form in it, and a fade drawn over cards that had nothing cut off.
 *
 * Nothing here saves anything — the library is read, never pressed — so it runs against
 * the development site like the rest of the editor checks.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

const PAGE = 1;

export default {
  name: 'library',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('library: log in', `could not log in; at ${page.url()}`);

      return;
    }

    await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
    // The cards measure themselves once their previews have loaded, so the check waits for
    // the measurement rather than for a fixed time.
    const measured = await page.waitForFunction(() => {
      const frames = document.querySelectorAll('.library-frame');

      return frames.length > 0 && [...frames].every((f) => f.style.getPropertyValue('--library-block'));
    }, { timeout: 25000 }).then(() => true).catch(() => false);

    if (!measured) {
      report.fail('every library card measures the block it shows',
        'no card had set --library-block after 25s');

      return;
    }

    const cards = await page.$$eval('.library-card', (els) => els.map((card) => {
      const frame = card.querySelector('.library-frame');
      const doc = card.querySelector('iframe').contentDocument;
      const block = doc && doc.querySelector('.block');

      return {
        name: card.querySelector('.library-name').textContent.trim(),
        height: Math.round(frame.getBoundingClientRect().height),
        block: Math.round(block ? block.getBoundingClientRect().height : 0),
        cut: frame.hasAttribute('data-cut'),
        words: doc ? doc.body.textContent.replace(/\s+/g, ' ').trim() : '',
        placeholderGlyph: doc
          ? [...doc.querySelectorAll('.media-placeholder')]
            .every((p) => window.getComputedStyle(p, '::before').maskImage !== 'none')
          : false,
        placeholders: doc ? doc.querySelectorAll('.media-placeholder').length : 0,
        form: doc ? doc.querySelectorAll('.site-form-field').length : 0,
      };
    }));
    report.pass('the library draws its cards', cards.map((c) => `${c.name} ${c.height}px`).join(', '));

    // A fixed 16/9 window made every card the same height whatever it held, which is the
    // one thing a picture of a block can say that its name cannot.
    const heights = new Set(cards.map((c) => c.height));
    report.verdict('a card is as tall as the block it shows, so no two blocks look alike',
      heights.size > 1 && cards.every((c) => c.block > 0),
      cards.map((c) => `${c.name}: card ${c.height}, block ${c.block}`).join(' | '));

    // The card is capped, and the fade means "there is more below" — so it belongs only on
    // the cards where there is. Drawn on all of them it covered most of a short one.
    const fadeWrong = cards.filter((c) => c.cut !== (c.block * 0.4 > c.height + 1));
    report.verdict('the fade is on the cards that are cut off and on no others',
      fadeWrong.length === 0,
      fadeWrong.length === 0
        ? `${cards.filter((c) => c.cut).length} of ${cards.length} cards are cut off`
        : fadeWrong.map((c) => `${c.name} says cut=${c.cut}`).join(' | '));

    // sampleFields() mapped every text field to one generic string, so all five cards read
    // "A heading sits here" and told themselves apart by shape alone.
    const sentences = new Set(cards.map((c) => c.words));
    report.verdict('each card says its own words', sentences.size === cards.length,
      `${sentences.size} different previews among ${cards.length} cards`);

    // The panel's own hint promises "the block as this site renders it". It was not true of
    // the Form card, which drew a heading, a sentence and then nothing at all.
    const form = cards.find((c) => c.form > 0);
    report.verdict('the Form card shows a form', form !== undefined,
      form ? `${form.name} draws ${form.form} fields` : 'no card drew a single form field');

    // A flat rectangle where a picture goes reads as damage rather than as a picture area.
    const withPlaceholder = cards.filter((c) => c.placeholders > 0);
    report.verdict('an empty picture area wears a picture, not a plain fill',
      withPlaceholder.length > 0 && withPlaceholder.every((c) => c.placeholderGlyph),
      withPlaceholder.map((c) => `${c.name}: ${c.placeholders} placeholder(s), glyph ${c.placeholderGlyph}`).join(' | ')
        || 'no card reserved a picture area');
  },
};
