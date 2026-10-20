/* v1.24.0: статичен тест на редактора без WP — контекстът (Основа / кацане), лентата на потока, стълбата, заключените групи,
 * картите на кацанията, генерираният линк и „Провери линк“. Канвасът е about:blank (postMessage отива в нищото).
 * NODE_PATH=$(npm root -g) node tests/harness/editor.js OUTDIR  (OUTDIR = папка за shots/) */
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const path = require('path'), fs = require('fs');
(async () => {
  const S = process.argv[2] || '/tmp/ansa-promo-editor';
  fs.mkdirSync(S + '/shots', { recursive: true });
  const root = path.resolve(__dirname, '../..');
  const D = execSync('php ' + root + '/tests/harness/editor-boot.php').toString();
  const html = '<!doctype html><html lang="bg"><head><meta charset="utf-8"><link rel="stylesheet" href="file://' + root + '/assets/editor.css"></head><body><div id="apEd" class="ap-ed"><div class="ap-ed-top" id="apEdTop"></div><div class="ap-ed-body"><aside class="ap-ed-rail" id="apEdRail"></aside><main class="ap-ed-canvas" id="apEdCanvas"></main><aside class="ap-ed-insp" id="apEdInsp"></aside></div><div class="ap-ed-toast" id="apEdToast" hidden></div></div>'
    + '<script>window.AnsaPromoEditor=' + D + ';window.AnsaPromoEditor.page="about:blank";</script><script src="file://' + root + '/assets/editor.js"></script></body></html>';
  fs.writeFileSync(S + '/editor.html', html);
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' }).catch(async e => { console.log('fallback launch', e.message.split('\n')[0]); return chromium.launch(); });
  const errors = []; let n = 0; const ok = (name, cond, info) => { n++; if (!cond) errors.push(name + (info ? ' — ' + info : '')); };
  const ctx = await browser.newContext({ viewport: { width: 1500, height: 900 } });
  const p = await ctx.newPage();
  p.on('pageerror', e => errors.push('pageerror: ' + e.message)); p.on('console', m => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
  p.on('dialog', d => d.accept());
  await p.goto('file://' + S + '/editor.html', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(600);
  const txt = sel => p.evaluate(s => (document.querySelector(s) || { innerText: '' }).innerText, sel);
  const app = () => p.evaluate(() => ({ ctx: window.AnsaPromoEditorApp.ctx(), landings: window.AnsaPromoEditorApp.cfg.landings.map(l => ({ slug: l.slug, copy: l.copy })) }));
  /* 1. основа */
  ok('top: селекторът на контекста има Основа + 2 кацания', await p.evaluate(() => document.querySelectorAll('#apCtx option').length) === 3);
  ok('top: жълт пил за непознати стойности', /непознати utm_content/.test(await txt('#apEdTop')));
  ok('rail: 🎯 Кацания с брояч', /Кацания \(реклами\)/.test(await txt('#apEdRail')));
  ok('canvas в Основа: лентата е „симулация“', /симулация/.test(await txt('#apScenBar')));
  /* 2. 🎯 Кацания */
  await p.click('[data-mode="landings"]'); await p.waitForTimeout(200);
  let t = await txt('#apEdInsp');
  ok('кацания: списъкът', /Козметика/.test(t) && /Грийнс/.test(t) && /utm_content=kozmetika/.test(t));
  ok('кацания: непознатите с подсказка', /kozmetica/.test(t) && /≈ kozmetika/.test(t));
  ok('кацания: автоматичните от продукт', /Автоматични кацания/.test(t) && /Направи го кацане/.test(t));
  ok('кацания: статистика 7д', /12 пос\. \/ 2 пор\./.test(t));
  await p.click('[data-lopen="kozmetika"]'); await p.waitForTimeout(200); t = await txt('#apEdInsp');
  ok('карта: потокът', /Имейл-попъп → Въпросникът → Напълни кутията → Честито/.test(t), (t.match(/Какво преживява.{0,120}/) || [''])[0]);
  ok('карта: slug е заключен (публикуван)', await p.evaluate(() => document.querySelector('[data-lf="slug"]').disabled) === true);
  const link = await p.evaluate(() => document.getElementById('apLUrl').value);
  ok('карта: генериран линк', link === 'https://ansa.bg/sezonut-na-ansa/?utm_content=kozmetika', link);
  await p.fill('[data-lx="utm_source"]', 'facebook'); await p.waitForTimeout(100);
  ok('карта: линкът с utm_source', /utm_source=facebook/.test(await p.evaluate(() => document.getElementById('apLUrl').value)));
  ok('карта: „Пробвай като клиент“ = чернова + чиста сесия', /ansa_promo=draft&utm_content=kozmetika&fresh=1/.test(await p.evaluate(() => document.querySelector('a[href*="fresh=1"]').getAttribute('href'))));
  await p.screenshot({ path: S + '/shots/editor-landings.png' });
  /* 3. проверка на линк (същата резолюция) */
  const chk = u => p.evaluate(u => window.AnsaPromoEditorApp.checkLink(u), u);
  let r = await chk('https://ansa.bg/sezonut-na-ansa/?utm_content=kozmetica');
  ok('провери линк: непознато + „Имаш предвид“', !r.ok && r.lines.some(l => /непознато/.test(l) && /kozmetika/.test(l)), JSON.stringify(r));
  r = await chk('https://ansa.bg/sezonut-na-ansa/?utm_source=fb&utm_content=kozmetika');
  ok('провери линк: кацане ✓', r.ok && r.lines.some(l => /✓ → 🎯 Козметика/.test(l)), JSON.stringify(r));
  r = await chk('https://ansa.bg/sezonut-na-ansa/?utm_content=greens');
  ok('провери линк: непубликувано кацане предупреждава', r.lines.some(l => /не е публикувано/.test(l)), JSON.stringify(r));
  r = await chk('https://ansa.bg/sezonut-na-ansa/?utm_content=sakura');
  ok('провери линк: продукт = автоматично кацане', r.lines.some(l => /продукт/.test(l) && /Sakura/i.test(l)), JSON.stringify(r));
  r = await chk('https://ansa.bg/drug/?utm_content=kozmetika');
  ok('провери линк: друга страница ⚠', r.lines.some(l => /не е промо страницата/.test(l)), JSON.stringify(r));
  r = await chk('https://ansa.bg/sezonut-na-ansa/');
  ok('провери линк: без параметър → Основа', r.lines.some(l => /Основа: няма utm_content/.test(l)), JSON.stringify(r));
  /* 4. контекст „кацане“ */
  await p.selectOption('#apCtx', 'kozmetika'); await p.waitForTimeout(300);
  ok('ctx: класът ctx на рамката', await p.evaluate(() => document.getElementById('apEd').classList.contains('ctx') && document.getElementById('apEdTop').classList.contains('ctx')));
  ok('ctx: лентата на потока', /Въпросникът/.test(await txt('#apScenBar')) && (await p.$$('.fl-step.skip')).length >= 2);
  ok('ctx: заключени групи в рейла', (await p.$$('.ap-ed-rail .rg.lock')).length >= 4);
  await p.evaluate(() => window.AnsaPromoEditorApp.select('gate.title.noutm', false)); await p.waitForTimeout(200); t = await txt('#apEdInsp');
  ok('ctx: стълбата с 3 реда', (await p.$$('.ap-ed-stack .sr')).length === 3 && /Дефолт/.test(t) && /Основа/.test(t) && /Козметика/.test(t));
  ok('ctx: кацането печели', await p.evaluate(() => document.querySelector('.ap-ed-stack .sr.land').classList.contains('win')));
  ok('ctx: статусът — override, непубликуван', /override за кацането/.test(t) && /НЕ е публикуван/.test(t));
  ok('ctx: textarea = override-а', (await p.inputValue('#apTa')) === 'Козметиката за <s>€{{cosm_value}}</s> €{{cosm_pay_l}}');
  await p.fill('#apTa', 'Ново за кацането €129'); await p.waitForTimeout(200);
  let a = await app(); ok('ctx: писането отива в overlay-а на кацането', a.landings[0].copy['gate.title.noutm'] === 'Ново за кацането €129', JSON.stringify(a.landings[0].copy));
  ok('ctx: число до € дава грешка', /число до €/.test(await txt('#apErr')));
  await p.click('#apReset'); await p.waitForTimeout(200); a = await app();
  ok('ctx: „Наследи от основата“ трие override-а', !('gate.title.noutm' in a.landings[0].copy) && (await p.inputValue('#apTa')) === 'ОСНОВА: до −{{max_pct}}%');
  ok('ctx: основата е недокосната', await p.evaluate(() => window.AnsaPromoEditorApp.cfg.copy['gate.title.noutm'] === 'ОСНОВА: до −{{max_pct}}%'));
  const emailKey = await p.evaluate(() => Object.keys(window.AnsaPromoEditor.registry.keys).find(k => window.AnsaPromoEditor.registry.keys[k].g === 'emails'));
  await p.evaluate(k => window.AnsaPromoEditorApp.select(k, false), emailKey); await p.waitForTimeout(200);
  ok('ctx: имейл ключ е 🔒 с бутон към Основа', /🔒/.test(await txt('#apEdInsp')) && !!(await p.$('#apToBase')));
  await p.click('#apToBase'); await p.waitForTimeout(200);
  ok('към Основа: контекстът е празен', (await app()).ctx === '' && !(await p.evaluate(() => document.getElementById('apEd').classList.contains('ctx'))));
  await p.screenshot({ path: S + '/shots/editor-ctx.png' });
  /* 5. ново кацане + защита на slug */
  await p.click('[data-mode="landings"]'); await p.waitForTimeout(200); await p.click('#apLandAdd'); await p.waitForTimeout(200);
  a = await app(); ok('ново кацане', a.landings.length === 3 && a.landings[2].slug === 'novo-kacane', JSON.stringify(a.landings.map(l => l.slug)));
  await p.fill('[data-lf="slug"]', 'greens'); await p.press('[data-lf="slug"]', 'Tab'); await p.waitForTimeout(300);
  a = await app(); ok('slug дубликат се отхвърля', a.landings[2].slug === 'novo-kacane' && /друго кацане/.test(await txt('#apEdToast')));
  await p.click('[data-mk="sakura"]'); await p.waitForTimeout(200); a = await app();
  ok('„Направи го кацане“ = slug на продукта + същият core', a.landings.length === 4 && a.landings[3].slug === 'sakura' && (await p.evaluate(() => window.AnsaPromoEditorApp.cfg.landings[3].core)) === 'sakura');
  await ctx.close(); await browser.close();
  console.log(errors.length ? 'ERRORS (' + errors.length + ' от ' + n + '):\n' + errors.join('\n') : 'OK ' + n + ' проверки');
  process.exit(errors.length ? 1 : 0);
})();
