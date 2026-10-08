/* ansa™ Промо — фронт (порт на мокъп v73, три кутии).
 * Данни: window.AnsaPromoRuntime (продукти с цени от WC, кутии, награди, копи). Нищо не се смята от клиента за реалната цена —
 * сървърът преизчислява при добавяне (Фаза 3). Всички текстове минават през T(key, vars) — ключовете са в class-copy.php.
 * Функциите носят имената от мокъпа (chooseBox, fillPopup, celebrate, productPopup, switchPopup, sureDown…), за да се diff-ва 1:1.
 * Редактор (Фаза 2): при A.editor елементите с data-ck пращат ключа към родителя; родителят праща copy/cfg/scenario. */
(function(){
  'use strict';
  var A=window.AnsaPromoRuntime;if(!A)return;
  var root=document.getElementById('ansaPromo');if(!root)return;
  var $=function(id){return document.getElementById(id)};
  /* v1.0.18: overlay-ите и тостът живеят на body („портал“). На телефон темата (OceanWP/Elementor) слага transform върху обвивката
     и position:fixed спира да е спрямо екрана — попъпът се „хващаше“ в обвивката, overlay-ят не покриваше екрана, футърът падаше под него. */
  var portal=document.createElement('div');portal.className='ansa-promo ansa-promo-portal';portal.setAttribute('data-no-translation','');if(root.getAttribute('data-editor'))portal.setAttribute('data-editor','1');
  ['apToast','apOv','apOv2'].forEach(function(id){var el=$(id);if(el)portal.appendChild(el)});document.body.appendChild(portal);
  /* v1.0.24: body класът се слага и от тук — страница, сглобена с Elementor, не го получава от PHP (shortcode-ът не е в post_content) */
  document.body.classList.add('ansa-promo-page');
  /* v1.0.24: на телефон лентата с яхтата е плаваща картинка долу вдясно (в портала, за да не я хване transform на темата) */
  var yfab=document.createElement('div');yfab.className='yfab';yfab.id='apYfab';yfab.style.display='none';portal.appendChild(yfab);

  /* ── помощници ── */
  function m(n){return '€ '+Number(n).toFixed(2).replace('.',',')}
  function m0(n){return '€ '+Math.round(Number(n))}
  function r2(n){return Math.round(n*100)/100}
  function opk(n){return n===1?'опаковка':'опаковки'}
  function prodw(n){return n===1?'продукт':'продукта'}
  function shans(n){return n===1?'шанс':'шанса'}
  function ths(n){return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g,'\u00a0')}
  function sPrep(w,cap){var z=/^[сзСЗ]/.test(String(w||''))?'със':'с';return cap?z.charAt(0).toUpperCase()+z.slice(1):z}
  function vPrep(w,cap){var z=/^[вфВФ]/.test(String(w||''))?'във':'в';return cap?z.charAt(0).toUpperCase()+z.slice(1):z}
  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})}
  function tpl(str,v){return String(str==null?'':str).replace(/\{\{\s*([a-z0-9_]+)\s*\}\}/gi,function(x,k){return v&&Object.prototype.hasOwnProperty.call(v,k)?String(v[k]):x})}
  function uniq(a){return a.filter(function(k,i){return a.indexOf(k)===i})}
  function money(n){return String(n).replace('.',',')}
  function lv(n){if(!A.bgn||!A.bgn.show)return '';return '<span class="bgn">('+(Number(n)*Number(A.bgn.rate||1.95583)).toFixed(2).replace('.',',')+' лв.)</span>'}
  function MOB(){return window.innerWidth<=640}
  function MOBF(){return window.innerWidth<=760} /* v1.0.15: пълненето е „мобилно“ и на големи телефони/малки таблети */

  var PROD,ORDER,FIT,PROBS,BOXES,SHIP,BOOK_VAL,COSM,YACHT,IC,BI,SECRET_PCT,SECRET_N;
  function bind(){
    PROD=A.products||{};ORDER=A.order||Object.keys(PROD);FIT=A.fit||{};PROBS=A.problems||[];BOXES=A.boxes||[];SHIP=Number(A.ship)||0;
    BOOK_VAL=Number(A.rewards&&A.rewards.book&&A.rewards.book.value)||0;COSM=Number(A.rewards&&A.rewards.cosm&&A.rewards.cosm.value)||0;YACHT=Number(A.rewards&&A.rewards.yacht&&A.rewards.yacht.value)||0;
    IC=(A.rewards&&A.rewards.icons)||{};BI={};BOXES.forEach(function(b,i){BI[b.id]=i});
    SECRET_PCT=Number(A.secret&&A.secret.pct)||0;SECRET_N=Number(A.secret&&A.secret.count)||0;
  }
  bind();

  /* ── състояние (мокъп S) + sessionStorage ── */
  var SKEY='ansa_promo_s';
  function freshS(){return {box:null,slots:[],core:A.utm||null,secret:{},pay:'card',cosm:true,screen:1,utm:A.utm||null,_undo:null,timerEnd:null,replaceSlot:null,emailDone:!(A.gate&&A.gate.enabled),email:'',lead:0}}
  var S=freshS();
  function saveS(){try{sessionStorage.setItem(SKEY,JSON.stringify({id:A.id,v:A.version,s:{box:S.box,slots:S.slots,core:S.core,secret:S.secret,pay:S.pay,cosm:S.cosm,screen:S.screen,utm:S.utm,timerEnd:S.timerEnd,emailDone:S.emailDone,email:S.email,lead:S.lead,quiz:S.quiz||null}}))}catch(e){}}
  function restoreS(){try{var raw=sessionStorage.getItem(SKEY);if(!raw)return false;var d=JSON.parse(raw);if(!d||d.id!==A.id||!d.s)return false;var s=d.s;
    if(s.box!==null&&(!BOXES[s.box]))return false;var ok=true;(s.slots||[]).forEach(function(k){if(k&&!PROD[k])ok=false});if(!ok)return false;
    Object.keys(s).forEach(function(k){S[k]=s[k]});if(A.utm&&PROD[A.utm])S.core=S.core||A.utm;S.utm=A.utm||S.utm;return S.box!==null&&S.slots.filter(Boolean).length>0}catch(e){return false}}

  function BX(){return BOXES[S.box]}
  function boxOf(id){return BOXES[BI[id]]}
  function boxes(){return S.slots.filter(Boolean).length}
  function full(){return S.box!==null&&boxes()===BX().packs}
  function qtyOf(k){return S.slots.filter(function(x){return x===k}).length}
  function cat(){return r2(S.slots.filter(Boolean).reduce(function(a,k){return a+PROD[k].price},0))}
  function pct(){return S.box===null?0:BX().pct}
  function ship(){return S.box!==null&&BX().rw.indexOf('ship')>-1?0:SHIP}
  function pay(){return r2(cat()*(1-pct()/100)+(boxes()?ship():0))}
  function extraVal(b){return (b.rw.indexOf('book')>-1?BOOK_VAL:0)+(b.cosm_pay!==null&&b.cosm_pay!==undefined?COSM-b.cosm_pay:0)}
  function value(){return S.box===null?0:r2(cat()+(full()?extraVal(BX()):0))}
  function save(){return r2(value()-pay())}
  function avgPrice(){var s=0;ORDER.forEach(function(k){s+=PROD[k].price});return ORDER.length?s/ORDER.length:0}
  function boxSaveMax(b){return r2(b.packs*avgPrice()*b.pct/100+extraVal(b)+(b.rw.indexOf('ship')>-1?SHIP:0))}
  function SECRETLIST(){return ORDER.filter(function(k){return qtyOf(k)===0}).slice(0,SECRET_N)}
  function secretTotal(){var c=0;SECRETLIST().forEach(function(x){if(S.secret[x])c+=r2(PROD[x].price*(1-SECRET_PCT/100))});return r2(c)}
  function pic(pr,cls){return pr.img?'<img class="'+(cls||'')+'" src="'+esc(pr.img)+'" alt="" loading="lazy">':'<span class="'+(cls||'')+'">'+pr.ph+'</span>'}
  function tixOf(b){return Number(b.tickets)||1}

  /* ── копи ── */
  function baseVars(){
    var bs=boxOf('s'),bm=boxOf('m'),bl=boxOf('l');
    var v={yacht:ths(YACHT),ship:m(SHIP),book_value:BOOK_VAL,cosm_value:COSM,cosm_pay_m:bm&&bm.cosm_pay!=null?money(bm.cosm_pay):'',cosm_pay_l:bl&&bl.cosm_pay!=null?money(bl.cosm_pay):'',cosm_min:bl&&bl.cosm_pay!=null?money(bl.cosm_pay):'',deadline:A.deadline||'',max_pct:BOXES.length?Math.max.apply(null,BOXES.map(function(b){return b.pct})):0,
      tickets_s:bs?tixOf(bs):1,tickets_m:bm?tixOf(bm):3,tickets_l:bl?tixOf(bl):5,pct_s:bs?bs.pct:20,pct_m:bm?bm.pct:30,pct_l:bl?bl.pct:40,secret_pct:SECRET_PCT,total:ORDER.length,name:A.name||''};
    if(S.core&&PROD[S.core]){var c=PROD[S.core];v.core=esc(c.name);v.core_full=esc(c.gname||c.name);v.core_ds=c.ds?esc(c.ds.charAt(0).toUpperCase()+c.ds.slice(1)):'';v.s_core=sPrep(c.name)}
    if(S.box!==null&&BX()){var b=BX();v.box=esc(b.name);v.box_l=esc(b.name.toLowerCase());v.box_ic=b.ic;v.packs=b.packs;v.opk=opk(b.packs);v.pct=b.pct;v.tickets=tixOf(b);v.shans=shans(tixOf(b));v.cosm_pay=b.cosm_pay!=null?money(b.cosm_pay):'';v.s_box=sPrep(b.name);v.need=b.packs-boxes();v.n=boxes();v.save=m0(save());v.pay=m(pay());v.value=m0(value());v.rest=b.packs-1;v.opk_rest=opk(b.packs-1);v.produkt=b.packs===1?'продукт':'продукти'}
    return v;
  }
  function T(k,v){var s=A.copy&&A.copy[k];if(s==null)s=k;return tpl(s,Object.assign(baseVars(),v||{}))}
  function ck(k){return A.editor?' data-ck="'+k+'"':''}
  function RW(r){return {ic:IC[r]||'🎁',sh:T('rw.'+r+'.sh'),t:T('rw.'+r+'.t'),s:T('rw.'+r+'.s')}}
  /* v1.0.13: снимка на награда (gate.images от настройките: yacht/cosm/book/ship; празно = примерна) — без URL пада на емоджито */
  function rwImgKey(r){return /^tix/.test(r)?'yacht':/^cosm/.test(r)?'cosm':r==='book'?'book':r==='ship'?'ship':''}
  function rimg(r,emo,cls){var GI=(A.gate&&A.gate.images)||{},k=rwImgKey(r);return GI[k]?'<i class="'+(cls||'')+' rpic"><img src="'+esc(GI[k])+'" alt="" loading="lazy" decoding="async"></i>':'<i'+(cls?' class="'+cls+'"':'')+'>'+emo+'</i>'}
  function boxVars(b){return {box:esc(b.name),box_l:esc(b.name.toLowerCase()),box_ic:b.ic,packs:b.packs,opk:opk(b.packs),pct:b.pct,tickets:tixOf(b),shans:shans(tixOf(b)),bileta:tixOf(b)===1?'билет':'билета',rest:b.packs-1,opk_rest:opk(b.packs-1),cosm_pay:b.cosm_pay!=null?money(b.cosm_pay):'',save:m0(boxSaveMax(b)),produkt:b.packs===1?'продукт':'продукти'}}
  /* козметичният сет: винаги зачертана стойност + „величествена“ цена (pill, никога inline) */
  function cosmPrice(r,big){if(r!=='cosm1'&&r!=='cosm50')return '';var b=boxOf(r==='cosm1'?'l':'m');if(!b||b.cosm_pay==null)return '';return '<span class="cpx'+(r==='cosm1'?' one':' half')+(big?' big':'')+'"><small'+ck('cpx.label')+'>'+T('cpx.label')+'</small><s>€'+COSM+'</s><b>€'+money(b.cosm_pay)+'</b></span>'}
  /* v1.0.3: бадж с отстъпката + намалена/редовна цена в редовете на пълненето (искане на човека; не е в мокъп v73) */
  function fprc(pr,b,q){return '<span class="fpct" data-pct="'+esc(pr.key)+'">'+T('fill.pct',{pct:b.pct,save:m(r2(pr.price*b.pct/100*Math.max(1,q||0)))})+(q>1?' <i>×'+q+'</i>':'')+'</span>'}
  function fprice(pr,b){return '<span class="fprc"><b>'+m(r2(pr.price*(1-b.pct/100)))+'</b><s>'+m(pr.price)+'</s></span>'}
  function tgtVars(t){var d=t.packs-(S.box!==null?BX().packs:0);return {box_t:esc(t.name),box_t_l:esc(t.name.toLowerCase()),box_t_ic:t.ic,pct_t:t.pct,save_t:m0(boxSaveMax(t)),s_box_t:sPrep(t.name,true),diff:d,prod_diff:prodw(d),opk_diff:opk(d),delyat:d===1?'дели':'делят'}}

  /* ── имейл-попъп (v73 „g2“): веднъж на сесия, може да се пропусне ── */
  function gateSeen(){try{return sessionStorage.getItem('ansa_promo_gate')==='1'}catch(e){return false}}
  function gateMark(){try{sessionStorage.setItem('ansa_promo_gate','1')}catch(e){}}
  function emailGate(){
    if(S.emailDone||A.editorNoGate)return;var c=S.core?PROD[S.core]:null;var t=(c&&c.theme)||['#ffe3cf','#ffb37a','#c2410c'];
    var bs=boxOf('s'),bm=boxOf('m'),bl=boxOf('l');
    var dc=$('apDc');dc.className='dc gatew';dc.scrollTop=0;
    /* v1.0.8: снимки вместо емоджита на плочките (gate.images от настройките; празно = примерна снимка). Без URL → емоджито от мокъпа. */
    var GI=(A.gate&&A.gate.images)||{};
    function gi(k,emo){return GI[k]?'<i class="g2i"><img src="'+esc(GI[k])+'" alt="" loading="lazy" decoding="async"></i>':'<i>'+emo+'</i>'}
    function gcl(k){return GI[k]?' pic':''}
    function gbx(b,cls){if(!b)return '';return '<div class="g2b'+(cls||'')+gcl('box_'+b.id)+'">'+gi('box_'+b.id,b.ic)+'<b'+ck('gate.bx.'+b.id+'.name')+'>'+T('gate.bx.'+b.id+'.name')+'</b><small'+ck('gate.bx.packs')+'>'+T('gate.bx.packs',boxVars(b))+'</small><em'+ck('gate.bx.'+b.id+'.em')+'>'+T('gate.bx.'+b.id+'.em')+'</em></div>'}
    dc.innerHTML='<div class="gate g2" style="--g1:'+t[0]+';--g2:'+t[1]+';--g3:'+t[2]+'">'
      +'<div class="g2band"><div class="g2bt"><b><span'+ck('head.title')+'>'+T('head.title')+'</span> <i'+ck('head.brand')+'>'+T('head.brand')+'</i></b><em'+ck('gate.band')+'>'+T('gate.band')+'</em></div><div class="g2pic">'+(c?pic(c,'gimg'):'🎁')+'</div></div>'
      +'<div class="g2hd">'+(c?'<h3'+ck('gate.title')+'>'+T('gate.title')+'</h3><p>'+esc(c.gsub||c.ds)+'</p>':'<h3'+ck('gate.title.noutm')+'>'+T('gate.title.noutm')+'</h3><p'+ck('gate.sub.noutm')+'>'+T('gate.sub.noutm')+'</p>')+'</div>'
      +'<div class="g2s"><span class="g2n">1</span><span'+ck('gate.s1')+'>'+T('gate.s1')+'</span></div>'
      +'<div class="g2rw">'
        +'<div class="g2r hot'+gcl('yacht')+'">'+gi('yacht','🛥️')+'<div><b'+ck('gate.r1.b')+'>'+T('gate.r1.b')+'</b><small'+ck('gate.r1.s')+'>'+T('gate.r1.s')+'</small></div></div>'
        +'<div class="g2r gold'+gcl('cosm')+'">'+gi('cosm','👑')+'<div><b'+ck('gate.r2.b')+'>'+T('gate.r2.b')+'</b><small'+ck('gate.r2.s')+'>'+T('gate.r2.s')+'</small></div></div>'
        +'<div class="g2r'+gcl('book')+'">'+gi('book','📖')+'<div><b'+ck('gate.r3.b')+'>'+T('gate.r3.b')+'</b><small'+ck('gate.r3.s')+'>'+T('gate.r3.s')+'</small></div></div>'
        +'<div class="g2r'+gcl('pct')+'">'+gi('pct','💸')+'<div><b'+ck('gate.r4.b')+'>'+T('gate.r4.b')+'</b><small'+ck('gate.r4.s')+'>'+T('gate.r4.s')+'</small></div></div>'
      +'</div>'
      +'<div class="g2s"><span class="g2n">2</span><span'+ck('gate.s2')+'>'+T('gate.s2')+'</span></div>'
      +'<p class="g2p"'+ck('gate.p')+'>'+T('gate.p')+'</p>'
      +'<div class="g2bx">'+gbx(bs)+gbx(bm)+gbx(bl,' best')+'</div>'
      +'<div class="gform"><div class="g2f"><input type="email" id="gEmail" placeholder="'+esc(T('gate.placeholder'))+'" autocomplete="email" value="'+esc(S.email||'')+'"><button class="cta gcta" id="gGo"'+ck('gate.cta')+'>'+T('gate.cta')+'</button></div>'
      +'<label class="gc"><input type="checkbox" id="gOk"'+((A.gate&&A.gate.consent_default===false)?'':' checked')+'> <span'+ck('gate.consent')+'>'+T('gate.consent')+'</span></label>'
      +((A.gate&&A.gate.required)?'':'<button class="lnk gskip" id="gSkip"'+ck('gate.skip')+'>'+T('gate.skip')+'</button>')+'</div>'
      +'<div class="g2ft"'+ck('gate.foot')+'>'+T('gate.foot')+'</div></div>';
    $('apOv').classList.remove('off');S._lockOv=!!(A.gate&&A.gate.required);
    function done(){S.emailDone=true;S._lockOv=false;gateMark();closeInfo();saveS()}
    $('gGo').onclick=function(){var v=$('gEmail').value.trim();if(!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v)){$('gEmail').classList.add('err');$('gEmail').focus();toast(T('gate.err'),true);return}S.email=v;sendLead(v,$('gOk')&&$('gOk').checked);done();toast(T('gate.saved'),false)};
    var sk=$('gSkip');if(sk)sk.onclick=done;
  }
  function sendLead(email,consent){
    if(!A.ajax||A.editor)return;
    var fd=new FormData();fd.append('action','ansa_promo_lead');fd.append('nonce',A.nonce||'');fd.append('email',email);fd.append('consent',consent?'1':'0');fd.append('utm',S.utm||'');fd.append('box_seen',S.box!==null&&BX()?BX().id:'');
    fetch(A.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(r){if(r&&r.success&&r.data&&r.data.lead){S.lead=r.data.lead;saveS()}}).catch(function(){});
  }

  /* ── навигация ── */
  function go(n){
    S.screen=n;if(n===1&&!S.emailDone&&!gateSeen())setTimeout(emailGate,250);
    [1,2,4,5].forEach(function(i){var e=$('apS'+i);if(e)e.classList.toggle('on',i===n)});
    $('apHdr').classList.toggle('builder',n===2);
    if(n!==2)$('apStrip').innerHTML='';
    $('apSticky').style.display=(n===2&&window.innerWidth<1000)?'block':'none';
    window.scrollTo(0,0);render();saveS();
  }
  /* v1.0.24: обратното броене „кутията е запазена за mm:ss“ е махнато (искане на човека) — чипът в хедъра казва само „до {{deadline}}“;
     S.timerEnd остава в сесията само за съвместимост със стари сесии, head.timer остава в регистъра, но не се рендира */
  function startTimer(){S.timerEnd=null}
  function renderTimer(){var el=$('apTimer');if(el){el.innerHTML=T('head.deadline');el.classList.remove('hot')}var t2=$('apTimer2');if(t2){t2.innerHTML='';t2.classList.remove('hot')}}

  /* ── тост + undo ── */
  var toastT;
  function toast(txt,lock,undo){
    var el=$('apToast');el.innerHTML='<span>'+txt+'</span>'+(undo?'<button class="tundo" id="apUndo"'+ck('ms.undo')+'>'+T('ms.undo')+'</button>':'');el.className='toast on'+(lock?' lock':'');
    var ub=$('apUndo');if(ub)ub.onclick=function(){el.className='toast';if(S._undo!=null){var u=S._undo;S._undo=null;removeSlot(u,true)}};
    clearTimeout(toastT);toastT=setTimeout(function(){el.className='toast'},undo?4000:1800);
  }

  /* ── попъпи ── */
  function info(t,b,wide){var dc=$('apDc');dc.className='dc'+(wide?' wide':'');dc.scrollTop=0;dc.innerHTML='<button class="ux" id="apUx" aria-label="затвори">✕</button>'+(t?'<h3>'+t+'</h3>':'')+'<div class="ib">'+b+'</div>';$('apOv').classList.remove('off');$('apUx').onclick=closeInfo}
  /* v1.0.22: резерва за телефон — ако нещо на страницата (transform/zoom на родител) „хване“ fixed-а на листа и той излезе от екрана
     (виждаше се като „бутонът Продължи е под екрана, освен при 75% zoom“), го наместваме по измерения отместък и по височината на прозореца */
  function fitSheet(dc){dc=dc||$('apDc');if(!dc||!dc.classList.contains('fpm'))return;dc.style.top='';dc.style.left='';dc.style.width='';dc.style.height='';var r=dc.getBoundingClientRect(),vh=window.innerHeight,vw=window.innerWidth;
    if(Math.abs(r.top)>2)dc.style.top=(-r.top)+'px';if(Math.abs(r.left)>2)dc.style.left=(-r.left)+'px';if(Math.abs(r.width-vw)>2)dc.style.width=vw+'px';if(Math.abs(r.height-vh)>2)dc.style.height=vh+'px'}
  window.addEventListener('resize',function(){if(!$('apOv').classList.contains('off'))fitSheet()});
  function closeInfo(){$('apOv').classList.add('off');$('apOv').classList.remove('ovfpm');S.replaceSlot=null;if(S._fillPending){S._fillPending=false;if(S.screen!==2)go(2)}}
  function scrollHint(){var dc=$('apDc');var more=dc.scrollHeight-dc.scrollTop-dc.clientHeight>24;dc.classList.toggle('more',more);var h=$('apMore');if(!h){h=document.createElement('div');h.id='apMore';h.className='dcmore';h.innerHTML='<span'+ck('ms.more')+'>'+T('ms.more')+'</span>';dc.appendChild(h);h.onclick=function(){dc.scrollBy({top:dc.clientHeight*.7,behavior:'smooth'})}}else dc.appendChild(h)}
  (function(){var dc=$('apDc');dc.addEventListener('scroll',function(){dc.classList.toggle('more',dc.scrollHeight-dc.scrollTop-dc.clientHeight>24)});if(window.MutationObserver){var mo=new MutationObserver(function(ms){for(var i=0;i<ms.length;i++){if(ms[i].type==='childList'&&![].some.call(ms[i].addedNodes,function(n){return n.id==='apMore'})){setTimeout(scrollHint,60);return}}});mo.observe(dc,{childList:true})}window.addEventListener('resize',function(){if(!$('apOv').classList.contains('off'))scrollHint()})})();
  $('apOv').onclick=function(e){if(e.target.id==='apOv'&&!S._lockOv)closeInfo()};
  document.addEventListener('keydown',function(e){if(e.key==='Escape'){if(!$('apOv2').classList.contains('off')){$('apOv2').classList.add('off');return}if(!S._lockOv&&!$('apOv').classList.contains('off'))closeInfo()}});

  function howPopup(){info(T('how.title'),'<p class="hb"'+ck('how.p')+'>'+T('how.p')+'</p>'+BOXES.map(function(b){return '<div class="il"><span>'+esc(b.name)+' · '+b.packs+' '+opk(b.packs)+'</span><b>−'+b.pct+'% · '+b.rw.map(function(r){return RW(r).ic}).join(' ')+'</b></div>'}).join('')+'<p class="mut"'+ck('how.foot')+'>'+T('how.foot')+'</p>')}
  $('apHow').onclick=howPopup;
  function rewardPopup(k){var r=RW(k);var ik=/^tix/.test(k)?'rw.info.tix':/^cosm/.test(k)?'rw.info.cosm':k==='book'?'rw.info.book':'rw.info.ship';info(r.ic+' '+r.t,'<p'+ck('rw.'+k+'.s')+'>'+r.s+'</p><div'+ck(ik)+'>'+T(ik)+'</div>')}
  function packTxt(pr){return pr.pack?esc(pr.pack):T('prod.pack')}
  function prodBody(k){var pr=PROD[k];return '<p><b>'+esc(pr.ds)+'</b></p><p>'+esc(pr.desc)+'</p><p'+ck('prod.pack')+'>'+packTxt(pr)+'</p><div class="il"><span'+ck('prod.price')+'>'+T('prod.price')+'</span><b>'+m(r2(pr.price*(1-pct()/100)))+' <s class="was">'+m(pr.price)+'</s></b></div>'}
  function prodInfo(k){var pr=PROD[k];info(pic(pr,'hpic')+' '+esc(pr.name),prodBody(k)+(full()?'':'<button class="cta" id="iAdd" style="margin-top:10px"'+ck('prod.add')+'>'+T('prod.add')+'</button>'));var b=$('iAdd');if(b)b.onclick=function(){closeInfo();addSlot(k)}}

  /* ── продуктова страница в попъп (второ ниво, върху „Напълни кутията“) ── */
  function stars(n){var h='';for(var i=1;i<=5;i++)h+='<i class="'+(i<=n?'on':'')+'">★</i>';return '<span class="ppstars">'+h+'</span>'}
  function productPopup(k,onAdd){
    var pr=PROD[k],dc=$('apDc2'),p=pct();var cat='';PROBS.forEach(function(x){if(x.key===k)cat=x.t});
    /* v1.0.26: отзивите са карусел — по един, с ‹ › и точки (сменя се и сам на 4.5 s) */
    var RV=(pr.reviews||[]);var revs=RV.length?'<div class="pprc" id="ppRc">'+RV.map(function(r,i){return '<div class="pprv'+(i?'':' on')+'" data-i="'+i+'">'+stars(r.s)+'<p>'+esc(r.t)+'</p><small>— '+esc(r.n)+'</small></div>'}).join('')+(RV.length>1?'<div class="pprn"><button class="pprb" data-rv="-1" aria-label="предишен">‹</button><span class="pprd">'+RV.map(function(_,i){return '<i'+(i?'':' class="on"')+'></i>'}).join('')+'</span><button class="pprb" data-rv="1" aria-label="следващ">›</button></div>':'')+'</div>':'';
    dc.innerHTML='<button class="ux" id="apUx2" aria-label="затвори">✕</button><div class="pph"><div class="ppimg">'+pic(pr,'ppi')+'</div><div class="pptx">'+(cat?'<em class="fct">'+esc(cat)+'</em>':'')+'<h3>'+esc(pr.name)+'</h3><p class="ppds">'+esc(pr.ds)+'</p>'+(pr.rating?'<div class="pprate">'+stars(5)+' <span>'+esc(pr.rating)+'</span></div>':'')+'</div></div>'
      +'<p class="ppdesc">'+esc(pr.desc)+'</p>'
      +((pr.ing||pr.who)?'<div class="ppgrid">'+(pr.ing?'<div><b'+ck('pp.ing')+'>'+T('pp.ing')+'</b><p>'+esc(pr.ing)+'</p></div>':'')+(pr.who?'<div><b'+ck('pp.who')+'>'+T('pp.who')+'</b><p>'+esc(pr.who)+'</p></div>':'')+'</div>':'')
      +(revs?'<div class="pprevs"><b'+ck('pp.reviews')+'>'+T('pp.reviews')+'</b>'+revs+'</div>':'')
      /* v1.0.26: цената и спестяването са ясно видими — голяма цена, зачертана редовна, зелен ред „Спестяваш €X“ */
      +'<div class="ppfoot"><div class="ppprice"><small class="ppl"'+ck('pp.price.now')+'>'+T('pp.price.now',{pct:p})+'</small><div class="ppnow"><b>'+m(r2(pr.price*(1-p/100)))+'</b><s class="was">'+m(pr.price)+'</s></div><div class="ppsave"'+ck('pp.save')+'>'+T('pp.save',{save:m(r2(pr.price*p/100))})+'</div>'+(packTxt(pr)?'<small class="pppack">'+packTxt(pr)+'</small>':'')+'</div>'+(onAdd?'<button class="cta" id="ppAdd"'+ck('pp.add')+'>'+T('pp.add')+'</button>':'')+'<button class="lnk" id="ppClose"'+ck('pp.close')+'>'+T('pp.close')+'</button></div>';
    $('apOv2').classList.remove('off');dc.scrollTop=0;
    var close=function(){$('apOv2').classList.add('off')};
    $('apUx2').onclick=close;$('ppClose').onclick=close;var ad=$('ppAdd');if(ad)ad.onclick=function(){close();onAdd(k)};
    var rc=$('ppRc');if(rc&&RV.length>1){var ri=0,rt;var show=function(n){ri=(n+RV.length)%RV.length;rc.querySelectorAll('.pprv').forEach(function(x){x.classList.toggle('on',Number(x.dataset.i)===ri)});rc.querySelectorAll('.pprd i').forEach(function(x,i){x.classList.toggle('on',i===ri)})};
      var arm=function(){clearInterval(rt);rt=setInterval(function(){if($('apOv2').classList.contains('off')||!document.body.contains(rc)){clearInterval(rt);return}show(ri+1)},4500)};
      rc.querySelectorAll('[data-rv]').forEach(function(x){x.onclick=function(e){e.stopPropagation();show(ri+Number(x.dataset.rv));arm()}});arm()}
  }
  $('apOv2').onclick=function(e){if(e.target.id==='apOv2')$('apOv2').classList.add('off')};
  /* v1.0.15: „За да отключиш тези награди…“ като попъп с ОК (мобилно; веднъж на сесия за кутия) */
  function unlockPopup(b,P){
    var key='ansa_promo_unlock_'+b.id;try{if(sessionStorage.getItem(key)==='1')return}catch(e){}
    var dc=$('apDc2');dc.innerHTML='<div class="okp"><div class="okpi">'+rimg('tix','🎁')+'</div><p'+ck('mfill.intro')+'>'+T('mfill.intro',Object.assign({same:P>1?T('mfill.same'):''},boxVars(b)))+'</p><button class="cta" id="okBtn"'+ck('mfill.ok')+'>'+T('mfill.ok')+'</button></div>';
    $('apOv2').classList.remove('off');
    $('okBtn').onclick=function(){$('apOv2').classList.add('off');try{sessionStorage.setItem(key,'1')}catch(e){}};
  }
  (function(){var rt;window.addEventListener('resize',function(){clearTimeout(rt);rt=setTimeout(function(){if($('apOv').classList.contains('off')||!document.querySelector('#apDc .fp2'))return;if(S._fillMob!==MOBF())fillPopup()},150)})})();

  /* ── честито ── */
  function celebrate(){
    var b=BX();if(!b)return;var conf='';for(var ci=0;ci<18;ci++)conf+='<i style="left:'+(ci*5.5+2)+'%;animation-delay:'+(ci*.11)+'s;background:'+['#e8722a','#f59e0b','#16a34a','#6366f1','#e0507a'][ci%5]+'"></i>';
    var dc=$('apDc');dc.className='dc wide';dc.scrollTop=0;
    /* наградите — всяка с пълното си име; яхтата и сетът са героите */
    var rows=b.rw.map(function(r){var k=RW(r);var cls=r==='cosm1'?'gold':r==='cosm50'?'violet':/^tix/.test(r)?'pink':'plain';var ic=/^tix/.test(r)?'🛥️':k.ic;return '<div class="hero '+cls+'">'+rimg(r,ic)+'<div><b'+ck('rw.'+r+'.t')+'>'+k.t+'</b><small'+ck('rw.'+r+'.s')+'>'+k.s+'</small>'+cosmPrice(r,true)+'</div><em class="hchk">✓</em></div>'}).join('');
    /* при по-малка кутия — предложение за Голямата */
    var big=boxOf('l'),up='';
    if(b.id==='s'&&big){var tv=tgtVars(big);var news=big.rw.filter(function(r){return b.rw.indexOf(r)<0});
      up='<div class="nudge"><b'+ck('cb.nudge')+'>'+T('cb.nudge',tv)+'</b><div class="nl"><span'+ck('cb.nudge.pct')+'>'+T('cb.nudge.pct',tv)+'</span>'+news.map(function(r){return '<span>'+RW(r).ic+' '+RW(r).t+'</span>'}).join('')+'</div>'
        +'<button class="cta gold" id="cUp"'+ck('cb.nudge.cta')+'>'+T('cb.nudge.cta',tv)+'</button><small'+ck('cb.nudge.s')+'>'+T('cb.nudge.s',tv)+'</small></div>'}
    var items=S.slots.filter(Boolean).map(function(k,i){var pr=PROD[k];return '<div class="cit"><em>'+['①','②','③','④','⑤','⑥','⑦','⑧','⑨','⑩','⑪','⑫'][i]+'</em><i>'+pic(pr,'cimg')+'</i><div class="ctx"><b>'+esc(pr.name)+'</b><small>'+esc(pr.ds)+'</small></div><span class="cpr">'+m(r2(pr.price*(1-b.pct/100)))+'<s>'+m(pr.price)+'</s></span></div>'}).join('');
    var mix=uniq(S.slots.filter(Boolean)).length>1;
    /* мобилно: еднаквите продукти се групират — „5× Sakura“ */
    if(MOB()){var grp={},go_=[];S.slots.filter(Boolean).forEach(function(k){if(!grp[k]){grp[k]=0;go_.push(k)}grp[k]++});
      /* v1.0.28: на телефон се вижда само първият продукт, останалите са зад „Виж всички продукти (+N)“ — да остане място за наградите */
      items=go_.map(function(k,gi){var pr=PROD[k],q=grp[k];return '<div class="cit'+(gi?' hid':'')+'"><em class="cq"'+ck('cb.q')+'>'+T('cb.q',{q:q})+'</em><i>'+pic(pr,'cimg')+'</i><div class="ctx"><b>'+esc(pr.name)+'</b><small>'+esc(pr.ds)+'</small></div><span class="cpr">'+m(r2(q*pr.price*(1-b.pct/100)))+'<s>'+m(q*pr.price)+'</s></span></div>'}).join('')
      +(go_.length>1?'<button class="cmore" id="cMore" type="button" data-n="'+(go_.length-1)+'"'+ck('cb.more')+'>'+T('cb.more',{n:go_.length-1})+'</button>':'')}
    var cbox='<div class="cbox"><div class="cbh"><b'+ck(mix?'cb.mix':'cb.same')+'>'+T(mix?'cb.mix':'cb.same')+'</b><small'+ck('cb.meta')+'>'+T('cb.meta',{save_prod:m(r2(cat()*b.pct/100))})+'</small></div>'+items+'</div>';
    var pay_='<div class="vline eq cpay"><div class="vl pay"><em'+ck('cb.pay')+'>'+T('cb.pay')+'</em><b>'+m(pay())+'</b></div><div class="vlarr">→</div><div class="vl get"><em'+ck('cb.get')+'>'+T('cb.get')+'</em><b>'+m0(value())+'</b></div></div>';
    var subk='cb.sub.'+b.id;
    dc.innerHTML='<div class="celeb"><div class="confetti">'+conf+'</div><div class="chead"><span class="cbig">🎉</span><h3'+ck('cb.title')+'>'+T('cb.title')+'</h3></div>'
      +'<div class="cgrid"><div class="cg1">'+cbox+pay_+'</div><div class="cg2"><p class="csub"'+ck(subk)+'>'+T(subk)+'</p><div class="rlist">'+rows+'</div></div></div>'+up
      +'<div class="cacts"><div class="csave"'+ck('cb.save')+'>'+T('cb.save',{save:m(r2(cat()*b.pct/100))})+'</div><button class="cta" id="cGo"'+ck('cb.cta')+'>'+T('cb.cta',{total:m(pay())})+'</button><div class="crow"><button class="lnk" id="cEdit"'+ck('cb.edit')+'>'+T('cb.edit')+'</button><button class="lnk" id="cSwitch"'+ck('cb.switch')+'>'+T('cb.switch')+'</button></div></div></div>';
    $('apOv').classList.remove('off');S._lockOv=true;S._fillPending=false;saveS();
    $('cGo').onclick=function(){S._lockOv=false;closeInfo();go(5)};
    var cm=$('cMore');if(cm){cm.onclick=function(){var bx=cm.closest('.cbox'),open=!bx.classList.contains('open');bx.classList.toggle('open',open);cm.innerHTML=open?T('cb.less'):T('cb.more',{n:cm.dataset.n})}}
    $('cEdit').onclick=function(){S._lockOv=false;closeInfo();S._fillPending=(S.screen!==2);fillModePopup()};
    var cs=$('cSwitch');if(cs)cs.onclick=function(){S._lockOv=false;switchPopup()};
    var cu=$('cUp');if(cu)cu.onclick=function(){S._lockOv=false;closeInfo();chooseBox(BI.l,true)};
  }
  function fullMsg(){var b=BX();return T(b.id==='s'?'full.small':b.id==='m'?'full.medium':'full.large')}

  /* ── слотове ── */
  function addSlot(k){
    var i=S.slots.indexOf(null);if(i<0){toast(T('ms.full'),true);return}
    var wasFull=full();S.slots[i]=k;S._undo=i;
    if(full()&&!wasFull){render();saveS();setTimeout(celebrate,350);return}
    else if(i===0)toast(T('ms.first'),false,true);
    else toast(T('ms.added',{name:esc(PROD[k].name),need:BX().packs-boxes(),opk:opk(BX().packs-boxes())}),false,true);
    render();saveS();
  }
  function removeSlot(i,isUndo){if(S.slots[i]==null)return;S.slots[i]=null;S.slots=S.slots.filter(Boolean);while(S.slots.length<BX().packs)S.slots.push(null);if(isUndo)toast(T('ms.undone'),true);render();saveS()}
  function removeOne(k){var i=S.slots.lastIndexOf(k);if(i>=0)removeSlot(i)}
  function chooseBox(bi,keep){
    S.box=bi;var b=BOXES[bi];
    var kept=keep?S.slots.filter(Boolean):[];
    if(!keep&&S.core)kept=[S.core];
    S.slots=kept.slice(0,b.packs);while(S.slots.length<b.packs)S.slots.push(null);
    startTimer();saveS();
    /* попъпът пълни кутията докрай; таблото (екран 2) е само резервен екран за редакция */
    if(!full()){if(S.screen!==2)render();S._fillPending=(S.screen!==2);fillModePopup();return}
    if(S.screen===2){go(2);return}
    render();S._fillPending=false;setTimeout(celebrate,200);
  }

  /* ── ЕДИН попъп за пълнене: наградите на кутията + категории със степери (десктоп) / пълноекранен лист (мобилно) ── */
  var needQ={},needOrder=[];
  function fillModePopup(){needQ={};needOrder=[];S.slots.filter(Boolean).forEach(function(k){if(!needQ[k])needOrder.push(k);needQ[k]=(needQ[k]||0)+1});if(S.core&&PROD[S.core]&&!needQ[S.core]){needQ[S.core]=1;needOrder.unshift(S.core)}fillPopup()}
  function fillPopup(){
    var b=BX(),c=S.core&&PROD[S.core]?PROD[S.core]:null,P=b.packs,bv=boxVars(b);
    var rows=[];if(c)rows.push({key:S.core,t:T('fill.cat.core'),why:c.ds,core:true});
    /* v1.0.39: отговорите на въпросника (S.quiz) слагат избраните проблеми преди останалите; основният продукт остава първи */
    var qz=Array.isArray(S.quiz)&&S.quiz.length?S.quiz:null;var others=PROBS.filter(function(p){return PROD[p.key]&&p.key!==S.core});
    if(qz)others=others.filter(function(p){return qz.indexOf(p.key)>-1}).concat(others.filter(function(p){return qz.indexOf(p.key)<0}));
    others.forEach(function(p){rows.push({key:p.key,t:p.t,why:p.why||PROD[p.key].ds,pick:!!(qz&&qz.indexOf(p.key)>-1)})});
    var pcat=function(c){return T('fill.quiz.cat',{cat:esc(c.charAt(0).toLowerCase()+c.slice(1))})}; /* избран във въпросника: „✓ Според теб: тегло и метаболизъм“ */
    var gifts=b.rw.map(function(r){var w=RW(r);var big=/^tix|^cosm/.test(r);var lbl=/^tix/.test(r)?T('fill.g.tix',bv):r==='ship'?T('fill.g.ship'):r==='book'?T('fill.g.book'):T('fill.g.cosm');var sub=/^tix/.test(r)?T('fill.g.tix.sub'):/^cosm/.test(r)?cosmPrice(r):'';return '<span class="fg '+r+(big?' big':'')+'">'+rimg(r,/^tix/.test(r)?'🛥️':w.ic)+'<b>'+lbl+'</b>'+(sub?'<small>'+sub+'</small>':'')+'</span>'}).join('')+'<span class="fg pct"><i>💸</i><b>−'+b.pct+'%</b></span>';
    var mix='';if(P>1){var a1=Math.ceil(P/2),a2=P-a1;mix='<p class="fmixt"'+ck('fill.mix')+'>'+T('fill.mix',{a:a1,b:a2})+'</p>'}
    /* v1.0.31 (мокъпът на човека): хедър „Напълни кутията си“ + „Голяма кутия · Промени“ + ✕; панел „С пълна кутия получаваш“ с две големи
       плочки (яхта · основен подарък) и ред с останалите; „Комбинирай 5 опаковки…“ + „Избрани 2 от 5“ + прогрес; картите в 2 колони
       (снимка · име · „за какво е“ · описание · „За продукта ›“ · цена · „+ Добави“/степер · бадж −40%); лепкав футър „Спестяваш €X“ + бутон */
    /* v1.0.32: 2 продукта на страница и на десктоп, категорията е заглавие над картата; „Напълни своята ГОЛЯМА кутия (−40%)“ */
    /* v1.0.39: 3 продукта на страница на десктоп (6 продукта = 2 пълни страници), 2 на телефон */
    var MPP=MOBF()?2:3;var mcat=function(pr,r){var c=String(pr.cat||'').trim();return c?T('mfill.cat',{cat:esc(c.charAt(0).toLowerCase()+c.slice(1))}):esc(r.core?T('fill.cat.core'):r.t)};
    bv.box_adj_u=esc(String(b.name).replace(/\s*кутия\s*/i,' ').trim().toUpperCase());bv.nrw=b.rw.length;bv.nagradi=b.rw.length===1?'награда':'награди';
    /* v1.0.33 (мокъпът на човека): картата е на три реда — заглавие + бадж −X% · снимка (30%) + „за какво е“/описание/„За продукта ›“ ·
       стара цена, нова цена, „+ Добави“/степер — еднакво на десктоп (.drow) и телефон (.mrow) */
    /* v1.0.36 (инспирацията на човека): на телефон категорията е малък етикет вътре в картата над името (catIn), не заглавие отвън */
    var fcard=function(r,cls,catIn){var pr=PROD[r.key];return '<div class="fcat '+cls+' r3'+(r.core?' core':'')+'" data-nq="'+r.key+'">'
      +'<div class="r3h"><div class="r3t">'+(catIn?'<em class="r3cat'+(r.core?' core':r.pick?' pick':'')+'">'+(r.core?'★ ':'')+catIn+'</em>':'')+'<b>'+esc(pr.name)+'</b></div><span class="fpct mb">−'+b.pct+'%</span></div>'
      +'<div class="r3m"><span class="pic">'+pic(pr,'pimg')+'</span><div class="ftx">'+(pr.ds?'<span class="f3ds">'+esc(pr.ds)+'</span>':'')+'<small>'+esc(r.core&&pr.ds===r.why?pr.desc:r.why)+'</small><button class="fmore" data-more="'+r.key+'"'+ck('fill.more.l')+'>'+T('fill.more.l')+'</button></div></div>'
      +'<div class="fbar r3f">'+fprice(pr,b)+'<div class="mact"><button class="madd" data-inc="'+r.key+'"'+ck('mfill.add')+'>'+T('mfill.add')+'</button><span class="stp"><button data-dec="'+r.key+'" aria-label="−">−</button><b data-q="'+r.key+'">0</b><button data-inc="'+r.key+'" aria-label="+">+</button></span></div></div></div>'};
    var dmain=b.rw.indexOf('cosm1')>-1?'cosm1':b.rw.indexOf('cosm50')>-1?'cosm50':b.rw.indexOf('book')>-1?'book':'';
    var dtiles='<div class="f3t">'+rimg('tix','🛥️','f3i')+'<div><b'+ck('fill.g.tix')+'>'+T('fill.g.tix',bv)+'</b><span'+ck('fill.gt.tix.b')+'>'+T('fill.gt.tix.b')+'</span><small'+ck('fill.g.tix.sub')+'>'+T('fill.g.tix.sub')+'</small></div></div>'
      +(dmain==='cosm1'||dmain==='cosm50'?'<div class="f3t gold">'+rimg(dmain,RW(dmain).ic,'f3i')+'<div><b'+ck('fill.gt.cosm.t')+'>'+T('fill.gt.cosm.t',bv)+'</b><span'+ck('fill.gt.cosm.b')+'>'+T('fill.gt.cosm.b',bv)+'</span><small'+ck('fill.gt.cosm.s')+'>'+T('fill.gt.cosm.s')+'</small></div></div>'
        :dmain==='book'?'<div class="f3t">'+rimg('book',RW('book').ic,'f3i')+'<div><b'+ck('fill.g.book')+'>'+T('fill.g.book')+'</b><span'+ck('fill.gt.book.b')+'>'+T('fill.gt.book.b',bv)+'</span><small'+ck('fill.gt.book.s')+'>'+T('fill.gt.book.s')+'</small></div></div>':'');
    var drest=b.rw.filter(function(r){return !/^tix/.test(r)&&r!==dmain}).map(function(r){var w=RW(r);return '<span class="f3r">'+rimg(r,w.ic,'f3ri')+'<b'+ck('fill.g.'+r)+'>'+T('fill.g.'+r)+'</b></span>'}).join('<i class="f3sep"></i>');
    var body='<div class="fp2 fp3"><div class="f3h"><div class="f3hl"><h3'+ck('fill.title2')+'>'+T('fill.title2',bv)+'</h3><button class="lnk" id="fBack" type="button"'+ck('fill.chg')+'>'+T('fill.chg')+'</button></div><button class="f3x" id="fClose" type="button" aria-label="затвори">✕</button></div>'
      +'<div class="f3g"><b class="f3gt"'+ck('fill.gifts.t')+'>'+T('fill.gifts.t',bv)+'</b><div class="f3tiles">'+dtiles+'</div>'+(drest?'<div class="f3rest">'+drest+'</div>':'')+'</div>'
      +'<div class="f3mixr"><p class="f3mix"'+ck('fill.combine')+'>'+T('fill.combine',bv)+'</p><button class="lnk fqzl" id="fQuiz" type="button"'+ck(qz?'fill.quiz.redo':'fill.quiz.l')+'>'+T(qz?'fill.quiz.redo':'fill.quiz.l')+'</button></div>'
      +'<div class="f3p"><span class="f3n"><span'+ck('fill.sel.a')+'>'+T('fill.sel.a')+'</span> <span id="fSlotsN"></span> <span'+ck('fill.sel.b')+'>'+T('fill.sel.b')+'</span></span><span class="f3bar"><i id="fBar"></i></span><em id="fPct">0%</em></div>'
      +'<div class="fslots" id="fSlots" hidden></div>'
      +'<div class="fcats f3grid">'+rows.map(function(r,ri){var pr=PROD[r.key];return '<div class="f3item" data-pg="'+Math.floor(ri/MPP)+'"><h5 class="f3cat'+(r.core?' core':r.pick?' pick':'')+'"'+ck('mfill.cat')+'>'+(r.core?T('mfill.core',{t:mcat(pr,r)}):r.pick?pcat(String(pr.cat||'').trim()||r.t):mcat(pr,r))+'</h5>'+fcard(r,'drow')+'</div>'}).join('')+'</div>'+(rows.length>MPP?'<div class="mpg" id="mPg"></div>':'')+'</div>'
      +'<div class="f3f"><div class="f3s"><i class="f3pig"></i><div><div class="fsave" id="fSave"></div><small'+ck('fill.save.sub')+'>'+T('fill.save.sub',bv)+'</small></div></div><button class="cta" id="nFill"></button></div>';
    var mob=MOBF();S._fillMob=mob;
    if(mob){
      /* v1.0.22 (инспирацията на човека): пълноекранен лист — заглавие „Голяма кутия“ + „5 опаковки по твой избор“ + бадж „−40% на всяка
         опаковка“ + „Смени кутията“; лента „Подаръци и бонуси“ с „Виж всички ›“ (разгъва списъка); „Избери продуктите“; карти без категория
         и без бадж (снимка | име · описание · „Виж продукта ›“ · цена + „+ Добави“); пагинация ‹ 1 от 2 ›; лепкав футър „Твоята кутия · 0 от 5“
         + слотове + бутон. Хедърът и списъкът скролват заедно (.mfs), футърът е винаги видим. */
      var main=b.rw.indexOf('cosm1')>-1?'cosm1':b.rw.indexOf('cosm50')>-1?'cosm50':b.rw.indexOf('book')>-1?'book':'';
      var gkey=function(r){return /^tix/.test(r)?'mfill.g.tix':/^cosm/.test(r)?'mfill.g.cosm':'mfill.g.'+r};
      var skey=function(r){return /^tix/.test(r)?'mfill.gs.tix':/^cosm/.test(r)?'mfill.gs.cosm':'mfill.gs.'+r};
      var sv='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
      var iTag=sv+'<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5" fill="currentColor" stroke="none"/></svg>';
      var iSw=sv+'<path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/></svg>';
      var iGift=sv+'<path d="M20 12v10H4V12"/><path d="M2 7h20v5H2z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>';
      var iChev=sv+'<path d="m9 6 6 6-6 6"/></svg>';
      var gsum=[T('mfill.gs.tix',bv)].concat(main?[T(skey(main),bv)]:[]).join(' <i class="dot">•</i> ');
      var gall=b.rw.map(function(r){return '<span class="mfgi">'+rimg(r,/^tix/.test(r)?'🛥️':RW(r).ic)+'<b'+ck(gkey(r))+'>'+T(gkey(r),bv)+'</b></span>'}).join('');
      body='<div class="mfs" id="mfS"><div class="mfh">'
        +'<div class="mft"><div class="mftx"><b>'+esc(b.name)+'</b><small'+ck('mfill.sub')+'>'+T('mfill.sub',bv)+'</small></div><button class="mfx" id="fClose" aria-label="затвори">✕</button></div>'
        +'<div class="mfhr"><span class="mfpill"'+ck('mfill.pct')+'>'+iTag+'<span>'+T('mfill.pct',bv)+'</span></span><button class="mfsw" id="fBack" type="button"'+ck('mfill.sw')+'>'+iSw+'<span>'+T('mfill.sw')+'</span></button></div>'
        +'<div class="mfg2"><button class="mfgc" id="mfGx" type="button" aria-expanded="false"><i class="mfgic">'+iGift+'</i><span class="mfgt"><b'+ck('mfill.gifts.t')+'>'+T('mfill.gifts.t')+'</b><small>'+gsum+'</small></span><em class="mfga" id="mfGa"'+ck('mfill.gifts.all')+'>'+T('mfill.gifts.all')+'</em></button>'
        +'<div class="mfgh" id="mfGh" hidden>'+gall+'</div></div>'
        +'</div>'
        +'<h4 class="mfpk"'+ck('mfill.pick')+'>'+T('mfill.pick')+'</h4>'
        +'<button class="lnk fqzl" id="fQuiz" type="button"'+ck(qz?'fill.quiz.redo':'fill.quiz.l')+'>'+T(qz?'fill.quiz.redo':'fill.quiz.l')+'</button>'
        /* v1.0.25: 2 продукта на страница; категорията е заглавие над картата (.mcath), не вътре в нея */
        +'<div class="mfl" id="mfL">'+rows.map(function(r,ri){var pr=PROD[r.key];return '<div class="mitem" data-pg="'+Math.floor(ri/MPP)+'">'+fcard(r,'mrow',r.pick?pcat(String(pr.cat||'').trim()||r.t):esc(String(pr.cat||'').trim()||r.t))+'</div>'}).join('')
        /* v1.0.35: пагинацията е в скрол зоната под картите (не закотвена над футъра) — на нисък екран се вижда повече от картата */
        +'</div>'+(rows.length>MPP?'<div class="mpg" id="mPg"></div>':'')+'</div>'
        /* v1.0.25: „Спестяваш €X“ е отделен ясен ред под „Твоята кутия“ (зелен при избрани опаковки) */
        +'<div class="mff"><div class="mfft"><div class="mfftx"><b'+ck('mfill.box')+'>'+T('mfill.box')+'</b></div><span class="mffn" id="fSlotsN"></span></div><div class="fsave" id="fSave"></div><div class="fslots" id="fSlots"></div><button class="cta" id="nFill"></button></div>';
    }
    info(mob?'':T('fill.title'),body);var dc=$('apDc');dc.classList.add('fpw');if(mob){dc.classList.add('fpm');$('apOv').classList.add('ovfpm')} /* v1.0.35: без :has — overlay-ят е без отстъпи */
    /* v1.0.17: пагинация на телефон; v1.0.25: 2 на страница, бутоните са с текст „Назад“ / „Още продукти“ + точки + „1 от 3“ */
    var pgEl=$('mPg');if(pgEl){var pages=Math.ceil(rows.length/MPP),pg=0;var showPg=function(){dc.querySelectorAll('.mitem,.f3item').forEach(function(x){x.classList.toggle('pg-off',Number(x.dataset.pg)!==pg)});
        /* v1.0.27: по средата само текст „1 от 3“ (без точки и контейнер — беше пренаселено) */
        pgEl.innerHTML='<button class="mpgb pv" data-pg="prev"'+(pg===0?' disabled':'')+' aria-label="предишна"><i>‹</i><span'+ck('mfill.prev')+'>'+T('mfill.prev')+'</span></button><span class="mpgn"'+ck('mfill.page')+'>'+T('mfill.page',{pg:pg+1,pages:pages})+'</span><button class="mpgb nx" data-pg="next"'+(pg>=pages-1?' disabled':'')+' aria-label="следваща"><span'+ck('mfill.next')+'>'+T('mfill.next')+'</span><i>›</i></button>';
        pgEl.querySelectorAll('[data-pg]').forEach(function(x){x.onclick=function(){var v=x.dataset.pg;pg=v==='prev'?Math.max(0,pg-1):v==='next'?Math.min(pages-1,pg+1):Number(v);showPg();var sc=$('mfS')||$('mfL');if(sc)sc.scrollTop=0;else{var g=dc.querySelector('.f3grid');if(g)g.scrollIntoView({block:'nearest'})}}})};showPg()}
    var gx=$('mfGx');if(gx){gx.onclick=function(){var h=$('mfGh');if(!h)return;var open=h.hasAttribute('hidden');if(open)h.removeAttribute('hidden');else h.setAttribute('hidden','');gx.classList.toggle('open',open);gx.setAttribute('aria-expanded',open?'true':'false');var ga=$('mfGa');if(ga)ga.innerHTML=T(open?'mfill.gifts.less':'mfill.gifts.all')}}
    if(mob)fitSheet(dc);
    if(mob)unlockPopup(b,P);
    /* модален: няма ✕ и клик встрани — изходите са „смени кутията“ и ✕ в хедъра (и двата връщат към кутиите) */
    var ux=$('apUx');if(ux)ux.remove();S._lockOv=true;
    var goBack=function(){S._lockOv=false;S._fillPending=false;closeInfo();S.box=null;S.slots=[];S.timerEnd=null;go(1)};$('fBack').onclick=goBack;var fc=$('fClose');if(fc)fc.onclick=goBack;
    /* v1.0.39: въпросникът е панел върху попъпа — един ред на продукт (t + sub от „проблемите“), минимум един избор; „Покажи ми
       продуктите“ записва S.quiz и прерисува попъпа с избраните отпред (количествата се пазят); „Пропусни“ само затваря */
    var fq=$('fQuiz');if(fq)fq.onclick=function(){quizPanel(dc,function(keys){S.quiz=keys;saveS();fillPopup()})};
    function total(){var t=0;Object.keys(needQ).forEach(function(k){t+=needQ[k]});return t}
    function sync(){var t=total(),need=P-t;
      dc.querySelectorAll('[data-q]').forEach(function(x){var q=needQ[x.dataset.q]||0;x.textContent=q;x.closest('.fcat').classList.toggle('on',q>0)});
      dc.querySelectorAll('[data-inc]').forEach(function(x){x.disabled=need<=0});
      dc.querySelectorAll('[data-dec]').forEach(function(x){x.disabled=!(needQ[x.dataset.dec]>0)});
      var sl=[];needOrder.forEach(function(k){for(var i=0;i<(needQ[k]||0);i++)sl.push(k)});
      var cnt='<span class="fsl"'+ck('fill.dots')+'>'+T('fill.dots',{n:t,packs:P})+'</span>',sn=$('fSlotsN');if(sn){sn.innerHTML=cnt;cnt=''}
      var fb=$('fBar');if(fb){var pc=Math.round(t/P*100);fb.style.width=pc+'%';var fp=$('fPct');if(fp)fp.textContent=pc+'%'}
      $('fSlots').innerHTML=cnt+Array.from({length:P},function(_,i){var k=sl[i];return '<span class="fs'+(k?' on':'')+'" title="'+(k?esc(PROD[k].name):'')+'">'+(k?pic(PROD[k],'pimg'):(i+1))+'</span>'}).join('');
      var save=0;Object.keys(needQ).forEach(function(k){if(PROD[k])save+=needQ[k]*PROD[k].price*b.pct/100});save=r2(save);
      dc.querySelectorAll('.fpct[data-pct]').forEach(function(x){var pr=PROD[x.dataset.pct];if(!pr)return;var q=needQ[pr.key]||0;x.innerHTML=T('fill.pct',{pct:b.pct,save:m(r2(pr.price*b.pct/100*Math.max(1,q)))})+(q>1?' <i>×'+q+'</i>':'')});
      var fs=$('fSave');if(fs){var prev=fs.dataset.v;var mk=dc.classList.contains('fpm');fs.innerHTML=t>0?'<span'+ck(mk?'mfill.save':'fill.save')+'>'+T(mk?'mfill.save':'fill.save',{save:m(save),n:t,opk:opk(t),pct:b.pct})+'</span>':'<span'+ck(mk?'mfill.save.zero':'fill.save.zero')+'>'+T(mk?'mfill.save.zero':'fill.save.zero',{pct:b.pct})+'</span>';fs.classList.toggle('on',t>0);fs.classList.toggle('full',t>=P);if(prev!==undefined&&prev!==String(save)){fs.classList.remove('bump');void fs.offsetWidth;fs.classList.add('bump')}fs.dataset.v=String(save)}
      var f=$('nFill');f.disabled=need>0;f.innerHTML=need>0?T('fill.need',{need:need,opk:opk(need)}):T('fill.go')}
    sync();
    function inc(k){if(total()>=P){toast(T('fill.over2',{packs:P}),true);return}if(!needQ[k])needOrder.push(k);needQ[k]=(needQ[k]||0)+1;sync()}
    dc.querySelectorAll('[data-inc]').forEach(function(x){x.onclick=function(e){e.stopPropagation();inc(x.dataset.inc)}});
    dc.querySelectorAll('[data-dec]').forEach(function(x){x.onclick=function(e){e.stopPropagation();var k=x.dataset.dec;if(!needQ[k])return;needQ[k]--;if(!needQ[k]){delete needQ[k];needOrder=needOrder.filter(function(y){return y!==k})}sync()}});
    dc.querySelectorAll('[data-more]').forEach(function(x){x.onclick=function(e){e.stopPropagation();productPopup(x.dataset.more,inc)}});
    dc.querySelectorAll('.fcat').forEach(function(x){x.onclick=function(e){if(e.target.closest('button'))return;inc(x.dataset.nq)}});
    $('nFill').onclick=function(){if(total()!==P)return;
      var order=(S.core?[S.core]:[]).concat(needOrder.filter(function(k){return k!==S.core}));if(!S.core)S.core=order[0];
      var sl=[];order.forEach(function(k){for(var i=0;i<(needQ[k]||0);i++)sl.push(k)});
      S.slots=sl.slice(0,P);while(S.slots.length<P)S.slots.push(null);
      S._lockOv=false;S._fillPending=false;closeInfo();S._prevOn=null;render();saveS();setTimeout(celebrate,300)};
  }

  function quizPanel(dc,done){
    var cur=Array.isArray(S.quiz)?S.quiz:[];var items=PROBS.filter(function(p){return PROD[p.key]});
    var el=document.createElement('div');el.className='fqz';
    el.innerHTML='<div class="fqzc"><h3'+ck('fill.quiz.t')+'>'+T('fill.quiz.t')+'</h3><p'+ck('fill.quiz.s')+'>'+T('fill.quiz.s')+'</p>'
      +'<div class="fqzg">'+items.map(function(p){return '<label class="fqzi"><input type="checkbox" value="'+esc(p.key)+'"'+(cur.indexOf(p.key)>-1?' checked':'')+'><span><b>'+esc(p.t)+'</b>'+(p.sub?'<small>'+esc(p.sub)+'</small>':'')+'</span></label>'}).join('')+'</div>'
      +'<div class="fqza"><button class="cta" id="fqGo" type="button"'+ck('fill.quiz.go')+'>'+T('fill.quiz.go')+'</button><button class="lnk" id="fqSkip" type="button"'+ck('fill.quiz.skip')+'>'+T('fill.quiz.skip')+'</button></div></div>';
    dc.appendChild(el);el.scrollTop=0;
    var go=el.querySelector('#fqGo'),picked=function(){return Array.from(el.querySelectorAll('input:checked')).map(function(x){return x.value})};
    var sync=function(){go.disabled=!picked().length};sync();el.querySelectorAll('input').forEach(function(x){x.onchange=sync});
    go.onclick=function(){var k=picked();if(!k.length)return;el.remove();done(k)};
    el.querySelector('#fqSkip').onclick=function(){el.remove()};
  }

  /* ── ЕКРАН 1: избор на кутия (v73: лента −%, мобилно резюме, награди, „за да отключиш“) ── */
  function boxCard(b,i){
    var c=S.core&&PROD[S.core]?PROD[S.core]:null,bv=boxVars(b);
    var from=c?r2(c.price*b.packs*(1-b.pct/100)+(b.rw.indexOf('ship')>-1?0:SHIP)):null;bv.from=from!=null?m(from):'';if(c){bv.core=esc(c.name)}
    var tixr=b.rw.filter(function(r){return /^tix/.test(r)})[0];
    var yacht=tixr?'<div class="bry">'+rimg('tix','🛥️')+'<div><em class="tixb"'+ck('yacht.badge')+'>'+T('yacht.badge',bv)+'</em><b'+ck('yacht.hero')+'>'+T('yacht.hero')+'</b><small'+ck('yacht.sub')+'>'+T('yacht.sub')+'</small></div></div>':'';
    var main=b.rw.indexOf('cosm1')>-1?'cosm1':b.rw.indexOf('cosm50')>-1?'cosm50':b.rw.indexOf('book')>-1?'book':'';
    var cosm=main==='cosm50'?'<div class="cosmhl">'+rimg('cosm50',RW('cosm50').ic)+'<div><em'+ck('box.cosm50.em')+'>'+T('box.cosm50.em',bv)+'</em><b'+ck('box.cosm50.b')+'>'+T('box.cosm50.b',bv)+'</b><small'+ck('box.cosm50.s')+'>'+T('box.cosm50.s',bv)+'</small>'+cosmPrice('cosm50')+'</div></div>'
      :main==='cosm1'?'<div class="cosmhl gold">'+rimg('cosm1',RW('cosm1').ic)+'<div><em'+ck('box.cosm1.em')+'>'+T('box.cosm1.em',bv)+'</em><b'+ck('box.cosm1.b')+'>'+T('box.cosm1.b',bv)+'</b><small'+ck('box.cosm1.s')+'>'+T('box.cosm1.s',bv)+'</small>'+cosmPrice('cosm1')+'</div></div>'
      :main==='book'?'<div class="cosmhl book">'+rimg('book',RW('book').ic)+'<div><em'+ck('box.book.em')+'>'+T('box.book.em',bv)+'</em><b'+ck('box.book.b')+'>'+T('box.book.b',bv)+'</b><small'+ck('box.book.s')+'>'+T('box.book.s',bv)+'</small></div></div>'
      :'<div class="cosmhl no"><i>👑</i><div><b'+ck('box.cosm.no')+'>'+T('box.cosm.no')+'</b></div></div>';
    /* v1.0.29: наградите на десктоп са еднакви редове (снимка · заглавие · подзаглавие), сетът е откроен; липсващите са приглушени */
    var brows=(tixr?'<div class="br tix">'+rimg('tix','🛥️')+'<div><b'+ck('yacht.badge')+'>'+T('yacht.badge',bv)+'</b><small'+ck('yacht.hero')+'>'+T('yacht.hero')+'</small></div></div>':'')
      +b.rw.filter(function(r){return !/^tix/.test(r)}).map(function(r){var w=RW(r);
        if(r==='cosm1'||r==='cosm50')return '<div class="br hl">'+rimg(r,w.ic)+'<div><em'+ck('box.'+r+'.em')+'>'+T('box.'+r+'.em',bv)+'</em><b'+ck('box.'+r+'.b')+'>'+T('box.'+r+'.b',bv)+'</b><small'+ck('box.'+r+'.s')+'>'+T('box.'+r+'.s',bv)+'</small>'+cosmPrice(r)+'</div></div>';
        if(r==='book')return '<div class="br">'+rimg(r,w.ic)+'<div><b'+ck('box.book.b')+'>'+T('box.book.b',bv)+'</b><small'+ck('box.book.s')+'>'+T('box.book.s',bv)+'</small></div></div>';
        return '<div class="br">'+rimg(r,w.ic)+'<div><b'+ck('rw.'+r+'.sh')+'>'+w.sh+'</b></div></div>'}).join('')
      +b.no.map(function(r){var w=RW(r),nk=/^cosm/.test(r)?'rw.cosm.no.sh':'rw.'+r+'.sh';return '<div class="br off">'+rimg(r,w.ic)+'<div><b'+ck(nk)+'>'+T(nk)+'</b></div></div>'}).join('');
    var chips='<div class="brs">'+b.rw.filter(function(r){return !/^tix|^cosm/.test(r)&&r!==main}).map(function(r){var w=RW(r);return '<span class="brc">'+rimg(r,w.ic)+'<span'+ck('rw.'+r+'.sh')+'>'+w.sh+'</span></span>'}).join('')+b.no.filter(function(r){return !/^cosm/.test(r)||main==='book'}).map(function(r){var w=RW(r),nk=/^cosm/.test(r)?'rw.cosm.no.sh':'rw.'+r+'.sh';return '<span class="brc no">'+rimg(r,w.ic)+'<span'+ck(nk)+'>'+T(nk)+'</span></span>'}).join('')+'</div>';
    /* v1.0.10: „за да отключиш…“ вече не е в картата — показва се в попъпа „Напълни кутията си“ (fill.intro / mfill.intro) */
    var hasCosm=b.cosm_pay!=null,hasBook=b.rw.indexOf('book')>-1||b.rw.indexOf('ship')>-1;
    var best=b.id==='l'?'<div class="bbest"'+ck('box.best')+'>'+T('box.best')+'</div>':'';
    /* v1.0.24: на телефон „★ най-изгодна“ не е в реда с името (ставаха три реда и бутонът се разместваше), а най-отдолу в картата (.bbest-m) */
    var mini='<div class="bmini"><div class="bm1"><div class="bmt"><b>'+esc(b.name)+'</b><small'+ck('bmini.packs')+'>'+T('bmini.packs',bv)+'</small></div><button class="cta bmcta'+(b.id==='l'?' gold':b.id==='s'?' soft':'')+'" data-box="'+i+'"'+ck('bmini.cta')+'>'+T('bmini.cta')+'</button></div>'
      /* v1.0.29: „спестяваш до €X“ се вижда и на телефон */
      +'<div class="bmsave"><span'+ck('box.save')+'>'+T('box.save',bv)+'</span></div>'
      +'<div class="bmr"><div class="bmrow y">'+rimg('tix','🛥️')+'<span'+ck('bmini.yacht')+'>'+T('bmini.yacht',bv)+'</span></div>'
      +(hasCosm?'<div class="bmrow c'+(b.id==='l'?' g':'')+'">'+rimg('cosm1','🌸')+'<span'+ck('bmini.cosm')+'>'+T('bmini.cosm',bv)+'</span></div>':'')
      +(b.rw.indexOf('book')>-1?'<div class="bmrow x">'+rimg('book','📖')+'<span'+ck('bmini.book')+'>'+T('bmini.book')+'</span></div>':'')
      +(b.rw.indexOf('ship')>-1?'<div class="bmrow x">'+rimg('ship','🚚')+'<span'+ck('bmini.ship')+'>'+T('bmini.ship')+'</span></div>':'')
      +(!hasCosm&&hasBook?'<div class="bmrow off">'+rimg('cosm1','🌸')+'<span'+ck('bmini.nocosm')+'>'+T('bmini.nocosm')+'</span></div>':'')
      +(!hasCosm&&!hasBook?'<div class="bmrow off">'+rimg('book','🌸')+'<span'+ck('bmini.none')+'>'+T('bmini.none')+'</span></div>':'')
      +'<button class="bmore" data-more-box="'+b.id+'"><span'+ck('bmini.more')+'>'+T('bmini.more')+'</span> <i>▾</i></button></div></div>';
    var ord=(A.box_order&&A.box_order.mobile)||[];var oi=ord.indexOf(b.id);
    return '<div class="box '+b.id+'"'+(oi>-1&&MOB()?' style="order:'+(oi+1)+'"':'')+'>'
      +'<div class="brib'+(b.id==='l'?' gold':'')+'"><div class="bribl"'+ck('box.rib')+'>'+T('box.rib',bv)+'</div></div>'
      +mini
      +(b.id==='l'?'<div class="bbest-d"'+ck('box.best')+'>'+T('box.best')+'</div>':'')
      +'<div class="bhd"><div class="bname">'+esc(b.name)+'</div>'+best+'</div>'
      /* v1.0.29: десктоп — голям процент + „отстъпка на поръчката ти“, после „Спестяваш до €X“ като откроен ред, после наградите */
      +'<div class="bpc"><b>−'+b.pct+'%</b><small'+ck('box.pct.sub')+'>'+T('box.pct.sub')+'</small></div>'
      +'<div class="bsave"><span'+ck('box.save')+'>'+T('box.save',bv)+'</span></div>'
      +(from?'<div class="bfrom"'+ck('box.from')+'>'+T('box.from',bv)+'</div>':'')
      +'<div class="brw"><div class="brl">'+brows+'</div></div>'
      +'<button class="cta'+(b.id==='l'?' gold':b.id==='s'?' soft':'')+'" data-box="'+i+'"'+ck('box.cta')+'>'+T('box.cta',bv)+'</button>'
      +'<div class="bnote"'+ck('box.note')+'>'+T('box.note')+'</div>'
      +(b.id==='l'?'<div class="bbest-m"'+ck('box.best')+'>'+T('box.best')+'</div>':'')+'</div>';
  }
  function renderS1(){
    var el=$('apS1'),h='';
    var c=S.core&&PROD[S.core]?PROD[S.core]:null;
    var ord=(A.box_order&&A.box_order.desktop)||BOXES.map(function(b){return b.id});
    /* v1.0.29 (по примера на човека): стъпки 1·2·3, „Твоят избор: …“ + голямо заглавие + подзаглавие; лентата с яхтата е под кутиите,
       като блок със снимка, до нея блок за козметичния сет (и двата отварят инфото за наградата) */
    var GI=(A.gate&&A.gate.images)||{};
    h+='<div class="steps">'+[1,2,3].map(function(n){return '<span class="st'+(n===1?' on':'')+'"><i>'+n+'</i><span'+ck('steps.'+n)+'>'+T('steps.'+n)+'</span></span>'}).join('')+'</div>'
      +'<div class="s1hero">'+(c?'<em class="eyebrow"'+ck('boxes.eyebrow')+'>'+T('boxes.eyebrow')+'</em>':'')+'<h1'+ck(c?'boxes.h1':'boxes.h1.noutm')+'>'+T(c?'boxes.h1':'boxes.h1.noutm')+'</h1><p class="s1sub"'+ck('boxes.sub')+'>'+T('boxes.sub')+'</p></div>'
      +'<div class="s1info top"><div class="ib2 y" id="s1Y"><i class="ibpic">'+(GI.yacht?'<img src="'+esc(GI.yacht)+'" alt="">':'🛥️')+'</i><div class="ibtx"><em'+ck('yban.tag')+'>'+T('yban.tag')+'</em><b'+ck('yban.b')+'>'+T('yban.b')+'</b><small'+ck('yban.s')+'>'+T('yban.s')+'</small><button class="lnk" type="button" data-rw="tix"'+ck('yban.more')+'>'+T('yban.more')+'</button></div></div>'
      +'<div class="ib2 c" id="s1C"><i class="ibpic">'+(GI.cosm?'<img src="'+esc(GI.cosm)+'" alt="">':'🌸')+'</i><div class="ibtx"><em'+ck('cban.tag')+'>'+T('cban.tag')+'</em><b'+ck('cban.b')+'>'+T('cban.b')+'</b><small'+ck('cban.s')+'>'+T('cban.s')+'</small><button class="lnk" type="button" data-rw="cosm1"'+ck('cban.more')+'>'+T('cban.more')+'</button></div></div></div>'
      +'<div class="boxes">'+ord.filter(function(id){return BI[id]!=null}).map(function(id){return boxCard(boxOf(id),BI[id])}).join('')+'</div><div class="bnote-m"'+ck('box.note')+'>'+T('box.note')+'</div>'

      +'<div class="guarl"><span'+ck('boxes.guar')+'>'+T('boxes.guar')+'</span>'+(c?' · <button class="lnk" id="chg1"'+ck('boxes.chg')+'>'+T('boxes.chg')+'</button>':'')+'</div>';
    el.innerHTML=h;
    el.querySelectorAll('[data-box]').forEach(function(b){b.onclick=function(){chooseBox(+b.dataset.box,false)}});
    el.querySelectorAll('[data-rw]').forEach(function(x){x.onclick=function(e){e.stopPropagation();rewardPopup(x.dataset.rw)}});
    el.querySelectorAll('[data-more-box]').forEach(function(x){x.onclick=function(){var bx=x.closest('.box');var open=!bx.classList.contains('open');el.querySelectorAll('.box.open').forEach(function(o){o.classList.remove('open')});if(open){bx.classList.add('open');setTimeout(function(){bx.scrollIntoView({behavior:'smooth',block:'nearest'})},50)}}});
    var cg=$('chg1');if(cg)cg.onclick=function(){S.core=null;render();saveS()};
  }

  /* ── ЕКРАН 2: таблото (резервен път за редакция) ── */
  function tile(k){
    var pr=PROD[k],q=qtyOf(k),p=pct(),isFull=full();
    return '<div class="tile'+(q?' on':'')+'"><div class="pic">'+pic(pr,'timg')+'</div><div class="mid" data-p="'+k+'" style="cursor:pointer"><b>'+esc(pr.name)+'</b><small>'+esc(pr.ds)+'</small><span class="desc">'+esc(String(pr.desc).split('.')[0])+'.</span></div>'
      +'<div class="act"><div class="pr">'+m(r2(pr.price*(1-p/100)))+'<s>'+m(pr.price)+'</s></div>'+(q?'<div class="step"><button data-rm="'+k+'">−</button><b>'+q+'</b><button data-add="'+k+'"'+(isFull?' disabled':'')+'>+</button></div>':'<button class="addb" data-add="'+k+'"'+(isFull?' disabled':'')+ck('bl.tile.add')+'>'+T('bl.tile.add')+'</button>')+'</div></div>';
  }
  function shortRw(r){return RW(r).sh}
  function renderS2(){
    var el=$('apS2');if(S.box===null){el.innerHTML='';return}
    var b=BX(),n=boxes(),need=b.packs-n,h='',mob=window.innerWidth<1000;
    $('apChip').innerHTML=b.ic+' '+esc(b.name)+' · '+b.packs+' '+opk(b.packs)+' · −'+b.pct+'% <button class="sw" id="swBox"'+ck('head.chip.sw')+'>'+T('head.chip.sw')+'</button>';
    var onArr=b.rw.map(function(r,ri){return ri===0?n>=1:full()});var uc=onArr.filter(Boolean).length;
    var strip='<div class="rstrip"><div class="rst"><span'+ck('strip.play')+'>'+T('strip.play')+' <button class="sw" id="swBox3">'+T('strip.sw')+'</button></span><span class="rtm" id="apTimer2"></span><span class="rcnt"'+ck('strip.count')+'>'+T('strip.count',{on:uc,total:b.rw.length})+'</span></div><div class="rsi">'+b.rw.map(function(r,ri){var on=onArr[ri];return '<div class="rsc '+(on?'on':'lk')+'"><i>'+RW(r).ic+'</i><div class="tx"><b>'+shortRw(r)+'</b><small>'+(on?T('strip.on'):T('strip.locked'))+'</small></div>'+(on?'<em class="chk">✓</em>':'')+'</div>'}).join('')+'</div></div>';
    var hs=$('apStrip');if(S.screen!==2||!mob){hs.innerHTML=''}else if(hs.innerHTML!==strip)hs.innerHTML=strip;
    h+='<div class="colL"><div class="bhead"><div class="bi">'+b.ic+'</div><div><h2'+ck('bl.h2')+'>'+T('bl.h2')+'</h2><div class="sub"'+ck(need>0?'bl.sub.need':(mob?'bl.sub.full.m':'bl.sub.full.d'))+'>'+(need>0?T('bl.sub.need'):(mob?T('bl.sub.full.m',{full_msg:fullMsg()}):T('bl.sub.full.d')))+'</div></div>'+(mob?'<button class="lnk sw" id="swBox2"'+ck('bl.sw')+'>'+T('bl.sw')+'</button>':'')+'</div>';
    var slots='';for(var i=0;i<b.packs;i++){var k=S.slots[i];
      if(k){var pr=PROD[k];slots+='<div class="srow filled'+(i===0?' shero':'')+'" data-sl="'+i+'" data-hero="'+esc(T('bl.slot.hero'))+'"><span class="sn">'+(i+1)+'</span><span class="spic">'+pic(pr,'simg')+'</span><span class="smid"><b>'+esc(pr.name)+'</b><small>'+esc(pr.ds)+'</small></span><span class="spr">'+m(r2(pr.price*(1-b.pct/100)))+'<s>'+m(pr.price)+'</s></span><span class="sact"><button class="sbt rep" data-rep="'+i+'"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h13l-3-3"/><path d="M20 17H7l3 3"/></svg><span'+ck('bl.slot.rep')+'>'+T('bl.slot.rep')+'</span></button><button class="sbt rm" data-si="'+i+'" aria-label="махни">✕</button></span></div>'}
      else{slots+='<div class="srow empty'+(i===S.slots.indexOf(null)?' next':'')+(i===0?' shero':'')+'" data-se="'+i+'"><span class="sn">'+(i+1)+'</span><span class="spic">＋</span><span class="smid"><b'+ck(i===0?'bl.slot.core':'bl.slot.empty')+'>'+T(i===0?'bl.slot.core':'bl.slot.empty')+'</b><small'+ck('bl.slot.sub')+'>'+T('bl.slot.sub')+'</small></span><span class="spr"></span><span class="sact"><button class="sbt add" data-se="'+i+'">＋</button></span></div>'}}
    h+='<div class="slist" data-hero-label="'+esc(T('bl.slot.hero'))+'">'+slots+'</div>';
    var fits=(FIT[S.core]||[]).filter(function(k){return PROD[k]});
    var keys=[S.core].concat(fits).concat(ORDER.filter(function(k){return k!==S.core&&fits.indexOf(k)<0})).filter(function(k){return k&&PROD[k]});
    if(need>0){
      h+='<div class="sline"'+ck('bl.count')+'>'+T('bl.count')+'</div><button class="cta" id="pickBtn"'+ck('bl.pick')+'>'+T('bl.pick')+'</button>';
      if(!mob){var top3=keys.filter(function(k){return qtyOf(k)<b.packs}).slice(0,3);h+='<div class="k2 k2row"><span'+ck('bl.reco')+'>'+T('bl.reco')+'</span><button class="lnk" id="seeAll"'+ck('bl.seeall')+'>'+T('bl.seeall')+'</button></div><div class="tiles">'+top3.map(tile).join('')+'</div>'}
    }else if(!mob){
      h+='<div class="done mini"><span'+ck('bl.done.mini')+'>'+T('bl.done.mini')+'</span><button class="lnk" id="editPick"'+ck('bl.edit')+'>'+T('bl.edit')+'</button></div>';
    }else{
      h+='<div class="done"><div class="dh"><i>🎉</i><div><b'+ck('bl.done.b')+'>'+T('bl.done.b')+'</b><small>'+fullMsg()+'</small></div><div class="dsave"><em'+ck('bl.done.save')+'>'+T('bl.done.save')+'</em><b>'+m0(save())+'</b></div></div>'
        +'<div class="drw">'+b.rw.map(function(r){return '<div class="drc'+(/^cosm/.test(r)?' cosm':'')+'"><i>'+RW(r).ic+'</i><b>'+RW(r).t+'</b>'+cosmPrice(r)+'</div>'}).join('')+'</div>'
        +'<div class="vline eq"><div class="vl pay"><em>'+T('pane.pay')+'</em><b>'+m(pay())+'</b></div><div class="vlarr">→</div><div class="vl get"><em>'+T('pane.get')+'</em><b>'+m0(value())+'</b></div></div>'
        +'<button class="cta" id="goDone"'+ck('bl.done.cta')+'>'+T('bl.done.cta')+'</button><div style="text-align:center;margin-top:8px"><button class="lnk" id="editPick">'+T('bl.edit')+'</button></div></div>';
    }
    if(b.id!=='l'&&(need===0||!mob)){
      var target=b.id==='s'?boxOf('m'):boxOf('l');if(target){var tv=tgtVars(target);var news=target.rw.filter(function(r){return b.rw.indexOf(r)<0});
      h+='<div class="push"><div class="ph"><i>'+target.ic+'</i><span'+ck('push.h')+'>'+T('push.h',tv)+'</span></div><div class="pl"><div><i>💸</i><span'+ck('push.pct')+'>'+T('push.pct',tv)+'</span></div>'+news.slice(0,2).map(function(r){return '<div><i>'+RW(r).ic+'</i>'+shortRw(r)+'</div>'}).join('')+'</div>'
        +'<div class="ps"'+ck('push.save')+'>'+T('push.save',tv)+'</div><button class="cta gold" id="upBox"'+ck('push.cta')+'>'+T('push.cta',tv)+'</button>'+(b.id==='s'?'<div style="text-align:center;margin-top:6px"><button class="lnk" id="upBox2"'+ck('push.big')+'>'+T('push.big')+'</button></div>':'')+'</div>'}
    }
    h+='</div>';
    /* панел */
    h+='<div class="colR"><div class="card order"><div class="ohead"><span'+ck('pane.h')+'>'+T('pane.h')+'</span><button class="lnk sw" id="swBox4">'+T('bl.sw')+'</button></div>';
    var any=false;S.slots.forEach(function(k){if(!k)return;any=true;var pr=PROD[k];h+='<div class="orow"><span class="pic">'+pic(pr,'oimg')+'</span><span class="n">'+esc(pr.name)+'</span><span class="p">'+m(r2(pr.price*(1-b.pct/100)))+'<s>'+m(pr.price)+'</s></span></div>'});
    if(!any)h+='<div class="orow empty"'+ck('pane.empty')+'>'+T('pane.empty')+'</div>';
    for(var e=0;e<need;e++)h+='<div class="orow empty"'+ck('pane.slot')+'>'+T('pane.slot')+'</div>';
    if(need)h+='<div class="ostat"'+ck('pane.need')+'>'+T('pane.need')+'</div>';
    h+='<div class="rl">'+b.rw.map(function(r,ri){var on=onArr[ri],w=RW(r);return '<div class="rr'+(on?'':' lk')+'" data-rw="'+r+'"><div class="rim"><i>'+w.ic+'</i><em>'+(on?'✓':'🔒')+'</em></div><div class="rtx"><b'+ck('rw.'+r+'.t')+'>'+w.t+'</b><small>'+(on?w.s:T('pane.locked'))+'</small></div>'+(on?cosmPrice(r):'')+'</div>'}).join('')+'</div>'+(need?'':'<p class="ofull">'+fullMsg()+'</p>');
    h+='<div class="vline"><div class="vl pay"><em'+ck('pane.pay')+'>'+T('pane.pay')+'</em><b>'+m(pay())+'</b><small>'+(ship()&&n?T('pane.ship.paid'):T('pane.ship.free'))+'</small></div><div class="vlarr">→</div><div class="vl get"><em'+ck('pane.get')+'>'+T('pane.get')+'</em><b>'+m0(value())+'</b><small'+ck('pane.save')+'>'+T('pane.save',{save:m0(Math.max(0,save()))})+'</small></div></div>'
      +'<div class="ctawrap"><button class="cta" id="oGo"'+(full()?'':' disabled')+ck(full()?'pane.cta.full':'pane.cta.need')+'>'+(full()?T('pane.cta.full'):T('pane.cta.need'))+'</button><div class="hintb" id="hint2"'+ck('pane.hint')+'>'+T('pane.hint')+'</div></div>'
      +'<div class="trust"'+ck('pane.trust')+'>'+T('pane.trust')+'</div></div></div>';
    var sw=window.scrollY;el.innerHTML=h;window.scrollTo(0,sw);
    if(S._prevOn&&S._prevOn.length===onArr.length){onArr.forEach(function(v,i){if(v&&!S._prevOn[i]){var rr=el.querySelectorAll('.rr')[i];if(rr)rr.classList.add('unlock');var cs=root.querySelectorAll('#apStrip .rsc')[i];if(cs)cs.classList.add('unlock')}})}
    S._prevOn=onArr;
    el.querySelectorAll('[data-add]').forEach(function(x){x.onclick=function(){addSlot(x.dataset.add)}});
    var pb=$('pickBtn');if(pb)pb.onclick=pickerPopup;
    var gd=$('goDone');if(gd)gd.onclick=sureContinue;
    var ep=$('editPick');if(ep)ep.onclick=function(){removeSlot(S.slots.length-1);pickerPopup()};
    el.querySelectorAll('[data-rm]').forEach(function(x){x.onclick=function(){removeOne(x.dataset.rm)}});
    el.querySelectorAll('[data-si]').forEach(function(x){x.onclick=function(e){e.stopPropagation();removeSlot(+x.dataset.si)}});
    el.querySelectorAll('[data-rep]').forEach(function(x){x.onclick=function(e){e.stopPropagation();S.replaceSlot=+x.dataset.rep;pickerPopup()}});
    el.querySelectorAll('.srow.filled[data-sl]').forEach(function(x){x.onclick=function(){slotPopup(+x.dataset.sl)}});
    var sa=$('seeAll');if(sa)sa.onclick=function(){S.replaceSlot=null;pickerPopup()};
    el.querySelectorAll('[data-se]').forEach(function(x){x.onclick=function(e){e.stopPropagation();S.replaceSlot=+x.dataset.se;pickerPopup()}});
    el.querySelectorAll('.mid[data-p]').forEach(function(x){x.onclick=function(){prodInfo(x.dataset.p)}});
    el.querySelectorAll('.rr[data-rw]').forEach(function(x){x.onclick=function(){rewardPopup(x.dataset.rw)}});
    ['swBox','swBox2','swBox3','swBox4'].forEach(function(id){var x=$(id);if(x)x.onclick=switchPopup});
    var ub=$('upBox');if(ub)ub.onclick=function(){chooseBox(b.id==='s'?BI.m:BI.l,true)};
    var ub2=$('upBox2');if(ub2)ub2.onclick=function(){chooseBox(BI.l,true)};
    var g=el.querySelector('[data-guar]');if(g)g.onclick=function(){info(T('guar.title'),'<p'+ck('guar.p1')+'>'+T('guar.p1')+'</p><p'+ck('guar.p2')+'>'+T('guar.p2')+'</p>')};
    var og=$('oGo');if(og)og.onclick=sureContinue;
    $('apRib').innerHTML='<span class="rp"'+ck('sticky.pay')+'>'+T('sticky.pay')+'</span><span class="rg"'+ck('sticky.get')+'>'+T('sticky.get')+'</span>';
    var sg=$('apStGo');sg.disabled=!full();sg.innerHTML=full()?T('pane.cta.full'):T('pane.cta.need');sg.onclick=sureContinue;
    if(S.screen===2)armHint('hint2');
  }
  /* селектор (мобилно основно) */
  function prow(k){
    var pr=PROD[k],q=qtyOf(k),p=pct(),isFull=full()&&S.replaceSlot==null;
    return '<div class="prow'+(q?' on':'')+'"><div class="ppic" data-p="'+k+'">'+pic(pr,'pimg')+(q?'<em>'+q+'</em>':'')+'</div><div class="pmid" data-p="'+k+'"><b>'+esc(pr.name)+'</b><small>'+esc(pr.ds)+'</small></div><div class="ppr">'+m(r2(pr.price*(1-p/100)))+'<s>'+m(pr.price)+'</s></div>'
      +'<button class="padd" data-add="'+k+'"'+(isFull?' disabled':'')+' aria-label="добави">'+(S.replaceSlot!=null?'<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h13l-3-3"/><path d="M20 17H7l3 3"/></svg>':'＋')+'</button></div>';
  }
  function pickerPopup(){
    var b=BX(),need=b.packs-boxes(),rep=S.replaceSlot!=null;
    if(!rep&&need<=0){closeInfo();return}
    var repK=rep?S.slots[S.replaceSlot]:null;
    var cats=uniq(ORDER.map(function(k){return PROD[k].cat||''}));
    var fits=[S.core].concat(FIT[S.core]||[]).filter(function(k){return k&&PROD[k]});
    var groups=cats.map(function(c){return {c:c,ks:ORDER.filter(function(k){return (PROD[k].cat||'')===c})}}).filter(function(g){return g.ks.length});
    var head=rep?(repK?T('pk.head.rep',{name:esc(PROD[repK].name)}):T('pk.head.pos',{pos:S.replaceSlot+1})):T('pk.head.need');
    var body='<div class="pkhead">'+head+'<small'+ck('pk.head.sub')+'>'+T('pk.head.sub')+'</small></div>'
      +(fits.length?'<div class="pcat"><span class="pch"'+ck('pk.reco')+'>'+T('pk.reco')+'</span><div class="plist">'+fits.map(prow).join('')+'</div></div>':'')
      +groups.map(function(g){return '<div class="pcat"><span class="pch">'+esc(g.c)+'</span><div class="plist">'+g.ks.map(prow).join('')+'</div></div>'}).join('');
    info(rep?(repK?T('pk.title.rep'):T('pk.title.pos',{pos:S.replaceSlot+1})):T('pk.title'),body);
    var dc=$('apDc');
    dc.querySelectorAll('[data-add]').forEach(function(x){x.onclick=function(){var sc=dc.scrollTop,k=x.dataset.add;
      if(S.replaceSlot!=null){var wasEmpty=S.slots[S.replaceSlot]==null;S.slots[S.replaceSlot]=k;S.replaceSlot=null;closeInfo();render();saveS();if(full()&&wasEmpty)setTimeout(celebrate,350);else toast(T(wasEmpty?'pk.added':'pk.replaced',{name:esc(PROD[k].name)}),false);return}
      addSlot(k);if(full())closeInfo();else{pickerPopup();$('apDc').scrollTop=sc}}});
    dc.querySelectorAll('[data-p]').forEach(function(x){x.onclick=function(){prodInfo(x.dataset.p)}});
  }
  function slotPopup(i){
    var k=S.slots[i],pr=PROD[k];
    info(pic(pr,'hpic')+' '+esc(pr.name),prodBody(k)+'<div class="sbtns"><button class="cta soft" id="sRep"'+ck('slot.rep')+'>'+T('slot.rep')+'</button><button class="cta soft" id="sRm"'+ck('slot.rm')+'>'+T('slot.rm')+'</button></div>');
    $('sRep').onclick=function(){S.replaceSlot=i;pickerPopup()};$('sRm').onclick=function(){closeInfo();removeSlot(i)};
  }
  function sureContinue(){
    if(BX().id==='l'||!BOXES[S.box+1]){go(A.secret&&A.secret.enabled&&SECRETLIST().length?4:5);return}
    var cur=BX(),tgt=BOXES[S.box+1],tv=tgtVars(tgt);
    var news=tgt.rw.filter(function(r){return cur.rw.indexOf(r)<0});
    var rows='<div class="dl"><i>💸</i><span'+ck('su.pct')+'>'+T('su.pct',tv)+'</span></div>'+news.map(function(r){return '<div class="dl"><i>'+RW(r).ic+'</i><span>'+RW(r).t+'</span></div>'}).join('');
    var dc=$('apDc');dc.className='dc';dc.scrollTop=0;S._lockOv=false;
    dc.innerHTML='<div class="dcx"><div class="dpill"'+ck('su.pill')+'>'+T('su.pill')+'</div><h3 class="dbig"'+ck('su.title')+'>'+T('su.title')+'</h3><p class="sup"'+ck('su.p')+'>'+T('su.p',tv)+'</p>'
      +'<div class="dbox"><em'+ck('su.get')+'>'+T('su.get',tv)+'</em>'+rows+'</div><div class="dkeep"'+ck('su.keep')+'>'+T('su.keep',tv)+'</div>'
      +'<button class="cta gold" id="upNow" style="margin-top:14px"'+ck('su.cta')+'>'+T('su.cta',tv)+'</button>'+(cur.id==='s'?'<div style="margin-top:6px"><button class="lnk" id="upBig"'+ck('su.big')+'>'+T('su.big')+'</button></div>':'')
      +'<div class="dor"><span'+ck('su.or')+'>'+T('su.or')+'</span></div><button class="cta soft" id="keepGo"'+ck('su.stay')+'>'+T('su.stay')+'</button></div>';
    $('apOv').classList.remove('off');
    $('upNow').onclick=function(){closeInfo();chooseBox(S.box+1,true)};
    var ub=$('upBig');if(ub)ub.onclick=function(){closeInfo();chooseBox(BI.l,true)};
    $('keepGo').onclick=function(){closeInfo();go(A.secret&&A.secret.enabled&&SECRETLIST().length?4:5)};
  }
  function switchPopup(){
    var b='<p class="mut"'+ck('sw.p')+'>'+T('sw.p')+'</p><div class="bx3">'+BOXES.map(function(x,i){return '<div class="bx '+x.id+(i===S.box?' cur':'')+'" data-bx="'+i+'"><b>'+esc(x.name)+'</b><small>'+x.packs+' '+opk(x.packs)+'</small><em>−'+x.pct+'%</em><small>'+x.rw.map(function(r){return RW(r).ic}).join(' ')+'</small>'+(i===S.box?'<small class="curl"'+ck('sw.cur')+'>'+T('sw.cur')+'</small>':'')+'</div>'}).join('')+'</div>';
    info(T('sw.title'),b);
    $('apDc').querySelectorAll('.bx[data-bx]').forEach(function(x){x.onclick=function(){var to=+x.dataset.bx;if(to===S.box){closeInfo();return}if(to<S.box)sureDown(to);else{closeInfo();chooseBox(to,true)}}});
  }
  function sureDown(to){
    var cur=BX(),tgt=BOXES[to],tv=tgtVars(tgt);
    var lost=cur.rw.filter(function(r){return tgt.rw.indexOf(r)<0}).map(function(r){return '<div class="dl"><i>'+RW(r).ic+'</i><span>'+RW(r).t+'</span></div>'}).join('')+'<div class="dl"><i>💸</i><span'+ck('dn.pct')+'>'+T('dn.pct',tv)+'</span></div>';
    var stay=boxes()<cur.packs?T('dn.stay.need'):T('dn.stay.'+cur.id);
    var dc=$('apDc');dc.className='dc';dc.scrollTop=0;
    dc.innerHTML='<div class="dcx"><div class="dpill"'+ck('dn.pill')+'>'+T('dn.pill',tv)+'</div><h3 class="dbig"'+ck('dn.title')+'>'+T('dn.title')+'</h3><div class="dbox"><em'+ck('dn.lose')+'>'+T('dn.lose')+'</em>'+lost+'</div><div class="dkeep"'+ck('dn.keep')+'>'+T('dn.keep')+'</div>'
      +'<button class="cta gold" id="stay" style="margin-top:14px"'+ck('dn.stay')+'>'+T('dn.stay',{stay_what:stay})+'</button><div class="dor"><span>'+T('su.or')+'</span></div><button class="cta soft" id="down"'+ck('dn.go')+'>'+T('dn.go',tv)+'</button></div>';
    $('apOv').classList.remove('off');$('stay').onclick=function(){if(S.screen!==2&&full()){celebrate()}else closeInfo()};$('down').onclick=function(){closeInfo();chooseBox(to,true)};
  }
  /* подсказка при колебание */
  var hintT=null,hintId=null;
  function showHint(){var x=hintId&&$(hintId);if(x&&full())x.classList.add('on')}
  function hideHint(){var x=hintId&&$(hintId);if(x)x.classList.remove('on')}
  function armHint(id){hintId=id;clearTimeout(hintT);hideHint();hintT=setTimeout(showHint,8000)}
  ['click','keydown','scroll','touchstart'].forEach(function(ev){document.addEventListener(ev,function(){if(!hintId)return;hideHint();clearTimeout(hintT);hintT=setTimeout(showHint,8000)},{passive:true})});

  /* ── ЕКРАН 4: три любимци (изключено по подразбиране — A.secret.enabled) ── */
  function renderS4(){
    var el=$('apS4');if(S.box===null||!(A.secret&&A.secret.enabled)||!SECRETLIST().length){el.innerHTML='';return}
    var cart=S.slots.filter(Boolean).map(function(k){return PROD[k].name}).join(', '),cnt=Object.keys(S.secret).length,st=secretTotal(),f=1-SECRET_PCT/100;
    var h='<div class="narrow"><button class="back" id="b4"'+ck('sc.back')+'>'+T('sc.back')+'</button><span class="sbadge"'+ck('sc.badge')+'>'+T('sc.badge')+'</span><h2'+ck('sc.h2')+'>'+T('sc.h2')+'</h2><p class="lead"'+ck('sc.lead')+'>'+T('sc.lead',{cart:esc(cart)})+'</p><div class="tiles">';
    SECRETLIST().forEach(function(k){var pr=PROD[k],on=!!S.secret[k];h+='<div class="tile'+(on?' on':'')+'"><div class="pic">'+pic(pr,'timg')+'</div><div class="mid"><b>'+esc(pr.name)+'</b><small>'+esc(pr.ds)+'</small><span class="desc">'+esc(String(pr.desc).split('.')[0])+'.</span></div><div class="act"><div class="pr rose">'+m(r2(pr.price*f))+'<s>'+m(pr.price)+'</s></div><button class="addb'+(on?' on':'')+'" data-s="'+k+'">'+(on?T('sc.added'):T('sc.add'))+'</button></div></div>'});
    var v2=r2(value()+st/f);
    h+='</div><div class="vline nar"><div class="vl pay"><em>'+T('pane.pay')+'</em><b>'+m(r2(pay()+st))+'</b></div><div class="vlarr">→</div><div class="vl get"><em>'+T('pane.get')+'</em><b>'+m0(v2)+'</b><small>'+T('pane.save',{save:m0(r2(v2-pay()-st))})+'</small></div></div>'
      +'<div class="nar2"><button class="cta" id="c4"'+ck(cnt?'sc.cta.n':'sc.cta.0')+'>'+(cnt?T('sc.cta.n',{n:cnt,lyubimci:cnt===1?'любимец':'любимци'}):T('sc.cta.0'))+'</button></div></div>';
    el.innerHTML=h;$('b4').onclick=function(){go(2)};
    el.querySelectorAll('[data-s]').forEach(function(b){b.onclick=function(){var k=b.dataset.s;if(S.secret[k])delete S.secret[k];else S.secret[k]=1;render();saveS()}});
    $('c4').onclick=function(){go(5)};
  }
  /* ── ЕКРАН 5: поръчка (предаване към плащане) ── */
  function renderS5(){
    var el=$('apS5');if(S.box===null){el.innerHTML='';return}
    var b=BX(),p=b.pct,st=secretTotal(),f=1-SECRET_PCT/100,tot=r2(pay()+st),h='';
    h+='<div><button class="back" id="b5"'+ck('or.back')+'>'+T('or.back')+'</button><h2'+ck('or.h2')+'>'+T('or.h2')+'</h2><div class="card sum">';
    h+='<div class="row"><span>'+b.ic+' '+esc(b.name)+' · '+b.packs+' '+opk(b.packs)+'<small'+ck('or.box.sub')+'>'+T('or.box.sub')+'</small></span><span></span></div>';
    var cnt={};S.slots.filter(Boolean).forEach(function(k){cnt[k]=(cnt[k]||0)+1});
    Object.keys(cnt).forEach(function(k){var pr=PROD[k],q=cnt[k];h+='<div class="row"><span>'+pr.ph+' '+esc(pr.name)+' × '+q+'</span><span>'+m(r2(pr.price*q*(1-p/100)))+'</span></div>'});
    b.rw.forEach(function(r){var w=RW(r);h+='<div class="row"><span>'+w.ic+' '+w.t+'<small>'+w.s+'</small></span><span>'+(/^cosm/.test(r)?cosmPrice(r):(r==='book'?T('or.free'):T('or.incl')))+'</span></div>'});
    if(b.cosm_pay!=null)h+='<label class="row cosmrow consent"><span><input type="checkbox" id="cosmOk"'+(S.cosm?' checked':'')+'> <span'+ck('or.cosm')+'>'+T('or.cosm')+'</span><small'+ck('or.cosm.sub')+'>'+T('or.cosm.sub')+'</small></span><span>'+m(b.cosm_pay)+'<s>€ '+COSM+'</s></span></label>';
    SECRETLIST().forEach(function(k){if(S.secret[k])h+='<div class="row"><span>'+PROD[k].ph+' '+esc(PROD[k].name)+'<small'+ck('or.secret.sub')+'>'+T('or.secret.sub')+'</small></span><span>'+m(r2(PROD[k].price*f))+'</span></div>'});
    h+='<div class="row"><span'+ck('or.ship')+'>'+T('or.ship')+'</span><span>'+(ship()?m(SHIP):T('or.ship.free'))+'</span></div><div class="row tot"><span'+ck('or.total')+'>'+T('or.total')+'</span><span>'+m(tot)+lv(tot)+'</span></div>'
      +'<div class="vline"><div class="vl pay"><em>'+T('pane.pay')+'</em><b>'+m(tot)+'</b></div><div class="vlarr">→</div><div class="vl get"><em>'+T('pane.get')+'</em><b>'+m0(r2(value()+st/f))+'</b><small>'+T('pane.save',{save:m0(r2(value()+st/f-tot))})+'</small></div></div>'
      +'<div class="guar"'+ck('boxes.guar')+'>'+T('boxes.guar')+'</div></div></div>';
    h+='<div><div class="card paycard"><div class="k2"'+ck('or.pay.h')+'>'+T('or.pay.h')+'</div><div class="pay">'
      +'<button data-p="card"'+(S.pay==='card'?' class="on"':'')+'><i>💳</i><span><b'+ck('or.pay.card')+'>'+T('or.pay.card')+'</b><small'+ck('or.pay.card.s')+'>'+T('or.pay.card.s')+'</small></span><span class="rd"></span></button>'
      +(A.checkout&&A.checkout.cod===false?'':'<button data-p="cod"'+(S.pay==='cod'?' class="on"':'')+'><i>📦</i><span><b'+ck('or.pay.cod')+'>'+T('or.pay.cod')+'</b><small'+ck('or.pay.cod.s')+'>'+T('or.pay.cod.s')+'</small></span><span class="rd"></span></button>')+'</div>'
      +'<button class="cta" id="finish" style="margin-top:12px"'+ck('or.cta')+'>'+T('or.cta',{total:m(tot)})+'</button><div class="trust"'+ck('or.trust')+'>'+T('or.trust')+'</div></div></div>';
    el.innerHTML=h;$('b5').onclick=function(){if(A.secret&&A.secret.enabled&&SECRETLIST().length)go(4);else{go(1);setTimeout(celebrate,200)}};
    var ckb=$('cosmOk');if(ckb)ckb.onchange=function(){S.cosm=ckb.checked;saveS()};
    el.querySelectorAll('[data-p]').forEach(function(b){b.onclick=function(){S.pay=b.dataset.p;render();saveS()}});
    $('finish').onclick=function(){
      if(!full()){toast(T('ms.full'),true);return}
      if(!A.cart){toast(T('ms.preview'),true);return}
      var btn=$('finish');btn.disabled=true;btn.innerHTML=T('or.sending');
      var fd=new FormData();fd.append('action','ansa_promo_add');fd.append('nonce',A.nonce);fd.append('box',b.id);fd.append('pay',S.pay);fd.append('cosm',S.cosm?'1':'0');fd.append('email',S.email||'');fd.append('utm',S.utm||'');fd.append('lead',S.lead||0);if(A.draft)fd.append('draft','1');
      fd.append('items',JSON.stringify(S.slots.filter(Boolean).map(function(k){return PROD[k].id})));fd.append('secret',JSON.stringify(Object.keys(S.secret).filter(function(k){return PROD[k]}).map(function(k){return PROD[k].id})));
      fetch(A.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(r){
        if(r&&r.success&&r.data&&r.data.checkoutUrl){window.location.href=r.data.checkoutUrl;return}
        btn.disabled=false;btn.innerHTML=T('or.cta',{total:m(tot)});toast((r&&r.data&&r.data.msg)||T('or.err'),true);
      }).catch(function(){btn.disabled=false;btn.innerHTML=T('or.cta',{total:m(tot)});toast(T('or.err'),true)});
    };
  }
  /* v1.0.24: плаващата яхта (телефон, само на екрана с кутиите): картинка + „Томбола“; при докосване се разгъва с текста на лентата
     (yban.b / yban.s), „Повече за наградата ›“ отваря инфото за томболата, „Разбрах“ / ✕ / докосване извън нея я свива */
  function yfabClose(){yfab.querySelectorAll('.fabu.open').forEach(function(u){u.classList.remove('open');var b=u.querySelector('.yfabb');if(b)b.setAttribute('aria-expanded','false')})}
  function renderYfab(){
    var on=S.screen===1;yfab.style.display=on?'':'none';if(!on){yfab.querySelectorAll('.fabu.open').forEach(function(u){u.classList.remove('open')});return}
    /* v1.0.34: две плаващи плочки една над друга — яхтата (томбола) и козметичният сет; всяка се разгъва в картичка */
    var unit=function(rw,tagK,bK,sK,moreK){return '<div class="fabu" data-fab="'+rw+'"><button class="yfabb" type="button" aria-expanded="false">'+rimg(rw,rw==='tix'?'🛥️':'🌸','yfi')+'<span'+ck(tagK)+'>'+T(tagK)+'</span></button>'
      +'<div class="yfabc"><button class="yfabx" type="button" data-fx aria-label="затвори">✕</button>'+rimg(rw,rw==='tix'?'🛥️':'🌸','yfi')
      +'<b'+ck(bK)+'>'+T(bK)+'</b><small'+ck(sK)+'>'+T(sK)+'</small>'
      +'<div class="yfaba"><button class="lnk" type="button" data-rw="'+rw+'"'+ck(moreK)+'>'+T(moreK)+'</button><button class="cta" type="button" data-fx'+ck('yfab.ok')+'>'+T('yfab.ok')+'</button></div></div></div>'};
    var open=yfab.querySelector('.fabu.open');var openK=open?open.dataset.fab:null;
    yfab.innerHTML=unit('tix','yfab.tag','yfab.b','yfab.s','yfab.more')+unit('cosm1','cban.tag','cban.b','cban.s','cban.more');
    if(openK){var u=yfab.querySelector('.fabu[data-fab="'+openK+'"]');if(u)u.classList.add('open')}
    yfab.querySelectorAll('.yfabb').forEach(function(bt){bt.onclick=function(){var u=bt.closest('.fabu');yfab.querySelectorAll('.fabu.open').forEach(function(o){if(o!==u)o.classList.remove('open')});u.classList.add('open');bt.setAttribute('aria-expanded','true')}});
    yfab.querySelectorAll('[data-fx]').forEach(function(x){x.onclick=function(){yfabClose()}});
    yfab.querySelectorAll('[data-rw]').forEach(function(x){x.onclick=function(){yfabClose();rewardPopup(x.dataset.rw)}});
  }
  document.addEventListener('click',function(e){if(yfab.querySelector('.fabu.open')&&!yfab.contains(e.target))yfabClose()},true);
  function render(){renderS1();renderS2();renderS4();renderS5();renderTimer();renderYfab();var br=$('apHdr').querySelector('.brand');br.querySelector('b').innerHTML=T('head.brand');br.querySelector('span').innerHTML=T('head.title');$('apHow').innerHTML=T('head.how');var f=root.querySelector('.foot');if(f)f.innerHTML=T('foot.note')}

  /* ── runtime refresh (кеширана страница → цените се сверяват със сървъра) ── */
  function refreshRuntime(){
    if(!A.ajax||A.editor||A.draft||!A.refresh)return;
    fetch(A.ajax+'?action=ansa_promo_runtime&v='+encodeURIComponent(A.version||''),{credentials:'same-origin'}).then(function(r){return r.json()}).then(function(r){
      if(!r||!r.success||!r.data||!r.data.products)return;var d=r.data,changed=false;
      Object.keys(d.products).forEach(function(k){if(!PROD[k]||PROD[k].price!==d.products[k].price||PROD[k].stock!==d.products[k].stock)changed=true});
      if(Object.keys(PROD).length!==Object.keys(d.products).length)changed=true;
      if(changed){A.products=d.products;A.order=d.order||Object.keys(d.products);bind();S.slots=S.slots.map(function(k){return k&&PROD[k]?k:null});render()}
    }).catch(function(){});
  }

  /* ── редактор: хотспотове + живо копи + сценарии ── */
  function scenario(o){
    o=o||{};closeInfo();$('apOv2').classList.add('off');S._lockOv=false;S._fillPending=false;
    if(o.utm!==undefined){S.utm=o.utm&&PROD[o.utm]?o.utm:null;S.core=S.utm}
    if(o.box!==undefined){if(o.box===null||BI[o.box]==null){S.box=null;S.slots=[]}else{var b=boxOf(o.box);S.box=BI[o.box];var kept=S.slots.filter(Boolean);if(!kept.length&&S.core)kept=[S.core];S.slots=kept.slice(0,b.packs);while(S.slots.length<b.packs)S.slots.push(null)}}
    if(o.full&&S.box!==null){var fillK=S.core||ORDER[0];S.slots=S.slots.map(function(k){return k||fillK})}
    S.emailDone=true;A.editorNoGate=true;
    var sc=o.screen||'boxes';
    if(sc==='gate'){S.screen=1;go(1);A.editorNoGate=false;S.emailDone=false;emailGate();return}
    if(S.box===null&&sc!=='boxes'){S.box=BI.l;var bl=boxOf('l');S.slots=(S.core?[S.core]:[]).slice(0,bl.packs);while(S.slots.length<bl.packs)S.slots.push(null);if(o.full!==false)S.slots=S.slots.map(function(k){return k||S.core||ORDER[0]})}
    if(sc==='boxes'){go(1)}
    else if(sc==='fill'){go(1);S._fillPending=false;fillModePopup()}
    else if(sc==='celeb'){go(1);celebrate()}
    else if(sc==='builder'){go(2)}
    else if(sc==='picker'){go(2);S.replaceSlot=null;pickerPopup()}
    else if(sc==='sure'){go(2);sureContinue()}
    else if(sc==='switch'){go(2);switchPopup()}
    else if(sc==='secret'){go(4)}
    else if(sc==='order'){go(5)}
    else if(sc==='product'){go(1);productPopup(S.core||ORDER[0],function(){})}
    else if(sc==='how'){go(1);howPopup()}
  }
  window.AnsaPromo={scenario:scenario,state:function(){return S},render:render,T:T};
  if(A.editor){
    [root,portal].forEach(function(r){r.addEventListener('click',function(e){var t=e.target.closest('[data-ck]');if(!t||!t.dataset.ck)return;e.preventDefault();e.stopPropagation();try{parent.postMessage({ansaPromo:'ck',key:t.dataset.ck},'*')}catch(x){}},true)});
    window.addEventListener('message',function(e){var d=e.data;if(!d||!d.ansaPromo)return;
      if(d.ansaPromo==='copy'){if(d.key){A.copy[d.key]=d.value}else{A.copy=Object.assign({},A.copy,d.copy||{})}render();if(!$('apOv').classList.contains('off')&&S._last)S._last()}
      else if(d.ansaPromo==='cfg'&&d.runtime){Object.assign(A,d.runtime);bind();render()}
      else if(d.ansaPromo==='scenario'){scenario(d)}
      else if(d.ansaPromo==='goto'){scenario({screen:d.screen})}
    });
  }
  window.addEventListener('resize',function(){if(S.screen===2){$('apSticky').style.display=window.innerWidth<1000?'block':'none'}render()});

  /* ── старт ── */
  if(!A.live&&!A.draft){go(1);return}
  var restored=restoreS();
  if(A.editor){S.emailDone=true;A.editorNoGate=true;go(1)}
  else{go(S.screen===2&&restored?2:1);if(restored&&full()){toast(T('ms.restored'),false)}else if(restored&&!full()){S._fillPending=false;fillModePopup()}}
  refreshRuntime();
})();
