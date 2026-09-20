/*
 * The browser suite's shared harness (CLAUDE.md rule 12).
 *
 * Nineteen one-off probes each re-implemented the same four things: finding
 * chrome-headless-shell, logging in, screenshotting, and reporting. That duplication is
 * why the same three bugs kept coming back, so each has its fix here, once:
 *
 *   - submitVia() submits the form that OWNS a field. The first form in the admin
 *     document is the header's Log out, so an unscoped button[type=submit] click signs
 *     the session out and every later step then meets the login screen.
 *   - requireServed() refuses to run against stale assets. The site copy is a real copy,
 *     not a symlink, so a screenshot of yesterday's CSS reported as today's is one
 *     forgotten rsync away.
 *   - everything runs from this directory, so `import puppeteer` resolves. A probe run
 *     from the scratchpad fails with ERR_MODULE_NOT_FOUND, which reads like a product
 *     failure for exactly as long as it takes to look.
 *
 * A scenario file exports { name, base, run(ctx) } and records verdicts through ctx.
 * Verdicts are PASS, FAIL (with what was seen) or NOT CHECKABLE (with why).
 */
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';
import { readdirSync, existsSync, mkdirSync, rmSync, copyFileSync, readFileSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { resolve } from 'node:path';
import { SHOTS as SHOTS_DIR, CHROME, MODULES, SITE_DIR, CHECKOUT } from './config.mjs';

/*
 * Puppeteer, found where node_modules actually is rather than where this file sits.
 *
 * A plain `import puppeteer from 'puppeteer'` worked only while the suite lived beside
 * them; moving into the repository broke all nineteen scenarios at once, because Node
 * searches upward from the importing file and the repository root has no node_modules —
 * and by D-013 it never will.
 *
 * createRequire only RESOLVES the path here. Loading through require() was my first
 * attempt and it cannot work: puppeteer is an ES module, so require() of it throws
 * ERR_REQUIRE_ESM. The resolution was right, the loading was wrong.
 */
const puppeteer = (await import(
  pathToFileURL(createRequire(`${MODULES}/`).resolve('puppeteer')).href
)).default;

/** Outside the repository, so it comes from configuration (D-029). */
export const SHOTS = SHOTS_DIR;

/** Typing delay. The driver outruns the editor's re-render; three "bugs" were that. */
export const SLOW = 30;

function findShell() {
  const root = CHROME;
  for (const version of existsSync(root) ? readdirSync(root) : []) {
    for (const dir of readdirSync(`${root}/${version}`)) {
      const candidate = `${root}/${version}/${dir}/chrome-headless-shell`;
      if (existsSync(candidate)) return candidate;
    }
  }
  return null;
}

export async function openBrowser({ width = 1400, height = 1000, scale = 2 } = {}) {
  const browser = await puppeteer.launch({
    executablePath: findShell(),
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
  const page = await browser.newPage();
  await page.setViewport({ width, height, deviceScaleFactor: scale });

  const errors = [];
  const blocked = [];
  const dialogs = [];
  page.on('pageerror', (e) => errors.push(e.message.split('\n')[0].slice(0, 160)));

  // Nothing handled dialogs anywhere in the suite. Headless Chrome suppresses
  // beforeunload, so it has not bitten yet — but a confirm() would hang a scenario with
  // no output at all, which is the worst kind of failure to diagnose. Accept and record.
  page.on('dialog', async (dialog) => {
    dialogs.push(`${dialog.type()}: ${dialog.message().slice(0, 120)}`);
    try {
      await dialog.accept();
    } catch {
      // Already handled or the page is gone; nothing useful to do.
    }
  });
  page.on('console', (m) => {
    const text = m.text();
    if (/Content Security Policy|Refused to/i.test(text)) blocked.push(text.slice(0, 160));
  });

  return { browser, page, errors, blocked, dialogs };
}

/**
 * Submits the form that owns `selector` — never the first form on the page.
 */
export async function submitVia(page, selector, timeout = 25000) {
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2', timeout }),
    page.$eval(selector, (el) => {
      const form = el.closest('form');
      const button = form.querySelector('button[type="submit"], button[name="action"]');
      button.click();
    }),
  ]);
}

/**
 * Clicks an exact control and waits for the navigation.
 *
 * Not submitVia: the page editor's first submit is a visually-hidden save button (so that
 * Enter in a field saves), so "the form's submit button" is the wrong one for Add, Move up
 * and Move down, which are all name="action" with different values.
 */
/**
 * Refuses a selector that does not name exactly one control (PLAN.md D-029).
 *
 * `form button[type="submit"]` matches the admin shell's Log out button first, because the
 * header's form is the first in the document. 08-update pressed it for weeks: nothing was
 * applied, three verdicts failed, and the product was behaving perfectly. The warning was
 * in this file's own header the whole time.
 *
 * So the harness refuses rather than explains. "The first of several" is never what a
 * scenario means — it is what a scenario gets when nobody scoped the selector.
 */
async function only(page, selector) {
  if (/^form\s+button\[type=["']submit["']\]$/.test(selector.trim())) {
    throw new Error(`Refusing "${selector}": in the admin it matches Log out first. `
      + 'Scope it to the form that owns the button, e.g. form[action$="/admin/update"] button[type="submit"].');
  }

  const found = await page.$$(selector);
  if (found.length === 0) {
    throw new Error(`Nothing matches "${selector}".`);
  }
  if (found.length > 1) {
    throw new Error(`"${selector}" matches ${found.length} controls, and the first is not `
      + 'necessarily the one meant. Scope it to the form, row or panel that owns it.');
  }
}

export async function clickAndWait(page, selector, timeout = 25000) {
  await only(page, selector);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2', timeout }),
    page.click(selector),
  ]);
}

export async function login(page, base, email, password) {
  await page.goto(`${base}/admin/login`, { waitUntil: 'networkidle2' });
  await page.type('input[name="email"]', email, { delay: 10 });
  await page.type('input[name="password"]', password, { delay: 10 });
  await submitVia(page, 'input[name="password"]');
  return !page.url().includes('/login');
}

/**
 * Aborts when the site copy is running OLDER CODE than the checkout (PLAN.md D-029).
 *
 * requireServed() below refuses stale stylesheets. Nothing refused stale PHP, and that is
 * a whole class of wasted measurement: 5c's logo was fixed in the checkout, the copy still
 * held the controller from before the fix, and five screenshots under five characters all
 * said "no logo" about code that did not exist there. A green row about the wrong tree.
 *
 * The copy records the revision it was synced from, in storage/checkout.rev. A file rather
 * than an admin endpoint on purpose: an endpoint would mean adding product code to serve a
 * development need, which is the worse trade.
 *
 * WHAT IT DOES NOT CATCH, said plainly: a copy edited by hand after the sync still claims
 * that revision. It catches "older than the checkout", which is what actually happens.
 */
export function requireCurrentCode() {
  const recorded = existsSync(`${SITE_DIR}/storage/checkout.rev`)
    ? readFileSync(`${SITE_DIR}/storage/checkout.rev`, 'utf8').trim()
    : '';
  const head = execSync('git rev-parse HEAD', { cwd: CHECKOUT, encoding: 'utf8' }).trim();

  if (recorded === '') {
    throw new Error(`${SITE_DIR} does not record which revision it was synced from. `
      + `Sync it and write ${head} into storage/checkout.rev, or the run measures code nobody chose.`);
  }
  if (recorded !== head) {
    throw new Error(`${SITE_DIR} is running ${recorded.slice(0, 12)}, the checkout is at ${head.slice(0, 12)}. `
      + 'Re-sync the copy: a run against older code reports its absence as a product failure.');
  }

  return head;
}

/**
 * Aborts unless the served asset contains every expected fragment. A check that reads
 * stale CSS reports the old design as the new one and looks like a pass.
 */
export async function requireServed(page, base, asset, fragments) {
  const css = await page.evaluate(async (url) => {
    const response = await fetch(url);
    return response.ok ? response.text() : '';
  }, `${base}/assets/${asset}`);

  const missing = fragments.filter((fragment) => !css.includes(fragment));
  if (missing.length > 0) {
    throw new Error(`${asset} is stale — missing ${JSON.stringify(missing)}. Sync the site copy.`);
  }
}

/**
 * The fixtures a scenario needs, or a FAILURE (PLAN.md D-029).
 *
 * NOT CHECKABLE is for a limit of the environment: no browser, something this host cannot
 * prove. Missing test data is not that — it is the check not running, and in a summary of a
 * hundred PASS lines it reads like a pass. Six media scenarios reported NOT CHECKABLE for
 * every run after the photographs were renamed, and nobody saw it.
 *
 * The decision lives here rather than in each scenario, because a rule every caller may
 * reinterpret is not a rule.
 *
 * @returns {string[]|null} the files, or null once the failure has been recorded
 */
export function fixtures(report, area, files, what) {
  const missing = files.filter((file) => !file || !existsSync(file));
  if (files.length === 0 || missing.length > 0) {
    report.fail(`${area}: its test data`,
      `needs ${what}; missing ${JSON.stringify(missing.length > 0 ? missing : ['nothing was offered'])}. `
      + 'A FAILURE, not something we cannot check: the scenario did not run.');
    return null;
  }
  return files;
}

/**
 * Replaces what is in a field, through real input. THE ONLY BLESSED WAY.
 *
 * Never click({ clickCount: 3 }): a triple click through this driver selects NOTHING, so
 * the new text is appended and the read-back looks exactly like the product mangling a
 * save. That cost time in 11-picture, was written down there, and was then made again in
 * 04-builder and in 17-settings. A lesson recorded in one scenario plainly does not hold,
 * so it lives here and run.mjs refuses to start when a scenario reaches for the old way.
 */
export async function retype(page, selector, value) {
  await page.click(selector);
  await page.keyboard.down('Control');
  await page.keyboard.press('KeyA');
  await page.keyboard.up('Control');
  await page.keyboard.press('Backspace');
  await page.type(selector, value, { delay: SLOW });
}

/**
 * Puts a site copy back to "never installed", so 01-install can actually run (D-029).
 *
 * That scenario installs from nothing, and the installer deletes itself once it has
 * finished — correctly, since a second run must be impossible. The consequence was a check
 * that could only ever pass once and was red for ever after, and a check that can only fail
 * stops being read at all.
 *
 * IT DELETES A DATABASE AND A .env, so it refuses before it acts rather than after:
 * never the checkout itself, never anything under a served htdocs, and only a directory
 * that actually looks like a Boxlet copy. The copy is complete already — vendor and all —
 * so only STATE is removed; nothing is rebuilt.
 *
 * @returns {string} what it did, for the scenario to put in its verdict
 */
export function resetForInstall() {
  const site = resolve(SITE_DIR);
  const checkout = resolve(CHECKOUT);

  if (site === checkout) {
    throw new Error(`Refusing to reset ${site}: that is the checkout itself, not a copy.`);
  }
  if (site.includes('/htdocs/')) {
    throw new Error(`Refusing to reset ${site}: it is under htdocs, where a served site lives.`);
  }
  if (!existsSync(`${site}/public/index.php`) || !existsSync(`${site}/app`)) {
    throw new Error(`Refusing to reset ${site}: it does not look like a Boxlet copy.`);
  }

  const installer = `${checkout}/public/install.php`;
  if (!existsSync(installer)) {
    throw new Error(`No installer to restore: ${installer} does not exist.`);
  }

  const removed = [];
  for (const relative of ['storage/install.lock', '.env', 'storage/database.sqlite', 'storage/database.sqlite-journal']) {
    if (existsSync(`${site}/${relative}`)) {
      rmSync(`${site}/${relative}`);
      removed.push(relative);
    }
  }

  copyFileSync(installer, `${site}/public/install.php`);

  return `removed ${removed.length > 0 ? removed.join(', ') : 'nothing — it was already clean'}; `
    + `restored public/install.php from ${checkout}`;
}

export function reporter(area) {
  const results = [];
  mkdirSync(`${SHOTS}/${area}`, { recursive: true });

  return {
    area,
    results,
    record(item, verdict, detail = '') {
      results.push({ item, verdict, detail });
      console.log(`  ${verdict.padEnd(14)} ${item}${detail ? ' — ' + detail : ''}`);
    },
    pass(item, detail) { this.record(item, 'PASS', detail); },
    fail(item, detail) { this.record(item, 'FAIL', detail); },
    skip(item, why) { this.record(item, 'NOT CHECKABLE', why); },
    verdict(item, ok, detail) { this.record(item, ok ? 'PASS' : 'FAIL', detail); },
    async shot(page, name, options = {}) {
      await page.screenshot({ path: `${SHOTS}/${area}/${name}.png`, fullPage: true, ...options });
    },
  };
}

/** The admin's visible alerts — field errors and notices. */
export const alerts = (page) => page.$$eval(
  '[role="alert"], .notice-error, .field-error',
  (els) => els.map((el) => el.textContent.trim()).filter(Boolean),
).catch(() => []);

export const heading = (page) => page.$eval('h1', (el) => el.textContent.trim()).catch(() => '(no h1)');

/**
 * No form control sits directly on the page's own background.
 *
 * The rule is about what a person sees, not about class names: a control must sit on
 * something that paints. Measuring it by class was tried and was wrong — the admin has at
 * least four legitimate containers (.panel, .card in the installer's own layout,
 * .panel-block in the builder's aside, and form.media-meta with a fieldset in Media), so a
 * grep for one name reports three false failures and misses a real one. Computed colour
 * asks the question the eye asks.
 *
 * Only the main frame is queried, which puts the builder's canvas iframe out of scope by
 * construction rather than by a skip that could rot: the canvas renders the SITE, where
 * the background belongs to the design.
 */
export async function controlsOnPanels(page, report, where) {
  const seen = await page.evaluate(() => {
    const paints = (colour) => typeof colour === 'string' && colour !== 'transparent'
      && !/^rgba\([^)]*,\s*0\s*\)$/.test(colour);

    // The ground the page actually paints: body first, then html, the order a browser
    // resolves it in. A transparent body over a coloured html is a real arrangement.
    let ground = 'rgba(0, 0, 0, 0)';
    for (const el of [document.body, document.documentElement]) {
      const colour = getComputedStyle(el).backgroundColor;
      if (paints(colour)) { ground = colour; break; }
    }

    const bare = [];
    let unrendered = 0;
    for (const control of document.querySelectorAll('input, select, textarea')) {
      // A hidden input has no box, and the CSRF token is one on every single form.
      if (control.type === 'hidden') { continue; }
      // Nothing is painted behind something with no box; counted, not silently dropped.
      //
      // KNOWN LIMIT, and the count is here so it cannot be forgotten: a control that is
      // hidden until something opens is judged in neither state. On Settings this skips
      // the three selects media-picker.js replaces (the replacement is what a person sees,
      // and the scenario judges that separately) — but also the picker dialog's own search
      // fields, which DO have to sit on a panel once the dialog is open. A scenario that
      // opens a dialog should call this again with it open; closed, the guard cannot see in.
      if (control.getClientRects().length === 0) { unrendered += 1; continue; }

      let panel = null;
      for (let el = control.parentElement; el && el !== document.documentElement; el = el.parentElement) {
        const colour = getComputedStyle(el).backgroundColor;
        if (paints(colour)) { panel = { colour, on: el.tagName.toLowerCase() + '.' + el.className }; break; }
      }

      if (panel === null || panel.colour === ground) {
        bare.push({
          control: control.name || control.id || control.tagName.toLowerCase(),
          sits_on: panel === null ? 'nothing that paints' : `${panel.on} — the page's own colour`,
        });
      }
    }
    return { ground, bare, unrendered };
  });

  report.verdict(`${where}: every control sits on something that paints`, seen.bare.length === 0,
    seen.bare.length === 0
      ? `page ground ${seen.ground}${seen.unrendered ? `; ${seen.unrendered} not rendered` : ''}`
      : `${seen.bare.length} on the bare page: ${JSON.stringify(seen.bare).slice(0, 400)}`);
}

