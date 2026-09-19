/*
 * Two-step login, as the owner meets it (SPEC §6, PLAN.md D-050): set it up from Settings
 * with an authenticator app, log out, log back in with the password and then a code, and
 * switch it off again.
 *
 * The app is played by a few lines below computing the same six-digit code any
 * authenticator app computes from the key the setup screen shows (RFC 6238).
 *
 * COPY ONLY: switching two-step login on for the development site's one account would
 * change how its owner logs in.
 */
import { createHmac } from 'node:crypto';
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, clickAndWait } from '../harness.mjs';

/** What an authenticator app shows for a base32 key, now. */
function appCode(key) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const char of key.replace(/[\s=]/g, '').toUpperCase()) {
    bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
  }
  const bytes = Buffer.from((bits.match(/.{8}/g) || []).map((b) => parseInt(b, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 1000 / 30)));
  const hmac = createHmac('sha1', bytes).update(counter).digest();
  const offset = hmac[hmac.length - 1] & 0xf;
  const value = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1000000;
  return String(value).padStart(6, '0');
}

const flash = (page) => page.$$eval('[role="status"], [role="alert"], .flash, .notice', (els) => els.map((e) => e.textContent.trim()).join(' | ')).catch(() => '');

export default {
  name: 'two-step',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('two-step: log in', `could not log in; at ${page.url()}`);
      return;
    }
    let on = false;
    try {
      // ---- set up -----------------------------------------------------------------------------
      await page.goto(`${BASE}/admin/settings#two-step`, { waitUntil: 'networkidle2' });
      await clickAndWait(page, '#two-step a.button');
      const key = await page.$eval('.two-step-secret', (c) => c.textContent);
      const qr = await page.$('.two-step-qr svg');
      await report.shot(page, '01-setup', { fullPage: false });
      await page.type('#two-step-code', appCode(key));
      await clickAndWait(page, 'form.two-step-confirm button');
      const codes = await page.$$eval('.two-step-codes code', (c) => c.map((x) => x.textContent));
      on = codes.length === 10;
      await report.shot(page, '02-codes', { fullPage: false });
      report.verdict('setup shows a QR code and a key, and a code from the app switches it on with ten recovery codes',
        qr !== null && key.length > 10 && on, `${codes.length} codes`);

      // ---- log out, and back in -------------------------------------------------------------------
      await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle2' });
      await clickAndWait(page, 'form[action$="/admin/logout"] button');
      await page.type('input[name="email"]', ADMIN.email);
      await page.type('input[name="password"]', ADMIN.password);
      await clickAndWait(page, 'form[action$="/admin/login"] button[type="submit"]');
      const asked = page.url().endsWith('/admin/login/code');
      await report.shot(page, '03-code', { fullPage: false });
      await page.type('input[name="code"]', appCode(key));
      await clickAndWait(page, 'form[action$="/admin/login/code"] button[type="submit"]');
      report.verdict('after the password it asks for a code, and the code logs in', asked && /\/admin$/.test(page.url()), page.url());

      // ---- off --------------------------------------------------------------------------------
      await page.goto(`${BASE}/admin/settings#two-step`, { waitUntil: 'networkidle2' });
      await page.type('#two-step-off-password', ADMIN.password);
      await clickAndWait(page, 'form[action$="/two-step/off"] button');
      const said = await flash(page);
      on = !/is off/.test(said);
      report.verdict('the password turns it off again', !on, said);
    } finally {
      if (on) {
        report.fail('two-step: left on', 'two-step login is still on for the copy; it will ask for a code at the next login');
      }
    }
  },
};
