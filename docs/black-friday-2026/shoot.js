const { chromium } = require('playwright');
(async () => {
  const dir = __dirname;
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1400, height: 1000 }, deviceScaleFactor: 2 });
  const p = await ctx.newPage();
  await p.goto('file://' + dir + '/mockup.html', { waitUntil: 'networkidle' });
  await p.evaluate(() => document.fonts.ready);
  await p.waitForTimeout(400);
  const names = {s1:'01-signup-landing',s1b:'01b-signup-done',s2:'02-email-7d-existing-greens',s3:'03-email-7d-new-signup',s4:'04-email-2d-reminder',s4b:'04b-email-start-cta',s5:'05-early-access-start',s5b:'05b-product-info-sheet',s6:'06-gift-picker',s7:'07-checkout-summary',s8:'08-email-12h-left',s9:'09-public-black-friday',s10:'10-ad-berberin',s11:'11-ad-2-equals-4',s12:'12-ad-signup'};
  const foldIds = ['s1','s5','s6','s7','s9'];
  for (const [id, name] of Object.entries(names)) {
    const el = await p.$('#' + id + ' .phone');
    await el.screenshot({ path: `${dir}/shots/${name}.png` });
    const box = await el.boundingBox();
    console.log(name, Math.round(box.width), 'x', Math.round(box.height));
  }
  // first fold: 390x844 with the sticky footer pinned at the bottom (as on a real phone)
  for (const id of foldIds) {
    await p.$eval('#' + id + ' .phone', e => e.classList.add('fold'));
    const el = await p.$('#' + id + ' .phone');
    await el.screenshot({ path: `${dir}/shots/${names[id]}-fold.png` });
    await p.$eval('#' + id + ' .phone', e => e.classList.remove('fold'));
  }
  await b.close();
})();
