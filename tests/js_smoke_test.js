/**
 * JS 冒烟测试（Node.js）
 * ---------------------------------------------------------------------------
 * 把 index.html / fill.html 里的 <script> 放进最小 DOM 桩环境里真实执行，
 * 自动发现两类 API 级验收测试永远测不出的 bug：
 *   1. ReferenceError —— 引用了从未定义的函数/变量（例如曾经的 saveSurvey）
 *   2. 空元素访问     —— getElementById('xxx') 在页面里不存在，后续 .xxx 抛 TypeError
 *
 * 另外做一次静态检查：JS 里所有 getElementById('字符串字面量') 必须能在 HTML 里找到对应 id。
 *
 * 用法：node tests/js_smoke_test.js
 * 退出码：0 = 全部通过；1 = 有失败
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const pages = process.argv.length > 2
  ? process.argv.slice(2)      // 指定文件时（相对仓库根目录的路径）只测这些文件
  : ['index.html', 'fill.html'];
let failed = false;

function makeEl() {
  return {
    style: {},
    value: '',
    innerHTML: '',
    innerText: '',
    textContent: '',
    className: '',
    dataset: {},
    src: '',
    classList: { add() {}, remove() {}, toggle() {} },
    addEventListener() {},
    removeEventListener() {},
    appendChild() {},
    removeChild() {},
    insertBefore() {},
    querySelectorAll() { return []; },
    querySelector() { return null; },
    getAttribute() { return null; },
    setAttribute() {},
    select() {},
    focus() {},
  };
}

function runPage(file) {
  const html = fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
  const ids = new Set([...html.matchAll(/\bid="([^"]+)"/g)].map(m => m[1]));

  // ---- 静态检查：JS 里字面量引用的元素 id 必须存在 ----
  const scripts = [...html.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)]
    .map(m => m[1]).filter(s => s.trim());
  const staticMissing = [];
  for (const code of scripts) {
    const refs = [...code.matchAll(/getElementById\(\s*['"]([^'"]+)['"]\s*\)/g)].map(m => m[1]);
    for (const id of new Set(refs)) {
      if (!ids.has(id)) staticMissing.push(id);
    }
  }

  // ---- 运行时检查：DOM 桩环境里执行全部内联脚本 ----
  const docCache = {};
  const document = {
    getElementById(id) {
      if (!ids.has(id)) return null; // 不存在的元素 → 访问属性会抛 TypeError，被下方捕获
      if (!docCache[id]) docCache[id] = makeEl();
      return docCache[id];
    },
    querySelectorAll() { return []; },
    querySelector() { return null; },
    createElement() { return makeEl(); },
    body: makeEl(),
  };

  const bindErrors = [];
  const sandbox = {};
  vm.createContext(sandbox);
  for (const k of Object.getOwnPropertyNames(globalThis)) {
    if (!(k in sandbox)) sandbox[k] = globalThis[k];
  }
  sandbox.document = document;
  sandbox.window = sandbox;
  sandbox.location = {
    search: '', pathname: '/' + file, origin: 'http://127.0.0.1:8080',
    href: 'http://127.0.0.1:8080/' + file, reload() {},
  };
  sandbox.localStorage = { getItem() { return null; }, setItem() {}, removeItem() {} };
  sandbox.alert = () => {};
  sandbox.prompt = () => null;
  sandbox.confirm = () => true;
  sandbox.console = {
    log() {}, warn() {},
    error(...args) {
      const msg = args.map(a => String(a)).join(' ');
      if (msg.includes('[bind]')) bindErrors.push(msg);
    },
  };
  sandbox.fetch = () => Promise.resolve({
    json: () => Promise.resolve({ success: false, msg: 'stub', data: [], questions: [], survey: {}, stats: {} }),
    ok: true, status: 200,
  });
  sandbox.Swal = { fire() {}, close() {}, showLoading() {} };

  let threw = null;
  try {
    for (const code of scripts) {
      vm.runInContext(code, sandbox, { filename: file + ':inline-script' });
    }
  } catch (e) {
    threw = e;
  }

  // ---- 汇总 ----
  const problems = [];
  for (const id of staticMissing) problems.push('静态: 引用了不存在的元素 #' + id);
  for (const e of bindErrors) problems.push('运行时: ' + e);
  if (threw) {
    const loc = (threw.stack || '').split('\n')[1] || '';
    problems.push('运行时: ' + threw.name + ': ' + threw.message + '  @ ' + loc.trim());
  }

  if (problems.length === 0) {
    console.log('PASS  ' + file + '  脚本完整执行，无未定义引用、无空元素访问');
  } else {
    failed = true;
    console.error('FAIL  ' + file);
    for (const p of problems) console.error('      ' + p);
  }
}

for (const p of pages) runPage(p);
process.exit(failed ? 1 : 0);
