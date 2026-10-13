/*
 * WHAT A LINK TO A PAGE LOOKS LIKE WHERE IT IS SHARED (PLAN.md D-242).
 *
 * The served home page carries the Open Graph and Twitter tags made from what it already says,
 * and WebSite structured data with the site's name. The builder's Page tab offers the page's own
 * sharing picture as a picker, under the SEO fields; choosing one in the media browser, as the
 * owner does, writes it into the draft's seo_json and shows it on the button, and Discard puts
 * the page back as it was.
 *
 * ON THE COPY. It changes a draft and discards it in the same run.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openBuilder, openTab, settle } from '../harness.mjs';
import { pick } from '../media-helpers.mjs';

export default {
  name: 'sharing',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('sharing: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    const home = await (await fetch(`${BASE}/`)).text();
    const tag = (name) => (home.match(new RegExp(`<meta (?:property|name)="${name}" content="([^"]*)">`)) || [])[1] ?? null;
    report.verdict('the home page tells a link preview its title, address and site', tag('og:title') !== null && tag('og:url') === `${BASE}/` && tag('og:site_name') !== null && tag('og:type') === 'website',
      JSON.stringify({ title: tag('og:title'), url: tag('og:url'), site: tag('og:site_name') }));
    report.verdict('and a card for Twitter-style previews', ['summary', 'summary_large_image'].includes(tag('twitter:card')), tag('twitter:card') ?? 'none');
    report.verdict('and names the site for search engines', /"@type":"WebSite","name":"[^"]+","url":"[^"]+"/.test(home), (home.match(/"@type":"WebSite"[^<]*/) || ['none'])[0]);

    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
    const id = await page.evaluate(() => (document.querySelector('.page-tree a[href*="/admin/pages/"]')?.getAttribute('href') || '').match(/pages\/(\d+)/)?.[1] ?? null);
    if (id === null) {
      report.fail('sharing: a page to open', 'the page list has no page; the copy should have the demo');
      return;
    }
    await openBuilder(page, BASE, id);
    await openTab(page, 'page');
    const field = await page.evaluate(() => {
      const select = document.querySelector('#pb-seo-image');
      const options = select ? [...select.options].map((o) => o.value).filter(Boolean) : [];
      return { upgraded: !!select?.closest('.field')?.querySelector('.media-picker-current'), first: options[0] ?? null, chosen: select?.value ?? null };
    });
    report.verdict('the Page tab offers a sharing picture, as a picker, with none chosen', field.upgraded && field.chosen === '' && field.first !== null, JSON.stringify(field));
    await page.evaluate(() => document.querySelector('#pb-seo-image').closest('.field').scrollIntoView({ block: 'center' }));
    await report.shot(page, '01-page-tab', { fullPage: false });
    if (field.first === null) {
      return;
    }

    // Chosen as the owner chooses: the button, the browser, the first picture in it.
    const picked = await pick(page, '#pb-seo-image', 0);
    await settle(page);
    const stored = await page.evaluate(() => JSON.parse(window.pb.doc.seo_json || '{}').image ?? null);
    report.verdict('choosing writes the picture into the draft', stored !== null && String(stored) === picked.chosen, `chose ${picked.chosen}, seo_json.image = ${stored}`);
    const button = await page.$eval('#pb-seo-image', (s) => ({ img: !!s.closest('.field').querySelector('.media-picker-current img'), text: s.closest('.field').querySelector('.media-picker-current').textContent.trim() }));
    report.verdict('and the button shows it, picture and all', button.img && !button.text.startsWith('No picture'), JSON.stringify(button));
    await report.shot(page, '02-chosen', { fullPage: false });

    // Put back: Discard, through the builder.
    await page.click('[data-pb-discard]');
    await page.waitForFunction(() => document.querySelector('[data-pb-state]').className.includes('status-published'), { timeout: 15000 }).catch(() => {});
    const after = await page.evaluate(() => ({ state: document.querySelector('[data-pb-state]').className, image: JSON.parse(window.pb.doc.seo_json || '{}').image ?? null }));
    report.verdict('Discard puts the page back as it was', after.state.includes('status-published') && after.image === null, JSON.stringify(after));
  },
};
