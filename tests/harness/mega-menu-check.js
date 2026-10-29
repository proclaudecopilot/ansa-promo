// 1.42.3: Playwright проверка на мега менюто — без вертикален скрол в панела на няколко екрана, страниците ‹ › работят, филтърът по нужда също.
// node tests/harness/mega-menu-check.js /tmp/mm.html [shots-dir]
const { chromium } = require('playwright'); const path = require('path');
(async () => {
  const file = 'file://' + path.resolve(process.argv[2]); const shots = process.argv[3] || ''; const fails = []; let n = 0;
  const ok = (name, cond, info = '') => { n++; if (!cond) fails.push(name + (info ? ' — ' + info : '')); };
  const browser = await chromium.launch(); 
  for (const [w, h] of [[1920, 787], [1920, 1080], [1366, 720], [1280, 650], [1600, 900]]) {
    const page = await browser.newPage({ viewport: { width: w, height: h } }); await page.goto(file); await page.waitForTimeout(300);
    const st = async () => page.evaluate(() => { const r = document.querySelector('.amm'); const vis = [...document.querySelectorAll('.amm-card')].filter(c => !c.hidden); const rect = r.getBoundingClientRect();
      return { sh: r.scrollHeight, ch: r.clientHeight, bottom: rect.bottom, inner: innerHeight, vis: vis.length, pager: document.querySelector('.amm-pager').hidden ? '' : document.querySelector('.amm-pgn').textContent, cnt: document.querySelector('.amm-cnt').textContent, title: document.querySelector('.amm-title').textContent, lastVisBottom: vis.length ? Math.max(...vis.map(c => c.getBoundingClientRect().bottom)) : 0, footBottom: document.querySelector('.amm-foot').getBoundingClientRect().bottom, next: document.querySelector('.amm-next').disabled, prev: document.querySelector('.amm-prev').disabled }; });
    let s = await st(); const tag = `${w}x${h}`;
    ok(`${tag} всички: без скрол`, s.sh <= s.ch + 1 && s.footBottom <= s.inner + 1, JSON.stringify(s));
    ok(`${tag} всички: видимите ≤ 13 и страницата е цяла`, s.vis >= 1 && s.vis <= 13 && (s.vis === 13 ? s.pager === '' : s.pager.startsWith('1 / ')), JSON.stringify(s));
    if (shots) await page.screenshot({ path: `${shots}/mm-${tag}-all.png` });
    const first = s.vis, pages = s.pager ? parseInt(s.pager.split('/')[1]) : 1; let seen = first;
    for (let p = 2; p <= pages; p++) { await page.click('.amm-next'); const t = await st(); seen += t.vis; ok(`${tag} страница ${p}: без скрол, брояч`, t.sh <= t.ch + 1 && t.pager === `${p} / ${pages}` && t.vis > 0, JSON.stringify(t)); if (shots && p === 2) await page.screenshot({ path: `${shots}/mm-${tag}-p2.png` }); }
    ok(`${tag} всички 13 карти се виждат през страниците`, seen === 13, `seen=${seen} pages=${pages} first=${first}`);
    if (pages > 1) { s = await st(); ok(`${tag} на последната „›“ е изключен`, s.next === true); }
    await page.hover('.amm-need[data-n="meno"]'); await page.waitForTimeout(150); s = await st();
    ok(`${tag} Менопауза: 5 продукта, без скрол, от страница 1`, s.title === 'Менопауза' && s.cnt.startsWith('5 продукта') && s.sh <= s.ch + 1 && (s.pager === '' || s.pager.startsWith('1 / ')), JSON.stringify(s));
    const names = await page.evaluate(() => [...document.querySelectorAll('.amm-card')].filter(c => !c.hidden).map(c => c.querySelector('b').textContent));
    ok(`${tag} Менопауза страница 1 започва с MenoStop`, names[0] === 'MenoStop', names.join(','));
    if (shots) await page.screenshot({ path: `${shots}/mm-${tag}-meno.png` });
    await page.hover('.amm-need[data-n="all"]'); await page.waitForTimeout(150); s = await st(); ok(`${tag} обратно на всички: страница 1`, s.title === 'Всички продукти' && (s.pager === '' || s.pager.startsWith('1 / ')), JSON.stringify(s));
    const errs = []; page.on('pageerror', e => errs.push(String(e))); await page.waitForTimeout(100); ok(`${tag} без JS грешки`, errs.length === 0, errs.join(' | '));
    await page.close();
  }
  await browser.close();
  if (fails.length) { console.log('ГРЕШКИ:\n- ' + fails.join('\n- ')); process.exit(1); } console.log('OK ' + n + ' проверки');
})();
