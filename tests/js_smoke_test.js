/**
 * JS 冒烟测试（Node.js）
 * ---------------------------------------------------------------------------
 * 三层检查，专门拦截"只有浏览器才会暴露、接口验收测不出"的前端错误：
 *
 *   1. 静态检查  —— JS 里所有 getElementById('字面量') 必须在 HTML 里有对应 id
 *   2. 初始化执行 —— 把页面 <script> 放进最小 DOM 桩环境完整执行，
 *                   捕获 ReferenceError（引用未定义函数）与空元素访问
 *   3. UI 流程模拟（仅 index.html）——
 *                   模拟真实点击："添加问题"(prompt 序列) → "保存问卷" → "发布问卷"，
 *                   并断言发出的 fetch 请求 URL 与请求体内容正确
 *
 * 用法：node tests/js_smoke_test.js           （默认 index.html + fill.html）
 *       node tests/js_smoke_test.js <file>    （只测指定文件，路径相对仓库根目录）
 * 退出码：0 = 全部通过；1 = 有失败
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const pages = process.argv.length > 2
  ? process.argv.slice(2)
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
    handlers: {},
    classList: { add() {}, remove() {}, toggle() {} },
    addEventListener(type, fn) { this.handlers[type] = fn; },
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

const sleep = ms => new Promise(r => setTimeout(r, ms));

async function runPage(file) {
  const html = fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
  const ids = new Set([...html.matchAll(/\bid="([^"]+)"/g)].map(m => m[1]));
  const scripts = [...html.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)]
    .map(m => m[1]).filter(s => s.trim());

  // ---- 1) 静态检查 ----
  const staticMissing = [];
  for (const code of scripts) {
    const refs = [...code.matchAll(/getElementById\(\s*['"]([^'"]+)['"]\s*\)/g)].map(m => m[1]);
    for (const id of new Set(refs)) {
      if (!ids.has(id)) staticMissing.push(id);
    }
  }

  // ---- 1b) 样式依赖检查：用了 Swal 就必须引入它的样式 ----
  // 教训：sweetalert2.min.js 是「只有 JS、不含 CSS」的构建产物，
  // 只引它会导致弹窗完全没有样式（跑到角落、内容被裁切）。
  const styleMissing = [];
  if (/\bSwal\s*\./.test(scripts.join('\n'))) {
    const hasSwalCss =
      /<link[^>]+href=["'][^"']*sweetalert2[^"']*\.css["']/i.test(html) ||
      /sweetalert2\.all(\.min)?\.js/i.test(html);
    if (!hasSwalCss) {
      styleMissing.push('页面使用了 Swal，但未引入 sweetalert2 的 CSS（或 all 版 JS），弹窗会完全错位');
    }
  }

  // ---- 2) 运行时执行 ----
  const docCache = {};
  const document = {
    getElementById(id) {
      if (!ids.has(id)) return null;
      if (!docCache[id]) docCache[id] = makeEl();
      return docCache[id];
    },
    querySelectorAll() { return []; },
    querySelector() { return null; },
    createElement() { return makeEl(); },
    body: makeEl(),
  };

  const bindErrors = [];
  const calls = [];   // 捕获 fetch 调用：{url, opts}
  const promptQueue = [];
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
  sandbox.prompt = () => (promptQueue.length ? promptQueue.shift() : null);
  sandbox.confirm = () => true;
  sandbox.console = {
    log() {}, warn() {},
    error(...args) {
      const msg = args.map(a => String(a)).join(' ');
      if (msg.includes('[bind]')) bindErrors.push(msg);
    },
  };
  sandbox.fetch = (url, opts) => {
    calls.push({ url: String(url), opts: opts || {} });
    return Promise.resolve({
      json: () => Promise.resolve({ success: true, msg: 'stub-ok', data: [], questions: [], survey: {}, stats: { total_responses: 0 } }),
      ok: true, status: 200,
    });
  };
  sandbox.Swal = { fire() {}, close() {}, showLoading() {} };

  let threw = null;
  try {
    for (const code of scripts) vm.runInContext(code, sandbox, { filename: file + ':inline-script' });
  } catch (e) {
    threw = e;
  }

  const problems = [];
  for (const id of staticMissing) problems.push('静态: 引用了不存在的元素 #' + id);
  for (const s of styleMissing) problems.push('样式: ' + s);
  for (const e of bindErrors) problems.push('运行时: ' + e);
  if (threw) {
    const loc = (threw.stack || '').split('\n')[1] || '';
    problems.push('运行时: ' + threw.name + ': ' + threw.message + '  @ ' + loc.trim());
  }

  // ---- 3) UI 流程模拟（仅 index.html）----
  if (file === 'index.html' && problems.length === 0) {
    const ui = await simulateUiFlow(document, promptQueue, calls);
    problems.push(...ui);
  }

  if (problems.length === 0) {
    console.log('PASS  ' + file + '  （静态 + 初始化 + UI 流程三层检查通过）');
  } else {
    failed = true;
    console.error('FAIL  ' + file);
    for (const p of problems) console.error('      ' + p);
  }
}

async function simulateUiFlow(document, promptQueue, calls) {
  const problems = [];

  // 脚本化 prompt 序列：类型=1(单选) → 题目 → 选项数=2 → 两个选项
  promptQueue.push('1', '你对系统满意吗？', '2', '满意', '不满意');

  const titleEl = document.getElementById('survey-title');
  const descEl = document.getElementById('survey-description');
  if (!titleEl || !descEl) {
    return ['UI: 缺少问卷标题/描述输入框'];
  }
  titleEl.value = 'UI 冒烟测试问卷';
  descEl.value = '由 js_smoke_test 自动生成';

  // 点击「添加问题」
  const addBtn = document.getElementById('add-question-btn');
  if (!addBtn || typeof addBtn.handlers.click !== 'function') {
    return ['UI: 「添加问题」按钮没有绑定事件'];
  }
  addBtn.handlers.click();

  // 点击「保存问卷」
  const saveBtn = document.getElementById('save-survey');
  if (!saveBtn || typeof saveBtn.handlers.click !== 'function') {
    return ['UI: 「保存问卷」按钮没有绑定事件'];
  }
  saveBtn.handlers.click();
  await sleep(50);

  const saveCall = calls.find(c => c.url.includes('create_survey.php'));
  if (!saveCall) {
    problems.push('UI: 点击「保存问卷」后没有发出 create_survey 请求');
  } else {
    try {
      const body = JSON.parse(saveCall.opts.body);
      if (body.title !== 'UI 冒烟测试问卷') problems.push('UI: 保存请求的标题不正确: ' + body.title);
      if (!Array.isArray(body.questions) || body.questions.length !== 1) {
        problems.push('UI: 保存请求的问题数量应为 1');
      } else {
        const q = body.questions[0];
        if (q.type !== 'radio') problems.push('UI: 题型应为 radio，实际 ' + q.type);
        if (q.title !== '你对系统满意吗？') problems.push('UI: 题目文本不正确');
        if (!Array.isArray(q.options) || q.options.length !== 2) {
          problems.push('UI: 选项应为 2 个字符串');
        } else if (q.options.join('/') !== '满意/不满意') {
          problems.push('UI: 选项内容不正确: ' + q.options.join('/'));
        }
      }
    } catch (e) {
      problems.push('UI: 保存请求体不是合法 JSON: ' + e.message);
    }
  }

  // 点击「发布问卷」（confirm 桩返回 true）
  const pubBtn = document.getElementById('publish-survey');
  if (!pubBtn || typeof pubBtn.handlers.click !== 'function') {
    problems.push('UI: 「发布问卷」按钮没有绑定事件');
  } else {
    pubBtn.handlers.click();
    await sleep(50);
    const pubCall = calls.find(c => {
      if (!c.url.includes('create_survey.php')) return false;
      try { return JSON.parse(c.opts.body).status === 'published'; } catch (e) { return false; }
    });
    if (!pubCall) {
      problems.push('UI: 点击「发布问卷」后没有发出 status=published 的 create_survey 请求');
    }
  }

  return problems.map(p => 'UI: ' + p);
}

(async () => {
  for (const p of pages) {
    await runPage(p);
  }
  process.exit(failed ? 1 : 0);
})();
