// Run from any directory: node crmeb/tests/points_frontend.cjs
// Executes the actual page methods with mocked network/uni APIs; no WeChat runtime required.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
let checks = 0;
function check(condition, message) { assert.ok(condition, message); checks++; }
const flush = () => new Promise(resolve => setImmediate(resolve));
function page(file, api) {
  const source = fs.readFileSync(path.join(__dirname, '../../template/uni-app', file), 'utf8');
  const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace(/import\s+[\s\S]*?from\s+['"][^'"]+['"];?/g, '')
    .replace('export default', 'module.exports =');
  const events = { login: 0, stop: 0 };
  const context = { module: { exports: {} }, mapGetters: () => ({}), colors: {},
    uni: { stopPullDownRefresh: () => events.stop++ }, toLogin: () => events.login++, ...api };
  vm.runInNewContext(script, context, { filename: file });
  const component = context.module.exports;
  const instance = { ...component.data(), isLogin: true };
  for (const [name, method] of Object.entries(component.methods)) instance[name] = method.bind(instance);
  return { instance, component, events };
}
async function run() {
  let fail = false, calls = [];
  const first = Array.from({ length: 20 }, (_, i) => ({ rank: i + 1, nickname: `用户${i}`, total_integral: 100 - i }));
  const rankApi = async query => {
    calls.push(query.page);
    if (fail) throw new Error('network down');
    return { data: { list: query.page === 1 ? first : [{ rank: 21 }], count: 21,
      my: { rank: 2, total_integral: 1000, available_integral: 700 } } };
  };
  const ranking = page('pages/points_mall/ranking.vue', { getIntegralRanking: rankApi });
  const r = ranking.instance;
  ranking.component.onShow.call(r);
  await flush();
  check(r.list.length === 20 && r.page === 2 && !r.finished, 'ranking loads first page');
  check(r.my.total_integral === 1000 && r.my.available_integral === 700, 'ranking displays separate cumulative and available points');
  fail = true;
  await r.load();
  check(r.error && r.page === 2 && r.list.length === 20 && !r.loading, 'ranking failure preserves pagination and permits retry');
  fail = false;
  await r.load();
  check(r.list.length === 21 && r.finished && !r.error, 'ranking retry appends only missing page');
  const count = calls.length;
  await r.load();
  check(calls.length === count, 'ranking stops after final page');
  ranking.component.onPullDownRefresh.call(r);
  await flush();
  check(r.list.length === 20 && r.page === 2 && ranking.events.stop > 0, 'pull refresh resets list without duplication');
  r.isLogin = false;
  r.refresh();
  check(ranking.events.login === 1, 'ranking requires login');

  let listFail = false, summaryFail = false, listCalls = [];
  const points = page('pages/users/user_integral/index.vue', {
    getIntegralList: async query => {
      listCalls.push(query.page);
      if (listFail) throw '明细服务不可用';
      return { data: query.page === 1 ? Array.from({ length: 20 }, (_, i) => ({ pm: i % 2, number: 10 })) : [{ pm: 0, number: 3 }] };
    },
    getIntegralRanking: async () => {
      if (summaryFail) throw new Error('summary down');
      return { data: { my: { rank: 2, total_integral: 1000, available_integral: 700 } } };
    }
  });
  const p = points.instance;
  await p.refresh();
  check(p.list.length === 20 && p.my.rank === 2 && p.my.available_integral === 700, 'my points loads ledger and summary');
  await p.load();
  check(p.list.length === 21 && p.finished, 'ledger appends next page and finishes');
  await p.refresh();
  check(p.list.length === 20 && !p.finished && p.page === 2, 'returning to page refreshes ledger');
  listFail = true;
  await p.load();
  check(p.error === '明细服务不可用' && p.page === 2 && !p.loading, 'ledger failure is retryable');
  listFail = false;
  await p.load();
  check(p.list.length === 21 && !p.error, 'ledger retry does not duplicate earlier entries');
  summaryFail = true;
  await p.refresh();
  check(p.error === '积分加载失败', 'summary errors are visible');
  summaryFail = false;
  await p.refresh();
  check(!p.error && p.my.total_integral === 1000, 'summary refresh recovers');
  p.isLogin = false;
  await p.refresh();
  check(points.events.login === 1, 'my points requires login');
  console.log(`PASS: ${checks} frontend behavior checks`);
}
run().catch(error => { console.error(error); process.exitCode = 1; });
