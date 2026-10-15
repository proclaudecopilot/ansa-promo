const { chromium } = require('playwright');
(async () => {
  const S = process.argv[2]; // папка с harness/index.html и shots/
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' }).catch(async e => { console.log('fallback launch', e.message.split('\n')[0]); return chromium.launch(); });
  const errors = [];
  async function run(w, h, tag) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1, isMobile: w < 500, hasTouch: w < 500 });
    const page = await ctx.newPage();
    await page.route(/fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    page.on('pageerror', e => errors.push(tag + ': ' + e.message));
    page.on('console', m => { if (m.type() === 'error') errors.push(tag + ' console: ' + m.text()); });
    await page.goto('file://' + S + '/harness/index.html', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(500);
    const shots = [];
    async function shot(name) { await page.waitForTimeout(250); const f = S + '/shots/' + tag + '-' + name + '.png'; await page.screenshot({ path: f, fullPage: false }); shots.push(name); }
    async function check(name) {
      const t = await page.evaluate(() => document.body.innerText + ' ' + Array.from(document.querySelectorAll('[placeholder]')).map(x => x.placeholder).join(' '));
      const left = (t.match(/\{\{[^}]*\}\}/g) || []);
      if (left.length) errors.push(tag + ' ' + name + ': непопълнени плейсхолдъри ' + JSON.stringify(left.slice(0, 5)));
      if (/undefined|NaN|\[object/.test(t)) errors.push(tag + ' ' + name + ': undefined/NaN в текста: ' + (t.match(/.{0,30}(undefined|NaN|\[object).{0,30}/) || [''])[0]);
    }
    await shot('gate'); await check('gate');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'boxes', utm: 'sakura' })); await shot('boxes'); await check('boxes');
    await page.screenshot({ path: S + '/shots/' + tag + '-boxes-full.png', fullPage: true });
    await page.click('.fabu[data-fab="tix"] .yfabb'); await shot('yfab'); await check('yfab'); await page.click('.fabu[data-fab="tix"] [data-fx].cta');
    await page.click('.fabu[data-fab="cosm1"] .yfabb'); await shot('cfab'); await check('cfab'); await page.click('.fabu[data-fab="cosm1"] [data-fx].cta');
    if (w < 500) { await page.click('[data-more-box="l"]'); await shot('boxes-open'); }
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'fill', box: 'l', utm: 'sakura' })); await shot('fill'); await check('fill');
    { const ok = await page.$('#okBtn'); if (ok) { await ok.click(); await page.waitForTimeout(200); await shot('fill-ok'); } }
    // add 4 more via steppers on mobile/desktop
    await page.evaluate(() => { const d = document.getElementById('apDc'); for (let i = 0; i < 4; i++) { const b = d.querySelector('[data-inc="meno"]:not([disabled])') || d.querySelector('[data-inc]:not([disabled])'); if (b) b.click(); } });
    // v1.0.39: въпросникът „Не си сигурна какво ти трябва?“ → избор на 2 проблема → подредба с етикет „според теб“
    await page.click('#fQuiz'); await shot('quiz'); await check('quiz');
    await page.evaluate(() => { ['thyro', 'berber'].forEach(k => { const i = document.querySelector('.fqz input[value="' + k + '"]'); if (i) { i.click(); } }); });
    await shot('quiz-picked');
    await page.click('#fqGo'); await page.waitForTimeout(300); await shot('fill-quiz'); await check('fill-quiz');
    { const o = await page.evaluate(() => Array.from(document.querySelectorAll('#apDc [data-nq]')).map(x => x.dataset.nq).join(',')); if (!/^sakura,(thyro,berber|berber,thyro)/.test(o)) errors.push(tag + ' fill-quiz: подредбата е ' + o); }
    await page.evaluate(() => { const n = document.querySelector('#apDc .mpgb.nx'); if (n) n.click(); }); await shot('fill-quiz-p2');
    await page.evaluate(() => { const n = document.querySelector('#apDc .mpgb.pv'); if (n) n.click(); });
    await shot('fill-done');
    await page.click('#nFill'); await page.waitForTimeout(600); await shot('celeb'); await check('celeb');
    await page.click('#cGo'); await shot('order'); await check('order');
    { const f = await page.$('#finish'); if (f && await f.isVisible()) { await f.click(); await page.waitForTimeout(200); await shot('order-toast'); } } // v1.5.1: без екран „Поръчка“ — бутонът може да липсва
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'boxes', utm: null })); await shot('boxes-noutm'); await check('boxes-noutm');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'fill', box: 'm', utm: null })); await check('fill-noutm');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'celeb', box: 's', utm: 'sakura', full: true })); await shot('celeb-small'); await check('celeb-small');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'builder', box: 'm', utm: 'sakura' })); await shot('builder'); await check('builder');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'sure', box: 'm', utm: 'sakura', full: true })); await shot('sure'); await check('sure');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'switch', box: 'l', utm: 'sakura', full: true })); await check('switch');
    await page.evaluate(() => { document.querySelector('.bx.s').click(); }); await page.waitForTimeout(200); await shot('suredown'); await check('suredown');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'product', utm: 'sakura' })); await shot('product'); await check('product');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'how' })); await shot('how'); await check('how'); // v1.7.0: цялата страница на сезона в попъпа
    { const ov = await page.evaluate(() => { const e = document.querySelector('#apDc > .ib'); return e ? e.scrollWidth - e.clientWidth : 0; }); if (ov > 1) errors.push(tag + ' how: хоризонтален скрол ' + ov + 'px'); }
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'picker', box: 'l', utm: 'sakura', full: false })); await shot('picker'); await check('picker');
    // v1.10.0: кутията расте с броя — малка + 3 опаковки = средна, + 5 = голяма, 7 = пак голяма (−40%); редът „Още N опаковки и отключваш …“
    const boxId = () => page.evaluate(() => { const s = AnsaPromo.state(); return s.box == null ? null : window.AnsaPromoRuntime.boxes[s.box].id; });
    const incN = (n) => page.evaluate((n) => { const d = document.getElementById('apDc'); for (let i = 0; i < n; i++) { const b = d.querySelector('[data-inc="meno"]:not([disabled])') || d.querySelector('[data-inc]:not([disabled])'); if (b) b.click(); } }, n);
    const nextTxt = () => page.evaluate(() => { const e = document.getElementById('fNext'); return e && e.style.display !== 'none' ? e.innerText : ''; });
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'fill', box: 's', utm: 'sakura' }));
    { const ok = await page.$('#okBtn'); if (ok && await ok.isVisible()) { await ok.click(); await page.waitForTimeout(200); } }
    await shot('grow-s'); await check('grow-s');
    if (await boxId() !== 's') errors.push(tag + ' grow-s: с 1 опаковка кутията трябва да е s, а е ' + await boxId());
    { const t = await nextTxt(); if (!/Още\s+2\s+опаковки.*средна кутия.*30/i.test(t)) errors.push(tag + ' grow-s: няма ред „Още 2 опаковки … средна кутия с −30%“: ' + JSON.stringify(t)); }
    await incN(2); await page.waitForTimeout(300); await shot('grow-m'); await check('grow-m');
    if (await boxId() !== 'm') errors.push(tag + ' grow-m: с 3 опаковки кутията трябва да е m, а е ' + await boxId());
    { const t = await nextTxt(); if (!/Още\s+2\s+опаковки.*голяма кутия.*40/i.test(t)) errors.push(tag + ' grow-m: няма ред „Още 2 опаковки … голяма кутия с −40%“: ' + JSON.stringify(t)); }
    { const q = await page.evaluate(() => Array.from(document.querySelectorAll('#apDc [data-q]')).map(x => x.dataset.q + '=' + x.textContent).join(',')); if (!/sakura=1/.test(q) || !/meno=2/.test(q)) errors.push(tag + ' grow-m: количествата не се пазят при вдигане: ' + q); }
    await incN(2); await page.waitForTimeout(300); await shot('grow-l'); await check('grow-l');
    if (await boxId() !== 'l') errors.push(tag + ' grow-l: с 5 опаковки кутията трябва да е l, а е ' + await boxId());
    await incN(2); await page.waitForTimeout(300); await shot('grow-l7'); await check('grow-l7');
    if (await boxId() !== 'l') errors.push(tag + ' grow-l7: със 7 опаковки кутията трябва да остане l');
    { const t = await nextTxt(); if (!/40/.test(t)) errors.push(tag + ' grow-l7: липсва „най-голямата отстъпка −40%“: ' + JSON.stringify(t)); }
    { const d = await page.evaluate(() => ({ btn: document.getElementById('nFill').disabled, inc: !!document.querySelector('#apDc [data-inc]:not([disabled])') })); if (d.btn) errors.push(tag + ' grow-l7: „Продължи“ е заключен при 7 опаковки'); if (!d.inc) errors.push(tag + ' grow-l7: степерите са заключени под тавана'); }
    await page.click('#nFill'); await page.waitForTimeout(600); await shot('grow-celeb'); await check('grow-celeb');
    { const n = await page.evaluate(() => AnsaPromo.state().slots.filter(Boolean).length); if (n !== 7) errors.push(tag + ' grow-celeb: очаквах 7 опаковки, има ' + n); }
    { const t = await page.evaluate(() => document.getElementById('apDc').innerText); if (!/7\s+опаковки/.test(t)) errors.push(tag + ' grow-celeb: „7 опаковки“ липсва в честито'); if (/undefined/.test(t)) errors.push(tag + ' grow-celeb: undefined'); }
    // таблото: малка (1) → „＋ Добави още опаковки“ → селектор → 2 (още s) → 3 = средна (честито) → махни една → пак малка
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'builder', box: 's', utm: 'sakura', full: true })); await shot('grow-builder'); await check('grow-builder');
    await page.click('#morePick'); await page.waitForTimeout(200); await shot('grow-picker'); await check('grow-picker');
    await page.click('#apDc [data-add="meno"]'); await page.waitForTimeout(250);
    if (await boxId() !== 's') errors.push(tag + ' grow-builder: с 2 опаковки още трябва да е s, а е ' + await boxId());
    await page.click('#apDc [data-add="meno"]'); await page.waitForTimeout(600); await shot('grow-builder-celeb'); await check('grow-builder-celeb');
    if (await boxId() !== 'm') errors.push(tag + ' grow-builder: с 3 опаковки трябва да е m, а е ' + await boxId());
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'builder' })); await page.waitForTimeout(200);
    await page.click('#apS2 [data-si="2"]'); await page.waitForTimeout(250); await shot('grow-down'); await check('grow-down');
    if (await boxId() !== 's') errors.push(tag + ' grow-down: след махане на 3-тата опаковка трябва да е пак s, а е ' + await boxId());
    { const t = await page.evaluate(() => document.getElementById('apToast').innerText); if (!/малка кутия/i.test(t)) errors.push(tag + ' grow-down: няма тост „Вече си на малка кутия“: ' + JSON.stringify(t)); }
    console.log(tag, 'shots:', shots.join(' '));
    await ctx.close();
  }
  await run(390, 780, 'm390');
  await run(1280, 900, 'd1280');
  await browser.close();
  console.log(errors.length ? 'ERRORS:\n' + errors.join('\n') : 'no errors');
})();
