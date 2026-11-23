// 1.56.0: Playwright проверка на мега менюто, както стои на сайта (скрит панел в навигацията):
//  (1) снимките на картите идват предварително — щом страницата се успокои или мишката приближи хедъра — не при първото отваряне;
//  (2) зад менюто няма blur; (3) кампанията се сменя по час и върху стар (кеширан) HTML: Сезонът е скрит до 01.12, Черният петък е 25.11 07:00 → 30.11 10:00.
// node tests/harness/mega-menu-speed-check.js /tmp/mms.html [old]   („old“ = страницата е от версия преди 1.56.0: очаква се старото поведение — за сравнение)
const { chromium } = require('playwright'); const path = require('path');
(async () => {
  const file = 'file://' + path.resolve(process.argv[2]); const old = process.argv[3] === 'old'; const fails = []; let n = 0; const out = {};
  const ok = (name, cond, info = '') => { n++; if (!cond) fails.push(name + (info ? ' — ' + info : '')); };
  const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
  const at = (s) => Date.parse(s + '+02:00');
  const browser = await chromium.launch();
  const open = async (opts) => {
    const page = await browser.newPage({ viewport: { width: opts.w || 1440, height: opts.h || 900 } }); const req = new Set(); const errs = [];
    page.on('pageerror', (e) => errs.push(String(e)));
    await page.route('http://img.test/**', (r) => { req.add(r.request().url()); r.fulfill({ status: 200, contentType: 'image/png', body: PNG }); });
    await page.addInitScript(([t, noIdle]) => { /* headless Chromium праща pointermove на (0,0) при зареждане — това не е човек при хедъра */ window.addEventListener('pointermove', (e) => { if (e.clientX === 0 && e.clientY === 0) e.stopImmediatePropagation(); }, true); if (t) { const off = t - Date.now(), dn = Date.now.bind(Date); Date.now = () => dn() + off; } if (noIdle) { window.requestIdleCallback = function () {}; } }, [opts.now || 0, !!opts.noIdle]);
    await page.goto(file); return { page, req, errs };
  };
  const seen = (page) => page.evaluate(() => { const v = (sel) => [...document.querySelectorAll(sel)].filter((e) => !e.hidden && getComputedStyle(e).display !== 'none').map((e) => e.textContent.trim().slice(0, 14)); return { pill: v('ul.elementskit-navbar-nav li.amm-season'), w: [...document.querySelectorAll('.amm-w')].length }; });
  const cards = async (page) => { await page.hover('#menu-item-15392 > a'); await page.waitForTimeout(250); return page.evaluate(() => ({ promo: [...document.querySelectorAll('.amm-aside .amm-promo')].filter((e) => !e.hidden && e.offsetHeight > 0).map((e) => e.querySelector('.amm-ey').textContent), bd: getComputedStyle(document.querySelector('.amm-backdrop')).backdropFilter, bdOn: document.querySelector('.amm-backdrop').classList.contains('on'), vis: [...document.querySelectorAll('.amm-card')].filter((c) => !c.hidden).length })); };

  /* (1а) без посочване: снимките идват сами, след като страницата се успокои */
  let t = await open({ now: at('2026-11-23T13:00:00') }); await t.page.waitForTimeout(300); const early = t.req.size; await t.page.waitForTimeout(5000); out.idle = { early, after5s: t.req.size };
  if (old) ok('СТАРО: без посочване снимките НЕ тръгват (0 заявки за 5 сек.)', t.req.size === 0, JSON.stringify(out.idle));
  else ok('без посочване: 8-те снимки са заявени до 5 сек. след load', t.req.size === 8, JSON.stringify(out.idle));
  let c = await cards(t.page); out.open = c;
  if (old) ok('СТАРО: blur зад менюто', /blur/.test(c.bd), c.bd); else ok('зад менюто няма blur, затъмняването е включено', c.bd === 'none' && c.bdOn, JSON.stringify(c));
  ok('панелът се отваря с карти', c.vis >= 1, JSON.stringify(c)); ok('без JS грешки', t.errs.length === 0, t.errs.join('|')); await t.page.close();

  /* (1б) приближаване на мишката до хедъра (idle е спрян, за да се види само този път) */
  if (!old) {
    t = await open({ now: at('2026-11-23T13:00:00'), noIdle: true }); await t.page.waitForTimeout(1200); const a = t.req.size; await t.page.mouse.move(700, 600); await t.page.waitForTimeout(400); const b = t.req.size; await t.page.mouse.move(700, 180); await t.page.waitForTimeout(600); const d = t.req.size;
    out.intent = { noMove: a, farMove: b, nearHeader: d }; ok('мишка далеч от хедъра: 0 заявки; при хедъра: 8', a === 0 && b === 0 && d === 8, JSON.stringify(out.intent));
    const pr = await t.page.evaluate(() => [...document.querySelectorAll('.amm-card img')].every((i) => i.loading === 'eager')); ok('картите са eager след приближаване', pr); await t.page.close();
    /* телефон: нищо не се зарежда предварително */
    t = await open({ now: at('2026-11-23T13:00:00'), w: 390, h: 844 }); await t.page.waitForTimeout(5000); out.phone = t.req.size; ok('телефон 390px: без предварително зареждане', t.req.size === 0, String(t.req.size)); await t.page.close();
  }

  /* (3) кампанията по час върху HTML, „кеширан“ на 23.11 */
  if (!old) {
    for (const [when, pill, promo] of [['2026-11-23T13:00:00', [], []], ['2026-11-25T06:59:00', [], []], ['2026-11-25T07:00:30', ['Черен петък'], ['Черен петък 2026 · до 30.11']], ['2026-11-30T09:59:00', ['Черен петък'], ['Черен петък 2026 · до 30.11']], ['2026-11-30T10:00:30', [], []], ['2026-11-30T23:59:00', [], []], ['2026-12-01T00:00:30', ['Сезонът'], ['Сезонът на ANSA']]]) {
      t = await open({ now: at(when) }); await t.page.waitForTimeout(200); const s = await seen(t.page); c = await cards(t.page);
      const pOk = s.pill.length === pill.length && pill.every((x, i) => s.pill[i].startsWith(x)); const cOk = c.promo.length === promo.length && promo.every((x, i) => c.promo[i] === x);
      out[when] = { pill: s.pill, promo: c.promo }; ok(`${when}: хапче [${pill}] и карта [${promo}]`, pOk && cOk, JSON.stringify(out[when])); ok(`${when}: без JS грешки`, t.errs.length === 0, t.errs.join('|')); await t.page.close();
    }
  }
  await browser.close(); console.log(JSON.stringify(out));
  if (fails.length) { console.log('ГРЕШКИ:\n- ' + fails.join('\n- ')); process.exit(1); } console.log(`OK ${n} проверки`);
})();
