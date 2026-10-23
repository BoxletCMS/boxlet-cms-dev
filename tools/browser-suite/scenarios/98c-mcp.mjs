/*
 * AI ACCESS OVER MCP (PLAN.md O-78), from the owner's side and an AI client's.
 *
 *   - Settings → AI access: a key made, shown once, with the line that connects Claude Code;
 *   - the endpoint answers that key over real HTTP: a modern server/discover, the tool list with
 *     its caching hints, and a tool;
 *   - a design set the client keeps is where its link lands, marked as an AI client's (D-290:
 *     the owner looked for one among the characters and did not find it); removed again here;
 *   - a page drawn again shows the key no more;
 *   - revoked, the key opens nothing; removed, it is off the list.
 *
 * The scenario's key is its own, removed again through the screen. On the copy.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, clickAndWait } from '../harness.mjs';

const NAME = `Suite client ${Date.now()}`;
const DESIGN = `Suite navy ${Date.now()}`;

async function call(key, method, params = {}) {
  const meta = { 'io.modelcontextprotocol/protocolVersion': '2026-07-28', 'io.modelcontextprotocol/clientInfo': { name: 'suite', version: '1' }, 'io.modelcontextprotocol/clientCapabilities': {} };
  const headers = { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', Authorization: `Bearer ${key}`, 'MCP-Protocol-Version': '2026-07-28', 'Mcp-Method': method };
  if (params.name) headers['Mcp-Name'] = params.name;
  const response = await fetch(`${BASE}/mcp`, { method: 'POST', headers, body: JSON.stringify({ jsonrpc: '2.0', id: 1, method, params: { ...params, _meta: meta } }) });
  return { status: response.status, body: await response.json().catch(() => null) };
}

export default {
  name: 'mcp',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('mcp: log in', `could not log in as ${ADMIN.email}`);
      return;
    }
    let key = '';
    let designId = null;
    try {
      await page.goto(`${BASE}/admin/settings#mcp`, { waitUntil: 'networkidle2' });
      await page.type('#mcp-name', NAME);
      await page.select('#mcp-write', '1');
      await clickAndWait(page, 'form[action$="/admin/settings/mcp/keys"] button[type="submit"]');
      const shown = await page.evaluate(() => ({
        key: document.querySelector('#mcp-new-key')?.value || '',
        connect: document.querySelector('#mcp-connect')?.value || '',
        endpoint: document.querySelector('#mcp-endpoint')?.value || '',
      }));
      key = shown.key;
      await page.$eval('#mcp', (el) => el.scrollIntoView({ block: 'start' }));
      await report.shot(page, '01-key-made', { fullPage: false });
      report.verdict('a key made is shown once, with the address and the line that connects Claude Code',
        /^bxk_[0-9a-f]{48}$/.test(key) && shown.connect.includes(`Bearer ${key}`) && shown.endpoint.endsWith('/mcp'), JSON.stringify({ ...shown, key: key.slice(0, 8) }));

      const discover = await call(key, 'server/discover');
      const list = await call(key, 'tools/list');
      const site = await call(key, 'tools/call', { name: 'get_site', arguments: {} });
      // ttlMs and cacheScope are required: Claude Code refused a list without them.
      const hints = [discover.body?.result, list.body?.result].map((r) => [r?.ttlMs, r?.cacheScope]);
      report.verdict('discover and the tool list carry the caching hints a modern client requires',
        Number.isInteger(hints[0][0]) && hints[0][1] === 'public' && Number.isInteger(hints[1][0]) && hints[1][1] === 'private' && list.body?.result?.tools?.length > 0,
        JSON.stringify(hints));
      report.verdict('over HTTP the key opens the endpoint: discover, and a tool',
        discover.status === 200 && discover.body?.result?.supportedVersions?.includes('2026-07-28') && site.status === 200 && site.body?.result?.structuredContent?.this_key?.writes === true,
        JSON.stringify({ discover: discover.status, site: site.body?.result?.structuredContent?.name }));

      const set = { format: 'boxlet-design-set', version: 2, id: 'suite-navy', name: { en: DESIGN }, decisions: { seed: '#1f3a68' } };
      const kept = (await call(key, 'tools/call', { name: 'create_design_set', arguments: { set } })).body?.result?.structuredContent || {};
      designId = kept.id || null;
      // The address the client is given, as the owner would open it: its path, on this copy.
      const link = new URL(kept.try_it || '/', BASE);
      await page.goto(`${BASE}${link.pathname}${link.hash}`, { waitUntil: 'networkidle2' });
      const landed = await page.evaluate((name) => {
        const row = [...document.querySelectorAll('#group-designs .design-row')].find((li) => li.querySelector('.design-row-name')?.textContent === name);
        const box = row?.getBoundingClientRect();
        return { found: !!row, inView: !!box && box.top >= 0 && box.bottom <= window.innerHeight, marked: !!row?.querySelector('.design-row-ai'), mark: row?.querySelector('.design-row-ai')?.textContent || '' };
      }, DESIGN);
      await report.shot(page, '02-design-kept', { fullPage: false });
      report.verdict('a design set the client keeps is in view where its link lands, marked as an AI client\'s',
        link.hash === '#group-designs' && landed.found && landed.inView && landed.marked, JSON.stringify({ hash: link.hash, ...landed }));

      await page.reload({ waitUntil: 'networkidle2' });
      const again = await page.evaluate((k) => document.body.innerHTML.includes(k), key);
      report.verdict('drawn again, the screen no longer shows the key', !again, again ? 'the key is still on the page' : '');
    } finally {
      if (designId) {
        // Thrown away through the screen's own delete, by the id the client was given.
        await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
        await clickAndWait(page, `button[value="library:delete:${designId}"]`);
        const left = await page.evaluate((name) => [...document.querySelectorAll('.design-row-name')].some((el) => el.textContent === name), DESIGN);
        report.verdict('the scenario\'s design removed again', !left, left ? 'still listed' : '');
      }
      await page.goto(`${BASE}/admin/settings#mcp`, { waitUntil: 'networkidle2' });
      const id = await page.evaluate((name) => {
        const row = [...document.querySelectorAll('#mcp tbody tr')].find((tr) => tr.textContent.includes(name));
        const form = row?.querySelector('form[action$="/revoke"]');
        return form ? (form.getAttribute('action').match(/keys\/(\d+)\//) || [])[1] : null;
      }, NAME);
      if (id) {
        await page.$eval(`form[action$="/keys/${id}/revoke"] button`, (b) => b.removeAttribute('data-confirm'));
        await clickAndWait(page, `form[action$="/keys/${id}/revoke"] button`);
        const refused = key ? (await call(key, 'server/discover')).status : 0;
        report.verdict('revoked, the key opens nothing', refused === 401, `status ${refused}`);
        await clickAndWait(page, `form[action$="/keys/${id}/remove"] button`);
        const left = await page.evaluate((name) => document.querySelector('#mcp')?.textContent.includes(name), NAME);
        report.verdict('removed, it is off the list', !left, left ? 'still listed' : '');
      } else {
        report.fail('mcp: the scenario\'s key to remove', 'not listed');
      }
    }
  },
};
