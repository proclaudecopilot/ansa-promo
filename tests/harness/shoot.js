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
    if (await page.evaluate(() => document.getElementById('fqGo').disabled)) errors.push(tag + ' quiz-auto-noutm: „Продължи“ е неактивен с 1 избор без UTM (1.24.31: един стига)');
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
  // 1.24.20: listicle страницата /kozmetika (runtime.mode = li): без грешки/плейсхолдъри, всеки бутон води към промо страницата с ?utm_content=cosm,
  // 7 въпроса, залепен бутон, броячът „Останаха“ е скрит при 90 % останали (1000 от 10 000 резервирани) и се показва при ≤ 40 %
  async function runLi(w, h, tag) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1, isMobile: w < 500, hasTouch: w < 500 });
    const page = await ctx.newPage();
    await page.route(/fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    page.on('pageerror', e => errors.push(tag + ': ' + e.message));
    page.on('console', m => { if (m.type() === 'error') errors.push(tag + ' console: ' + m.text()); });
    await page.goto('file://' + S + '/harness/kozmetika.html?utm_source=fb&utm_medium=paid&utm_campaign=set', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(500);
    await page.screenshot({ path: S + '/shots/' + tag + '-li.png', fullPage: true });
    const t = await page.evaluate(() => document.body.innerText);
    const left = (t.match(/\{\{[^}]*\}\}/g) || []); if (left.length) errors.push(tag + ' li: непопълнени плейсхолдъри ' + JSON.stringify(left.slice(0, 5)));
    if (/undefined|NaN|\[object/.test(t)) errors.push(tag + ' li: undefined/NaN: ' + (t.match(/.{0,30}(undefined|NaN|\[object).{0,30}/) || [''])[0]);
    for (const s of ['Къде е уловката', 'Какви са козметичните продукти', 'Три стъпки', 'Правилно прочете', 'Въпроси', 'Екатерина и екипът на ansa']) if (t.indexOf(s) < 0) errors.push(tag + ' li: липсва „' + s + '“');
    const btns = await page.evaluate(() => Array.from(document.querySelectorAll('#apS1 [data-li="go"]')).map(b => b.getAttribute('data-go') || ''));
    if (btns.length < 5) errors.push(tag + ' li: само ' + btns.length + ' бутона');
    btns.forEach((u, i) => { if (!/utm_content=cosm/.test(u) || !/utm_source=fb/.test(u) || !/utm_campaign=set/.test(u) || !/^https:\/\/ansa\.bg\/sezonut-na-ansa\/\?/.test(u)) errors.push(tag + ' li: бутон ' + i + ' води към ' + u); });
    if ((await page.$$('#apS1 .hw-faqs details')).length !== 7) errors.push(tag + ' li: въпросите не са 7');
    if (!(await page.$('#apS1 .hw-stk.li-stk .cta'))) errors.push(tag + ' li: няма залепен бутон');
    if (await page.$('#apS1 .li-left')) errors.push(tag + ' li: броячът „Останаха“ се показва при 90 % останали');
    if (!/Показваме първите три/.test(t)) errors.push(tag + ' li: без карти на продуктите трябва да стои „Показваме първите три…“');
    // 1.24.22: „Какво се крие в кутията“ — 5 карти след „Какви са козметичните продукти“, 4 и 5 замъглени с „?“, без снимки → емоджи; снимка от runtime.li_items → <img>
    { const o = await page.evaluate(() => Array.from(document.querySelectorAll('#apS1 section[data-sec]')).map(s => s.dataset.sec)); if (o.indexOf('prods') !== o.indexOf('set') + 1) errors.push(tag + ' li: секцията prods не е след set: ' + JSON.stringify(o)); }
    if (!/Какво се крие в кутията/.test(t) || !/Мицеларен тоник/.test(t) || !/Пребиотичен нощен серум/.test(t)) errors.push(tag + ' li: секцията „Какво се крие в кутията“ липсва или без продуктите');
    if ((await page.$$('#apS1 .li-prod')).length !== 5) errors.push(tag + ' li: продуктите не са 5');
    if ((await page.$$('#apS1 .li-prod.blur em')).length !== 2) errors.push(tag + ' li: замъглените изненади с „?“ не са 2');
    if ((await page.$$('#apS1 .li-ppic img')).length) errors.push(tag + ' li: без снимки трябва да има емоджи, не <img>');
    await page.evaluate(() => { window.AnsaPromoRuntime.li_items[0].img = window.AnsaPromoRuntime.gate.images.book; AnsaPromo.render(); }); await page.waitForTimeout(250);
    if (!/book\.svg$/.test(await page.evaluate(() => (document.querySelector('#apS1 .li-prod .li-ppic img') || {}).src || ''))) errors.push(tag + ' li: снимката на продукт 1 не се показва');
    await page.evaluate(() => { window.AnsaPromoRuntime.li_items[0].img = ''; AnsaPromo.render(); });
    // 1.24.23: празен текст = скрит елемент (без „＋“ на живо); „＋ допълнителен текст“ (li.x.catch) с # заглавие, - точки, > бележка
    await page.evaluate(() => { const c = window.AnsaPromoRuntime.copy; c['li.in.h'] = ''; c['li.in.cta'] = ''; c['li.faq.7.q'] = ''; c['li.x.catch'] = '# Ново заглавие\nАбзац едно.\n- точка едно\n- точка две\n> бележка'; AnsaPromo.render(); }); await page.waitForTimeout(250);
    if (await page.$('#apS1 .li-in h3') || await page.$('#apS1 .li-in .cta')) errors.push(tag + ' li: скритото заглавие/бутон на „веднага“ още се показва');
    if (await page.$('#apS1 .ck-empty')) errors.push(tag + ' li: „＋“ хотспот на живо (без редактор)');
    if ((await page.$$('#apS1 .hw-faqs details')).length !== 6) errors.push(tag + ' li: скритият въпрос 7 още е там');
    { const x = await page.evaluate(() => { const d = document.querySelector('#apS1 .li-catch .hw-x'); return d ? { h4: (d.querySelector('h4') || {}).textContent, li: d.querySelectorAll('ul li').length, n: (d.querySelector('p.hw-n') || {}).textContent, p: d.querySelectorAll('p:not(.hw-n)').length } : null; });
      if (!x || x.h4 !== 'Ново заглавие' || x.li !== 2 || x.n !== 'бележка' || x.p !== 1) errors.push(tag + ' li: допълнителният текст не се рендира: ' + JSON.stringify(x)); }
    await page.screenshot({ path: S + '/shots/' + tag + '-li-xtra.png', fullPage: false });
    await page.evaluate(() => { const c = window.AnsaPromoRuntime.copy; delete c['li.x.catch']; AnsaPromo.render(); });
    const links = await page.evaluate(() => Array.from(document.querySelectorAll('#apS1 .li-link, #apS1 .li-links a')).map(a => a.href));
    if (links.length !== 3 || links.some(u => !/sezonut-na-ansa\/(info|tombola)\//.test(u))) errors.push(tag + ' li: линковете към /info и правилата: ' + JSON.stringify(links));
    // броячът при 6 500 резервирани (35 % остават) → „Останаха 3 500 от 10 000“ и в залепения ред
    await page.evaluate(() => { window.AnsaPromoRuntime.cosm_reserved = 6500; AnsaPromo.render(); }); await page.waitForTimeout(300);
    const t2 = await page.evaluate(() => document.body.innerText);
    if (!/Останаха 3 500 от 10 000/.test(t2)) errors.push(tag + ' li: броячът при 35 % липсва: ' + (t2.match(/Останаха[^\n]{0,30}/) || [''])[0]);
    if (!(await page.$('#apS1 .li-stk .li-left, #apS1 .li-stk b'))) errors.push(tag + ' li: броячът не е в залепения ред');
    await page.screenshot({ path: S + '/shots/' + tag + '-li-left.png', fullPage: false });
    console.log(tag, 'li ok');
    await ctx.close();
  }
  // 1.31.0: адверториалът /kozmetichen-set-promo (runtime.mode = sx): без грешки/плейсхолдъри, всеки бутон води към промо страницата с
  // ?utm_content=sakura&box=l (+ utm_* от адреса), цените от двигателя (Sakura 44,90 × 5 = 224,50 → −40 % = 134,70; + 1 € = 135,70; стойност 353,50),
  // 10 въпроса, ревютата са скрити без цитат, залепеният ред на телефона, броячът „Останаха“ при ≤ 40 %
  async function runSx(w, h, tag) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1, isMobile: w < 500, hasTouch: w < 500 });
    const page = await ctx.newPage();
    await page.route(/fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    page.on('pageerror', e => errors.push(tag + ': ' + e.message));
    page.on('console', m => { if (m.type() === 'error') errors.push(tag + ' console: ' + m.text()); });
    await page.goto('file://' + S + '/harness/sx.html?utm_source=fb&utm_medium=paid&utm_campaign=sakura5', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(600);
    await page.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 700) { window.scrollTo(0, y); await new Promise(r => setTimeout(r, 40)); } window.scrollTo(0, 0); });
    await page.waitForTimeout(300);
    await page.screenshot({ path: S + '/shots/' + tag + '-sx.png', fullPage: true });
    // innerText носи text-transform (кикърите са главни) и NBSP (ths) — сравняваме с малки букви и обикновени интервали
    const norm = x => String(x).replace(/\u00a0/g, ' ').toLowerCase();
    const t = norm(await page.evaluate(() => document.body.innerText));
    const left = (t.match(/\{\{[^}]*\}\}/g) || []); if (left.length) errors.push(tag + ' sx: непопълнени плейсхолдъри ' + JSON.stringify(left.slice(0, 5)));
    if (/undefined|NaN|\[object/.test(t)) errors.push(tag + ' sx: undefined/NaN: ' + (t.match(/.{0,30}(undefined|NaN|\[object).{0,30}/) || [''])[0]);
    for (const s of ['Знаем, че си дошла за козметиката', 'Ето какво вероятно пропускаш', 'Как го обясняват дерматолозите', 'Няма колаген вътре, нарочно', 'Защо пет, а не една', 'Три стъпки, едната е през януари', 'Правилно прочете, почивка на яхта', 'Екатерина и екипът на ansa']) if (t.indexOf(norm(s)) < 0) errors.push(tag + ' sx: липсва „' + s + '“');
    // цените от двигателя: Sakura × packs на Голямата (харнесът е в седмица 3 → процентът на седмицата), + комплектът за 1 €
    const ex = await page.evaluate(() => { const R = window.AnsaPromoRuntime, bl = R.boxes.filter(b => b.id === 'l')[0], sp = R.products.sakura, pct = Number((sp.pct || {}).l) || bl.pct, r2 = n => Math.round(n * 100) / 100, f = n => (Math.abs(n - Math.round(n)) < .005 ? String(Math.round(n)) : n.toFixed(2)).replace('.', ',');
      const full = r2(sp.price * bl.packs), pay = r2(full * (1 - pct / 100)), cp = Number(bl.cosm_pay) || 0, cv = Number(R.rewards.cosm.value), days = bl.packs * R.sx.days; return { full: f(full), pay: f(pay), total: f(r2(pay + cp)), value: f(r2(full + cv)), save: f(r2(full + cv - pay - cp)), day: f(r2(pay / days)), days: String(days) }; });
    for (const k of ['full', 'pay', 'total', 'value', 'save', 'day']) if (t.indexOf(ex[k] + ' €') < 0) errors.push(tag + ' sx: липсва сумата ' + k + ' „' + ex[k] + ' €“');
    if (t.indexOf(ex.days + ' дни') < 0) errors.push(tag + ' sx: липсва „' + ex.days + ' дни“');
    if (/какво казват жените/.test(t)) errors.push(tag + ' sx: секцията с ревютата се показва без цитати');
    const btns = await page.evaluate(() => Array.from(document.querySelectorAll('#apS1 [data-sx="go"]')).map(b => b.getAttribute('data-go') || ''));
    if (btns.length < 6) errors.push(tag + ' sx: само ' + btns.length + ' бутона');
    btns.forEach((u, i) => { if (!/utm_content=sakura/.test(u) || !/box=l/.test(u) || !/utm_source=fb/.test(u) || !/utm_campaign=sakura5/.test(u) || !/^https:\/\/ansa\.bg\/sezonut-na-ansa\/\?/.test(u)) errors.push(tag + ' sx: бутон ' + i + ' води към ' + u); });
    if ((await page.$$('#apS1 .sx-faqs details')).length !== 10) errors.push(tag + ' sx: въпросите не са 10');
    if (!(await page.$('#apS1 .sx-stk .sx-btn'))) errors.push(tag + ' sx: няма залепен ред');
    if (await page.$('#apS1 .sx-left')) errors.push(tag + ' sx: броячът „Останаха“ се показва при 90 % останали');
    if (await page.$('#apS1 .ck-empty')) errors.push(tag + ' sx: „＋“ хотспот на живо (без редактор)');
    { const o = await page.evaluate(() => Array.from(document.querySelectorAll('#apS1 section[data-sec]')).map(s => s.dataset.sec)); const want = ['hero', 'miss', 'derm', 'sol', 'offer', 'five', 'steps', 'get', 'yacht', 'faq', 'final']; if (JSON.stringify(o) !== JSON.stringify(want)) errors.push(tag + ' sx: секциите са ' + JSON.stringify(o)); }
    const sw = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]); if (sw[0] > sw[1]) errors.push(tag + ' sx: хоризонтален скрол ' + sw.join('/'));
    if (!/sx\/hero\.webp$/.test(await page.evaluate(() => (document.querySelector('#apS1 .sx-hero-v > img') || {}).src || ''))) errors.push(tag + ' sx: hero снимката не е тази от assets/img/sx');
    if ((await page.$$('#apS1 .sx-prod.blur em')).length !== 2) errors.push(tag + ' sx: замъглените изненади с „?“ не са 2');
    // ревю с цитат → секцията се показва с 1 карта; празен h2 = скрит; броячът при 35 % → в лентата, офертата и финала; своя hero снимка
    await page.evaluate(() => { const c = window.AnsaPromoRuntime.copy; c['sx.rv.1.q'] = '„Кожата ми е друга.“'; c['sx.rv.1.n'] = 'Мария, 44'; c['sx.miss.h'] = ''; window.AnsaPromoRuntime.cosm_reserved = 6500; window.AnsaPromoRuntime.pages.sx.images = { hero: window.AnsaPromoRuntime.gate.images.yacht }; AnsaPromo.render(); }); await page.waitForTimeout(300);
    const t2 = norm(await page.evaluate(() => document.body.innerText));
    if (!/какво казват жените/.test(t2) || (await page.$$('#apS1 .sx-rev')).length !== 1) errors.push(tag + ' sx: ревюто с цитат не се показва като 1 карта');
    if (/ето какво вероятно пропускаш/.test(t2)) errors.push(tag + ' sx: скритото заглавие още се показва');
    if ((t2.match(/останаха 3 500 от 10 000/g) || []).length < 3) errors.push(tag + ' sx: броячът при 35 % не е на 3 места: ' + (t2.match(/останаха[^\n]{0,30}/g) || []).join(' | '));
    if (!/gate\/yacht\.svg$/.test(await page.evaluate(() => (document.querySelector('#apS1 .sx-hero-v > img') || {}).src || ''))) errors.push(tag + ' sx: своята hero снимка не се показва');
    await page.screenshot({ path: S + '/shots/' + tag + '-sx-left.png', fullPage: false });
    console.log(tag, 'sx ok');
    await ctx.close();
  }
  // 1.24.21: секциите на /info и /kozmetika — по реда от runtime.pages, скритите (off) не се рендират, снимката на секция е своята (images) или тази от попъпа
  async function runPages(w, h, tag) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1, isMobile: w < 500, hasTouch: w < 500 });
    const page = await ctx.newPage();
    await page.route(/fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    page.on('pageerror', e => errors.push(tag + ': ' + e.message));
    page.on('console', m => { if (m.type() === 'error') errors.push(tag + ' console: ' + m.text()); });
    const secs = () => page.evaluate(() => Array.from(document.querySelectorAll('#apS1 section[data-sec]')).map(s => s.dataset.sec));
    for (const [file, pg, def, hideId, imgSel] of [['kozmetika.html', 'li', ['hero', 'catch', 'set', 'prods', 'in', 'steps', 'box', 'yacht', 'faq'], 'faq', '.li-hero .hw-hpic img'], ['info.html', 'how', ['hero', 'boxes', 'ladder', 'steps', 'yacht', 'cosm', 'prods', 'why', 'reviews', 'faq', 'rules'], 'reviews', '.hw-hero .hw-hpic img']]) {
      await page.goto('file://' + S + '/harness/' + file, { waitUntil: 'domcontentloaded' }); await page.waitForTimeout(400);
      let got = await secs(); if (JSON.stringify(got) !== JSON.stringify(def)) errors.push(tag + ' ' + pg + ': редът по подразбиране е ' + JSON.stringify(got));
      if (await page.$('#apS1 .sec-tools')) errors.push(tag + ' ' + pg + ': лентата ↑↓ се показва на живо (без редактор)');
      if (!/gate\/(cosm|yacht)\.svg$/.test(await page.evaluate(sel => (document.querySelector('#apS1 ' + sel) || {}).src || '', imgSel))) errors.push(tag + ' ' + pg + ': hero снимката не е тази от попъпа');
      const rev = def.slice().reverse();
      await page.evaluate(([pg, rev, hideId]) => { const P = window.AnsaPromoRuntime.pages[pg]; P.order = rev; P.off = [hideId]; P.images = { hero: 'file:///tmp/own-hero.png' }; AnsaPromo.render(); }, [pg, rev, hideId]); await page.waitForTimeout(300);
      got = await secs(); const want = rev.filter(x => x !== hideId);
      if (JSON.stringify(got) !== JSON.stringify(want)) errors.push(tag + ' ' + pg + ': след смяна на реда/скриване: ' + JSON.stringify(got) + ' ≠ ' + JSON.stringify(want));
      if (!/own-hero\.png$/.test(await page.evaluate(sel => (document.querySelector('#apS1 ' + sel) || {}).src || '', imgSel))) errors.push(tag + ' ' + pg + ': своята снимка на hero не се показва');
      if (!(await page.$('#apS1 .hw-stk .cta'))) errors.push(tag + ' ' + pg + ': залепеният бутон липсва след смяна на реда');
      const t = await page.evaluate(() => document.body.innerText); if (/undefined|NaN|\[object/.test(t)) errors.push(tag + ' ' + pg + ' pages: undefined/NaN');
      await page.screenshot({ path: S + '/shots/' + tag + '-' + pg + '-reordered.png', fullPage: true });
    }
    console.log(tag, 'pages ok'); await ctx.close();
  }
  // 1.24.21: редакторът (harness/editor.html + канвас canvas.html): 📄 Страници → списъкът, ↓ в инспектора мести секцията и в канваса, лентата в канваса
  // (↑ ↓ 👁 🖼) стига до родителя, URL на снимка сменя картинката в канваса, „ред по подразбиране“ връща
  async function runEditor(tag) {
    const ctx = await browser.newContext({ viewport: { width: 1600, height: 1000 } });
    const page = await ctx.newPage();
    await page.route(/fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    page.on('pageerror', e => errors.push(tag + ': ' + e.message));
    page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource|fetch|admin-ajax/.test(m.text())) errors.push(tag + ' console: ' + m.text()); });
    await page.goto('file://' + S + '/harness/editor.html', { waitUntil: 'domcontentloaded' }); await page.waitForTimeout(800);
    const fr = () => page.frames().find(f => f !== page.mainFrame());
    if (!fr()) { errors.push(tag + ': канвасът (iframe) липсва'); await ctx.close(); return; }
    const btn = await page.$('#apEdRail [data-secpg="li"]'); if (!btn) { errors.push(tag + ': бутонът 📄 /kozmetika липсва в рейла'); await ctx.close(); return; }
    await btn.click(); await page.waitForTimeout(900);
    const rows = () => page.evaluate(() => Array.from(document.querySelectorAll('#apSecList .sec:not(.other)')).map(li => li.dataset.id));
    const canvas = () => fr().evaluate(() => Array.from(document.querySelectorAll('#apDc section[data-sec]')).map(s => s.dataset.sec));
    const def = ['hero', 'catch', 'set', 'prods', 'in', 'steps', 'box', 'yacht', 'faq'];
    if (JSON.stringify(await rows()) !== JSON.stringify(def)) errors.push(tag + ': списъкът в инспектора: ' + JSON.stringify(await rows()));
    if (JSON.stringify(await canvas()) !== JSON.stringify(def)) errors.push(tag + ': канвасът не показва /kozmetika по подразбиране: ' + JSON.stringify(await canvas()));
    if (!(await fr().$('#apDc .sec-tools [data-sect="up"]'))) errors.push(tag + ': лентата ↑↓ липсва в канваса');
    await page.screenshot({ path: S + '/shots/' + tag + '-sections.png', fullPage: false });
    // ↓ на първия ред в инспектора → hero става втори (и в канваса)
    await page.click('#apSecList .sec[data-id="hero"] [data-mv="1"]'); await page.waitForTimeout(600);
    const o1 = ['catch', 'hero', 'set', 'prods', 'in', 'steps', 'box', 'yacht', 'faq'];
    if (JSON.stringify(await rows()) !== JSON.stringify(o1)) errors.push(tag + ': след ↓ инспекторът е ' + JSON.stringify(await rows()));
    if (JSON.stringify(await canvas()) !== JSON.stringify(o1)) errors.push(tag + ': след ↓ канвасът е ' + JSON.stringify(await canvas()));
    if (JSON.stringify(await page.evaluate(() => window.AnsaPromoEditor.cfg.pages.li.order)) === JSON.stringify(def)) { /* cfg е локална променлива в editor.js; D.cfg е същият обект при старт */ }
    // лентата в канваса: ↑ на „set“ (3-та) → 2-ра; 👁 скрий „faq“ → избледняла в канваса, „скрита“ в инспектора
    await fr().click('#apDc section[data-sec="set"] [data-sect="up"]'); await page.waitForTimeout(600);
    const o2 = ['catch', 'set', 'hero', 'prods', 'in', 'steps', 'box', 'yacht', 'faq'];
    if (JSON.stringify(await rows()) !== JSON.stringify(o2)) errors.push(tag + ': след ↑ от канваса инспекторът е ' + JSON.stringify(await rows()));
    if (JSON.stringify(await canvas()) !== JSON.stringify(o2)) errors.push(tag + ': след ↑ от канваса канвасът е ' + JSON.stringify(await canvas()));
    await fr().click('#apDc section[data-sec="faq"] [data-sect="off"]'); await page.waitForTimeout(600);
    if (!(await fr().$('#apDc section[data-sec="faq"].sec-off'))) errors.push(tag + ': скритата секция не е избледняла в канваса');
    if (!(await page.$('#apSecList .sec[data-id="faq"].off'))) errors.push(tag + ': скритата секция не е отбелязана в инспектора');
    if (!/скрити/.test(await page.evaluate(() => document.querySelector('#apEdRail [data-secpg="li"]').textContent))) errors.push(tag + ': рейлът не брои скритите');
    if (!/незаписани/.test(await page.evaluate(() => document.querySelector('#apEdTop .st').textContent))) errors.push(tag + ': промяната не маркира черновата като незаписана');
    // снимка по URL за hero → канвасът показва нея
    await page.fill('#apSecList .sec[data-id="hero"] [data-iurl]', 'file:///tmp/own-hero.png'); await page.press('#apSecList .sec[data-id="hero"] [data-iurl]', 'Tab'); await page.waitForTimeout(600);
    if (!/own-hero\.png$/.test(await fr().evaluate(() => (document.querySelector('#apDc section[data-sec="hero"] .hw-hpic img') || {}).src || ''))) errors.push(tag + ': своята снимка не стигна до канваса');
    if (!(await page.$('#apSecList .sec[data-id="hero"] [data-clr]'))) errors.push(tag + ': няма „⟲ като в попъпа“ при своя снимка');
    await page.screenshot({ path: S + '/shots/' + tag + '-sections-changed.png', fullPage: false });
    await page.click('#apSecList .sec[data-id="hero"] [data-clr]'); await page.waitForTimeout(600);
    if (!/gate\/cosm\.svg$/.test(await fr().evaluate(() => (document.querySelector('#apDc section[data-sec="hero"] .hw-hpic img') || {}).src || ''))) errors.push(tag + ': след „като в попъпа“ снимката не се върна');
    // 1.24.23: цък върху текст в канваса НЕ сменя панела — отваря реда на секцията и фокусира полето; празно поле = скрит (＋ в канваса); „＋ допълнителен текст“
    await fr().click('#apDc section[data-sec="in"] [data-ck="li.in.h"]'); await page.waitForTimeout(500);
    if (!(await page.$('#apSecList'))) errors.push(tag + ': цък върху текст смени панела „Страници“');
    if (await page.evaluate(() => (document.activeElement || {}).dataset ? document.activeElement.dataset.ta : '') !== 'li.in.h') errors.push(tag + ': полето li.in.h не е на фокус след цък в канваса');
    if (!(await page.$('#apSecList .sec[data-id="in"] details.txt[open]'))) errors.push(tag + ': текстовете на секцията не се отвориха');
    await page.fill('#apSecList [data-ta="li.in.h"]', ''); await page.waitForTimeout(500);
    if (!(await fr().$('#apDc section[data-sec="in"] .ck-empty[data-ck="li.in.h"]'))) errors.push(tag + ': празното заглавие не стана „＋“ в канваса');
    if (!(await page.$('#apSecList .tk[data-tk="li.in.h"].hid'))) errors.push(tag + ': полето не е отбелязано като скрито');
    await page.click('#apSecList [data-tr="li.in.h"]'); await page.waitForTimeout(500);
    if (await fr().$('#apDc section[data-sec="in"] .ck-empty[data-ck="li.in.h"]')) errors.push(tag + ': ⟲ не върна заглавието');
    await page.fill('#apSecList [data-ta="li.x.in"]', '# Тест заглавие\nТест абзац\n- точка'); await page.waitForTimeout(500);
    { const x = await fr().evaluate(() => { const d = document.querySelector('#apDc section[data-sec="in"] .hw-x'); return d ? (d.querySelector('h4') || {}).textContent + '|' + d.querySelectorAll('li').length : ''; }); if (x !== 'Тест заглавие|1') errors.push(tag + ': допълнителният текст не стигна до канваса: ' + x); }
    await fr().click('#apDc section[data-sec="in"] .hw-x'); await page.waitForTimeout(400);
    if (await page.evaluate(() => (document.activeElement || {}).dataset ? document.activeElement.dataset.ta : '') !== 'li.x.in') errors.push(tag + ': цък върху допълнителния текст не го фокусира');
    if (!(await page.$('#apSecList .sec.other [data-ta="li.title"]'))) errors.push(tag + ': редът „Други текстове“ (li.title) липсва');
    await page.screenshot({ path: S + '/shots/' + tag + '-sections-texts.png', fullPage: false });
    // 1.24.22: петте продукта в реда „Какво се крие в кутията“ — URL на снимка и „замъглена“ стигат до канваса
    if ((await page.$$('#apSecList .sec[data-id="prods"] .item')).length !== 5) errors.push(tag + ': редът prods няма 5 продукта');
    const bookUrl = await page.evaluate(() => window.AnsaPromoEditor.gate_images.book); await page.fill('#apSecList [data-liurl="0"]', bookUrl); await page.press('#apSecList [data-liurl="0"]', 'Tab'); await page.waitForTimeout(600);
    if (!/book\.svg$/.test(await fr().evaluate(() => (document.querySelector('#apDc section[data-sec="prods"] .li-prod .li-ppic img') || {}).src || ''))) errors.push(tag + ': снимката на продукт 1 не стигна до канваса');
    await page.click('#apSecList [data-liblur="4"]'); await page.waitForTimeout(600);
    if ((await fr().$$('#apDc section[data-sec="prods"] .li-prod.blur')).length !== 1) errors.push(tag + ': махането на „замъглена“ на продукт 5 не стигна до канваса');
    // ред по подразбиране
    await page.click('#apSecReset'); await page.waitForTimeout(600);
    if (JSON.stringify(await rows()) !== JSON.stringify(def) || JSON.stringify(await canvas()) !== JSON.stringify(def)) errors.push(tag + ': „ред по подразбиране“ не върна реда: ' + JSON.stringify(await rows()) + ' / ' + JSON.stringify(await canvas()));
    if (await fr().$('#apDc section.sec-off')) errors.push(tag + ': „ред по подразбиране“ не показа скритите');
    // /info също е в рейла и се отваря
    await page.click('#apEdRail [data-secpg="how"]'); await page.waitForTimeout(900);
    if ((await rows()).length !== 11) errors.push(tag + ': /info няма 11 секции в инспектора: ' + JSON.stringify(await rows()));
    if (!(await fr().$('#apDc section[data-sec="boxes"]'))) errors.push(tag + ': канвасът не показва /info');
    console.log(tag, 'editor ok'); await ctx.close();
  }
  await run(390, 780, 'm390');
  await run(1280, 900, 'd1280');
  await runLi(390, 780, 'm390');
  await runLi(1280, 900, 'd1280');
  await runSx(390, 780, 'm390');
  await runSx(1280, 900, 'd1280');
  await runPages(390, 780, 'm390');
  await runPages(1280, 900, 'd1280');
  await runEditor('editor');
  await browser.close();
  console.log(errors.length ? 'ERRORS:\n' + errors.join('\n') : 'no errors');
})();
