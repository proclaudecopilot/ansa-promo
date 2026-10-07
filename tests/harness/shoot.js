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
    if (w < 500) { await page.click('#apYfabB'); await shot('yfab'); await check('yfab'); await page.click('#apYfabOk'); await page.click('[data-more-box="l"]'); await shot('boxes-open'); }
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'fill', box: 'l', utm: 'sakura' })); await shot('fill'); await check('fill');
    { const ok = await page.$('#okBtn'); if (ok) { await ok.click(); await page.waitForTimeout(200); await shot('fill-ok'); } }
    // add 4 more via steppers on mobile/desktop
    await page.evaluate(() => { const d = document.getElementById('apDc'); for (let i = 0; i < 4; i++) { const b = d.querySelector('[data-inc="meno"]:not([disabled])') || d.querySelector('[data-inc]:not([disabled])'); if (b) b.click(); } });
    await shot('fill-done');
    await page.click('#nFill'); await page.waitForTimeout(600); await shot('celeb'); await check('celeb');
    await page.click('#cGo'); await shot('order'); await check('order');
    await page.click('#finish'); await page.waitForTimeout(200); await shot('order-toast');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'boxes', utm: null })); await shot('boxes-noutm'); await check('boxes-noutm');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'fill', box: 'm', utm: null })); await check('fill-noutm');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'celeb', box: 's', utm: 'sakura', full: true })); await shot('celeb-small'); await check('celeb-small');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'builder', box: 'm', utm: 'sakura' })); await shot('builder'); await check('builder');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'sure', box: 'm', utm: 'sakura', full: true })); await shot('sure'); await check('sure');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'switch', box: 'l', utm: 'sakura', full: true })); await check('switch');
    await page.evaluate(() => { document.querySelector('.bx.s').click(); }); await page.waitForTimeout(200); await shot('suredown'); await check('suredown');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'product', utm: 'sakura' })); await shot('product'); await check('product');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'how' })); await check('how');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'picker', box: 'l', utm: 'sakura', full: false })); await shot('picker'); await check('picker');
    console.log(tag, 'shots:', shots.join(' '));
    await ctx.close();
  }
  await run(390, 780, 'm390');
  await run(1280, 900, 'd1280');
  await browser.close();
  console.log(errors.length ? 'ERRORS:\n' + errors.join('\n') : 'no errors');
})();
