/* ansa™ Промо — вариант B „потокът без кутии“ (1.34.0; мокъпът: docs/ansa-desktop-mockup.html).
 * Клиентът не избира кутия: добавя опаковки с „+“, а отстъпката, козметичният сет, книгата и билетите се отключват с броя
 * (кутията е по броя — Config::flow_box на сървъра: 1–2 → малка, 3–4 → средна, 5+ → голяма; 2 и 4 са възможни; книгата и сетът
 * само в най-голямата; 1 опаковка = 1 билет). Данни: window.AnsaPromoRuntime (същият като на promo.js). Нищо от цените не се
 * решава тук за реалната поръчка — ansa_promo_add (с variant=flow) преизчислява на сървъра. Текстовете: T(key) от copy-регистъра
 * (група „flow“, ключове fl.*). Insights: ansa_promo_beacon с variant=flow — view (runtime) → box (при ново ниво) → item → full
 * (стигнала най-голямата кутия) → order (цъкнала „Продължи към плащане“) → add (сървърът прие). */
(function(){
  'use strict';
  var A=window.AnsaPromoRuntime;if(!A)return;
  var root=document.getElementById('flApp');if(!root)return;
  document.documentElement.classList.add('ansa-flow-html');document.body.classList.add('ansa-promo-page','ansa-flow-page');

  /* ── помощници ── */
  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})}
  function tpl(str,v){return String(str==null?'':str).replace(/\{\{\s*([a-z0-9_]+)\s*\}\}/gi,function(x,k){return v&&Object.prototype.hasOwnProperty.call(v,k)?String(v[k]):x})}
  function fmt(n){return String(Number(n).toFixed(2)).replace(/\.?0+$/,'').replace('.',',')}
  function money(c){return '€ '+(c/100).toFixed(2).replace('.',',')}
  function lv(c){if(!A.bgn||!A.bgn.show)return '';return '<span class="bgn">('+(c/100*Number(A.bgn.rate||1.95583)).toFixed(2).replace('.',',')+' лв.)</span>'}
  function cents(x){return Math.round(Number(x)*100)}
  function opk(n){return n===1?'опаковка':'опаковки'}
  function bileta(n){return n===1?'билет':'билета'}
  function produkt(n){return n===1?'продукт':'продукта'}
  function pad(n){return String(n).length<2?'0'+n:String(n)}
  var $=function(id){return document.getElementById(id)};
  function icon(id,cls){return '<svg aria-hidden="true"'+(cls?' class="'+cls+'"':'')+'><use href="#fi-'+id+'"/></svg>'}
  function MOB(){return window.innerWidth<900}

  /* ── данни ── */
  var PROD,ORDER,BOXES,BIG,SMALL,COPY,MAXP,COSM_VAL,YACHT;
  function bind(){
    PROD=A.products||{};ORDER=(A.order||Object.keys(PROD)).filter(function(k){return PROD[k]});
    BOXES=(A.boxes||[]).slice().filter(function(b){return b&&b.packs>0}).sort(function(a,b){return a.packs-b.packs});
    BIG=BOXES[BOXES.length-1]||null;SMALL=BOXES[0]||null;COPY=A.copy||{};MAXP=Math.max(BIG?BIG.packs:1,Number(A.max_packs)||20);
    COSM_VAL=Number(A.rewards&&A.rewards.cosm&&A.rewards.cosm.value)||0;YACHT=Number(A.rewards&&A.rewards.yacht&&A.rewards.yacht.value)||0;
  }
  bind();
  if(!BIG||!ORDER.length){root.innerHTML='';root.removeAttribute('aria-busy');return}
  function byId(id){for(var i=0;i<BOXES.length;i++)if(BOXES[i].id===id)return BOXES[i];return null}
  function boxFor(n){var b=null;for(var i=0;i<BOXES.length;i++)if(BOXES[i].packs<=n)b=BOXES[i];return b}
  function nextBox(n){for(var i=0;i<BOXES.length;i++)if(BOXES[i].packs>n)return BOXES[i];return null}
  function isBig(b){return !!b&&b.id===BIG.id}
  function hasShip(b){return !!b&&(b.rw||[]).indexOf('ship')>-1}
  function cosmOn(){return BIG.cosm_pay!==null&&BIG.cosm_pay!==undefined}
  function pctOf(p,b){var v=p.pct&&p.pct[b.id];v=Number(v);return v>0?v:Number(b.pct)||0}
  function unit(p,pct){return Math.round(cents(p.price)*(100-pct)/100)}
  function ticketsFor(b,n){return b?Number(b.tickets)+Math.max(0,n-b.packs):0}
  /* 1.34.0: броячът „още N дни“ — от deadline_ts (сървърът), часовникът на телефона влияе само на минутите */
  function remaining(){var ms=Math.max(0,(Number(A.deadline_ts)||0)*1000-Date.now());return {d:Math.floor(ms/864e5),h:Math.floor(ms%864e5/36e5),m:Math.floor(ms%36e5/6e4),over:ms<=0}}
  function left(){var r=remaining();if(!A.deadline_ts)return '';if(r.over)return 'приключи';return r.d>0?r.d+' '+(r.d===1?'ден':'дни'):r.h+' ч '+r.m+' мин'}
  function V(x){
    var v={max_pct:fmt(BIG.pct),pct_s:fmt(SMALL.pct),pct_l:fmt(BIG.pct),packs_s:SMALL.packs,packs_l:BIG.packs,tickets_s:SMALL.tickets,tickets_l:BIG.tickets,
      cosm_pay_l:cosmOn()?fmt(BIG.cosm_pay):'',cosm_value:fmt(COSM_VAL),yacht:String(Math.round(YACHT)).replace(/\B(?=(\d{3})+(?!\d))/g,' '),
      deadline:A.deadline||'',draw:A.draw||'',tombola_url:A.tombola_url||'',left:left(),max:MAXP,cosm_limit:String((A.rewards&&A.rewards.cosm&&A.rewards.cosm.limit)||10000).replace(/\B(?=(\d{3})+(?!\d))/g,' ')};
    var m=byId('m');if(m){v.pct_m=fmt(m.pct);v.packs_m=m.packs;v.tickets_m=m.tickets}
    if(x)for(var k in x)v[k]=x[k];return v;
  }
  function T(k,x){var s=COPY[k];if(s==null||s==='')s=k;return tpl(s,V(x))}
  function TT(k,x){return esc(T(k,x))} /* само текст */

  /* ── състояние ── */
  var S={qty:{},cosm:true,pay:'card',utm:''},modal=null,toastTimer=null,lastFocus=null,filter='all',sending=false;
  try{var qs=new URLSearchParams(location.search),u=qs.get(A.utm_param||'utm_content');if(u){u=String(u).toLowerCase().replace(/[^a-z0-9_\-]/g,'');if(PROD[u])S.utm=u;else{var nn=u.replace(/[^a-z0-9]/g,'');for(var pk in PROD){if(String(PROD[pk].name||'').toLowerCase().replace(/[^a-z0-9]/g,'')===nn){S.utm=pk;break}}}}}catch(e){}
  var SKEY='ansa_flow_s';
  function saveS(){try{sessionStorage.setItem(SKEY,JSON.stringify({id:A.id,qty:S.qty,cosm:S.cosm,pay:S.pay}))}catch(e){}}
  function restoreS(){try{var d=JSON.parse(sessionStorage.getItem(SKEY)||'null');if(!d||d.id!==A.id)return;var q={};for(var k in d.qty||{}){if(PROD[k]&&d.qty[k]>0)q[k]=Math.min(99,d.qty[k]|0)}S.qty=q;S.cosm=d.cosm!==false;S.pay=d.pay==='cod'?'cod':'card'}catch(e){}}
  restoreS();
  function itemCount(){var n=0;for(var k in S.qty)n+=S.qty[k];return n}
  function totals(){
    var n=itemCount(),b=boxFor(n),disp=b||SMALL,orig=0,tot=0;
    for(var k in S.qty){var p=PROD[k];if(!p)continue;orig+=cents(p.price)*S.qty[k];tot+=unit(p,pctOf(p,disp))*S.qty[k]}
    return {count:n,box:b,disp:disp,pct:b?Number(b.pct):0,original:orig,total:tot,saving:orig-tot,gift:!!b&&isBig(b)&&cosmOn(),book:!!b&&isBig(b),ship:hasShip(b),tickets:ticketsFor(b,n),max:!!b&&isBig(b)};
  }

  /* ── Insights ── */
  function sid(){try{var v=sessionStorage.getItem('ansa_promo_sid');if(!v){v='s'+Date.now().toString(36)+Math.random().toString(36).slice(2,10);sessionStorage.setItem('ansa_promo_sid',v)}return v}catch(e){return S._sid||(S._sid='s'+Date.now().toString(36)+Math.random().toString(36).slice(2,10))}}
  function vid(){try{var o=JSON.parse(localStorage.getItem('ansa_promo_vid')||'null'),now=Date.now();if(!o||!o.v||!o.t||now-o.t>30*864e5){o={v:'v'+now.toString(36)+Math.random().toString(36).slice(2,12),t:now};localStorage.setItem('ansa_promo_vid',JSON.stringify(o))}return o.v}catch(e){return ''}}
  function items(){var o=[];for(var k in S.qty)for(var i=0;i<S.qty[k];i++)o.push(k);return o}
  function beacon(step,k){
    if(!A.ajax||A.editor||A.draft)return;var t=totals();var key='ansa_flow_bc_'+A.id+'_'+step+(k?'_'+k:'')+(step==='box'?'_'+(t.box?t.box.id:''):'');
    try{if(sessionStorage.getItem(key))return;sessionStorage.setItem(key,'1')}catch(e){}
    var fd=new FormData();fd.append('action','ansa_promo_beacon');fd.append('nonce',A.nonce||'');fd.append('ev',step);fd.append('step',step);fd.append('sid',sid());fd.append('vid',vid());fd.append('variant','flow');
    fd.append('box',t.box?t.box.id:'');fd.append('utm',S.utm||'');fd.append('n',t.count);if(k)fd.append('k',k);
    if(step==='full'||step==='order'||step==='add')fd.append('items',JSON.stringify(items()));
    if(step==='view'){try{var q=new URLSearchParams(location.search);fd.append('src',q.get('utm_source')||'');fd.append('med',q.get('utm_medium')||'');fd.append('camp',q.get('utm_campaign')||'');fd.append('ref',document.referrer||'');fd.append('dev',MOB()?'mobile':'desktop')}catch(e){}}
    try{if(navigator.sendBeacon&&step==='add'){navigator.sendBeacon(A.ajax,fd);return}}catch(e){}
    fetch(A.ajax,{method:'POST',body:fd,credentials:'same-origin',keepalive:true}).catch(function(){});
  }

  /* ── скелет ── */
  function cats(){var o=[],seen={};ORDER.forEach(function(k){var c=String(PROD[k].cat||'').trim();if(c&&!seen[c]){seen[c]=1;o.push(c)}});return o}
  function shell(){
    var bars=['fl.bar.1','fl.bar.2','fl.bar.3'].map(function(k){return T(k)}).filter(function(s){return s&&!/^fl\./.test(s)});
    var cs=cats();
    root.innerHTML=
      '<svg aria-hidden="true" style="position:absolute;width:0;height:0;overflow:hidden"><defs>'
      +'<symbol id="fi-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol><symbol id="fi-minus" viewBox="0 0 24 24"><path d="M5 12h14"/></symbol>'
      +'<symbol id="fi-arrow" viewBox="0 0 24 24"><path d="M5 12h14m-5-5 5 5-5 5"/></symbol><symbol id="fi-chevron" viewBox="0 0 24 24"><path d="m9 5 7 7-7 7"/></symbol>'
      +'<symbol id="fi-bag" viewBox="0 0 24 24"><path d="M5 7h14l1 14H4L5 7Z"/><path d="M8 8V6a4 4 0 0 1 8 0v2"/></symbol>'
      +'<symbol id="fi-gift" viewBox="0 0 24 24"><path d="M3 8h18v4H3zM5 12v9h14v-9M12 8v13"/><path d="M12 8C7 8 5 7 5 4.5 5 1 11 2 12 8Zm0 0c5 0 7-1 7-3.5C19 1 13 2 12 8Z"/></symbol>'
      +'<symbol id="fi-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></symbol><symbol id="fi-close" viewBox="0 0 24 24"><path d="m6 6 12 12M6 18 18 6"/></symbol>'
      +'<symbol id="fi-book" viewBox="0 0 24 24"><path d="M4 3h16v18H6a2 2 0 0 1-2-2V3Zm0 14h16M8 7h8M8 10h6"/></symbol>'
      +'<symbol id="fi-pause" viewBox="0 0 24 24"><path d="M8 5v14M16 5v14" stroke-width="3"/></symbol><symbol id="fi-play" viewBox="0 0 24 24"><path d="m8 4 12 8-12 8V4Z"/></symbol>'
      +'<symbol id="fi-lock" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></symbol>'
      +'<symbol id="fi-ticket" viewBox="0 0 24 24"><path d="M3 9V6h18v3a2 2 0 0 0 0 4v3H3v-3a2 2 0 0 0 0-4Z"/><path d="M14 6v10" stroke-dasharray="2 2"/></symbol>'
      +'<symbol id="fi-anchor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="2.5"/><path d="M12 7.5V21M5 12H3c0 5 4 9 9 9s9-4 9-9h-2M8 12h8"/></symbol></defs></svg>'
      +'<header class="promo-bar"><div class="promo-text" id="flPromoText">'+(bars[0]||'')+'</div><button class="promo-pause" id="flPause" type="button" aria-label="Спри редуването на банера">'+icon('pause')+'</button></header>'
      +'<nav class="app-nav" aria-label="Основна навигация"><div class="wordmark" aria-label="ANSA">ansa<sup>™</sup></div><div class="nav-right"><button class="help-button" type="button" data-open="rules">'+TT('fl.nav.how')+'</button><button class="bag-button" type="button" data-open="cart" aria-label="Отвори количката">'+icon('bag')+'<span class="bag-count" id="flBagCount">0</span></button></div></nav>'
      +'<section class="desk-hero" aria-label="'+TT('fl.hero.badge')+'"><div class="hero-copy"><span class="bf-badge">'+TT('fl.hero.badge')+'</span><span class="eyebrow">'+TT('fl.hero.kick')+'</span><h1>'+T('fl.hero.h')+'</h1><p>'+T('fl.hero.p')+'</p>'
      +'<div class="countdown" aria-label="Оставащо време до края на сезона"><div><strong id="flCdD">0</strong><span>дни</span></div><div><strong id="flCdH">00</strong><span>часа</span></div><div><strong id="flCdM">00</strong><span>мин</span></div><p>'+T('fl.hero.cd')+'</p></div></div>'
      +'<ul class="hero-perks"><li><span class="perk-ic">%</span><strong>'+T('fl.hero.r1.b')+'</strong><small>'+T('fl.hero.r1.s')+'</small></li>'
      +(cosmOn()?'<li><span class="perk-ic">'+icon('gift')+'</span><strong>'+T('fl.hero.r2.b')+'</strong><small>'+T('fl.hero.r2.s')+'</small></li>':'')
      +'<li><span class="perk-ic">'+icon('ticket')+'</span><strong>'+T('fl.hero.r3.b')+'</strong><small>'+T('fl.hero.r3.s')+'</small></li></ul></section>'
      +'<section class="catalog-head" aria-labelledby="flCatalogTitle"><div class="catalog-title-row"><h2 id="flCatalogTitle">'+TT('fl.head.h')+'</h2><div style="display:flex;gap:8px;align-items:center"><span class="count-label" id="flVisibleCount"></span><button class="catalog-help" type="button" data-open="rules" aria-label="'+TT('fl.nav.how')+'">?</button></div></div>'
      +'<p class="season-line"><b>'+TT('fl.head.badge')+'</b><span id="flHeadLine">'+T('fl.head.line')+'</span></p>'
      +(cs.length>1?'<div class="filters" aria-label="Филтрирай продуктите"><button class="filter" type="button" data-filter="all" aria-pressed="true">'+TT('fl.filter.all')+'</button>'+cs.map(function(c){return '<button class="filter" type="button" data-filter="'+esc(c)+'" aria-pressed="false">'+esc(c)+'</button>'}).join('')+'</div>':'<div class="filters" style="padding:6px 0 4px"></div>')+'</section>'
      +'<main class="catalog" id="flCatalog" tabindex="-1" aria-label="Списък с продукти"><div class="product-list" id="flProductList"></div><div class="more-products">Твоят избор. Твоята ежедневна грижа.</div><p class="legal-small">Хранителните добавки не заместват разнообразното хранене. Прочети информацията за продукта преди употреба.</p></main>'
      +'<section class="reward-dock" id="flDock" aria-label="Твоята отстъпка и поръчка">'
      +'<div class="dock-eyebrow"><span>'+TT('fl.dock.eyebrow')+'</span><em><b>'+TT('fl.dock.badge')+'</b><span id="flDockLeft"></span></em></div>'
      +'<div class="dock-top"><div class="discount-block"><strong class="discount-value" id="flDiscountValue"></strong><span class="discount-label" id="flDiscountLabel"></span></div><button class="basket-link" id="flBasketLink" type="button" data-open="cart"><span id="flSelectedCount"></span>'+icon('chevron')+'</button><button class="save-block" id="flSaveBlock" type="button" data-open="cart" hidden aria-label="Виж количката"><strong id="flSaveValue"></strong><span>'+TT('fl.dock.saving')+'</span></button></div>'
      +'<div class="tier-slider" role="img" id="flSlider" aria-label="'+esc(BOXES.map(function(b,i){var nx=BOXES[i+1];return (nx?b.packs+'–'+(nx.packs-1):b.packs+'+')+' опаковки: минус '+fmt(b.pct)+' процента'}).join('; '))+'">'
      +'<div class="slider-track"><div class="slider-fill" id="flFill"></div>'+BOXES.map(function(b,i){return '<i class="slider-dot'+(isBig(b)?' last':'')+'" data-box="'+b.id+'" style="--x:'+dotX(i)+'%"></i>'}).join('')+'<span class="slider-knob" id="flKnob" aria-hidden="true">0</span></div>'
      +'<div class="slider-labels">'+BOXES.map(function(b,i){var nx=BOXES[i+1];return '<div class="slider-label" data-box="'+b.id+'" style="--x:'+dotX(i)+'%"><strong>−'+fmt(b.pct)+'%</strong><span>'+TT(nx?'fl.tier.n':'fl.tier.last',{a:b.packs,b:nx?nx.packs-1:''})+'</span></div>'}).join('')+'</div></div>'
      +'<div class="next-unlock" id="flNext">'+icon('arrow')+'<span id="flNextText"></span></div>'
      +'<div class="perks">'
      +(cosmOn()?'<button class="gift-preview" id="flGift" type="button" data-open="gifts"><span class="gift-icon" id="flGiftIcon">'+icon('lock')+'</span><span class="gift-text"><strong id="flGiftTitle"></strong><small><span class="pack-dots" id="flDots" aria-hidden="true">'+Array(BIG.packs+1).join('<i></i>')+'</span><span id="flGiftSub"></span></small></span><span class="gift-badge" id="flGiftBadge"></span>'+icon('chevron','gift-chevron')+'</button>':'')
      +'<button class="tombola-row" id="flTix" type="button" data-open="tombola"><span class="gift-icon">'+icon('ticket')+'</span><span class="gift-text"><strong id="flTixTitle"></strong><small id="flTixSub"></small></span><span class="tombola-count" id="flTixCount" hidden>0</span>'+icon('chevron','gift-chevron')+'</button></div>'
      +'<button class="checkout-button" id="flCheckout" type="button" data-open="cart" disabled><span class="checkout-main"><span id="flCheckoutLabel"></span><span class="checkout-sep" id="flCheckoutSep" hidden></span><span class="checkout-total" id="flCheckoutTotal" hidden></span></span>'+icon('arrow')+'</button>'
      +'<p class="dock-foot" id="flFoot"></p></section>'
      +'<div class="toast" id="flToast" aria-hidden="true"></div><div class="sr-only" id="flStatus" aria-live="polite" aria-atomic="true"></div>'
      +'<div class="drawer-layer" id="flLayer" hidden><section class="drawer" id="flDrawer" role="dialog" aria-modal="true" aria-labelledby="flDrawerTitle" tabindex="-1"><div class="drawer-top"><div class="drawer-handle"></div><div class="drawer-title-row"><h2 id="flDrawerTitle"></h2><button class="close-drawer" id="flClose" type="button" aria-label="Затвори">'+icon('close')+'</button></div></div><div class="drawer-content" id="flDrawerContent"></div></section></div>';
    root.removeAttribute('aria-busy');
    /* ротация на банера */
    var idx=0,paused=(window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches)||bars.length<2;
    function pauseIcon(){var b=$('flPause');if(!b)return;b.innerHTML=icon(paused?'play':'pause');b.setAttribute('aria-label',paused?'Пусни редуването на банера':'Спри редуването на банера');if(bars.length<2)b.style.display='none'}
    $('flPause').onclick=function(){paused=!paused;pauseIcon()};pauseIcon();
    setInterval(function(){if(paused||document.hidden||modal)return;var el=$('flPromoText');if(!el)return;el.classList.add('switching');setTimeout(function(){idx=(idx+1)%bars.length;el.innerHTML=bars[idx];el.classList.remove('switching')},200)},5200);
  }
  function dotX(i){var n=BOXES.length;return n<2?90:Math.round(10+80*i/(n-1))}
  /* позицията на плъзгача: 0 → 0%; точките на кутиите са на dotX; между тях линейно; над най-голямата — до 100% при max_packs */
  function pos(n){if(n<=0)return 0;if(n<SMALL.packs)return dotX(0)*n/SMALL.packs;for(var i=0;i<BOXES.length;i++){var b=BOXES[i],nx=BOXES[i+1];if(!nx||n<nx.packs){if(!nx){var span=Math.max(1,MAXP-b.packs);return Math.min(100,dotX(i)+(100-dotX(i))*Math.min(1,(n-b.packs)/span))}return dotX(i)+(dotX(i+1)-dotX(i))*(n-b.packs)/(nx.packs-b.packs)}}return 100}

  /* ── продукти ── */
  function tagText(){var n=itemCount(),b=boxFor(n);return b?T('fl.tag.now',{pct:fmt(b.pct)}):T('fl.tag.max')}
  function priceBlock(p){var t=totals(),pct=pctOf(p,t.disp),u=unit(p,pct),low=unit(p,pctOf(p,BIG));
    return '<div><div class="price"><strong>'+money(u)+'</strong><s>'+money(cents(p.price))+'</s></div><div class="price-note">'+TT('fl.price.note',{pct:fmt(pct),save:money(cents(p.price)-u)})+'</div>'+(t.max?'':'<div class="lowest-price">'+TT('fl.price.lowest',{lowest:money(low)})+'</div>')+'</div>'}
  function stepper(p){var n=S.qty[p.key]||0;return '<div class="quantity">'+(n?'<button type="button" data-change="-1" data-id="'+esc(p.key)+'" aria-label="Премахни една опаковка '+esc(p.name)+'">'+icon('minus')+'</button><output aria-label="Брой '+esc(p.name)+'">'+n+'</output><button type="button" data-change="1" data-id="'+esc(p.key)+'" aria-label="Добави още една опаковка '+esc(p.name)+'">'+icon('plus')+'</button>':'<button type="button" class="add-only" data-change="1" data-id="'+esc(p.key)+'" aria-label="'+TT('fl.add')+' '+esc(p.name)+'">'+icon('plus')+TT('fl.add')+'</button>')+'</div>'}
  function picture(p,cls){return p.img?'<img class="'+cls+'" src="'+esc(p.img)+'" alt="'+esc(p.name)+' — опаковка" loading="lazy">':'<span class="'+cls+' ph" aria-hidden="true">'+esc(p.ph||'🌿')+'</span>'}
  function renderProducts(){
    var list=ORDER.filter(function(k){return filter==='all'||String(PROD[k].cat||'').trim()===filter}),t=totals();
    $('flVisibleCount').textContent=T('fl.head.n',{n:list.length,produkt:produkt(list.length)});
    $('flProductList').innerHTML=list.map(function(k){var p=PROD[k];var n=S.qty[k]||0;
      return '<article class="product'+(n>0?' is-selected':'')+(p.stock===false?' is-out':'')+'" data-product="'+esc(k)+'"><button class="product-image-button" type="button" data-detail="'+esc(k)+'" aria-label="Виж '+esc(p.name)+'"><span class="pack-picture">'+picture(p,'product-image')+'<span class="mini-tag'+(t.max?' max':'')+'">'+esc(tagText())+'</span></span>'+(p.pack?'<span class="pack-label">'+esc(p.pack)+'</span>':'')+'</button><div>'+(p.cat?'<div class="product-kind">'+esc(p.cat)+'</div>':'')+'<button class="product-title" type="button" data-detail="'+esc(k)+'">'+esc(p.name)+'</button><p class="product-desc">'+esc(p.ds||'')+'</p><div class="purchase-row">'+priceBlock(p)+stepper(p)+'</div></div></article>'}).join('');
  }
  function updateProducts(){var t=totals();ORDER.forEach(function(k){var card=root.querySelector('[data-product="'+k+'"]');if(!card)return;var p=PROD[k];card.classList.toggle('is-selected',!!S.qty[k]);var tag=card.querySelector('.mini-tag');if(tag){tag.textContent=tagText();tag.classList.toggle('max',t.max)}card.querySelector('.purchase-row').innerHTML=priceBlock(p)+stepper(p)})}

  /* ── панелът ── */
  function nextMsg(t){
    if(!t.count)return T('fl.next.0');
    var nx=nextBox(t.count);if(!nx)return T('fl.next.max');
    var need=nx.packs-t.count;return T(isBig(nx)?'fl.next.last':'fl.next.more',{need:need,opk_need:opk(need),pct_next:fmt(nx.pct)});
  }
  function renderDock(){
    var t=totals(),n=t.count;
    $('flBagCount').textContent=n;$('flSelectedCount').textContent=T('fl.dock.sel',{n:n});$('flDockLeft').textContent=T('fl.dock.left');
    $('flDiscountValue').textContent='−'+fmt(t.disp.pct)+'%';$('flDiscountLabel').textContent=n?T('fl.dock.yours'):T('fl.dock.first');
    $('flBasketLink').hidden=n>0;$('flSaveBlock').hidden=!n;$('flSaveValue').textContent='−'+money(t.saving);
    var pp=pos(n)+'%';$('flFill').style.setProperty('--p',pp);$('flKnob').style.setProperty('--p',pp);$('flKnob').textContent=n;$('flFill').classList.toggle('max',t.max);
    BOXES.forEach(function(b){var dot=root.querySelector('.slider-dot[data-box="'+b.id+'"]'),lab=root.querySelector('.slider-label[data-box="'+b.id+'"]');if(dot)dot.classList.toggle('on',n>=b.packs);if(lab){lab.classList.toggle('is-active',!!t.box&&t.box.id===b.id);lab.classList.toggle('is-passed',!!t.box&&t.box.packs>b.packs)}});
    var msg=nextMsg(t);$('flNextText').textContent=msg;$('flNext').classList.toggle('success',t.max);$('flNext').querySelector('use').setAttribute('href',t.max?'#fi-check':'#fi-arrow');
    if(cosmOn()){var need=Math.max(0,BIG.packs-n);$('flGift').classList.toggle('unlocked',t.gift);$('flGiftIcon').innerHTML=icon(t.gift?'gift':'lock');
      $('flGiftBadge').innerHTML=t.gift?icon('check')+'<span>'+TT('fl.gift.on')+'</span>':icon('lock')+'<span>'+TT('fl.gift.lock')+'</span>';
      var dots=root.querySelectorAll('#flDots i');for(var i=0;i<dots.length;i++)dots[i].classList.toggle('on',i<Math.min(BIG.packs,n));
      $('flGiftTitle').innerHTML=t.gift&&S.cosm?T('fl.gift.saved'):T('fl.gift.t');
      $('flGiftSub').textContent=t.gift?(S.cosm?T('fl.gift.sres'):T('fl.gift.son')):(n?T('fl.gift.sn',{need:need,opk_need:opk(need)}):T('fl.gift.s0'));
      $('flGift').setAttribute('aria-label',t.gift?'Козметичен сет: отключен':'Козметичен сет: заключен, отключва се при '+BIG.packs+' опаковки');}
    $('flTix').classList.toggle('has-tickets',n>0);$('flTixCount').hidden=!n;$('flTixCount').textContent=t.tickets;
    $('flTixTitle').textContent=n?T('fl.tix.n',{tickets:t.tickets,bileta:bileta(t.tickets)}):T('fl.tix.0');$('flTixSub').textContent=T('fl.tix.s');
    $('flCheckout').disabled=!n;$('flCheckoutLabel').textContent=n?T('fl.cta'):T('fl.cta.0');$('flCheckoutTotal').innerHTML='<s>'+money(t.original)+'</s>'+money(t.total);$('flCheckoutTotal').hidden=!n;$('flCheckoutSep').hidden=!n;
    $('flFoot').innerHTML=n?T('fl.foot',{total_reg:money(t.original)})+(t.ship?T('fl.foot.ship'):''):T('fl.foot.0');
    return msg;
  }
  function tick(){var r=remaining(),t=left();var hl=$('flHeadLine');if(hl)hl.innerHTML=T('fl.head.line');var dl=$('flDockLeft');if(dl)dl.textContent=T('fl.dock.left');if($('flCdD')){$('flCdD').textContent=r.d;$('flCdH').textContent=pad(r.h);$('flCdM').textContent=pad(r.m)}if(!t){var cd=root.querySelector('.countdown');if(cd)cd.style.display='none';if(hl)hl.parentNode.style.display='none';if(dl)dl.parentNode.style.display='none'}}

  /* ── съобщения ── */
  function toast(text){var el=$('flToast');el.textContent=text;el.classList.add('show');clearTimeout(toastTimer);toastTimer=setTimeout(function(){el.classList.remove('show')},2900)}
  function announce(text){$('flStatus').textContent=text}
  function sparkle(){if(window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches)return;var colors=['#ef6b4d','#f5be6b','#719f7d','#eaa3af'];for(var i=0;i<18;i++){var e=document.createElement('i');e.className='spark';e.style.left=(15+Math.random()*70)+'%';e.style.background=colors[i%4];e.style.setProperty('--dx',(Math.random()-.5)*150+'px');e.style.setProperty('--rot',(Math.random()-.5)*800+'deg');e.style.animationDelay=(Math.random()*.2)+'s';root.appendChild(e);setTimeout(function(x){return function(){x.remove()}}(e),1500)}}

  /* ── промяна на броя ── */
  function change(key,delta){
    var p=PROD[key];if(!p)return;var before=totals();var cur=S.qty[key]||0;
    if(delta>0&&before.count>=MAXP){toast(T('fl.toast.max_packs'));return}
    var nv=Math.max(0,Math.min(99,cur+delta));if(nv)S.qty[key]=nv;else delete S.qty[key];
    var t=totals();if(!t.gift)S.cosm=true;saveS();
    var active=document.activeElement,activeChange=active&&active.dataset?active.dataset.change:null,inModal=!!(active&&active.closest&&active.closest('#flDrawer'));
    updateProducts();var next=renderDock();
    if(modal==='cart')renderCart();else if(modal&&modal.indexOf('product:')===0)renderDetail(modal.split(':')[1]);
    if(activeChange){var scope=inModal?$('flDrawerContent'):root.querySelector('[data-product="'+key+'"]');var tg=scope&&(scope.querySelector('[data-id="'+key+'"][data-change="'+activeChange+'"]')||scope.querySelector('[data-id="'+key+'"]'));if(tg)try{tg.focus({preventScroll:true})}catch(e){tg.focus()}}
    if(delta>0){beacon('item',key)}
    if(t.box&&(!before.box||t.box.id!==before.box.id)&&t.count>before.count){beacon('box');if(t.max)beacon('full')}
    if(t.pct>before.pct&&before.count>0){toast(t.max?T('fl.toast.max',{pct:fmt(t.pct)}):T('fl.toast.up',{pct:fmt(t.pct)}));$('flDock').classList.add('unlock-flash');setTimeout(function(){$('flDock').classList.remove('unlock-flash')},800);if(t.max)sparkle()}
    else if(t.pct<before.pct){toast(t.count?T('fl.toast.down',{n:t.count,opk_n:opk(t.count),pct:fmt(t.pct)}):T('fl.toast.empty'))}
    announce(t.count+' избрани опаковки. '+fmt(t.pct)+'% отстъпка. Сума '+money(t.total)+'. '+t.tickets+' '+bileta(t.tickets)+' за томболата. '+next);
  }

  /* ── драуъри ── */
  function openDrawer(kind){
    lastFocus=document.activeElement;modal=kind;document.body.classList.add('modal-open');$('flLayer').hidden=false;
    for(var i=0;i<root.children.length;i++){var el=root.children[i];if(el.id!=='flLayer'&&!el.classList.contains('sr-only')&&!el.classList.contains('toast'))el.inert=true}
    if(kind==='cart')renderCart();else if(kind==='gifts')renderGifts();else if(kind==='rules')renderRules();else if(kind==='tombola')renderTombola();else if(kind.indexOf('product:')===0)renderDetail(kind.split(':')[1]);
    $('flDrawerContent').scrollTop=0;try{$('flClose').focus({preventScroll:true})}catch(e){}
  }
  function closeDrawer(){modal=null;document.body.classList.remove('modal-open');$('flLayer').hidden=true;for(var i=0;i<root.children.length;i++)root.children[i].inert=false;if(lastFocus&&lastFocus.isConnected){try{lastFocus.focus({preventScroll:true})}catch(e){}}else{try{$('flCatalog').focus({preventScroll:true})}catch(e){}}}
  function cta(k,attrs){return '<button class="modal-cta" type="button" '+(attrs||'data-close')+'>'+T(k)+'</button>'}
  function renderDetail(key){var p=PROD[key];if(!p)return;$('flDrawerTitle').textContent=T('fl.d.prod.h');
    $('flDrawerContent').innerHTML=picture(p,'detail-image')+(p.cat?'<div class="detail-meta">'+esc(p.cat)+'</div>':'')+'<h3 class="detail-name">'+esc(p.name)+'</h3>'+(p.ds?'<p><b>'+esc(p.ds)+'</b></p>':'')+(p.desc?'<p>'+p.desc+'</p>':'')+(p.ing?'<p><b>Съставки:</b> '+esc(p.ing)+'</p>':'')+(p.who?'<p><b>За кого:</b> '+esc(p.who)+'</p>':'')
      +'<div class="facts">'+(p.pack?'<div class="fact"><strong>'+esc(p.pack)+'</strong>'+TT('fl.d.prod.pack')+'</div>':'')+'<div class="fact"><strong>'+TT('fl.d.prod.mix.b')+'</strong>'+TT('fl.d.prod.mix.s')+'</div></div><p class="modal-tier-note">'+TT('fl.d.prod.note')+'</p><div class="detail-purchase">'+priceBlock(p)+stepper(p)+'</div>'}
  function giftCheck(){var t=totals();return '<label class="gift-option'+(t.gift?'':' is-disabled')+'"><input type="checkbox" id="flGiftChoice"'+(S.cosm?' checked':'')+(t.gift?'':' disabled')+'><span>'+TT('fl.d.gift.check')+'<small>'+TT('fl.d.gift.check.s')+'</small></span></label>'}
  function lineup(){var li=(A.li_items||[]).filter(function(it){return it&&(it.img||it.url)});if(!li.length)return '';var names=[T('fl.d.gift.i1'),T('fl.d.gift.i2'),T('fl.d.gift.i3'),'Изненада','Изненада'];return '<div class="gift-lineup" aria-label="Козметичният сет на ANSA">'+li.map(function(it,i){var src=it&&(it.img||it.url);return src?'<img src="'+esc(src)+'" alt="'+esc(names[i]||'')+'"'+(it.blur?' class="mystery"':'')+' loading="lazy">':''}).join('')+'</div>'}
  function renderGifts(){var t=totals(),need=Math.max(0,BIG.packs-t.count);
    $('flDrawerTitle').textContent=t.gift?T('fl.d.gift.on'):T('fl.d.gift.off',{need:need,opk_need:opk(need)});
    $('flDrawerContent').innerHTML=lineup()+'<div class="gift-price"><s>€'+fmt(COSM_VAL)+'</s>€'+fmt(BIG.cosm_pay)+'</div><p class="gift-sub">'+TT(t.gift?'fl.d.gift.sub.on':'fl.d.gift.sub.off',{n:t.count,opk_n:opk(t.count)})+'</p>'
      +'<div class="gift-list"><div>'+icon('check')+TT('fl.d.gift.i1')+'</div><div>'+icon('check')+TT('fl.d.gift.i2')+'</div><div>'+icon('check')+TT('fl.d.gift.i3')+'</div><div>'+icon('check')+TT('fl.d.gift.i4')+'</div><div>'+icon('book')+TT('fl.d.gift.book')+'</div></div>'
      +'<div class="notice"><strong>'+TT('fl.d.gift.note.b')+'</strong><br>'+TT('fl.d.gift.note')+'</div>'+giftCheck()+(t.gift?'':'<p class="modal-tier-note">'+TT('fl.d.gift.need',{need:need,opk_need:opk(need)})+'</p>')+cta('fl.d.cont')}
  function renderTombola(){var t=totals();$('flDrawerTitle').textContent=T('fl.d.tb.h');
    $('flDrawerContent').innerHTML='<div class="yacht-hero">'+icon('anchor')+'<strong>'+T('fl.d.tb.hero.b')+'</strong><span>'+T('fl.d.tb.hero.s')+'</span></div>'
      +'<div class="ticket-now"><span>'+TT(t.count?'fl.d.tb.now':'fl.d.tb.now0')+'</span><strong>'+(t.count?t.tickets+' '+bileta(t.tickets):TT('fl.d.tb.now0.v'))+'</strong></div>'
      +'<div class="ticket-list"><div>'+icon('ticket')+'<div><b>'+TT('fl.d.tb.r1.b')+'</b><span>'+TT('fl.d.tb.r1.s')+'</span></div></div><div>'+icon('check')+'<div><b>'+TT('fl.d.tb.r2.b')+'</b><span>'+TT('fl.d.tb.r2.s')+'</span></div></div><div>'+icon('gift')+'<div><b>'+TT('fl.d.tb.r3.b')+'</b><span>'+TT('fl.d.tb.r3.s')+'</span></div></div></div>'
      +'<p>'+TT('fl.d.tb.p')+'</p>'+(A.tombola_url?'<a class="rules-link" href="'+esc(A.tombola_url)+'" target="_blank" rel="noopener">'+TT('fl.d.tb.rules')+'</a>':'')+cta('fl.d.cont')}
  function renderRules(){$('flDrawerTitle').textContent=T('fl.d.rules.h');
    $('flDrawerContent').innerHTML='<p>'+TT('fl.d.rules.p1')+'</p><div class="rules-grid">'+BOXES.map(function(b,i){var nx=BOXES[i+1];var t1=ticketsFor(b,b.packs),t2=nx?ticketsFor(b,nx.packs-1):t1;var tix=nx&&t2!==t1?t1+'–'+t2:String(t1);return '<div><span>'+TT(nx?'fl.d.rules.row':'fl.d.rules.row.last',{a:b.packs,b:nx?nx.packs-1:''})+'<small>'+TT(isBig(b)&&cosmOn()?'fl.d.rules.row.big':'fl.d.rules.row.s',{tickets:tix,bileta:nx&&t2!==t1?'билета':bileta(t1)})+'</small></span><strong>−'+fmt(b.pct)+'%</strong></div>'}).join('')+'</div><p>'+T('fl.d.rules.p2')+'</p>'
      +[1,2,3,4,5].map(function(i){return '<details'+(i===1?' open':'')+'><summary>'+TT('fl.d.rules.q'+i)+'</summary><p>'+TT('fl.d.rules.a'+i)+'</p></details>'}).join('')+cta('fl.d.rules.cta')}
  function renderCart(){var t=totals();$('flDrawerTitle').textContent=T('fl.d.cart.h')+(t.count?' · '+t.count+' '+opk(t.count):'');
    if(!t.count){$('flDrawerContent').innerHTML='<div class="empty-cart">'+icon('bag')+'<h3>'+TT('fl.d.cart.empty.h')+'</h3><p>'+TT('fl.d.cart.empty.p')+'</p>'+cta('fl.d.cart.empty.cta')+'</div>';return}
    var rows=ORDER.filter(function(k){return S.qty[k]}).map(function(k){var p=PROD[k],pct=pctOf(p,t.disp);return '<div class="cart-item">'+picture(p,'cart-pic')+'<div class="cart-item-info"><strong>'+esc(p.name)+'</strong><small>'+TT('fl.d.cart.line',{unit:money(unit(p,pct)),pct:fmt(pct)})+'</small></div>'+stepper(p)+'</div>'}).join('');
    var cod=!!(A.checkout&&A.checkout.cod);
    $('flDrawerContent').innerHTML=rows
      +(t.book?'<div class="cart-gift">'+icon('book')+'<span><strong>'+TT('fl.d.cart.book.b')+'</strong><small>'+TT('fl.d.cart.book.s')+'</small></span></div>':'<p class="modal-tier-note">'+esc(nextMsg(t))+'</p>')
      +(t.gift?giftCheck():'')
      +'<div class="cart-tickets">'+icon('ticket')+'<span><strong>'+TT('fl.d.cart.tix.b',{tickets:t.tickets,bileta:bileta(t.tickets)})+'</strong><small>'+TT('fl.d.cart.tix.s')+'</small></span></div>'
      +'<div class="cart-sum"><div><span>'+TT('fl.d.cart.reg')+'</span><s>'+money(t.original)+'</s></div><div class="discount-row"><span>'+TT('fl.d.cart.disc',{pct:fmt(t.pct)})+'</span><strong>−'+money(t.saving)+'</strong></div><div><span>'+TT('fl.d.cart.ship')+'</span><span>'+TT(t.ship?'fl.d.cart.ship.free':'fl.d.cart.ship.next')+'</span></div><div class="sum-total"><span>'+TT('fl.d.cart.total')+'</span><span>'+money(t.total)+' '+lv(t.total)+'</span></div></div>'
      +(S.cosm&&t.gift?'<div class="notice"><strong>'+TT('fl.d.cart.cosm.b')+'</strong><br>'+TT('fl.d.cart.cosm.s')+'</div>':'')
      +(cod?'<div class="pay-choice" role="radiogroup" aria-label="'+TT('fl.d.cart.pay')+'"><b>'+TT('fl.d.cart.pay')+'</b><label><input type="radio" name="flPay" value="card"'+(S.pay!=='cod'?' checked':'')+'> '+TT('fl.d.cart.pay.card')+'</label><label><input type="radio" name="flPay" value="cod"'+(S.pay==='cod'?' checked':'')+'> '+TT('fl.d.cart.pay.cod')+'</label></div>':'')
      +'<button class="modal-cta" type="button" id="flSubmit">'+TT('fl.d.cart.cta')+'</button>'+(A.cart&&!A.editor&&!A.draft?'':'<p class="demo-notice">'+TT('fl.d.cart.preview')+'</p>');
  }

  /* ── поръчката: ansa_promo_add с variant=flow — сървърът избира кутията по броя и преизчислява всичко ── */
  function submit(btn){
    if(sending)return;var t=totals();if(!t.count)return;
    if(!A.cart||A.editor){toast(T('fl.d.cart.preview'));return}
    beacon('order');sending=true;var html=btn?btn.innerHTML:'';if(btn){btn.disabled=true;btn.textContent=T('fl.d.cart.sending')}
    var restore=function(){sending=false;if(btn){btn.disabled=false;btn.innerHTML=html}};
    var fd=new FormData();fd.append('action','ansa_promo_add');fd.append('nonce',A.nonce||'');fd.append('variant','flow');fd.append('box',t.box?t.box.id:'');fd.append('pay',S.pay||'card');fd.append('cosm',S.cosm&&t.gift?'1':'0');fd.append('email','');fd.append('utm',S.utm||'');fd.append('lead',0);fd.append('sid',sid());if(A.draft)fd.append('draft','1');
    fd.append('items',JSON.stringify(items().map(function(k){return PROD[k].id})));fd.append('secret','[]');
    var send=function(retry){fd.set('nonce',A.nonce||'');return fetch(A.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(r){
      if(r&&r.success&&r.data&&r.data.checkoutUrl){beacon('add');window.location.href=r.data.checkoutUrl;return}
      if(retry&&r&&r.data&&r.data.code==='nonce'){return fetch(A.ajax+'?action=ansa_promo_runtime',{credentials:'same-origin'}).then(function(x){return x.json()}).then(function(x){if(x&&x.success&&x.data&&x.data.nonce)A.nonce=x.data.nonce;return send(false)})}
      restore();toast((r&&r.data&&r.data.msg)||T('fl.d.cart.err'));
    })};
    send(true).catch(function(){restore();toast(T('fl.d.cart.err'))});
  }

  /* ── събития ── */
  root.addEventListener('click',function(e){
    var ch=e.target.closest('[data-change]');if(ch){change(ch.dataset.id,Number(ch.dataset.change));return}
    var op=e.target.closest('[data-open]');if(op){openDrawer(op.dataset.open);return}
    var dt=e.target.closest('[data-detail]');if(dt){openDrawer('product:'+dt.dataset.detail);return}
    var f=e.target.closest('[data-filter]');if(f){filter=f.dataset.filter;var fs=root.querySelectorAll('[data-filter]');for(var i=0;i<fs.length;i++)fs[i].setAttribute('aria-pressed',String(fs[i]===f));renderProducts();$('flCatalog').scrollTop=0;return}
    if(e.target.closest('#flSubmit')){submit(e.target.closest('#flSubmit'));return}
    if(e.target.closest('[data-close]')||e.target.closest('#flClose')||e.target===$('flLayer')){closeDrawer()}
  });
  root.addEventListener('change',function(e){
    if(e.target.id==='flGiftChoice'){S.cosm=e.target.checked;saveS();renderDock();if(modal==='cart'){renderCart();var c=$('flGiftChoice');if(c)c.focus()}announce(S.cosm?'Козметичният сет е запазен.':'Резервацията за козметичния сет е премахната.')}
    if(e.target.name==='flPay'){S.pay=e.target.value==='cod'?'cod':'card';saveS()}
  });
  document.addEventListener('keydown',function(e){
    if($('flLayer').hidden)return;
    if(e.key==='Escape'){e.preventDefault();closeDrawer();return}
    if(e.key==='Tab'){var nodes=Array.prototype.slice.call($('flDrawer').querySelectorAll('button:not(:disabled),input:not(:disabled),summary,[href],[tabindex="0"]')).filter(function(el){return el.getClientRects().length});var first=nodes[0],last=nodes[nodes.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();if(last)last.focus()}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();if(first)first.focus()}}
  });

  /* ── свеж runtime (цени, nonce, седмица) + „view“ в Insights; кешираната страница носи стар JSON ── */
  function refresh(){
    if(!A.ajax||A.editor||A.draft||A.refresh===false)return;
    fetch(A.ajax+'?action=ansa_promo_runtime&v='+encodeURIComponent(A.version||'')+'&sid='+encodeURIComponent(sid())+'&vid='+encodeURIComponent(vid())+'&utm='+encodeURIComponent(S.utm||'')+'&variant=flow',{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json()}).then(function(r){
      if(!r||!r.success||!r.data)return;var d=r.data;
      ['products','order','boxes','max_packs','rewards','nonce','season','deadline','deadline_ts','draw','tombola_url','cosm_reserved','copy','live','checkout','bgn','li_items','version'].forEach(function(k){if(d[k]!==undefined)A[k]=d[k]});
      bind();if(!BIG||!ORDER.length)return;var q={};for(var k in S.qty){if(PROD[k])q[k]=S.qty[k]}S.qty=q;
      renderProducts();renderDock();tick();
    }).catch(function(){});
  }

  shell();renderProducts();renderDock();tick();setInterval(tick,30000);refresh();
  if(itemCount()>0){beacon('box')}
})();