/**
 * Opens one of the Appearance screen's five tabs (PLAN.md D-059) and waits for its panel.
 *
 * WITHOUT THIS A SCENARIO JUDGES A SCREEN IT CANNOT SEE. Four panels in five are hidden, so
 * a control in a closed tab has no box: page.type refuses it, and controlsOnPanels counts it
 * as unrendered rather than judging it. Anything that reads or fills a tab opens it first,
 * and a guard that means to cover the whole screen runs once per tab.
 *
 * Returns false when there are no tabs — a screen without JavaScript shows every panel, and
 * the caller can carry on.
 */
export async function openTab(page, name) {
  const tab = await page.$(`[data-tab="${name}"]`);
  if (tab === null) {
    return false;
  }
  await tab.click();
  await page.waitForFunction(
    (which) => {
      const panel = document.querySelector(`[data-panel="${which}"]`);
      return panel !== null && !panel.hidden;
    },
    { timeout: 10000 },
    name,
  );
  return true;
}

/**
 * Applies a design character, in the two clicks the admin deliberately requires:
 * `preset:<name>` only LOADS the preset into the form (AppearanceController: "Publish is the
 * confirmation"), and `action=save` writes it. `save_composition` is the second,
 * destructive action that also rewrites every block's layer 2 and 3.
 *
 * Shared rather than copied: 03-design applies every character to judge the design, and
 * 14-front applies every character to judge pictures under it.
 *
 * Returns the admin's visible alerts — empty when the character was accepted.
 */
