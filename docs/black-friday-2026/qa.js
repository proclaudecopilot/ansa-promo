// QA: обикаля всеки екран на емулатора на 390×844 и 360×640, мери overflow/изрязан текст/бутони на 2+ реда и прави снимки в qa-shots/.
// Пуска се с: node docs/black-friday-2026/qa.js (преди това mkdir qa-shots; _emu_test.html = emulator.html, увит в <!doctype html><html><body>).
const { chromium } = require('playwright');
const U='file://'+__dirname+'/_emu_test.html';
const OUT=__dirname+'/qa-shots/';
const issues=[];
async function audit(p,label){
  const r=await p.evaluate(()=>{
    const ph=document.getElementById('phone'); const pr=ph.getBoundingClientRect(); const out=[];
    const all=[...ph.querySelectorAll('*')];
    for(const el of all){
      const cs=getComputedStyle(el); if(cs.display==='none'||cs.visibility==='hidden') continue;
      const r=el.getBoundingClientRect(); if(r.width===0||r.height===0) continue;
      // horizontal overflow beyond phone
      if(r.right>pr.right+1||r.left<pr.left-1){ if(!el.closest('.vch')) out.push(`overflow-x: <${el.tagName.toLowerCase()} class="${el.className}"> right=${Math.round(r.right-pr.right)}`); }
      // clipped text (ellipsis/overflow hidden with scrollWidth bigger)
      if((cs.overflow==='hidden'||cs.overflowX==='hidden')&&cs.textOverflow==='ellipsis'&&el.scrollWidth>el.clientWidth+2&&el.children.length===0) out.push(`ellipsis: "${el.textContent.trim().slice(0,40)}"`);
      // buttons wrapping to 2+ lines where nowrap intended
      if(el.matches('.addb,.chip,.tpill,.pill,.sum')&&r.height>44) out.push(`tall-control(${Math.round(r.height)}px): "${el.textContent.trim().slice(0,30)}"`);
      // cta more than 2 lines
      if(el.matches('.cta')&&r.height>70) out.push(`cta-3lines(${Math.round(r.height)}px): "${el.textContent.trim().slice(0,40)}"`);
    }
    // measure key widths for consistency
    const w=sel=>{const e=ph.querySelector(sel);return e?Math.round(e.getBoundingClientRect().width):null;};
    return {out, widths:{vch:w('.vch'), card:w('.card'), tile:w('.tile'), rows:w('.rows'), cta:w('.foot .cta')}};
  });
  r.out.forEach(x=>issues.push(`[${label}] ${x}`));
  return r.widths;
}
(async()=>{
  const b=await chromium.launch();
  for(const [w,h] of [[390,844],[360,640]]){
    const ctx=await b.newContext({viewport:{width:w,height:h},isMobile:true,hasTouch:true}); const p=await ctx.newPage();
    p.on('pageerror',e=>issues.push(`[${w}] pageerror ${e.message}`));
    const widths={};
    const shot=async(n)=>{ await p.waitForTimeout(250); widths[n]=await audit(p,`${w} ${n}`); await p.screenshot({path:`${OUT}${w}-${n}.png`}); };
    await p.goto(U+'#prep'); await shot('01-cold-signup');
    await p.fill('#emailIn','maria@test.bg'); await p.click('#signupForm button[type=submit]'); await shot('02-wallet');
    await p.click('[data-act="cart+"][data-id=greens]'); await shot('03-wallet-cart');
    await p.click('[data-act=sheet][data-id=greens]'); await shot('04-wallet-sheet'); await p.click('.shx');
    await p.click('[data-act=screen][data-id=precheckout]'); await shot('05-precheckout');
    await p.click('[data-act=order]'); await shot('06-predone');
    await p.click('#resetBtn'); await p.evaluate(()=>document.querySelector('.bar').classList.remove('collapsed')); await p.click('#srcSeg button[data-src=aff]'); await shot('07-aff-signup');
    await p.fill('#emailIn','maria@test.bg'); await p.click('#signupForm button[type=submit]'); await shot('08-aff-done');
    await p.evaluate(()=>document.querySelector('.bar').classList.remove('collapsed')); await p.click('#phaseSeg button[data-phase=emails]');
    for(const k of ['e7old','e7new','e2','estart','e12','epub','stories']){ await p.click(`[data-act=emailview][data-id=${k}]`); await shot('09-email-'+k); }
    await p.evaluate(()=>document.querySelector('.bar').classList.remove('collapsed')); await p.click('#phaseSeg button[data-phase=early]'); await shot('10-early-shop-empty');
    await p.click('[data-act="cart+"][data-id=greens]'); await p.click('[data-act="cart+"][data-id=greens]'); await p.click('[data-act="cart+"][data-id=berberin]'); await shot('11-early-shop-3');
    await p.click('[data-act=sheet][data-id=sakura]'); await shot('12-early-sheet'); await p.click('.shx');
    await p.click('[data-act=screen][data-id=gifts]'); await shot('13-gifts-0');
    await p.click('[data-act="gift+"][data-id=kera]'); await p.click('[data-act="gift+"][data-id=kera]'); await shot('14-gifts-2');
    await p.click('[data-act=sheet][data-id=alivia]'); await shot('15-gifts-sheet'); await p.click('.sheet [data-act="gift+"]'); await shot('16-gifts-3');
    await p.click('[data-act=screen][data-id=checkout]'); await shot('17-checkout');
    await p.click('[data-act=pay][data-id=cod]'); await p.click('[data-act=order]'); await shot('18-ordered');
    await p.evaluate(()=>document.querySelector('.bar').classList.remove('collapsed')); await p.click('#phaseSeg button[data-phase=public]'); await p.click('[data-act=again]').catch(()=>{}); await shot('19-public-shop-empty');
    await p.click('[data-act="cart+"][data-id=yoni]'); await shot('20-public-shop-1');
    await p.click('[data-act=screen][data-id=gifts]'); await p.click('[data-act="gift+"][data-id=greens]'); await p.click('[data-act=screen][data-id=checkout]'); await shot('21-public-checkout-aff');
    await p.click('[data-act=order]'); await shot('22-public-ordered');
    // consistency: voucher widths signup vs wallet
    if(widths['01-cold-signup'].vch!==widths['02-wallet'].vch) issues.push(`[${w}] voucher width differs: ${widths['01-cold-signup'].vch} vs ${widths['02-wallet'].vch}`);
    const tileW=new Set(Object.values(widths).map(x=>x.tile).filter(Boolean)); if(tileW.size>1) issues.push(`[${w}] tile widths vary: ${[...tileW].join(',')}`);
    const cardW=new Set(Object.values(widths).map(x=>x.card).filter(Boolean)); if(cardW.size>1) issues.push(`[${w}] card widths vary: ${[...cardW].join(',')}`);
    await ctx.close();
  }
  await b.close();
  console.log(issues.length?issues.join('\n'):'NO ISSUES');
})();
