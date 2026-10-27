/* 1.32.0: Playwright проверка на имейл-попъпа — никога при кацане (и с ?box=l / ?fill=), на избраната стъпка (box / full / order / random), „Продължи без имейл“ е голям бутон.
   python3 tests/harness/build.py OUTDIR && NODE_PATH=$(npm root -g) node tests/harness/gate.js OUTDIR   (прави gate-<when>.html варианти от harness/index.html и снимки в shots/) */
const { chromium } = require('playwright');
const fs = require('fs');
const S = process.argv[2];
const base = fs.readFileSync(S + '/harness/index.html', 'utf8');
if (!base.includes('"when":"random"')) { console.log('runtime няма gate.when=random'); process.exit(1); }
function variant(when) { const f = S + '/harness/gate-' + when + '.html'; fs.writeFileSync(f, base.replace('"when":"random"', '"when":"' + when + '"')); return 'file://' + f; }
(async () => {
  const browser = await chromium.launch();
  const errors = [], notes = [];
  async function fresh(url, w = 390, h = 844) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, isMobile: w < 500, hasTouch: w < 500 });
    const page = await ctx.newPage();
    await page.route(/fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    page.on('pageerror', e => errors.push(url.split('/').pop() + ': ' + e.message));
    page.on('console', m => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
    await page.goto(url, { waitUntil: 'domcontentloaded' }); await page.waitForTimeout(600);
    return { ctx, page };
  }
  const gateOpen = page => page.evaluate(() => { const dc = document.getElementById('apDc'), ov = document.getElementById('apOv'); return !!dc && dc.classList.contains('gatew') && !!ov && !ov.classList.contains('off'); });
  const st = page => page.evaluate(() => 'dc:' + (document.getElementById('apDc') || {}).className + ' ov:' + (document.getElementById('apOv') || {}).className + ' screen:' + AnsaPromo.state().screen + ' gateAt:' + AnsaPromo.state().gateAt);
  const liveFlags = page => page.evaluate(() => { const s = AnsaPromo.state(); s.emailDone = false; window.AnsaPromoRuntime.editorNoGate = false; });

  /* 1. кацане без параметри и с ?box=l — без попъп до 2 с, при всяка настройка */
  for (const when of ['random', 'box', 'full', 'order']) {
    for (const qs of ['', '?box=l', '?box=l&fill=sakura']) {
      const { ctx, page } = await fresh(variant(when) + qs);
      await page.waitForTimeout(1600);
      if (await gateOpen(page)) errors.push('when=' + when + ' ' + qs + ': попъпът излезе при кацане');
      notes.push('кацане when=' + when + ' ' + (qs || '(без параметри)') + ' → ' + await st(page));
      await ctx.close();
    }
  }
  /* 2. when=box: кутия от екран 1 → попъп → бутонът „Продължи без имейл“ → пълненето; после не излиза пак */
  { const { ctx, page } = await fresh(variant('box'));
    await page.locator('[data-box]:visible').first().click(); await page.waitForTimeout(800);
    if (!(await gateOpen(page))) errors.push('when=box: попъпът не излезе след избор на кутия (' + await st(page) + ')');
    const sk = await page.$eval('#gSkip', el => { const r = el.getBoundingClientRect(), cs = getComputedStyle(el); return { w: Math.round(r.width), h: Math.round(r.height), fs: cs.fontSize, border: cs.borderTopWidth, text: el.textContent.trim() }; }).catch(() => null);
    notes.push('gSkip (390px): ' + JSON.stringify(sk));
    if (!sk || sk.h < 40 || sk.w < 260) errors.push('gSkip е малък: ' + JSON.stringify(sk));
    await page.screenshot({ path: S + '/shots/gate-box-mobile.png' });
    await page.click('#gSkip'); await page.waitForTimeout(900);
    const after = await st(page); notes.push('след Продължи без имейл → ' + after);
    if (await gateOpen(page)) errors.push('when=box: попъпът остана след бутона');
    const fillOpen = await page.evaluate(() => { const dc = document.getElementById('apDc'), ov = document.getElementById('apOv'); return !!dc && /fpw|fpm|wide/.test(dc.className) && !ov.classList.contains('off'); });
    if (!fillOpen) errors.push('when=box: след бутона не се отвори пълненето: ' + after);
    await page.screenshot({ path: S + '/shots/gate-box-after-skip.png' });
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'order' })); await liveFlags(page); await page.waitForTimeout(700);
    if (await gateOpen(page)) errors.push('when=box: попъпът излезе втори път на „Поръчка“');
    await ctx.close(); }
  /* 3. when=order: кутия → без попъп; екран „Поръчка“ → попъп; десктоп размер на бутона */
  { const { ctx, page } = await fresh(variant('order'), 1280, 800);
    await page.locator('[data-box]:visible').first().click(); await page.waitForTimeout(800);
    if (await gateOpen(page)) errors.push('when=order: попъпът излезе на кутия');
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'order' })); await liveFlags(page); await page.waitForTimeout(700);
    if (!(await gateOpen(page))) errors.push('when=order: попъпът не излезе на „Поръчка“ (' + await st(page) + ')');
    const sk = await page.$eval('#gSkip', el => { const r = el.getBoundingClientRect(), cs = getComputedStyle(el); return { w: Math.round(r.width), h: Math.round(r.height), fs: cs.fontSize }; }).catch(() => null);
    notes.push('gSkip (1280px): ' + JSON.stringify(sk)); if (!sk || sk.h < 38) errors.push('gSkip десктоп е малък: ' + JSON.stringify(sk));
    await page.screenshot({ path: S + '/shots/gate-order-desktop.png' });
    await ctx.close(); }
  /* 4. when=full: попъпът за пълнене с пълна кутия → бутонът „Напълних“ (#nFill, реалният път към „Честито“) → попъпът за имейл → след бутона „Честито“ */
  { const { ctx, page } = await fresh(variant('full'));
    await page.evaluate(() => AnsaPromo.scenario({ screen: 'fill', box: 'l', full: true })); await liveFlags(page); await page.waitForTimeout(300);
    await page.evaluate(() => { const b = document.querySelector('#apOv2 button'); if (b) b.click(); }); await page.waitForTimeout(300); /* интрото на пълненето („ОК, разбрах“) — харнесът го отваря със сценария */
    const has = await page.evaluate(() => !!document.getElementById('nFill')); if (!has) errors.push('when=full: няма #nFill в попъпа за пълнене');
    await page.evaluate(() => { const b = document.getElementById('nFill'); if (b) b.click(); }); await page.waitForTimeout(1000);
    if (!(await gateOpen(page))) errors.push('when=full: попъпът за имейл не излезе след „Напълних“ (' + await st(page) + ')');
    else { await page.screenshot({ path: S + '/shots/gate-full-mobile.png' }); await page.click('#gSkip'); await page.waitForTimeout(1000); const celeb = await page.evaluate(() => !!document.getElementById('cGo')); notes.push('full: след бутона → ' + await st(page) + ' cGo:' + celeb); if (!celeb) errors.push('when=full: след бутона няма „Честито“'); }
    await ctx.close(); }
  /* 5. random: gateAt се тегли веднъж и е една от трите */
  { const seen = new Set(); for (let i = 0; i < 6; i++) { const { ctx, page } = await fresh(variant('random')); await page.locator('[data-box]:visible').first().click(); await page.waitForTimeout(500); const g = await page.evaluate(() => AnsaPromo.state().gateAt); seen.add(g); if (!['box', 'full', 'order'].includes(g)) errors.push('random: gateAt=' + g); await ctx.close(); }
    notes.push('random gateAt за 6 сесии: ' + [...seen].join(', ')); }
  await browser.close();
  console.log(notes.join('\n'));
  if (errors.length) { console.log('\nГРЕШКИ:\n - ' + errors.join('\n - ')); process.exit(1); }
  console.log('\nOK — попъпът: без при кацане, на избраната стъпка, бутонът е голям');
})();
