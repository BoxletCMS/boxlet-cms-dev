#!/usr/bin/env node
/*
 * The browser suite's runner (CLAUDE.md rule 12): one command, one scenario file per area.
 *
 *   node suite/run.mjs              every scenario, in order
 *   node suite/run.mjs pages design just those
 *
 * Scenarios are ./scenarios/NN-name.mjs and export default { name, run({ page, report }) }.
 * The numeric prefix is the order: 01 installs the site the rest of them use.
 *
 * Each scenario gets its own browser, so one that hangs or dies cannot take the others
 * with it, and a failure in one still leaves the rest reportable — which matters for a
 * checklist whose whole output is per-item verdicts.
 */
import { readdirSync, readFileSync } from 'node:fs';
import { openBrowser, reporter } from './harness.mjs';

const dir = new URL('./scenarios/', import.meta.url);
const wanted = process.argv.slice(2);

const files = readdirSync(dir)
  .filter((f) => f.endsWith('.mjs'))
  .sort()
  .filter((f) => wanted.length === 0 || wanted.some((w) => f.includes(w)));

if (files.length === 0) {
  console.error(wanted.length ? `No scenario matches ${wanted.join(', ')}` : 'No scenarios found.');
  process.exit(2);
}

/*
 * THE TRIPLE-CLICK BAN, ENFORCED (PLAN.md D-029).
 *
 * A triple click through this driver selects nothing, so typing is appended and the
 * read-back looks exactly like the product mangling a save. It was diagnosed once, written
 * down in 11-picture.mjs — and then made again in two other scenarios. A rule that lives
 * only in a comment is a rule that comes back, so the runner refuses to start.
 *
 * Comment lines are stripped first: 11-picture explains the ban in prose, and a scan that
 * punished the lesson along with the offence would teach everyone to stop writing it down.
 */
const banned = [];
for (const file of files) {
  const code = readFileSync(new URL(file, dir), 'utf8')
    .split('\n')
    .filter((line) => !/^\s*(\/\/|\*|\/\*)/.test(line))
    .join('\n');
  if (/clickCount:\s*3/.test(code)) {
    banned.push(file);
  }
}
if (banned.length > 0) {
  console.error(`Refusing to run. ${banned.join(', ')} use click({ clickCount: 3 }), which `
    + 'selects nothing through this driver and appends instead of replacing. '
    + 'Use retype() from harness.mjs.');
  process.exit(2);
}

const all = [];

for (const file of files) {
  const scenario = (await import(new URL(file, dir))).default;
  const area = scenario.name || file.replace(/\.mjs$/, '');
  console.log(`\n=== ${area} ===`);

  const report = reporter(area);
  const { browser, page, errors, blocked, dialogs } = await openBrowser(scenario.viewport);

  try {
    await scenario.run({ page, report, browser });
  } catch (error) {
    // The stack, not just the message. A collapsed "Navigation timeout of 30000ms
    // exceeded" sent me to fix a save click twice while the throw was somewhere else
    // entirely — the message named a timeout no call in that function even used.
    const where = (error.stack || '').split('\n')
      .filter((line) => line.includes('scenarios/'))
      .slice(0, 3)
      .map((line) => line.trim())
      .join(' | ');
    report.fail(`${area}: the scenario itself`,
      `${error.message.split('\n')[0]}${where ? ` — thrown at ${where}` : ' — no scenario frame in the stack'}`);
    if (error.stack) {
      console.log('  stack:', error.stack.split('\n').slice(0, 6).map((l) => l.trim()).join('\n         '));
    }
  } finally {
    if (dialogs.length) console.log('  DIALOGS:', JSON.stringify(dialogs.slice(0, 5)));
    if (blocked.length) console.log('  CSP BLOCKED:', JSON.stringify(blocked.slice(0, 5)));
    if (errors.length) console.log('  PAGE ERRORS:', JSON.stringify(errors.slice(0, 5)));
    await browser.close();
  }

  all.push(...report.results.map((r) => ({ ...r, area })));
}

const count = (verdict) => all.filter((r) => r.verdict === verdict).length;

console.log('\n================ SUMMARY ================');
for (const result of all) {
  console.log(`${result.verdict.padEnd(14)} ${result.item}`);
}
console.log(`\n${count('PASS')} pass, ${count('FAIL')} fail, ${count('NOT CHECKABLE')} not checkable`);

if (count('FAIL') > 0) {
  console.log('\n--- failures, with what was seen ---');
  for (const result of all.filter((r) => r.verdict === 'FAIL')) {
    console.log(`  ${result.item}\n      ${result.detail}`);
  }
}

process.exit(count('FAIL') > 0 ? 1 : 0);
