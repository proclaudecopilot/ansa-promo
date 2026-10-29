// 1.44.0: Playwright проверка на /shop/ — без хоризонтален скрол, 13 карти, чиповете филтрират, #meno предизбира, без JS грешки.
const { chromium } = require('playwright'); const path = require('path');
(async () => {
  const file = 'file://' + path.resolve(process.argv[2]); const shots = process.argv[3] || ''; const fails = []; let n = 0;
  const ok = (name, cond, info = '') => { n++; if (!cond) fails.push(name + (info ? ' — ' + info : '')); };
  const browser = await chromium.launch();
  for (const [w, h, tag] of [[1440, 900, 'desk'], [1024, 768, 'tablet'], [390, 844, 'mob']]) {
    const page = await browser.newPage({ viewport: { width: w, height: h } }); const errs = []; page.on('pageerror', e => errs.push(String(e)));
    await page.goto(file); await page.waitForTimeout(200);
    const st = async () => page.evaluate(() => { const vis = [...document.querySelectorAll('.as-card')].filter(c => !c.hidden); return { sw: document.documentElement.scrollWidth, iw: innerWidth, vis: vis.length, title: document.querySelector('.as-title').textContent, cnt: document.querySelector('.as-cnt').textContent, on: document.querySelector('.as-chip.on').getAttribute('data-n'), cols: getComputedStyle(document.querySelector('.as-grid')).gridTemplateColumns.split(' ').length, h1: document.querySelector('.as-hero h1').textContent, hash: location.hash, prices: vis.map(c => c.querySelector('.as-price').textContent) }; });
    let s = await st();
    ok(`${tag}: без хоризонтален скрол`, s.sw <= s.iw, JSON.stringify(s));
    ok(`${tag}: 13 карти, заглавие, колони`, s.vis === 13 && s.h1 === 'Всички продукти' && s.cnt === '13 продукта' && s.cols === (w >= 1101 ? 4 : w > 640 ? 3 : 2), JSON.stringify(s));
    ok(`${tag}: цените са „от“ 1 кутия (EyeFresh 53,92 → 15,90)`, s.prices.some(p => p.includes('15,90')) && !s.prices.some(p => p.includes('53,92')), s.prices.join(' | '));
    if (shots) await page.screenshot({ path: `${shots}/shop-${tag}.png`, fullPage: true });
    await page.click('.as-chip[data-n="meno"]'); await page.waitForTimeout(100); s = await st();
    ok(`${tag}: чипът Менопауза → 5 продукта, #meno`, s.vis === 5 && s.title === 'Менопауза' && s.cnt === '5 продукта' && s.on === 'meno' && s.hash === '#meno', JSON.stringify(s));
    if (shots && tag === 'desk') await page.screenshot({ path: `${shots}/shop-${tag}-meno.png`, fullPage: false });
    await page.click('.as-chip[data-n="all"]'); await page.waitForTimeout(100); s = await st();
    ok(`${tag}: обратно на всички`, s.vis === 13 && s.on === 'all' && s.hash === '', JSON.stringify(s));
    await page.goto(file + '#skin'); await page.reload(); await page.waitForTimeout(200); s = await st();
    ok(`${tag}: #skin в адреса предизбира „Кожа и красота“`, s.on === 'skin' && s.title === 'Кожа и красота' && s.vis === 3, JSON.stringify(s));
    await page.evaluate(() => { location.hash = '#gut'; }); await page.waitForTimeout(100); s = await st();
    ok(`${tag}: смяна на hash → Храносмилане`, s.on === 'gut' && s.vis === 2, JSON.stringify(s));
    ok(`${tag}: без JS грешки`, errs.length === 0, errs.join(' | '));
    await page.close();
  }
  await browser.close();
  if (fails.length) { console.log('ГРЕШКИ:\n- ' + fails.join('\n- ')); process.exit(1); } console.log('OK ' + n + ' проверки');
})();