/*
 * THE SITE IS AN ARGUMENT, NEVER THIS FILE'S BASE. Applying a character rewrites the whole
 * design, which only the copy may have done to it (PLAN.md D-033). This read the
 * development site's address from config.mjs, so a scenario that declared `copy: true`
 * and passed its own base nowhere still rewrote the development site's design — which is
 * what scenario 25 did on its first run, five times. run.mjs also refuses a scenario that
 * calls this without running against the copy.
 */
export async function applyCharacter(page, base, preset, action = 'save') {
  await page.goto(`${base}/admin/appearance`, { waitUntil: 'networkidle2' });
  await clickAndWait(page, `button[name="action"][value="preset:${preset}"]`);
  // BY THE FORM IT NAMES, not by the form it sits in: Save moved out of the controls and
  // beside the preview, where the sticky column keeps it in reach (D-058). It still submits
  // the same form — through form="design-form" — and the selector has to say so, or it
  // matches the character cards' own buttons.
  await clickAndWait(page, `button[form="design-form"][name="action"][value="${action}"]`);
  return alerts(page);
}

/**
 * A menu in the header, on a COPY that has none: an empty header is not drawn (D-032), so a
 * check of the header's width or its submenu measured nothing. The demo seed now ships a menu
 * of its own (D-057), which this finds and keeps — it returns early — so what is left here is
 * the fallback for a copy installed WITHOUT the demo. Made through the admin as the owner
 * would, and only where the chrome names no menu yet; never call it against the development
 * site, whose header is the owner's.
 *
 * Returns the menu's name, or '' when it could not be set up.
 */
