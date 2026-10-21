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
    // 1.24.7: gate по рекламата — yacht / cosm (general = общият)
    for (const gv of ['yacht', 'cosm']) { await page.evaluate(g => AnsaPromo.scenario({ screen: 'gate', utm: null, gv: g }), gv); await page.waitForTimeout(250); await shot('gate-' + gv); await check('gate-' + gv);
      const h = await page.evaluate(() => (document.querySelector('#apDc .g2hd h3') || {}).textContent || ''); if (!(gv === 'yacht' ? /яхта/ : /козметичен сет/).test(h)) errors.push(tag + ' gate-' + gv + ': заглавието е „' + h + '“');
      if (gv === 'cosm' && !(await page.evaluate(() => document.querySelector('#apDc .g2rw .g2r').classList.contains('gold')))) errors.push(tag + ' gate-cosm: сетът не е първата награда'); }
    // 1.24.12: попъпът за козметиката (?utm_content=cosm): „Искам помощ“ → Голямата кутия с въпросника отпред; „Не искам помощ“ → направо пълненето
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'cosm', utm: 'cosm' })); await page.waitForTimeout(300); await shot('cosm-pop'); await check('cosm-pop');
    if (!(await page.$('#cpYes')) || !(await page.$('#cpNo'))) errors.push(tag + ' cosm-pop: липсват бутоните Искам/Не искам помощ');
    { const t = await page.evaluate(() => document.querySelector('#apDc').innerText); if (!/за 1 евро ще бъде твоя/.test(t) || !/на цената на три/.test(t)) errors.push(tag + ' cosm-pop: текстът не е този на човека: ' + t.slice(0, 120)); }
    await page.click('#cpNo'); await page.waitForTimeout(500); await shot('cosm-direct'); await check('cosm-direct');
    { const st = await page.evaluate(() => { const s = AnsaPromo.state(); return { box: s.box, hasFill: !!(document.querySelector('#fSlotsN') || document.querySelector('#apDc.fpm #mfMore')), quiz: !!document.querySelector('#fqGo') }; }); if (st.box === null || !st.hasFill || st.quiz) errors.push(tag + ' cosm-direct: очаквах Голямата кутия с пълненето без въпросник, а е ' + JSON.stringify(st)); }
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'cosm', utm: 'cosm', box: null })); await page.waitForTimeout(300);
    await page.click('#cpYes'); await page.waitForTimeout(500); await shot('cosm-help'); await check('cosm-help');
    { const st = await page.evaluate(() => { const s = AnsaPromo.state(); return { box: s.box, quiz: !!document.querySelector('#fqGo') }; }); if (st.box === null || !st.quiz) errors.push(tag + ' cosm-help: очаквах Голямата кутия с въпросника отпред, а е ' + JSON.stringify(st)); }
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'gate', utm: 'sakura', gv: null })); await page.waitForTimeout(250);
    if (!/Sakura/.test(await page.evaluate(() => (document.querySelector('#apDc .g2hd h3') || {}).textContent || ''))) errors.push(tag + ' gate-sakura: заглавието не е за продукта');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'boxes', utm: 'sakura' })); await shot('boxes'); await check('boxes');
    await page.screenshot({ path: S + '/shots/' + tag + '-boxes-full.png', fullPage: true });
    // v1.23.1: един плаващ бутон „?“ → попъпът „Как работи играта“
    await page.click('.yfab .yhow'); await shot('yfab'); await check('yfab'); await page.click('#apUx'); await page.waitForTimeout(200);
    // 1.24.4: клик на кутия → направо пълненето (без „Запозната ли си с продуктите?“)
    await page.evaluate(() => document.querySelector('.box.l [data-box]').click()); await page.waitForTimeout(400); await shot('box-fill'); await check('box-fill');
    if (await page.$('#knowNo')) errors.push(tag + ' box-fill: попъпът „Запозната ли си“ още се показва');
    if (!(await page.$('#apDc [data-inc]')) && !(await page.$('#okBtn'))) errors.push(tag + ' box-fill: пълненето не се отвори след клик на кутия');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'boxes', utm: 'sakura' }));
    // 1.23.8: над 5 опаковки плочките са слайдер със стрелки (телефон); до 5 — без слайдер
    if (w < 500) {
      await page.evaluate(() => AnsaPromo.scenario({ screen: 'fill', box: 'l', utm: 'sakura', full: true })); await page.waitForTimeout(300);
      await page.evaluate(() => { const ok = document.getElementById('okBtn'); if (ok) ok.click(); });
      if (await page.$('#fSlots.many')) errors.push(tag + ' slots: слайдер при 5 опаковки (не трябва)');
      await page.evaluate(() => { const d = document.getElementById('apDc'); for (let i = 0; i < 2; i++) { const b = d.querySelector('[data-inc]:not([disabled])'); if (b) b.click(); } }); await page.waitForTimeout(300);
      const sl = await page.evaluate(() => { const t = document.querySelector('#fSlots.many .fstrack'); return t ? { n: t.querySelectorAll('.fs').length, scroll: t.scrollWidth > t.clientWidth, arrows: document.querySelectorAll('#fSlots .fsarr').length } : null; });
      if (!sl || sl.n !== 7 || !sl.scroll || sl.arrows !== 2) errors.push(tag + ' slots: слайдерът при 7 опаковки липсва или не скролва: ' + JSON.stringify(sl));
      await shot('slots-7');
      await page.evaluate(() => AnsaPromo.scenario({ screen: 'boxes', box: null, utm: 'sakura' })); await page.waitForTimeout(200);
    }
    if (w < 500) { await page.click('[data-more-box="l"]'); await shot('boxes-open'); }
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'fill', box: 'l', utm: 'sakura' })); await shot('fill'); await check('fill');
    { const ok = await page.$('#okBtn'); if (ok && await ok.isVisible()) { await ok.click(); await page.waitForTimeout(200); await shot('fill-ok'); } } // 1.23.8: интрото е веднъж на сесия за кутия — може да е скрито
    // add 4 more via steppers on mobile/desktop
    await page.evaluate(() => { const d = document.getElementById('apDc'); for (let i = 0; i < 4; i++) { const b = d.querySelector('[data-inc="meno"]:not([disabled])') || d.querySelector('[data-inc]:not([disabled])'); if (b) b.click(); } });
    // v1.0.39: въпросникът „Не си сигурна какво ти трябва?“ → избор на 2 проблема → подредба с етикет „според теб“
    await page.click('#fQuiz'); await shot('quiz'); await check('quiz');
    // 1.24.5: с UTM продукт — „Продължи“ е неактивен с 0, активен от 1; редът на продукта липсва
    if (!(await page.evaluate(() => document.getElementById('fqGo').disabled))) errors.push(tag + ' quiz: „Продължи“ е активен без избор');
    await page.evaluate(() => { const i = document.querySelector('.fqz input[value="thyro"]'); if (i) i.click(); });
    if (await page.evaluate(() => document.getElementById('fqGo').disabled)) errors.push(tag + ' quiz: „Продължи“ е неактивен с 1 избор (с UTM продукт един стига)');
    if (await page.$('.fqz input[value="sakura"]')) errors.push(tag + ' quiz: редът на UTM продукта (Sakura) е във въпросника');
    if (!/Sakura е перфектен избор/.test(await page.evaluate(() => document.querySelector('.fqz h3').textContent))) errors.push(tag + ' quiz: заглавието с UTM не е „Sakura е перфектен избор…“');
    await shot('quiz-one');
    await page.evaluate(() => { const i = document.querySelector('.fqz input[value="berber"]'); if (i) i.click(); });
    if (await page.evaluate(() => document.getElementById('fqGo').disabled)) errors.push(tag + ' quiz: „Продължи“ е неактивен с 2 избора');
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
    // 1.24.5: въпросникът е първото нещо при влизане в пълненето (quiz:true в сценария) — с UTM: заглавие с продукта; без UTM: „Кое от следното те касае?“ и минимум 2
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'fill', box: 'l', utm: 'sakura', quiz: true })); await page.waitForTimeout(300); await shot('quiz-auto'); await check('quiz-auto');
    if (!(await page.$('#apDc .fqz'))) errors.push(tag + ' quiz-auto: въпросникът не се отвори сам при влизане в пълненето');
    if (await page.evaluate(() => !document.getElementById('apOv2').classList.contains('off'))) errors.push(tag + ' quiz-auto: интро листът е върху въпросника');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'fill', box: 'l', utm: null, quiz: true })); await page.waitForTimeout(300); await shot('quiz-auto-noutm'); await check('quiz-auto-noutm');
    if (!/Кое от следното те касае/.test(await page.evaluate(() => (document.querySelector('.fqz h3') || {}).textContent || ''))) errors.push(tag + ' quiz-auto-noutm: заглавието без UTM не е „Кое от следното те касае?“');
    await page.evaluate(() => { const i = document.querySelector('.fqz input[value="thyro"]'); if (i) i.click(); });
    if (!(await page.evaluate(() => document.getElementById('fqGo').disabled))) errors.push(tag + ' quiz-auto-noutm: „Продължи“ е активен с 1 избор без UTM');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'boxes', utm: null }));
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'fill', box: 'm', utm: null })); await check('fill-noutm');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'celeb', box: 's', utm: 'sakura', full: true })); await shot('celeb-small'); await check('celeb-small');
    // 1.23.9: „Честито“ с 11 опаковки от 6 продукта — групирани, най-много 4 реда (десктоп) / 1 (телефон) видими, останалите зад „Виж всички (+N)“
    await page.evaluate(() => { AnsaPromo.scenario({ screen: 'boxes', box: 'l', utm: 'sakura' }); const s = AnsaPromo.state(); const o = Object.keys(window.AnsaPromoRuntime.products).filter(k => k !== 'sakura'); s.slots = ['sakura', 'sakura', 'sakura', 'sakura'].concat(o.slice(0, 5)).concat([o[0], o[0]]); AnsaPromo.scenario({ screen: 'celeb' }); }); await page.waitForTimeout(400);
    { const c = await page.evaluate(() => { const b = document.querySelector('#apDc .cbox'); return b ? { all: b.querySelectorAll('.cit').length, vis: Array.from(b.querySelectorAll('.cit')).filter(e => e.offsetParent !== null).length, more: !!b.querySelector('#cMore'), q: (b.querySelector('.cit .cq') || {}).textContent } : null; });
      if (!c || c.all !== 6 || c.vis > (w < 500 ? 1 : 4) || !c.more || c.q !== '4×') errors.push(tag + ' celeb-11: списъкът не е групиран/лимитиран: ' + JSON.stringify(c)); }
    await shot('celeb-11');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'builder', box: 'm', utm: 'sakura' })); await shot('builder'); await check('builder');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'sure', box: 'm', utm: 'sakura', full: true })); await shot('sure'); await check('sure');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'switch', box: 'l', utm: 'sakura', full: true })); await check('switch');
    await page.evaluate(() => { document.querySelector('.bx.s').click(); }); await page.waitForTimeout(200); await shot('suredown'); await check('suredown');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'product', utm: 'sakura' })); await shot('product'); await check('product');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'how' })); await shot('how'); await check('how'); // v1.7.0: цялата страница на сезона в попъпа
    { const ov = await page.evaluate(() => { const e = document.querySelector('#apDc > .ib'); return e ? e.scrollWidth - e.clientWidth : 0; }); if (ov > 1) errors.push(tag + ' how: хоризонтален скрол ' + ov + 'px'); }
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'picker', box: 'l', utm: 'sakura', full: false })); await shot('picker'); await check('picker');
    // v1.12.0: само Голямата расте (над 5 на −40%, +1 билет за всяка над 5-ата); малка/средна са с точен брой
    const boxId = () => page.evaluate(() => { const s = AnsaPromo.state(); return s.box == null ? null : window.AnsaPromoRuntime.boxes[s.box].id; });
    const incN = (n) => page.evaluate((n) => { const d = document.getElementById('apDc'); for (let i = 0; i < n; i++) { const b = d.querySelector('[data-inc="meno"]:not([disabled])') || d.querySelector('[data-inc]:not([disabled])'); if (b) b.click(); } }, n);
    const nextTxt = () => page.evaluate(() => { const e = document.getElementById('fNext'); return e && e.style.display !== 'none' ? e.innerText : ''; });
    const canInc = () => page.evaluate(() => !!document.querySelector('#apDc [data-inc]:not([disabled])'));
    const tixTxt = () => page.evaluate(() => Array.from(document.querySelectorAll('#apDc [data-tix]')).map(x => x.innerText).join(' | '));
    // малка: точно 1 → степерите са заключени, без ред „Още …“
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'fill', box: 's', utm: 'sakura' }));
    { const ok = await page.$('#okBtn'); if (ok && await ok.isVisible()) { await ok.click(); await page.waitForTimeout(200); } }
    await shot('grow-s'); await check('grow-s');
    if (await canInc()) errors.push(tag + ' grow-s: малката кутия приема повече от 1 опаковка');
    if (await nextTxt()) errors.push(tag + ' grow-s: редът „Още …“ се показва на малка кутия: ' + await nextTxt());
    if (await boxId() !== 's') errors.push(tag + ' grow-s: кутията не е s');
    // средна: точно 3
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'fill', box: 'm', utm: 'sakura' }));
    { const ok = await page.$('#okBtn'); if (ok && await ok.isVisible()) { await ok.click(); await page.waitForTimeout(200); } }
    await incN(2); await page.waitForTimeout(250); await shot('grow-m'); await check('grow-m');
    if (await canInc()) errors.push(tag + ' grow-m: средната кутия приема повече от 3 опаковки');
    if (await nextTxt()) errors.push(tag + ' grow-m: редът „Още …“ се показва на средна кутия');
    if (await boxId() !== 'm') errors.push(tag + ' grow-m: кутията не е m, а ' + await boxId());
    { const d = await page.evaluate(() => document.getElementById('nFill').disabled); if (d) errors.push(tag + ' grow-m: „Продължи“ е заключен при 3 опаковки'); }
    // голяма: 1 → 5 (ред „+1 билет“, 5 билета) → 7 (7 билета) → честито със 7 реда и „общо 7 билета“
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'fill', box: 'l', utm: 'sakura' }));
    { const ok = await page.$('#okBtn'); if (ok && await ok.isVisible()) { await ok.click(); await page.waitForTimeout(200); } }
    if (await nextTxt()) errors.push(tag + ' grow-l1: редът „Още …“ се показва преди минимума');
    await incN(4); await page.waitForTimeout(250); await shot('grow-l'); await check('grow-l');
    if (await boxId() !== 'l') errors.push(tag + ' grow-l: кутията не е l');
    // 1.20.3: билетите вече не са в реда над футъра (fill.top = „−X% на всяка опаковка“); 1.24.6: на телефон редът го няма — проверките за „+1 билет / 5 билета“ са махнати
    if (!await canInc()) errors.push(tag + ' grow-l: степерите са заключени на 5');
    await incN(2); await page.waitForTimeout(250); await shot('grow-l7'); await check('grow-l7');
    if (await boxId() !== 'l') errors.push(tag + ' grow-l7: кутията не е l');
    if (w >= 500) { const t = await tixTxt(); if (!/7/.test(t)) errors.push(tag + ' grow-l7: плочката с билетите не показва 7: ' + JSON.stringify(t)); } // 1.24.6: на телефон списъкът с подаръците в хедъра е махнат
    { const d = await page.evaluate(() => document.getElementById('nFill').disabled); if (d) errors.push(tag + ' grow-l7: „Продължи“ е заключен при 7 опаковки'); }
    await page.click('#nFill'); await page.waitForTimeout(600); await shot('grow-celeb'); await check('grow-celeb');
    { const n = await page.evaluate(() => AnsaPromo.state().slots.filter(Boolean).length); if (n !== 7) errors.push(tag + ' grow-celeb: очаквах 7 опаковки, има ' + n); }
    { const t = await page.evaluate(() => document.getElementById('apDc').innerText); if (!/7\s+опаковки/.test(t)) errors.push(tag + ' grow-celeb: „7 опаковки“ липсва'); if (!/общо\s+7\s+билета/.test(t)) errors.push(tag + ' grow-celeb: „общо 7 билета“ липсва'); if (/undefined/.test(t)) errors.push(tag + ' grow-celeb: undefined'); }
    // таблото: голяма пълна (5) → „＋ Добави още опаковки“ → 6; средна пълна → няма такъв бутон
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'builder', box: 'l', utm: 'sakura', full: true })); await shot('grow-builder'); await check('grow-builder');
    await page.click('#morePick'); await page.waitForTimeout(200); await shot('grow-picker'); await check('grow-picker');
    await page.click('#apDc [data-add="meno"]'); await page.waitForTimeout(300); await shot('grow-builder6'); await check('grow-builder6');
    { const n = await page.evaluate(() => AnsaPromo.state().slots.filter(Boolean).length); if (n !== 6) errors.push(tag + ' grow-builder6: очаквах 6 опаковки, има ' + n); }
    { const t = await page.evaluate(() => document.getElementById('apToast').innerText); if (!/общо\s+6/.test(t)) errors.push(tag + ' grow-builder6: тостът „+1 билет — общо 6“ липсва: ' + JSON.stringify(t)); }
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'builder', box: 'm', utm: 'sakura', full: true }));
    if (await page.$('#morePick')) errors.push(tag + ' grow-builder-m: средната кутия има „＋ Добави още“');
    console.log(tag, 'shots:', shots.join(' '));
    await ctx.close();
  }
  await run(390, 780, 'm390');
  await run(1280, 900, 'd1280');
  await browser.close();
  console.log(errors.length ? 'ERRORS:\n' + errors.join('\n') : 'no errors');
})();
