/*
 * 5c: the site's own header and footer (PLAN.md D-028, D-030).
 *
 * What only a browser can answer. The PHP tests prove the registry, the resolution and the
 * settings key; they cannot see that the header is ONE bar rather than a bulleted list
 * falling down a third of the screen, which is exactly how it first rendered — the
 * templates carried class names and the stylesheet had no rules for any of them.
 *
 * THE COUNTING MATTERS MOST. The site has exactly one footer and the switcher lives in it;
 * with the layout still drawing its own, a translated page would have carried two. That is
 * asserted here by counting elements, because I mistook a downscaled screenshot for a
 * second switcher and only the DOM settled it.
 *
 * IT PUTS THE CHROME BACK. These settings are site-wide, so every later scenario and every
 * screenshot would otherwise carry this one's words.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, clickAndWait, controlsOnPanels, retype, openTab } from '../harness.mjs';

const MARKER = 'Zz chrome';

/** What the chrome screen currently holds, so the scenario can put it back. */
const readChrome = (page) => page.evaluate(() => {
  const value = (name) => (document.querySelector(`[name="${name}"]`) || {}).value ?? '';
  return {
    menu: value('header_menu'),
    page: value('header_button_page_en'),
    label: value('header_button_label_en'),
    url: value('header_button_url_en'),
    text: value('footer_text_en'),
    small: value('footer_small_print_en'),
  };
});

export default {
  name: 'chrome',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('chrome: log in', `could not log in; at ${page.url()}`);
      return;
    }

    // ---- reachable from the navigation -------------------------------------------------
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle2' });
    const link = await page.$('.rail-nav a[href$="/admin/appearance"]');
    report.verdict('the navigation offers the header and footer screen', link !== null,
      link === null ? 'no link to /admin/appearance in the admin bar' : 'the admin bar links to it');
    if (link === null) { return; }

    // One entry in the rail's Presentation group since the screens merged (D-059); it was
    // two, and the header and footer are now the fifth tab of this one.
    await clickAndWait(page, '.rail-nav a[href$="/admin/appearance"]');

    // ---- the tab that used to be a screen of its own (D-059) ---------------------------
    if (!await openTab(page, 'chrome')) {
      report.fail('chrome: the header and footer tab', 'the Appearance screen has no tabs');
      return;
    }
    const shape = await page.evaluate(() => ({
      panels: document.querySelectorAll('[data-panel="chrome"] fieldset').length,
      headings: Array.from(document.querySelectorAll('[data-panel="chrome"] legend')).map((h) => h.textContent.trim()),
      logoNote: !!document.querySelector('[data-panel="chrome"] a[href$="/admin/settings"]'),
      menuSelect: !!document.querySelector('[name="header_menu"]'),
      bareKeys: (document.body.textContent.match(/chrome\.[a-z_]+/g) || []).slice(0, 3),
    }));

    // One group for the choices, plus one per enabled locale for the words.
    report.verdict('the tab groups the shared choices and then the words per language',
      shape.panels >= 2 && shape.headings.length >= 2,
      `${shape.panels} panels: ${JSON.stringify(shape.headings)}`);
    // The logo moved to Settings → Branding (D-038); this screen says where it went.
    report.verdict('the menu control is there, and the screen points to where the logo is set',
      shape.logoNote && shape.menuSelect,
      `link to Branding=${shape.logoNote}, menu select=${shape.menuSelect}`);

    // A key that does not exist renders as the key itself. That has happened twice: once on
    // the settings screen, once in an aria-label on the front end.
    report.verdict('no untranslated key is showing', shape.bareKeys.length === 0,
      shape.bareKeys.length === 0 ? 'every string came from a language file' : JSON.stringify(shape.bareKeys));

    await controlsOnPanels(page, report, 'chrome tab');
    await report.shot(page, '01-chrome-screen');

    const before = await readChrome(page);

    try {
      // ---- saving, and reading back ------------------------------------------------------
      await retype(page, '[name="footer_text_en"]', `${MARKER} footer`);
      await retype(page, '[name="header_button_label_en"]', `${MARKER} button`);
      // An address of its own, so the page chooser first goes back to "another address".
      await page.select('[name="header_button_page_en"]', '');
      await retype(page, '[name="header_button_url_en"]', '/contact');
      await clickAndWait(page, 'button[form="design-form"][name="action"][value="save"]', 40000);

      await openTab(page, 'chrome');
      const after = await readChrome(page);
      report.verdict('what was typed is saved and comes back',
        after.text === `${MARKER} footer` && after.label === `${MARKER} button`,
        `footer text read back as ${JSON.stringify(after.text)}`);

      // ---- what the visitor gets -----------------------------------------------------------
      await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
      const site = await page.evaluate(() => {
        const inFooter = (el) => !!el.closest('footer.block-footer');
        const switchers = Array.from(document.querySelectorAll('.locale-switcher'));
        const header = document.querySelector('header.block-header');
        return {
          headers: document.querySelectorAll('header.block-header').length,
          footers: document.querySelectorAll('footer').length,
          switchers: switchers.length,
          switcherInFooter: switchers.every(inFooter),
          headerHeight: header ? Math.round(header.getBoundingClientRect().height) : 0,
          navLinks: document.querySelectorAll('.site-nav a').length,
          bulleted: header ? getComputedStyle(header.querySelector('.site-nav ul') || document.body).listStyleType : 'none',
          footerText: (document.querySelector('.site-footer-text') || {}).textContent?.trim() ?? '',
        };
      });

      report.verdict('the site has exactly one header and one footer',
        site.headers === 1 && site.footers === 1,
        `${site.headers} header(s), ${site.footers} footer(s)`);

      // The rule the whole arrangement exists for: one switcher, and it is the footer's.
      report.verdict('one language switcher, in the footer',
        site.switchers === 1 && site.switcherInFooter,
        `${site.switchers} switcher(s), all in the footer: ${site.switcherInFooter}`);

      report.verdict('the owner\'s footer words reach the visitor',
        site.footerText.includes(`${MARKER} footer`),
        `the footer says ${JSON.stringify(site.footerText.slice(0, 60))}`);

      // It first rendered as a list of bullets down a third of the screen, because no rule
      // described .site-nav at all. Geometry and list-style, not a screenshot.
      report.verdict('the header is a bar, not a bulleted list',
        site.bulleted === 'none' && site.headerHeight > 0 && site.headerHeight < 400,
        `list-style ${site.bulleted}, header ${site.headerHeight}px tall, ${site.navLinks} link(s)`);

      await report.shot(page, '02-site-with-chrome');
    } finally {
      // Put every word back, whatever happened above.
      await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
      await openTab(page, 'chrome');
      await retype(page, '[name="footer_text_en"]', before.text);
      // A page the button pointed at is put back as that page, not as its address. The
      // label goes last: choosing a page may offer its title in place of the text.
      await page.select('[name="header_button_page_en"]', before.page);
      if (before.page === '') {
        await retype(page, '[name="header_button_url_en"]', before.url);
      }
      await retype(page, '[name="header_button_label_en"]', before.label);
      await clickAndWait(page, 'button[form="design-form"][name="action"][value="save"]', 40000);

      await openTab(page, 'chrome');
      const restored = await readChrome(page);
      report.verdict('the scenario puts the chrome back',
        restored.text === before.text && restored.label === before.label,
        `footer text is ${JSON.stringify(restored.text)}, it was ${JSON.stringify(before.text)}`);
    }
  },
};
