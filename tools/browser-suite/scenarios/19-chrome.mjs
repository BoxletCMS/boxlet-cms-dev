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
 * The words and menus are on Navigation since D-180; Appearance keeps how they look.
 *
 * IT PUTS THE CHROME BACK. These settings are site-wide, so every later scenario and every
 * screenshot would otherwise carry this one's words.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, clickAndWait, controlsOnPanels, retype, openSection } from '../harness.mjs';

const MARKER = 'Zz chrome';

/** What Navigation currently holds, so the scenario can put it back. */
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

/**
 * Every language's words open: on a site whose first language is not English the English
 * panel is folded, and a field in a folded <details> cannot be typed into.
 */
const openWords = (page) => page.$$eval('details.words', (all) => all.forEach((d) => { d.open = true; }));

export default {
  name: 'chrome',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('chrome: log in', `could not log in; at ${page.url()}`);
      return;
    }

    // ---- where it is: the look on Appearance, the words and menus on Navigation (D-180) ----
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    if (!await openSection(page, 'header')) {
      report.fail('chrome: the header section', 'the Appearance screen has no sections');
      return;
    }
    const look = await page.evaluate(() => ({
      panels: document.querySelectorAll('[data-view="header"] .control-group').length,
      footerPanels: document.querySelectorAll('[data-view="footer"] .control-group').length,
      logoNote: !!document.querySelector('[data-view="header"] a[href$="/admin/settings"]'),
      toNavigation: document.querySelectorAll('.navigation-where a[href$="/admin/navigation"]').length,
      words: !!document.querySelector('[name="header_menu"], [name="footer_text_en"]'),
    }));
    report.verdict('Appearance keeps the header\'s and footer\'s look, and points to Navigation for their words',
      look.panels >= 3 && look.footerPanels >= 2 && look.logoNote && look.toNavigation === 2 && !look.words, JSON.stringify(look));
    await controlsOnPanels(page, report, 'header section');
    await report.shot(page, '01-appearance-header');

    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle2' });
    const link = await page.$('.rail-nav a[href$="/admin/navigation"]');
    report.verdict('the rail offers Navigation', link !== null, link === null ? 'no link to /admin/navigation' : 'the rail links to it');
    if (link === null) { return; }
    await clickAndWait(page, '.rail-nav a[href$="/admin/navigation"]');
    await openWords(page);
    const shape = await page.evaluate(() => ({
      menuSelect: !!document.querySelector('#navigation-form [name="header_menu"]'),
      footerMenus: document.querySelectorAll('#navigation-form [name^="footer_menu_"]').length,
      footerText: !!document.querySelector('#navigation-form [name="footer_text_en"]'),
      menus: !!document.querySelector('.navigation-menus'),
      bareKeys: (document.body.textContent.match(/(chrome|navigation)\.[a-z_]+/g) || []).slice(0, 3),
    }));
    report.verdict('Navigation holds the header\'s menu and button, the footer\'s columns and words, and the menus',
      shape.menuSelect && shape.footerMenus === 3 && shape.footerText && shape.menus, JSON.stringify(shape));
    // A key that does not exist renders as the key itself. That has happened twice: once on
    // the settings screen, once in an aria-label on the front end.
    report.verdict('no untranslated key is showing', shape.bareKeys.length === 0,
      shape.bareKeys.length === 0 ? 'every string came from a language file' : JSON.stringify(shape.bareKeys));
    await controlsOnPanels(page, report, 'navigation');
    await report.shot(page, '01-chrome-screen');

    const before = await readChrome(page);

    try {
      // ---- saving, and reading back ------------------------------------------------------
      // The footer's words on the Footer tab, the button's on the Header tab (D-111); a
      // field on a tab that is not open has no box to type into. The footer's text is rich
      // text since D-113: typed into the editor, with the whole of it selected first, the
      // way a person replaces a line — and with a link in it, which is what rich text is for.
      await openWords(page);
      // The English column's editor: the first in the section is the site's first language. Found
      // by the field's name, which the editor moves to a hidden input inside its container.
      const footerEditor = '#navigation-form [data-richtext]:has([name="footer_text_en"]) .ProseMirror';
      await page.waitForSelector(footerEditor, { timeout: 10000 });
      await page.click(footerEditor);
      await page.keyboard.down('Control');
      await page.keyboard.press('KeyA');
      await page.keyboard.up('Control');
      await page.keyboard.type(`${MARKER} footer, write to `, { delay: 20 });
      await page.keyboard.type('hello@example.com', { delay: 20 });
      // Select the address and make it a link through the panel.
      for (let i = 0; i < 'hello@example.com'.length; i++) {
        await page.keyboard.down('Shift');
        await page.keyboard.press('ArrowLeft');
        await page.keyboard.up('Shift');
      }
      await page.click('#navigation-form [data-richtext]:has([name="footer_text_en"]) [data-rt="link"]');
      await page.type('#navigation-form [data-richtext]:has([name="footer_text_en"]) .rt-link-input', 'hello@example.com', { delay: 10 });
      await page.click('#navigation-form [data-richtext]:has([name="footer_text_en"]) [data-rt-link="apply"]');
      await retype(page, '[name="header_button_label_en"]', `${MARKER} button`);
      // An address of its own, so the page chooser first goes back to "another address".
      await page.select('[name="header_button_page_en"]', '');
      await retype(page, '[name="header_button_url_en"]', '/contact');
      await clickAndWait(page, '#navigation-form button[type="submit"]', 40000);

      await openWords(page);
      const after = await readChrome(page);
      report.verdict('what was typed is saved and comes back',
        after.text.includes(`${MARKER} footer`) && after.text.includes('mailto:hello@example.com') && after.label === `${MARKER} button`,
        `footer text read back as ${JSON.stringify(after.text)}`);

      // ---- what the visitor gets -----------------------------------------------------------
      await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
      // The English page, where the words typed above are: through the language switcher when
      // the site's first language is another (the demo's is Croatian, D-167).
      const english = await page.evaluate(() => (document.documentElement.lang === 'en' ? null
        : (document.querySelector('.locale-switcher a[hreflang="en"]') || {}).href || null));
      if (english !== null) {
        await page.goto(english, { waitUntil: 'networkidle2' });
      }
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

      // The link made in the editor is a link on the site, and an email opens the visitor's
      // mail app (D-039): the sanitiser turned the bare address into mailto:.
      const footerLink = await page.$eval('.site-footer-text a', (a) => a.getAttribute('href')).catch(() => null);
      report.verdict('a link typed into the footer reaches the visitor as a link', footerLink === 'mailto:hello@example.com', `href ${JSON.stringify(footerLink)}`);
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
      await page.goto(`${BASE}/admin/navigation`, { waitUntil: 'networkidle2' });
      await openWords(page);
      // Put the stored HTML back through the plain view of the editor, which is the
      // textarea underneath; its value reaches the field that carries the name.
      await page.click('#navigation-form [data-richtext]:has([name="footer_text_en"]) [data-richtext-toggle]');
      await page.$eval('#navigation-form [data-richtext]:has([name="footer_text_en"]) textarea[data-richtext-source]', (el, value) => {
        el.value = value;
        el.dispatchEvent(new Event('input', { bubbles: true }));
      }, before.text);
      // A page the button pointed at is put back as that page, not as its address. The
      // label goes last: choosing a page may offer its title in place of the text.
      await page.select('[name="header_button_page_en"]', before.page);
      if (before.page === '') {
        await retype(page, '[name="header_button_url_en"]', before.url);
      }
      await retype(page, '[name="header_button_label_en"]', before.label);
      await clickAndWait(page, '#navigation-form button[type="submit"]', 40000);

      await openWords(page);
      const restored = await readChrome(page);
      report.verdict('the scenario puts the chrome back',
        restored.text === before.text && restored.label === before.label,
        `footer text is ${JSON.stringify(restored.text)}, it was ${JSON.stringify(before.text)}`);
    }
  },
};
