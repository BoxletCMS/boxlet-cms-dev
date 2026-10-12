/*
 * A LOGIN LEAVES THE BROWSER BOTH ITS COOKIES (PLAN.md O-65): the session's, and the one that
 * says this browser has logged in here, so failures for the email do not lock it out. The
 * second was first sent with header()'s default, which replaces: it took the session's cookie
 * with it and no login held. The PHP tests never call send(), so only a browser sees this.
 *
 *   - after logging in: boxlet_session, and boxlet_device on /admin, HttpOnly, SameSite Strict;
 *   - the dashboard opens: the session held;
 *   - the cookies a page of the site would be sent: the session's, not the device one.
 *
 * ON THE COPY; it only logs in.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

export default {
  name: 'login-cookies',
  copy: true,

  async run({ page, report }) {
    const client = await page.target().createCDPSession();
    await client.send('Network.clearBrowserCookies');
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('login-cookies: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    const { cookies } = await client.send('Network.getAllCookies');
    const session = cookies.find((c) => c.name === 'boxlet_session');
    const device = cookies.find((c) => c.name === 'boxlet_device');
    report.verdict('after logging in the browser holds the session\'s cookie and the known-device one',
      !!session && !!device && device.path === '/admin' && device.httpOnly && device.sameSite === 'Strict',
      JSON.stringify({ session: !!session, device: device ? { path: device.path, httpOnly: device.httpOnly, sameSite: device.sameSite } : null }));

    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle2' });
    const atDashboard = new URL(page.url()).pathname === '/admin' && await page.$('form[action$="/admin/login"] input[name="password"]') === null;
    report.verdict('the dashboard opens: the session held', atDashboard, page.url());

    // What the browser would send with a page of the site: the session's cookie is the control
    // (path /, it must be there), the device cookie must not. request.headers() leaves
    // cookies out altogether, so it cannot be asked.
    const forSite = (await page.cookies(`${BASE}/`)).map((c) => c.name);
    report.verdict('the site\'s own pages are not sent the device cookie, the session\'s are',
      forSite.includes('boxlet_session') && !forSite.includes('boxlet_device'), JSON.stringify(forSite));
  },
};
