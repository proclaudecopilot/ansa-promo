// 1.53.0: Playwright проверка на 📈 Insights → 🛒 Изоставени колички (tests/harness/carts-page.php) — без хоризонтален скрол на 1440/1280/1024/390,
// без JS грешки, всеки ред има основен бутон, „Отбележи резултат“ се отваря (само един наведнъж, Esc затваря), полетата следват статуса,
// бутоните се различават от етикетите (заобленост), телефонът е tel: линк. node tests/harness/carts-check.js /tmp/carts.html [shots-dir]
const { chromium } = require('playwright'); const path = require('path');
(async () => {
  const file = 'file://' + path.resolve(process.argv[2]); const shots = process.argv[3] || ''; const fails = []; let n = 0;
  const ok = (name, cond, info = '') => { n++; if (!cond) fails.push(name + (info ? ' — ' + info : '')); };
  const browser = await chromium.launch();
  for (const [w, h, tag] of [[1440, 900, 'desk'], [1280, 800, 'laptop'], [1024, 768, 'tablet'], [390, 844, 'mob']]) {
    const page = await browser.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: shots ? 2 : 1 }); const errs = []; page.on('pageerror', e => errs.push(String(e)));
    await page.goto(file); await page.waitForTimeout(250);
    // рамката е position:fixed със свой скрол — за цял скрийншот и за мерене я разпъваме
    await page.addStyleTag({ content: '#ansa-promo-app{position:static!important;height:auto!important;overflow:visible!important}.ap-page{height:auto!important;overflow:visible!important}.ap-body{overflow:visible!important}.ap-nav{max-height:none!important}' });
    const st = await page.evaluate(() => {
      const q = s => [...document.querySelectorAll(s)]; const r = el => parseFloat(getComputedStyle(el).borderTopLeftRadius);
      return {
        sw: document.documentElement.scrollWidth, iw: innerWidth, rows: q('.apc-row').length, go: q('.apc-row [data-op=go]').length, orders: q('.apc-row .apc-order').length,
        tabs: q('.apc-tab').map(a => a.textContent.trim()), on: (document.querySelector('.apc-tab.on') || {}).textContent, kpis: q('.apc-kpi').length,
        btnR: Math.min(...q('.apc-row .ap-abtn').map(r)), pillR: Math.max(...q('.apc-row .ap-pill').map(r)), tels: q('a.apc-tel').filter(a => /^tel:\+?\d+$/.test(a.getAttribute('href'))).length, telAll: q('a.apc-tel').length,
        dueFirst: !!document.querySelector('.apc-row') && (q('.apc-row.is-due').length === 0 || document.querySelector('.apc-row').classList.contains('is-due')), helpOpen: !!document.querySelector('.apc-help[open]'),
        minBtnH: Math.min(...q('.apc-row .ap-abtn').filter(b => b.offsetParent !== null).map(b => b.getBoundingClientRect().height)),
      };
    });
    ok(tag + ': без хоризонтален скрол', st.sw <= st.iw, st.sw + ' > ' + st.iw);
    ok(tag + ': без JS грешки', errs.length === 0, errs.join(' | '));
    ok(tag + ': има редове и всеки ред е с основен бутон или с поръчка', st.rows > 0 && st.go + st.orders === st.rows, JSON.stringify(st));
    ok(tag + ': 4 таба „работа“ с броячи, един избран', st.tabs.length === 4 && st.tabs.every(t => /\d/.test(t)) && !!st.on, JSON.stringify(st.tabs));
    ok(tag + ': 4 плочки', st.kpis === 4);
    ok(tag + ': бутоните са заоблени (≥ 17px), етикетите са с прави ъгли (≤ 6px)', st.btnR >= 17 && st.pillR <= 6, st.btnR + ' / ' + st.pillR);
    ok(tag + ': всеки телефон е tel: линк само с цифри', st.telAll > 0 && st.tels === st.telAll, st.tels + ' / ' + st.telAll);
    ok(tag + ': просрочените обратни обаждания са най-горе', st.dueFirst);
    ok(tag + ': помощта е сгъната', !st.helpOpen);
    ok(tag + ': бутоните в редовете са ≥ 34px високи' + (w < 500 ? ' (на телефон ≥ 44px)' : ''), st.minBtnH >= (w < 500 ? 44 : 34), String(st.minBtnH));
    if (shots) await page.screenshot({ path: path.join(shots, 'carts-' + tag + '.png'), fullPage: true });
    // „Отбележи резултат“: отваря се, само един наведнъж, полетата следват статуса, Esc затваря
    const sums = page.locator('.apc-row .ap-work__d > summary');
    await sums.nth(1).click(); await page.waitForTimeout(80);
    let f = await page.evaluate(() => { const d = document.querySelectorAll('.apc-row .ap-work__d')[1], vis = s => { const e = d.querySelector(s); return !!e && e.offsetParent !== null; }; const p = d.querySelector('.ap-work__p').getBoundingClientRect(); return { open: d.open, n: document.querySelectorAll('details.ap-pop[open]').length, cb: vis('.ap-work__f--cb'), order: vis('.ap-work__f--order'), quick: [...d.querySelectorAll('.ap-work__q button')].map(b => b.textContent.trim()), left: p.left, right: p.right, iw: innerWidth }; });
    ok(tag + ': панелът се отваря, вътре в екрана, с трите бързи бутона с текст', f.open && f.left >= 0 && f.right <= f.iw + 1 && f.quick.length === 3 && f.quick.every(t => t.length > 4), JSON.stringify(f));
    ok(tag + ': „Звънни пак на“ и „Поръчка №“ са скрити при „за прозвъняване“', !f.cb && !f.order, JSON.stringify(f));
    await page.locator('.apc-row .ap-work__d').nth(1).locator('select[name=status]').selectOption('callback'); await page.waitForTimeout(50);
    f = await page.evaluate(() => { const d = document.querySelectorAll('.apc-row .ap-work__d')[1], vis = s => { const e = d.querySelector(s); return !!e && e.offsetParent !== null; }; return { cb: vis('.ap-work__f--cb'), order: vis('.ap-work__f--order') }; });
    ok(tag + ': при „обратно обаждане“ излиза „Звънни пак на“ (без „Поръчка №“)', f.cb && !f.order, JSON.stringify(f));
    if (shots) await page.screenshot({ path: path.join(shots, 'carts-' + tag + '-form.png'), fullPage: w < 500 });
    await sums.nth(0).click(); await page.waitForTimeout(80); // друг ред (по-горе — отвореният панел покрива редовете под себе си)
    ok(tag + ': само един панел е отворен', await page.evaluate(() => document.querySelectorAll('details.ap-pop[open]').length) === 1);
    await page.keyboard.press('Escape'); await page.waitForTimeout(50);
    ok(tag + ': Esc затваря панела', await page.evaluate(() => document.querySelectorAll('details.ap-pop[open]').length) === 0);
    // жестът по скрипта: бутонът „Възстанови количката“ първо пита
    await page.locator('.apc-row [data-op=go]').first().click(); await page.waitForTimeout(80);
    ok(tag + ': „Възстанови количката“ пита за жеста', await page.evaluate(() => !!document.getElementById('apOpPick')));
    await page.keyboard.press('Escape');
    await page.locator('.apc-help > summary').click(); await page.waitForTimeout(60);
    ok(tag + ': помощта се разгъва (4 колони текст)', await page.evaluate(() => document.querySelectorAll('.apc-help[open] .apc-help__b > div').length) === 4);
    if (shots) await page.screenshot({ path: path.join(shots, 'carts-' + tag + '-help.png') });
    await page.close();
  }
  await browser.close();
  if (fails.length) { console.log('FAIL\n - ' + fails.join('\n - ')); process.exit(1); }
  console.log('OK ' + n + ' проверки');
})();
