/* v1.24.0: кацанията на живо и в редактора (Playwright, Chromium в /opt/pw-browsers).
 * python3 tests/harness/build.py OUTDIR && NODE_PATH=$(npm root -g) node tests/harness/landing.js OUTDIR
 * Проверява ЕДНАТА резолюция (кацане → продукт → непознато) от адреса, потока на кацането (gate → кутията сама → know → quiz/fill),
 * overlay-а на текстовете, ?fresh=1 и сценария {landing} на редактора. */
const { chromium } = require('playwright');
(async () => {
  const S = process.argv[2];
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' }).catch(async e => { console.log('fallback launch', e.message.split('\n')[0]); return chromium.launch(); });
  const errors = []; let n = 0;
  const ok = (name, cond, info) => { n++; if (!cond) errors.push(name + (info ? ' — ' + info : '')); };
  async function open(ctx, qs, tag) {
    const page = await ctx.newPage();
    page.on('pageerror', e => errors.push(tag + ' pageerror: ' + e.message));
    page.on('console', m => { if (m.type() === 'error') errors.push(tag + ' console: ' + m.text()); });
    await page.route(/fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    await page.goto('file://' + S + '/harness/index.html' + qs, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(700);
    return page;
  }
  const text = p => p.evaluate(() => document.body.innerText);
  const st = p => p.evaluate(() => { const s = AnsaPromo.state(); const L = AnsaPromo.landing(); return { box: s.box, slots: s.slots, core: s.core, utm: s.utm, know: s.know, emailDone: s.emailDone, landing: L ? L.slug : null, boxId: s.box === null ? null : window.AnsaPromoRuntime.boxes[s.box].id }; });
  async function run(w, h, tag) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1, isMobile: w < 500, hasTouch: w < 500 });
    /* 1. козметика: без основен продукт, голяма кутия сама, направо въпросникът, overlay на попъпа */
    let p = await open(ctx, '?utm_content=kozmetika&fresh=1', tag + ' kozmetika');
    let t = await text(p); let s = await st(p);
    ok(tag + ' kozmetika: резолюция = кацане', s.landing === 'kozmetika' && s.utm === 'kozmetika' && s.core === null, JSON.stringify(s));
    ok(tag + ' kozmetika: попъпът носи override-а', /Козметиката на ansa/.test(t) && /Сетът е в Голямата кутия/.test(t), t.slice(0, 200));
    ok(tag + ' kozmetika: без {{ }}', !/\{\{[^}]*\}\}/.test(t));
    await p.screenshot({ path: S + '/shots/' + tag + '-landing-kozmetika-gate.png' });
    await p.click('#gSkip'); await p.waitForTimeout(900);
    s = await st(p); t = await text(p);
    ok(tag + ' kozmetika: след попъпа голямата кутия е избрана сама', s.boxId === 'l', JSON.stringify(s));
    ok(tag + ' kozmetika: know=no → направо въпросникът', s.know === 'no' && /Кое от следното те касае/.test(t), (t.match(/.{0,40}касае.{0,40}/) || [''])[0]);
    ok(tag + ' kozmetika: въпросникът е отворен, не „Запозната ли си“', !/Запозната ли си с продуктите/.test(t));
    await p.screenshot({ path: S + '/shots/' + tag + '-landing-kozmetika-quiz.png' });
    await p.close();
    /* 2. грийнс: основен продукт в слот 1, средна кутия сама, без имейл-попъп, know=yes → направо пълненето с overlay на заглавието */
    p = await open(ctx, '?utm_content=greens&fresh=1', tag + ' greens');
    await p.waitForTimeout(600); s = await st(p); t = await text(p);
    ok(tag + ' greens: кацането печели над продукта и носи core', s.landing === 'greens' && s.core === 'greens' && s.utm === 'greens', JSON.stringify(s));
    ok(tag + ' greens: без имейл-попъп', s.emailDone === true && !(await p.$('.gate')));
    ok(tag + ' greens: средната кутия е избрана сама, грийнс е в слот 1', s.boxId === 'm' && s.slots[0] === 'greens', JSON.stringify(s));
    ok(tag + ' greens: know=yes → пълненето с override на заглавието', s.know === 'yes' && /Сложили сме ти една опаковка/.test(t) && !/Кое от следното те касае/.test(t), (t.match(/.{0,60}опаковка.{0,60}/) || [''])[0]);
    ok(tag + ' greens: без {{ }}', !/\{\{[^}]*\}\}/.test(t));
    await p.screenshot({ path: S + '/shots/' + tag + '-landing-greens-fill.png' });
    await p.close();
    /* 3. продукт без кацане = имплицитно кацане (както досега) */
    p = await open(ctx, '?utm_content=sakura&fresh=1', tag + ' sakura');
    s = await st(p); t = await text(p);
    ok(tag + ' sakura: имплицитно кацане от продукт', s.landing === null && s.core === 'sakura' && s.utm === 'sakura' && /Ти избра/.test(t), JSON.stringify(s));
    await p.close();
    /* 4. непозната стойност → основа (и „view“ би носил unk) */
    p = await open(ctx, '?utm_content=kozmetica&fresh=1', tag + ' unknown');
    s = await st(p); t = await text(p);
    ok(tag + ' kozmetica: непознато → основа', s.landing === null && s.core === null && s.utm === null && !/Козметиката на ansa/.test(t), JSON.stringify(s));
    await p.close();
    /* 5. запомнено кацане без параметър в адреса (връщане от чекаута): текстовете остават */
    p = await open(ctx, '?utm_content=kozmetika&fresh=1', tag + ' remember');
    await p.click('#gSkip'); await p.waitForTimeout(600);
    await p.goto('file://' + S + '/harness/index.html', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(700);
    s = await st(p);
    ok(tag + ' remember: кацането се възстановява от сесията', s.landing === 'kozmetika' && s.utm === 'kozmetika' && s.boxId === 'l', JSON.stringify(s));
    /* 6. ново кацане в адреса с избрана кутия: напредъкът се пази, текстовете са на новото */
    await p.goto('file://' + S + '/harness/index.html?utm_content=greens', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(700);
    s = await st(p);
    ok(tag + ' switch: с избрана кутия напредъкът остава (l), кацането е greens', s.landing === 'greens' && s.utm === 'greens' && s.boxId === 'l', JSON.stringify(s));
    await p.close();
    /* 7. сценарият на редактора: {landing} + lcopy на живо */
    p = await open(ctx, '?fresh=1', tag + ' editor');
    await p.evaluate(() => AnsaPromo.scenario({ landing: 'kozmetika', screen: 'gate' })); await p.waitForTimeout(400);
    t = await text(p); s = await st(p);
    ok(tag + ' scenario landing gate', s.landing === 'kozmetika' && /Козметиката на ansa/.test(t));
    await p.evaluate(() => AnsaPromo.scenario({ landing: 'greens', screen: 'fill' })); await p.waitForTimeout(400);
    t = await text(p); s = await st(p);
    ok(tag + ' scenario landing fill', s.landing === 'greens' && s.boxId === 'm' && s.slots[0] === 'greens' && /Сложили сме ти една опаковка/.test(t), JSON.stringify(s));
    await p.evaluate(() => AnsaPromo.scenario({ landing: 'kozmetika', screen: 'quiz' })); await p.waitForTimeout(400);
    t = await text(p); ok(tag + ' scenario quiz', /Кое от следното те касае/.test(t));
    await p.evaluate(() => AnsaPromo.scenario({ landing: 'kozmetika', screen: 'know' })); await p.waitForTimeout(400);
    t = await text(p); ok(tag + ' scenario know', /Запозната ли си с продуктите/.test(t));
    await p.evaluate(() => { AnsaPromo.landingFor('kozmetika').copy['know.t'] = 'ТЕСТ LCOPY'; }); /* = обработката на {ansaPromo:'lcopy'} (само при A.editor) */
    await p.evaluate(() => AnsaPromo.scenario({ landing: 'kozmetika', screen: 'know' })); await p.waitForTimeout(300);
    t = await text(p); ok(tag + ' lcopy на живо', /ТЕСТ LCOPY/.test(t));
    await p.evaluate(() => AnsaPromo.scenario({ landing: null, screen: 'know', box: 'l' })); await p.waitForTimeout(300);
    t = await text(p); ok(tag + ' основа след кацане: без override', /Запозната ли си с продуктите/.test(t) && !/ТЕСТ LCOPY/.test(t));
    await p.close();
    await ctx.close();
  }
  await run(390, 780, 'm390');
  await run(1280, 900, 'd1280');
  await browser.close();
  console.log(errors.length ? 'ERRORS (' + errors.length + ' от ' + n + '):\n' + errors.join('\n') : 'OK ' + n + ' проверки');
  process.exit(errors.length ? 1 : 0);
})();
