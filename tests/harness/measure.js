const { chromium } = require('playwright');
(async () => {
  const S = process.argv[2];
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' }).catch(() => chromium.launch());
  for (const [w, h] of [[1280, 720], [1366, 768], [1440, 900], [1536, 864], [1920, 1080]]) {
    const ctx = await b.newContext({ viewport: { width: w, height: h } }); const p = await ctx.newPage();
    await p.route(/fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    await p.goto('file://' + S + '/h2/harness/index.html', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(300);
    await p.evaluate(() => AnsaPromo.scenario({ screen: 'boxes', utm: 'sakura' })); await p.waitForTimeout(300);
    const r = await p.evaluate(() => { const d = document.documentElement; const wrap = document.querySelector('.ansa-promo .wrap'); const boxes = document.querySelector('.ansa-promo .boxes'); return { scroll: d.scrollHeight, vh: innerHeight, wrapW: wrap.getBoundingClientRect().width, boxesBottom: Math.round(boxes.getBoundingClientRect().bottom), zoom: getComputedStyle(wrap).zoom }; });
    console.log(w + 'x' + h, JSON.stringify(r));
    if (process.argv[3]) await p.screenshot({ path: S + '/h2/shots/fit-' + w + 'x' + h + '.png' });
    await ctx.close();
  }
  await b.close();
})();
