// 1.58.0: Playwright проверка на „Черен петък навсякъде“ (BF\Sitewide) с подменен часовник — лентата сяда на мястото на въртележката,
// Сезонът отстъпва, броячите вървят, лентата за продукта е пред опциите, лепкавата лента е само на телефон; след края (страница от кеша)
// всичко от Черния петък изчезва и Сезонът се връща; „заредена“ страница го показва сама в началото.
// php tests/harness/sitewide-page.php on > /tmp/sw-on.html; php tests/harness/sitewide-page.php armed > /tmp/sw-armed.html
// node tests/harness/sitewide-check.js /tmp/sw-on.html /tmp/sw-armed.html [shots-dir]
const { chromium } = require('playwright'); const path = require('path');
(async () => {
  const on = 'file://' + path.resolve(process.argv[2]); const armed = 'file://' + path.resolve(process.argv[3]); const shots = process.argv[4] || ''; const fails = []; let n = 0;
  const ok = (name, cond, info = '') => { n++; if (!cond) fails.push(name + (info ? ' — ' + (typeof info === 'string' ? info : JSON.stringify(info)) : '')); };
  const T = s => new Date(s + '+02:00'); // часът на София (ноември)
  const browser = await chromium.launch();
  const st = page => page.evaluate(() => {
    const vis = e => !!e && e.getClientRects().length > 0 && getComputedStyle(e).display !== 'none'; const all = s => [...document.querySelectorAll(s)]; const txt = e => e ? e.innerText.replace(/\s+/g, ' ').trim() : '';
    const bars = all('a.abf-bar'); const vb = bars.filter(vis); const pdp = document.querySelector('a.abf-pdp'); const stick = document.querySelector('a.abf-stick'); const fab = document.getElementById('fkcart-floating-toggler');
    return { cls: document.body.classList.contains('abf-on'), sw: document.documentElement.scrollWidth, iw: innerWidth, bars: bars.length, visBars: vb.length, barIn: vb[0] ? vb[0].parentNode.id : '', barText: txt(vb[0]), barH: vb[0] ? Math.round(vb[0].getBoundingClientRect().height) : 0, barFits: vb[0] ? vb[0].scrollWidth <= vb[0].clientWidth + 1 : null,
      slides: all('.elementor-widget-slides').filter(vis).length, bfPill: all('li.abf-li').filter(vis).length, seasonPill: all('li.amm-season:not(.abf-li)').filter(vis).length,
      bfPromo: all('a.amm-promo.abf-promo, a.amm-mpromo.abf-promo, a.as-season.abf-shop').filter(vis).length, seasonPromo: all('a.amm-promo:not(.abf-promo), a.amm-mpromo:not(.abf-promo), a.as-season:not(.abf-shop)').filter(vis).length,
      badges: all('.abf-cardbadge').filter(vis).map(e => e.parentNode.getAttribute('data-id')).join(','), cds: all('[data-abf-cd]').map(e => e.getAttribute('data-abf-cd') + '=' + e.textContent.trim()).filter((v, i, a) => a.indexOf(v) === i),
      pdp: pdp ? { vis: vis(pdp), next: pdp.nextElementSibling ? pdp.nextElementSibling.id : '', href: pdp.getAttribute('href') } : null,
      stick: stick ? { vis: vis(stick), fixed: getComputedStyle(stick).position === 'fixed' } : null, fabBottom: fab ? getComputedStyle(fab).bottom : '' };
  });
  for (const [w, h, tag] of [[1440, 900, 'desk'], [390, 760, 'mob']]) {
    const mob = tag === 'mob';
    /* 1. тече (26.11 12:00): всичко е на мястото си */
    let page = await browser.newPage({ viewport: { width: w, height: h } }); const errs = []; page.on('pageerror', e => errs.push(String(e)));
    await page.clock.install({ time: T('2026-11-26T11:59:30') }); /* остават 3 дни 22 ч 00 мин 30 сек */ await page.goto(on); await page.waitForTimeout(150); let s = await st(page);
    ok(`${tag}: без хоризонтален скрол`, s.sw <= s.iw, s);
    ok(`${tag}: лентата е в двата хоста, вижда се една — в ${mob ? 'мобилния' : 'десктоп'} хедър`, s.bars === 2 && s.visBars === 1 && s.barIn === (mob ? 'host-m' : 'host-d'), s);
    ok(`${tag}: лентата е 45px, текстът се събира`, s.barH === 45 && s.barFits === true, s);
    ok(`${tag}: текстът на лентата`, mob ? /^ЧЕРЕН ПЕТЪК Плащаш 1, получаваш 2 3д 22ч ›$/i.test(s.barText) : /^ЧЕРЕН ПЕТЪК Плащаш 1, получаваш 2\. Подаръкът е по твой избор \| свършва след 3д 22ч 00м \d\dс Избери подаръците →$/i.test(s.barText), s.barText);
    ok(`${tag}: въртележката на Elementor е скрита`, s.slides === 0, s);
    ok(`${tag}: хапчето — Черен петък, не Сезонът`, s.seasonPill === 0 && s.bfPill === (mob ? 0 : 1), s);
    ok(`${tag}: промо картите (мега меню, мобилно меню, магазин) — Черен петък, не Сезонът`, s.bfPromo === 3 && s.seasonPromo === 0, s);
    ok(`${tag}: етикет само върху участващите продукти`, s.badges === '101,103', s.badges);
    ok(`${tag}: броячите показват 3 дни 22 ч`, s.cds.includes('words=3 дни 22 ч 00 мин') && s.cds.includes('short=3 дни 22 ч') && s.cds.includes('mini=3д 22ч'), s.cds);
    ok(`${tag}: лентата за продукта е точно пред опциите за покупка и води към ?p=greens`, s.pdp && s.pdp.vis && s.pdp.next === 'offer' && /\/cheren-petak-2026\/\?p=greens$/.test(s.pdp.href), s.pdp);
    ok(`${tag}: лепкавата лента — само на телефон; кошницата се вдига над нея`, s.stick && s.stick.vis === mob && (!mob || (s.stick.fixed && s.fabBottom === '84px')) && (mob || s.fabBottom === '20px'), s);
    if (shots) await page.screenshot({ path: `${shots}/sitewide-${tag}.png` });
    await page.clock.fastForward(61000); await page.waitForTimeout(50); s = await st(page);
    ok(`${tag}: броячът върви`, s.cds.includes('words=3 дни 21 ч 59 мин'), s.cds);
    /* последният ден: без „дни“ */
    await page.close(); page = await browser.newPage({ viewport: { width: w, height: h } }); page.on('pageerror', e => errs.push(String(e)));
    await page.clock.install({ time: T('2026-11-30T08:29:30') }); await page.goto(on); await page.waitForTimeout(150); s = await st(page);
    ok(`${tag}: последният ден — „01 ч 30 мин“, лентата се събира`, s.cds.includes('words=01 ч 30 мин') && s.cds.includes('mini=01ч 30м') && s.barFits === true && (mob ? /получаваш 2 01ч 30м ›$/.test(s.barText) : /свършва след 01ч 30м \d\dс/.test(s.barText)), s);
    /* 2. краят идва, докато страницата е отворена */
    await page.clock.fastForward(91 * 60000); await page.waitForTimeout(80); s = await st(page);
    ok(`${tag}: в 10:00 на 30.11 Черният петък изчезва и Сезонът се връща, без презареждане`, !s.cls && s.visBars === 0 && s.slides === 1 && s.bfPromo === 0 && s.seasonPromo === 3 && s.bfPill === 0 && s.seasonPill === (mob ? 0 : 1) && s.badges === '' && !s.pdp.vis && !s.stick.vis && s.fabBottom === '20px', s);
    await page.close();
    /* 3. страница от кеша, отворена след края (сървърът още казва „включено“) */
    page = await browser.newPage({ viewport: { width: w, height: h } }); page.on('pageerror', e => errs.push(String(e)));
    await page.clock.install({ time: T('2026-12-01T09:00:00') }); await page.goto(on); await page.waitForTimeout(150); s = await st(page);
    ok(`${tag}: стара страница от кеша след края — нищо от Черния петък, Сезонът е на мястото си`, !s.cls && s.visBars === 0 && s.slides === 1 && s.bfPromo === 0 && s.seasonPromo === 3 && s.badges === '' && !s.pdp.vis && !s.stick.vis, s);
    await page.close();
    /* 4. „заредена“ страница (кеширана преди началото): скрито до 25.11 07:00, после се показва сама */
    page = await browser.newPage({ viewport: { width: w, height: h } }); page.on('pageerror', e => errs.push(String(e)));
    await page.clock.install({ time: T('2026-11-25T06:59:00') }); await page.goto(armed); await page.waitForTimeout(150); s = await st(page);
    ok(`${tag}: заредено, преди началото — Сезонът, нищо от Черния петък`, !s.cls && s.visBars === 0 && s.slides === 1 && s.bfPromo === 0 && s.seasonPromo === 3 && s.bfPill === 0 && s.badges === '' && !s.stick.vis, s);
    await page.clock.fastForward(62000); await page.waitForTimeout(80); s = await st(page);
    ok(`${tag}: в 07:00 на 25.11 Черният петък се показва сам`, s.cls && s.visBars === 1 && s.slides === 0 && s.bfPromo === 3 && s.seasonPromo === 0 && s.badges === '101,103' && s.pdp.vis && s.stick.vis === mob, s);
    ok(`${tag}: без JS грешки`, errs.length === 0, errs.join(' | '));
    await page.close();
  }
  await browser.close();
  if (fails.length) { console.log('ГРЕШКИ:\n- ' + fails.join('\n- ')); process.exit(1); } console.log('OK ' + n + ' проверки');
})();