export async function ensureHeaderMenu(page, base) {
  await page.goto(`${base}/admin/appearance`, { waitUntil: 'networkidle2' });
  const current = await page.$eval('#header_menu', (select) => select.value).catch(() => null);
  if (current === null) {
    return '';
  }
  if (current !== '') {
    return current;
  }

  const name = 'Suite menu';
  await page.goto(`${base}/admin/menus`, { waitUntil: 'networkidle2' });
  const exists = await page.$$eval('.row-title a', (links, wanted) => links.some((a) => a.textContent.trim() === wanted), name);
  if (!exists) {
    await page.type('input[name="name"]', name, { delay: SLOW });
    await submitVia(page, 'input[name="name"]', 40000);
    const add = 'form[action$="/items"]';
    for (const n of [2, 3]) {
      const value = await page.$eval(`${add} select[name="page_id"] option:nth-child(${n})`, (o) => o.value).catch(() => '');
      if (value === '') {
        break;
      }
      await page.select(`${add} select[name="page_id"]`, value);
      await submitVia(page, `${add} select[name="page_id"]`, 40000);
    }
  }

  await page.goto(`${base}/admin/appearance`, { waitUntil: 'networkidle2' });
  // The menu lives in the fifth tab now, and page.select refuses a control with no box.
  await openTab(page, 'chrome');
  await page.select('#header_menu', name);
  await clickAndWait(page, 'button[form="design-form"][name="action"][value="save"]', 40000);

  await openTab(page, 'chrome');
  return page.$eval('#header_menu', (select) => select.value).catch(() => '');
}
