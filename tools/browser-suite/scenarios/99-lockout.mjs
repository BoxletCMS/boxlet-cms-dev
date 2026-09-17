/*
 * Slice 2, the last item: five wrong passwords lock the account out.
 *
 * Runs last on purpose. LoginThrottle allows 5 failures per 900 seconds, counted on the
 * IP hash or the email hash, so this leaves the account locked for a quarter of an hour —
 * anything after it could not log in.
 *
 * auth.failed is deliberately identical for a wrong password and a locked account, so the
 * form never reveals whether an account exists. Reading the message therefore cannot tell
 * the two apart. The only honest test is to fail five times and then try the CORRECT
 * password: if it is refused, the lock is real.
 */
import { BASE, ADMIN } from '../config.mjs';
import { submitVia, alerts, SLOW } from '../harness.mjs';

export default {
  name: 'lockout',

  async run({ page, report }) {
    const messages = [];
    for (let attempt = 1; attempt <= 5; attempt++) {
      await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle2' });
      await page.type('input[name="email"]', ADMIN.email, { delay: 8 });
      await page.type('input[name="password"]', 'definitely not the password', { delay: 8 });
      await submitVia(page, 'input[name="password"]');
      messages.push((await alerts(page)).join(' | '));
    }
    await report.shot(page, '01-after-five-failures');

    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle2' });
    await page.type('input[name="email"]', ADMIN.email, { delay: SLOW });
    await page.type('input[name="password"]', ADMIN.password, { delay: SLOW });
    await submitVia(page, 'input[name="password"]');
    const refused = page.url().includes('/login');
    await report.shot(page, '02-locked-with-correct-password');

    report.verdict('five wrong passwords lock the account out', refused,
      `after five failures the correct password was ${refused ? 'refused' : 'ACCEPTED'}; `
      + `messages were ${JSON.stringify(messages)}`);
  },
};
