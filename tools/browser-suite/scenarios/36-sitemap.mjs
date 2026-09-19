/*
 * The sitemap and robots.txt as a search engine finds them (PLAN.md D-049).
 *
 * Both are real files, served by the web server without PHP — which is the reason they are
 * files: managed nginx answers any address ending in .xml or .txt from disk and never asks
 * PHP. Opening the dashboard writes them on a site that does not have them yet.
 *
 * Leaves the files in place: they are the site's own and are rewritten on every change.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

export default {
  name: 'sitemap',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('sitemap: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle2' });
    const fetched = await page.evaluate(async () => {
      const get = async (path) => {
        const response = await fetch(path, { cache: 'reload' });
        return { status: response.status, type: response.headers.get('content-type'), etag: response.headers.get('etag'), body: await response.text() };
      };
      return { sitemap: await get('/sitemap.xml'), robots: await get('/robots.txt') };
    });
    const pages = (fetched.sitemap.body.match(/<loc>/g) || []).length;
    report.verdict('sitemap.xml is served from disk and lists the published pages',
      fetched.sitemap.status === 200 && fetched.sitemap.etag !== null && pages > 0,
      `${fetched.sitemap.status}, ${fetched.sitemap.type}, ETag ${fetched.sitemap.etag}, ${pages} pages`);
    report.verdict('robots.txt points search engines at it and keeps them out of the admin',
      fetched.robots.status === 200 && /Sitemap: https?:\/\/[^\s]+\/sitemap\.xml/.test(fetched.robots.body) && /Disallow: \/admin/.test(fetched.robots.body),
      fetched.robots.body.replace(/\n/g, ' | '));
  },
};
