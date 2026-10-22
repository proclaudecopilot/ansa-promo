/* ansa™ Промо — редакторът (Фаза 2, план §5). Данни: window.AnsaPromoEditor (cfg = чернова, registry, state, gateways, products).
 * Канвасът е iframe със самата страница (?ansa_promo=draft&ansa_editor=1); promo.js в режим редактор праща {ansaPromo:'ck',key} при
 * клик на хотспот и приема {copy|cfg|scenario|mark|changed}. Записът е цялата чернова (сървърът нормализира и връща записаното). */
(function(){
  'use strict';
  var D=window.AnsaPromoEditor;if(!D||!document.getElementById('apEd'))return;
  var $=function(s,r){return (r||document).querySelector(s)},$$=function(s,r){return Array.prototype.slice.call((r||document).querySelectorAll(s))};
  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})}
  function h(html){var t=document.createElement('template');t.innerHTML=html.trim();return t.content.firstChild}
  var REG=D.registry,KEYS=REG.keys,GROUPS=REG.groups,SCREENS=REG.screens;
  var cfg=D.cfg,state=D.state,dirty=false,savedAt=state.draft_at?new Date(state.draft_at*1000):null,sel=null,region=Object.keys(GROUPS)[0],mode='copy',device='desk',search='',onlyChanged=false,invalid={},lastSaveJSON=JSON.stringify(cfg);
  var scen={utm:'',box:'',screen:'boxes',full:false}; /* 1.24.13: тръгва от „Кутиите“ без избрана кутия — стъпката нагласява кутията */
  var SCREEN_LABELS={gate:'1 · Имейл-попъп',cosm:'1b · Попъп „Козметиката за €1“ (само UTM Козметика)',boxes:'2 · Кутиите (всички кутии)',fill:'3 · Напълни кутията',celeb:'4 · Честито (кутията е пълна)',order:'5 · Поръчка',builder:'Таблото (резервен екран)',picker:'Селектор на продукт',product:'Попъп на продукт',sure:'„Сигурна ли си?“',switch:'Смяна на кутия',secret:'Любимци',how:'Как работи играта',li:'Страница „Комплектът за 1 €“ (/kozmetika)'};
  var frame=null,frameReady=false,liveT=null,autosaveT=null;
  var secPage='how',picked={}; /* 1.24.21: 📄 Страници — коя страница е в инспектора; attachment id → URL на току-що избраните снимки (до записа) */

  /* ── помощници за конфига ── */
  function get(path){return path.split('.').reduce(function(o,k){return o==null?undefined:o[k]},cfg)}
  function set(path,v){var ks=path.split('.'),o=cfg;for(var i=0;i<ks.length-1;i++){if(o[ks[i]]==null||typeof o[ks[i]]!=='object')o[ks[i]]={};o=o[ks[i]]}o[ks[ks.length-1]]=v;markDirty()}
  /* 1.24.19 (човекът: „написах копи за Sakura, после за Greens, после за Берберин — накрая всички UTM бяха с последното“): при избран UTM
     редакцията е override САМО за него (cfg.copy_utm[utm][key]); без UTM (или „Общ“) — общият текст (cfg.copy). Канвасът показва текста за UTM-а. */
  function utmKey(){return scen.utm&&scen.utm!=='general'?scen.utm:''}
  function utmMap(u){u=u===undefined?utmKey():u;return u&&cfg.copy_utm&&cfg.copy_utm[u]?cfg.copy_utm[u]:null}
  function hasOvr(k,u){var m=utmMap(u);return !!(m&&Object.prototype.hasOwnProperty.call(m,k)&&m[k]!=='')}
  function ovrCount(u){var m=utmMap(u);return m?Object.keys(m).filter(function(k){return m[k]!==''}).length:0}
  function ovrTotal(){var n=0;Object.keys(cfg.copy_utm||{}).forEach(function(u){n+=ovrCount(u)});return n}
  /* 1.24.23 (човекът: „не мога да изтрия заглавието“): на страниците /info и /kozmetika (групи how/li) празно = скрит текст (override ''), не дефолтът */
  function canEmpty(k){return /^(how|li)\./.test(k)}
  function isHidden(k){return canEmpty(k)&&!!cfg.copy&&Object.prototype.hasOwnProperty.call(cfg.copy,k)&&cfg.copy[k]===''&&KEYS[k].d!==''}
  function cget(m,k){return m&&Object.prototype.hasOwnProperty.call(m,k)?m[k]:null}
  function copyValGlobal(k){return cfg.copy&&Object.prototype.hasOwnProperty.call(cfg.copy,k)?cfg.copy[k]:KEYS[k].d}
  function copyVal(k){return hasOvr(k)?cfg.copy_utm[utmKey()][k]:copyValGlobal(k)}
  function changed(k){return !!(cfg.copy&&Object.prototype.hasOwnProperty.call(cfg.copy,k)&&cfg.copy[k]!==KEYS[k].d)}
  /* 1.23.7: кое е на живо — сравнение с публикуваните текстове (state.published_copy) */
  function isLive(k){var pc=state.published_copy||{};return (cfg.copy||{})[k]===pc[k]}
  function ovrLive(k){var u=utmKey(),pc=(state.published_copy_utm||{})[u]||{};return hasOvr(k)&&pc[k]===cfg.copy_utm[u][k]}
  function unpublished(k){return cget(cfg.copy,k)!==cget(state.published_copy,k)}
  function unpublishedCount(){var pc=state.published_copy||{},c=cfg.copy||{},n=0,seen={};
    (function(){var pu=state.published_copy_utm||{},cu=cfg.copy_utm||{},us={};Object.keys(cu).concat(Object.keys(pu)).forEach(function(u){if(us[u])return;us[u]=1;var a=cu[u]||{},b=pu[u]||{},ks={};Object.keys(a).concat(Object.keys(b)).forEach(function(k){if(ks[k])return;ks[k]=1;if(cget(a,k)!==cget(b,k))n++})})})();Object.keys(c).concat(Object.keys(pc)).forEach(function(k){if(seen[k])return;seen[k]=1;if(cget(c,k)!==cget(pc,k))n++});return n}
  function changedMap(){var m={};Object.keys(KEYS).forEach(function(k){if(changed(k))m[k]=1;if(hasOvr(k))m[k]=2});return m} /* 2 = свой текст за избрания UTM */
  function setCopy(k,v){var u=utmKey();
    if(u){if(!cfg.copy_utm||Array.isArray(cfg.copy_utm))cfg.copy_utm={};if(!cfg.copy_utm[u])cfg.copy_utm[u]={};
      if(v===''||v===copyValGlobal(k)){delete cfg.copy_utm[u][k];if(!Object.keys(cfg.copy_utm[u]).length)delete cfg.copy_utm[u]}else cfg.copy_utm[u][k]=v;
      markDirty();validate(k);postLive(k,hasOvr(k)?v:'',u);return}
    if(!cfg.copy)cfg.copy={};if(v===KEYS[k].d||(v===''&&!canEmpty(k)))delete cfg.copy[k];else cfg.copy[k]=v;markDirty();validate(k);postLive(k,(v===''&&!canEmpty(k))?KEYS[k].d:v)}
  function validate(k){var v=copyVal(k),e=[];if((v.match(/\{\{/g)||[]).length!==(v.match(/\}\}/g)||[]).length)e.push('небалансирани {{ }}');(v.match(/\{\{\s*([a-z0-9_]+)\s*\}\}/gi)||[]).forEach(function(x){var n=x.replace(/[{}\s]/g,'');if(REG.vars.indexOf(n)<0)e.push('непознат плейсхолдър {{'+n+'}}')});if(e.length)invalid[k]=e.join('; ');else delete invalid[k];return !e.length}
  function markDirty(){dirty=JSON.stringify(cfg)!==lastSaveJSON;renderTop();clearTimeout(autosaveT);if(dirty)autosaveT=setTimeout(function(){save(true)},20000)}
  function groupKeys(g){return Object.keys(KEYS).filter(function(k){return KEYS[k].g===g})}
  function matches(k){if(!search)return true;var s=search.toLowerCase();return k.toLowerCase().indexOf(s)>-1||copyVal(k).toLowerCase().indexOf(s)>-1||KEYS[k].d.toLowerCase().indexOf(s)>-1}
  function toast(msg,err){var t=$('#apEdToast');t.textContent=msg;t.className='ap-ed-toast'+(err?' err':'');t.hidden=false;clearTimeout(t._t);t._t=setTimeout(function(){t.hidden=true},err?5000:2200)}
  function fmtTime(d){return d?('0'+d.getHours()).slice(-2)+':'+('0'+d.getMinutes()).slice(-2):'—'}

  /* ── ajax ── */
  function ajax(action,data){var fd=new FormData();fd.append('action','ansa_promo_editor_'+action);fd.append('nonce',D.nonce);Object.keys(data||{}).forEach(function(k){fd.append(k,data[k])});
    return fetch(D.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(r){if(!r||!r.success){var e=new Error((r&&r.data&&r.data.msg)||'Грешка');e.data=r&&r.data;throw e}return r.data})}
  function fixCfg(c){['copy','fit','campaigns','copy_utm'].forEach(function(k){if(!c[k]||Array.isArray(c[k]))c[k]={}});if(!c.problems)c.problems=[];return c}
  function applyState(d){if(d.cfg){cfg=fixCfg(d.cfg);lastSaveJSON=JSON.stringify(cfg);dirty=false;savedAt=new Date()}if(d.state)state=d.state;invalid={};Object.keys(cfg.copy||{}).forEach(validate);renderAll()}
  var saving=false;
  var savePromise=null; /* 1.23.7: публикуването изчаква текущия запис, вместо да го надбягва */
  function save(auto){if(saving)return savePromise||Promise.resolve();if(!dirty&&auto)return Promise.resolve();saving=true;renderTop();
    return savePromise=ajax('save',{cfg:JSON.stringify(cfg)}).then(function(d){saving=false;applyState(d);if(d.invalid&&Object.keys(d.invalid).length){Object.keys(d.invalid).forEach(function(k){invalid[k]=d.invalid[k]});toast('Записано, но има текстове с грешки — виж ●',true)}else toast(auto?'Автозапис ✓':'Черновата е записана ✓');postChanged()}).catch(function(e){saving=false;renderTop();toast('Записът не мина: '+e.message,true)})}
  /* 1.23.7 (човекът: „Публикувай навярно само запазва“): изчаква записа, праща текущата конфигурация С публикуването (една заявка) и после проверява,
     че публикуваните текстове са същите като в редактора — ако не са, казва го вместо „Публикувано“ */
  function publish(force){save().then(function(){var d={cfg:JSON.stringify(cfg)};if(force)d.force='1';return ajax('publish',d)}).then(function(d){applyState(d);var pc=state.published_copy||{},miss=Object.keys(cfg.copy||{}).filter(function(k){return pc[k]!==cfg.copy[k]}).concat(Object.keys(pc).filter(function(k){return !Object.prototype.hasOwnProperty.call(cfg.copy||{},k)}));if(miss.length)toast('Публикувано v'+state.version+', но на живо '+miss.length+' текста се различават ('+miss.slice(0,3).join(', ')+') — презареди редактора',true);else toast('🚀 Публикувано — версия '+state.version+' · текстовете са на живо');if(frame)frame.src=D.page}).catch(function(e){if(e.data&&e.data.code==='doctor'){state.checks=e.data.checks;doctorModal(true)}else toast('Публикуването не мина: '+e.message,true)})}
  function discard(){if(!confirm('Да отхвърля ли черновата? Всички незаписани и записани промени след последното публикуване се губят.'))return;ajax('discard').then(function(d){applyState(d);toast('Черновата е отказана');if(frame)frame.src=D.page}).catch(function(e){toast(e.message,true)})}
  function restore(v){if(!confirm('Версия '+v+' да стане текущата чернова?'))return;ajax('restore',{version:v}).then(function(d){applyState(d);toast('Версия '+v+' е върната като чернова');if(frame)frame.src=D.page}).catch(function(e){toast(e.message,true)})}

  /* ── канвас ── */
  function post(m){if(frame&&frame.contentWindow)try{frame.contentWindow.postMessage(m,'*')}catch(e){}}
  function postLive(k,v,u){clearTimeout(liveT);liveT=setTimeout(function(){post({ansaPromo:'copy',key:k,value:v,utm:u||''})},150)}
  function postAllCopy(){post({ansaPromo:'copy',copy:cfg.copy||{},copy_utm:cfg.copy_utm||{}})}
  function postChanged(){post({ansaPromo:'changed',keys:changedMap()})}
  function postScenario(){post({ansaPromo:'scenario',screen:scen.screen,utm:scen.utm||null,box:scen.box||null,full:!!scen.full})}
  function runtimePartial(){var dl='';if(cfg.deadline){var m=String(cfg.deadline).match(/^(\d{4})-(\d{2})-(\d{2})/);if(m)dl=m[3]+'.'+m[2]+'.'+m[1]}
    return {name:cfg.name,deadline:dl,boxes:cfg.boxes,box_order:cfg.order,rewards:cfg.rewards,secret:cfg.secret,ship:Number(cfg.ship&&cfg.ship.paid)||0,timer:cfg.timer,gate:{enabled:!!cfg.gate.enabled,required:!!cfg.gate.required,consent_default:!!cfg.gate.consent_default},bgn:cfg.bgn,fit:cfg.fit||{},problems:cfg.problems||[],checkout:{cod:!!(cfg.checkout&&cfg.checkout.cod_gateway)},campaigns:cfg.campaigns||{},pages:pagesRuntime(),li_items:liItemsRuntime()}} /* 1.24.14: кампаниите по UTM · 1.24.21: секциите на /info и /kozmetika */
  function postCfg(){post({ansaPromo:'cfg',runtime:runtimePartial()})}
  function renderCanvas(){var c=$('#apEdCanvas');if(!D.page){c.innerHTML='<div class="ap-ed-scen">Няма страница — избери я в 🧰 Инструменти.</div>';return}
    if(!frame){c.innerHTML='';
      /* 1.24.13 (човекът: „тотално объркано е“): лентата е по пътя на клиента — 1) откъде идва (UTM: кампания или продукт),
         2) на коя стъпка е. Кутията и „пълна кутия“ се нагласят сами по стъпката и стоят в „⚙ детайли“. Под лентата пише какво
         точно се показва и има линк за тест на живо със същия UTM. */
      var utmOpts='<option value="">— без UTM (общ сезон) —</option><optgroup label="Кампания">'+CAMPAIGNS.map(function(x){return '<option value="'+x.v+'">'+esc(x.l)+' · ?utm_content='+x.v+'</option>'}).join('')+'</optgroup>'
        +'<optgroup label="Продукт (retargeting)">'+D.products.filter(function(p){return p.enabled}).map(function(p){return '<option value="'+esc(p.key)+'">'+esc(p.name)+' · ?utm_content='+esc(p.key)+'</option>'}).join('')+'</optgroup>';
      var bar=h('<div class="ap-ed-scen"><label title="откъде идва човекът — кампания или продукт от рекламата">1 · UTM <select id="apScUtm">'+utmOpts+'</select></label>'
      /* 1.24.14: настройките на кампанията за този UTM (пазят се в конфига → Запази/Публикувай) */
      +'<span class="camp" id="apScCamp"><label title="имейл-попъпът за този UTM">Имейл-попъп <select id="apScGate"><option value="default">както е глобално</option><option value="on">да</option><option value="off">не</option></select></label>'
      +'<label title="попъпът „Козметиката за €1 ще бъде твоя“ след имейл-попъпа — Искам помощ / Не искам помощ"><input type="checkbox" id="apScCosm"> попъп „Козметиката за €1“</label>'
      +'<label title="всички = екранът с трите кутии; една = избира се сама и се отваря пълненето">Кутии <select id="apScCBox"><option value="">всички (екранът с кутиите)</option>'+cfg.boxes.map(function(b){return '<option value="'+b.id+'">само '+esc(b.ic+' '+b.name)+' — избира се сама</option>'}).join('')+'</select></label></span>'
      +'<label title="коя стъпка от пътя гледаш/редактираш">2 · Стъпка <select id="apScScreen"></select></label>'
      +'<details class="ap-ed-det"><summary>⚙ детайли</summary><label>Кутия в канваса <select id="apScBox"><option value="">— всички —</option>'+cfg.boxes.map(function(b){return '<option value="'+b.id+'">'+esc(b.ic+' '+b.name)+'</option>'}).join('')+'</select></label>'
      +'<label title="пълна = всички опаковки са сложени (за Честито, Поръчка)"><input type="checkbox" id="apScFull"> кутията е пълна</label></details><span class="sp" style="flex:1"></span>'
      +'<span class="dev"><button class="sm" data-dev="desk">💻 1280</button><button class="sm" data-dev="mob">📱 390</button></span><button class="sm" id="apScReload" title="презареди канваса">⟳</button><a class="btn sm" target="_blank" id="apScOpen" href="'+esc(D.page)+'">↗ тест на живо</a>'
      +'<div class="hint" id="apScHint"></div><div class="url" id="apScUrl"></div></div>');
      c.appendChild(bar);var fr=h('<div class="ap-ed-frame" id="apEdFrame"><iframe id="apEdIframe" src="'+esc(D.page)+'"></iframe></div>');c.appendChild(fr);frame=$('#apEdIframe');
      frame.addEventListener('load',function(){frameReady=true;setTimeout(function(){postAllCopy();postCfg();postScenario();postChanged();if(sel)post({ansaPromo:'mark',key:sel})},150)});
      $('#apScUtm').onchange=function(){scen.utm=this.value;stepDefaults();renderCanvas();postScenario();renderRail();renderInsp();invalid={};Object.keys(KEYS).forEach(function(k){if(changed(k)||hasOvr(k))validate(k)})}; /* 1.24.19: текстовете са по UTM */
      $('#apScScreen').onchange=function(){scen.screen=this.value;stepDefaults(true);renderCanvas();postScenario()}; /* изрично избрана ⛔ стъпка остава (за редакция на текстовете ѝ) */
      $('#apScGate').onchange=function(){setCamp('gate',this.value)};$('#apScCosm').onchange=function(){setCamp('cosm_pop',this.checked)};$('#apScCBox').onchange=function(){setCamp('box',this.value)};
      $('#apScBox').onchange=function(){scen.box=this.value;renderCanvas();postScenario()};$('#apScFull').onchange=function(){scen.full=this.checked;renderCanvas();postScenario()};
      $$('[data-dev]').forEach(function(b){b.onclick=function(){device=b.dataset.dev;$$('[data-dev]').forEach(function(x){x.classList.toggle('on',x===b)});$('#apEdFrame').classList.toggle('mob',device==='mob');fitFrame()}});
      $('#apScReload').onclick=function(){frame.src=D.page};
      window.addEventListener('resize',fitFrame)}
    var cp=campGet(campKey());$('#apScGate').value=cp.gate;$('#apScCosm').checked=!!cp.cosm_pop;$('#apScCBox').value=cp.box||'';
    var steps=stepList(),ssel=$('#apScScreen');ssel.innerHTML=steps.map(function(g){return '<optgroup label="'+esc(g.l)+'">'+g.s.map(function(x){return '<option value="'+x.k+'">'+esc(x.l)+'</option>'}).join('')+'</optgroup>'}).join('');
    $('#apScUtm').value=scen.utm;$('#apScBox').value=scen.box;$('#apScScreen').value=scen.screen;$('#apScFull').checked=scen.full;$$('[data-dev]').forEach(function(x){x.classList.toggle('on',x.dataset.dev===device)});
    var lu=liveUrl(),bn=function(id){return (cfg.boxes.filter(function(b){return b.id===id})[0]||{}).name||id};
    $('#apScOpen').href=lu;
    var si=stepInfo(scen.screen);
    $('#apScHint').innerHTML='👁 Показва: <b>'+esc(utmLabel())+'</b> → <b>'+esc(si.l)+'</b>'+(scen.box?' · '+esc(bn(scen.box))+(scen.full?' (пълна)':''):'')
      +(si.off?' · <span class="warn">⚠ на живо при този UTM тази стъпка я няма — виждаш я само за да редактираш текстовете ѝ</span>':'')
      +(utmKey()?' · <b style="color:#c2410c">✎ '+ovrCount()+' свои текста</b>':'')+' · кампанията: имейл-попъп <b>'+(gateOn()?'да':'не')+'</b> · попъп козметика <b>'+(cp.cosm_pop?'да':'не')+'</b> · кутии <b>'+(cp.box?'само '+esc(bn(cp.box)):'всички')+'</b>'+(dirty?' · <span class="warn">незаписано — „Запази“, после „Публикувай“</span>':'');
    $('#apScUrl').innerHTML='🔗 URL за рекламата: <input type="text" readonly id="apScUrlIn" value="'+esc(lu)+'"><button type="button" class="sm" id="apScCopy">📋 копирай</button>';
    $('#apScCopy').onclick=function(){var i=$('#apScUrlIn');i.select();try{navigator.clipboard.writeText(i.value)}catch(e){document.execCommand('copy')}this.textContent='✓ копиран';var b=this;setTimeout(function(){b.textContent='📋 копирай'},1500)};
    fitFrame()}
  /* 1.24.14: кампанията на избрания UTM (конфиг campaigns) */
  function campKey(){return scen.utm||'general'}
  function campGet(k){var c=(cfg.campaigns&&cfg.campaigns[k])||{};return {gate:c.gate||'default',cosm_pop:c.cosm_pop!==undefined?!!c.cosm_pop:k==='cosm',box:c.box||''}}
  function setCamp(f,v){if(!cfg.campaigns||Array.isArray(cfg.campaigns))cfg.campaigns={};var k=campKey(),c=campGet(k);c[f]=v;cfg.campaigns[k]=c;markDirty();postCfg();stepDefaults();renderCanvas();postScenario()}
  function gateOn(){var g=campGet(campKey()).gate;return g==='on'||(g==='default'&&!!(cfg.gate&&cfg.gate.enabled))}
  /* 1.24.14.1: списъкът е винаги пълен — стъпка, която не важи за този UTM, е със „⛔ не се показва при този UTM“ (off:true), за да може
     да се редактират текстовете ѝ (напр. от списъка вляво), но да е ясно, че на живо я няма; stepDefaults я прескача при смяна на настройка */
  function stepList(){var cp=campGet(campKey()),bn=cp.box?((cfg.boxes.filter(function(b){return b.id===cp.box})[0]||{}).name||cp.box):'';var OFF=' ⛔ не се показва при този UTM';var path=[];
    path.push({k:'gate',l:'1 · Имейл-попъп'+(gateOn()?'':OFF),off:!gateOn()});path.push({k:'cosm',l:'1b · Попъп „Козметиката за €1“'+(cp.cosm_pop?'':OFF),off:!cp.cosm_pop});
    path.push({k:'boxes',l:'2 · Кутиите (всички кутии)'+(cp.box?' ⛔ кутията се избира сама':''),off:!!cp.box});path.push({k:'fill',l:(cp.box?'2 · Пълнене на '+bn+' (избира се сама)':'3 · Напълни кутията')});
    path.push({k:'celeb',l:'4 · Честито (кутията е пълна)'});path.push({k:'order',l:'5 · Поръчка'});
    return [{l:'Пътят на клиента при този UTM',s:path},{l:'Други екрани',s:STEP_GROUPS[1].s.map(function(k){return {k:k,l:SCREEN_LABELS[k]}})}]}
  function stepInfo(k){var f=null;stepList().forEach(function(g){g.s.forEach(function(x){if(x.k===k)f=x})});return f||{k:k,l:SCREEN_LABELS[k]||k}}
  function stepLabel(k){return stepInfo(k).l}
  /* 1.24.13: кампаниите и стъпките на лентата; стъпката нагласява кутията/„пълна“ сама */
  var CAMPAIGNS=[{v:'cosm',l:'💄 Козметика'},{v:'yacht',l:'⛵ Яхта'},{v:'general',l:'🎁 Общ'}];
  var STEP_GROUPS=[{l:'Пътят на клиента',s:['gate','cosm','boxes','fill','celeb','order']},{l:'Други екрани',s:['builder','picker','product','sure','switch','secret','how','li']}];
  function stepDefaults(keep){var cp=campGet(campKey()),ok=[];stepList().forEach(function(g){g.s.forEach(function(x){if(!x.off)ok.push(x.k)})});if(!keep&&ok.indexOf(scen.screen)<0)scen.screen=ok[0];
    var sc=scen.screen;if(sc==='gate'||sc==='cosm'||sc==='boxes'){scen.box='';scen.full=false}else if(sc==='fill'){scen.box=cp.box||scen.box||'l';scen.full=false}else{scen.box=cp.box||scen.box||'l';scen.full=true}}
  function utmLabel(){if(!scen.utm)return 'без UTM (общ сезон)';var c=CAMPAIGNS.filter(function(x){return x.v===scen.utm})[0];if(c)return c.l+' (utm_content='+scen.utm+')';var p=D.products.filter(function(x){return x.key===scen.utm})[0];return (p?p.name:scen.utm)+' (utm_content='+scen.utm+')'}
  function liveUrl(){var u=D.page_url||D.page||''; /* публичната страница; кутията/попъпите идват от кампанията в конфига, не от адреса */var q=[];q.push('utm_content='+encodeURIComponent(scen.utm||'general'));return u+(u.indexOf('?')<0?'?':'&')+q.join('&')}
  function fitFrame(){if(!frame)return;var wrap=$('#apEdFrame'),w=wrap.clientWidth-24,need=device==='mob'?390:1280,z=Math.min(1,w/need);frame.style.transform='scale('+z+')';frame.style.height=device==='mob'?'780px':((wrap.clientHeight-24)/z)+'px'}
  window.addEventListener('message',function(e){var d=e.data;if(!d||!d.ansaPromo)return;if(d.ansaPromo==='ck'&&d.key&&KEYS[d.key]){
      if(mode==='sections'&&KEYS[d.key].g===secPage&&focusSecKey(secPage,d.key))return; /* 1.24.23: панелът „Страници“ остава — текстът се отваря в него */
      mode='copy';region=KEYS[d.key].g;select(d.key,false,true)}
    else if(d.ansaPromo==='sec'&&d.page&&D.pages&&D.pages[d.page]){secAct(d.page,d.id,d.act)} /* 1.24.21: лентата над секцията в канваса */});

  /* ── рейл ── */
  function renderRail(){var r=$('#apEdRail'),out='';
    out+='<div class="srch"><input type="text" id="apSearch" placeholder="🔍 търси в текстовете…" value="'+esc(search)+'"></div><label class="cb" style="margin:0 6px 6px;font-size:12px;display:block"><input type="checkbox" id="apOnlyCh"'+(onlyChanged?' checked':'')+'> само променените</label>';
    out+='<h4>Текстове'+(utmKey()?' <small style="color:#c2410c;font-weight:600">· за '+esc(utmLabel())+' (✎ = свой текст)</small>':' <small style="color:#6b5f58;font-weight:600">· общите (без UTM)</small>')+'</h4>';var chm=changedMap();
    Object.keys(GROUPS).forEach(function(g){var ks=groupKeys(g),ch=ks.filter(function(k){return chm[k]}).length,vis=ks.filter(function(k){return matches(k)&&(!onlyChanged||chm[k])});if((search||onlyChanged)&&!vis.length)return;
      var ov=ks.filter(function(k){return chm[k]===2}).length;
      out+='<button class="rg'+(mode==='copy'&&region===g?' on':'')+'" data-g="'+g+'">'+esc(GROUPS[g])+'<span class="n">'+ks.length+(ch-ov?' · <b>'+(ch-ov)+'</b>':'')+(ov?' · <b style="color:#c2410c">✎'+ov+'</b>':'')+'</span></button>';
      if(mode==='copy'&&region===g||search||onlyChanged){out+='<div class="keys">'+vis.map(function(k){return '<button class="k'+(sel===k?' on':'')+(chm[k]?' ch':'')+(chm[k]===2?' ov':'')+(invalid[k]?' bad':'')+'" data-k="'+k+'" title="'+esc(copyVal(k))+'">'+(chm[k]===2?'✎ ':'')+esc(k)+'</button>'}).join('')+'</div>'}});
    /* 1.24.21: 📄 Страници — редът на секциите, скритите и снимките на /info и /kozmetika */
    out+='<h4>📄 Страници · секции и снимки</h4>';Object.keys(D.pages||{}).forEach(function(pg){var P=pageEd(pg),ni=Object.keys(P.images).length;out+='<button class="rg'+(mode==='sections'&&secPage===pg?' on':'')+'" data-secpg="'+pg+'">'+(pg==='how'?'ℹ️ ':'🌸 ')+esc(D.pages[pg].label)+'<span class="n">'+P.order.length+(P.off.length?' · <b>'+P.off.length+' скрити</b>':'')+(ni?' · 🖼'+ni:'')+'</span></button>'});
    out+='<h4>⚙️ Двигател</h4>';[['game','🎮 Играта'],['boxes','📦 Кутиите'],['rewards','🎁 Наградите'],['checkout','💳 Плащане'],['tickets','🎟️ Билети'],['fit','🔗 FIT · проблеми']].forEach(function(x){out+='<button class="rg'+(mode===x[0]?' on':'')+'" data-mode="'+x[0]+'">'+esc(x[1])+'</button>'});
    out+='<a class="rg" href="'+esc(D.products_tab)+'" target="_blank">📦 Продукти ↗</a>';
    out+='<h4>🧰 Инструменти</h4><button class="rg" data-mode="doctor">🩺 Doctor'+(state.checks.some(function(c){return c.level==='bad'})?' <span class="n"><b>⛔</b></span>':'')+'</button><button class="rg" data-mode="history">🕘 История <span class="n">'+state.history.length+'</span></button><button class="rg" data-mode="json">{ } JSON</button><a class="rg" href="'+esc(D.admin)+'">← Инструменти (стария екран)</a>';
    r.innerHTML=out;
    $('#apSearch').oninput=function(){search=this.value;renderRail()};$('#apOnlyCh').onchange=function(){onlyChanged=this.checked;renderRail()};
    $$('[data-g]',r).forEach(function(b){b.onclick=function(){mode='copy';region=b.dataset.g;var ks=groupKeys(region);if(!sel||KEYS[sel].g!==region)sel=ks[0];renderRail();renderInsp();goToKeyScreen(sel)}});
    $$('[data-k]',r).forEach(function(b){b.onclick=function(){mode='copy';region=KEYS[b.dataset.k].g;select(b.dataset.k,true)}});
    $$('[data-mode]',r).forEach(function(b){b.onclick=function(){mode=b.dataset.mode;sel=null;renderRail();renderInsp()}});
    $$('[data-secpg]',r).forEach(function(b){b.onclick=function(){openSections(b.dataset.secpg)}})}
  function goToKeyScreen(k){if(!k)return;var sc=SCREENS[KEYS[k].g]||[];if(sc.length&&sc.indexOf(scen.screen)<0){scen.screen=sc[0];if(sc[0]!=='boxes'&&!scen.box)scen.box='l';renderCanvas();postScenario()}}
  /* 1.24.16 (човекът: „като цъкам на секции да ги едитвам, ми мести Стъпка“): клик върху хотспот в канваса (fromCanvas) НИКОГА не сменя
     стъпката — текстът е на екрана, който гледаш; само изборът от списъка вляво навигира към екрана на ключа */
  function select(k,scroll,fromCanvas){mode='copy';region=KEYS[k]?KEYS[k].g:region;sel=k;renderRail();renderInsp();if(!fromCanvas)goToKeyScreen(k);post({ansaPromo:'mark',key:k,scroll:!!scroll});var ta=$('#apTa');if(ta&&scroll){ta.focus()}}

  /* ── инспектор ── */
  function renderInsp(){var p=$('#apEdInsp');
    if(mode==='copy'){if(!sel){p.innerHTML='<div class="empty">Цъкни текст в канваса или избери ключ вляво.</div>';return}
      var k=sel,m=KEYS[k],v=copyVal(k),ks=groupKeys(m.g),i=ks.indexOf(k),sc=SCREENS[m.g]||[];var vars=m.v.slice();REG.vars.forEach(function(x){if(vars.indexOf(x)<0)vars.push(x)});
      var u=utmKey();function ustatHtml(){return u?(hasOvr(k)?'<span style="color:#7c3a0a">✎ свой текст за <b>'+esc(utmLabel())+'</b>'+(ovrLive(k)?' · публикуван':' · НЕ е публикуван — натисни 🚀 Публикувай')+'</span>':'<span style="color:#6b5f58">ползва общия текст — каквото напишеш тук, важи само за <b>'+esc(utmLabel())+'</b></span>'):''}
      var ustat=' <span id="apUstat">'+ustatHtml()+'</span>';
      p.innerHTML='<h3>'+esc(GROUPS[m.g])+(u?' <small style="font-weight:600;color:#c2410c">· за '+esc(utmLabel())+'</small>':'')+'</h3><div class="key">'+esc(k)+(u?ustat:(isHidden(k)?' <span style="color:#b91c1c">🚫 скрит (празно) — не се показва на страницата'+(isLive(k)?'':' · НЕ е публикуван')+'</span>':changed(k)?(isLive(k)?' <span style="color:#15803d">● публикуван</span>':' <span style="color:#e8722a">● променен · НЕ е публикуван — натисни 🚀 Публикувай</span>'):(unpublished(k)?' <span style="color:#e8722a">● върнат към дефолта · НЕ е публикуван</span>':'')))+'</div>'+(canEmpty(k)&&!u?'<div class="hint">Празно = текстът се скрива от страницата. Нови абзаци/заглавия: „＋ допълнителен текст“ в 📄 Страници.</div>':'')+'<div class="grp">'+(sc.length?'Вижда се на: '+sc.map(function(s){return SCREEN_LABELS[s]||s}).join(', '):'Не е на канваса — '+(m.g==='checkout'?'чекаут / благодаря':(m.g==='emails'?'имейл / стикер':'съобщение')))+'</div>'
        +'<textarea id="apTa" rows="4">'+esc(v)+'</textarea><div class="err" id="apErr">'+esc(invalid[k]||'')+'</div>'
        +'<div class="hint">Плейсхолдъри (цък → вмъква): '+(m.v.length?'за този текст: ':'')+'</div><div class="chips">'+m.v.map(function(x){return '<span class="chip" data-ph="'+x+'">{{'+x+'}}</span>'}).join('')+'<details style="width:100%"><summary class="hint" style="cursor:pointer">всички '+REG.vars.length+'</summary><div class="chips">'+REG.vars.map(function(x){return '<span class="chip" data-ph="'+x+'">{{'+x+'}}</span>'}).join('')+'</div></details></div>'
        +(sc.length?'':'<div class="prev" id="apPrev"></div>')
        +(u?'<div class="def"><b>Общият текст (без UTM):</b>\n'+esc(copyValGlobal(k))+'</div>':'<div class="def"><b>Дефолт:</b>\n'+esc(m.d)+'</div>')
        +'<div class="row"><button class="sm" id="apReset"'+((u?hasOvr(k):changed(k))?'':' disabled')+'>'+(u?'⟲ Ползвай общия текст':'⟲ Върни дефолта')+'</button><span class="sp" style="flex:1"></span><button class="sm" id="apPrev2"'+(i<=0?' disabled':'')+'>← предишен</button><button class="sm" id="apNext"'+(i>=ks.length-1?' disabled':'')+'>следващ →</button></div><div class="hint">Ctrl+Enter = приложи · Ctrl+S = запази черновата</div>';
      var ta=$('#apTa');function grow(){ta.style.height='auto';ta.style.height=Math.min(420,ta.scrollHeight+4)+'px'}grow();
      ta.oninput=function(){setCopy(k,ta.value);$('#apErr').textContent=invalid[k]||'';$('#apReset').disabled=!(u?hasOvr(k):changed(k));var us=$('#apUstat');if(us)us.innerHTML=ustatHtml();grow();preview()};
      ta.onkeydown=function(e){if((e.ctrlKey||e.metaKey)&&e.key==='Enter'){e.preventDefault();setCopy(k,ta.value);post({ansaPromo:'copy',key:k,value:copyVal(k)});renderRail()}};
      $$('.chip',p).forEach(function(c){c.onclick=function(){var s=ta.selectionStart||0,e=ta.selectionEnd||0,ins='{{'+c.dataset.ph+'}}';ta.value=ta.value.slice(0,s)+ins+ta.value.slice(e);ta.selectionStart=ta.selectionEnd=s+ins.length;ta.focus();ta.oninput()}});
      $('#apReset').onclick=function(){ta.value=u?copyValGlobal(k):m.d;ta.oninput();renderRail();renderInsp()};$('#apPrev2').onclick=function(){select(ks[i-1],true)};$('#apNext').onclick=function(){select(ks[i+1],true)};
      function preview(){var pv=$('#apPrev');if(!pv)return;var sample={box:'Голяма кутия',box_l:'голяма кутия',box_ic:'👑',packs:5,opk:'опаковки',pct:40,tickets:5,bileta:'билета',shans:'шанса',cosm_pay:'1',cosm_value:'129',book_value:'19',yacht:'8 800',ship:'€ 2,55',url:'#',name:cfg.name,customer:'Мария',order:'12345',code:'ABCD-EFGH-JKLM',voucher:'VABC1234',portal_url:cfg.tickets.portal_url||D.page_url,book_url:cfg.rewards.book.url||'#',secret_pct:cfg.secret.pct,bonus:1,bonus_bileta:'билет',deadline:cfg.deadline};pv.innerHTML=copyVal(k).replace(/\{\{\s*([a-z0-9_]+)\s*\}\}/gi,function(x,n){return sample[n]!==undefined?'<b style="color:#c2410c">'+esc(sample[n])+'</b>':x})}preview();return}
    if(mode==='doctor'){p.innerHTML='<h3>🩺 Doctor</h3><div class="hint">Publish gate: ⛔ спира публикуването, ⚠ само предупреждава.</div><ul class="ap-ed-doc">'+state.checks.map(function(c){return '<li class="'+c.level+'">'+({bad:'⛔',warn:'⚠',ok:'✓',info:'ℹ'}[c.level]||'')+' '+esc(c.msg)+'</li>'}).join('')+'</ul><p><button class="sm" id="apDocRun">↻ провери пак (записва черновата)</button></p>';$('#apDocRun').onclick=function(){save().then(function(){renderInsp()})};return}
    if(mode==='history'){p.innerHTML='<h3>🕘 История</h3><div class="hint">Публикувано: версия '+state.version+(state.published_at?' · '+new Date(state.published_at*1000).toLocaleString('bg-BG'):'')+'</div><ul class="ap-ed-hist">'+(state.history.length?state.history.map(function(x){return '<li>версия '+x.version+' · '+new Date(x.at*1000).toLocaleString('bg-BG')+'<span style="flex:1"></span><button class="sm" data-rv="'+x.version+'">върни като чернова</button></li>'}).join(''):'<li>още няма публикувани версии</li>')+'</ul>';$$('[data-rv]',p).forEach(function(b){b.onclick=function(){restore(b.dataset.rv)}});return}
    if(mode==='json'){p.innerHTML='<h3>{ } JSON на черновата</h3><div class="hint">Редактирай и „Внеси“ — минава през normalize на сървъра при запис.</div><textarea id="apJson" style="height:60vh;font:11px/1.4 Menlo,Consolas,monospace">'+esc(JSON.stringify(cfg,null,2))+'</textarea><p><button class="sm" id="apJsonGo">⬆ Внеси в черновата</button></p>';$('#apJsonGo').onclick=function(){try{var d=JSON.parse($('#apJson').value);cfg=d;markDirty();dirty=true;save().then(function(){if(frame)frame.src=D.page})}catch(e){toast('Невалиден JSON: '+e.message,true)}};return}
    if(mode==='sections'){renderSections(p);return} /* 1.24.21 */
    renderEngine(p)}

  /* ── ⚙️ двигател ── */
  function field(path,label,type,opts){var v=get(path);opts=opts||{};if(type==='check')return '<label class="cb"><input type="checkbox" data-p="'+path+'" data-t="check"'+(v?' checked':'')+'> '+esc(label)+'</label>';
    if(type==='select')return '<div class="f"><label>'+esc(label)+'</label><select data-p="'+path+'" data-t="text">'+Object.keys(opts.options).map(function(o){return '<option value="'+esc(o)+'"'+(String(v)===String(o)?' selected':'')+'>'+esc(opts.options[o])+'</option>'}).join('')+'</select></div>';
    return '<div class="f"><label>'+esc(label)+'</label><input type="'+(type==='number'?'number':'text')+'" data-p="'+path+'" data-t="'+type+'" value="'+esc(v==null?'':v)+'"'+(opts.step?' step="'+opts.step+'"':'')+(opts.ph?' placeholder="'+esc(opts.ph)+'"':'')+'></div>'}
  function bindFields(p,after){$$('[data-p]',p).forEach(function(el){el.onchange=function(){var t=el.dataset.t,v=t==='check'?el.checked:(t==='number'?(el.value===''?null:Number(el.value)):el.value);if(t==='nullnum')v=el.value===''?null:Number(el.value);set(el.dataset.p,v);if(after)after(el.dataset.p);postCfg()}})}
  function renderEngine(p){var out='<div class="ap-ed-eng">';
    if(mode==='game'){out+='<h3>🎮 Играта</h3>'+field('name','Име на промото','text')+field('deadline','Край (YYYY-MM-DD HH:MM)','text',{ph:'2026-12-31 23:59'})+field('enabled','Играта е включена (публичната страница показва играта)','check')+'<div class="grid">'+field('timer.minutes','Таймер (мин)','number')+field('timer.warn_under','Предупреждение под (мин)','number')+'</div>'+field('utm_param','UTM параметър','text')+field('max_packs','Най-много опаковки в една кутия (опаковките на кутията са минимум — броят сам я вдига)','number')+'<div class="grid">'+field('ship.paid','Платена доставка (€, Малка кутия)','number',{step:'0.01'})+field('bgn.rate','Курс лв./€','number',{step:'0.00001'})+'</div>'+field('bgn.show','Показвай лева до крайната сума','check')+'<h3>Имейл-попъп</h3>'+field('gate.enabled','включен','check')+field('gate.required','задължителен','check')+field('gate.consent_default','съгласието е отметнато по подразбиране','check')+'<h3>Любимци (екран 4)</h3>'+field('secret.enabled','включено','check')+'<div class="grid">'+field('secret.pct','отстъпка %','number')+field('secret.count','брой','number')+'</div>'+'<h3>Преглед с парола</h3>'+field('preview.enabled','включен','check')+field('preview.password','парола','text')+'<h3>Ред на кутиите</h3><div class="hint">id-та със запетая (s, m, l)</div>'+field('_order_desktop','десктоп','text')+field('_order_mobile','телефон','text')}
    if(mode==='boxes'){out+='<h3>📦 Кутиите</h3>';cfg.boxes.forEach(function(b,i){var P='boxes.'+i+'.';out+='<div class="box"><h4>'+esc(b.ic+' '+b.name)+' <small style="color:#6b5f58">('+b.id+')</small></h4><div class="grid">'+field(P+'ic','емоджи','text')+field(P+'name','име','text')+'</div><div class="grid3">'+field(P+'packs','опаковки','number')+field(P+'pct','отстъпка %','number',{step:'0.5'})+field(P+'tickets','билети','number')+'</div><div class="grid">'+field(P+'grow','расте над броя (приема и повече опаковки на същата отстъпка, до тавана)','check')+field(P+'tix_extra','+билети за всяка опаковка над броя','number')+'</div>'+field(P+'tag','етикет (напр. Най-популярна)','text')+'<div class="f"><label>награди</label>'+Object.keys(D.rewards).map(function(r){return '<label class="cb"><input type="checkbox" data-rw="'+i+'" value="'+r+'"'+(b.rw.indexOf(r)>-1?' checked':'')+'> '+esc(D.rewards[r])+'</label>'}).join('')+'</div><div class="f"><label>„няма“ (зачертани в картата)</label>'+Object.keys(D.rewards).map(function(r){return '<label class="cb"><input type="checkbox" data-no="'+i+'" value="'+r+'"'+((b.no||[]).indexOf(r)>-1?' checked':'')+'> '+esc(D.rewards[r])+'</label>'}).join('')+'</div><div class="f"><label>сетът за € (празно = без сет)</label><input type="number" step="0.01" data-p="'+P+'cosm_pay" data-t="nullnum" value="'+(b.cosm_pay==null?'':b.cosm_pay)+'"></div></div>'})}
    if(mode==='rewards'){out+='<h3>🎁 Наградите</h3>'+field('rewards.yacht.value','Яхта — стойност €','number')+'<div class="grid">'+field('rewards.book.product_id','Книга — WC продукт ID','number')+field('rewards.book.value','стойност €','number')+'</div>'+field('rewards.book.url','Книга — PDF линк (имейл при „Изпълнена“)','text')+'<div class="grid">'+field('rewards.cosm.product_id','Сет — WC продукт ID','number')+field('rewards.cosm.value','стойност €','number')+'</div><div class="grid">'+field('rewards.cosm.limit','Сет — брой комплекти за €1','number')+field('rewards.cosm.bar_from','брояч „Първите N са резервирани“ — от дата (YYYY-MM-DD; празно = винаги)','text',{ph:'2026-10-23'})+'</div><h3>Икони</h3><div class="grid3">'+Object.keys(cfg.rewards.icons).map(function(k){return field('rewards.icons.'+k,k,'text')}).join('')+'</div><h3>Снимки на наградите</h3><div class="hint">Attachment ID или URL — в 🧰 Инструменти има избор от медийната библиотека.</div><div class="grid">'+Object.keys(cfg.gate.images).map(function(k){return field('gate.images.'+k,k,'text')}).join('')+'</div>'}
    if(mode==='checkout'){out+='<h3>💳 Плащане</h3>'+field('checkout.card_gateway','Gateway за „С карта“','select',{options:D.gateways})+field('checkout.cod_gateway','Gateway за „Наложен платеж“ (празно = без)','select',{options:Object.assign({'':'— без наложен платеж —'},D.gateways)})+field('checkout.block_coupons','Блокирай купоните в промо количката (партньорските минават)','check')+field('checkout.consent_default','☑ сетът е отметнат по подразбиране в чекаута','check')+'<h3>Скриване на темата</h3>'+field('theme.hide','скрий елементите по селектори','check')+'<div class="f"><label>селектори (един на ред)</label><textarea data-p="theme.selectors" data-t="text" rows="6">'+esc(cfg.theme.selectors)+'</textarea></div>'}
    if(mode==='tickets'){out+='<h3>🎟️ Билети</h3>'+field('tickets.portal_url','Портал (URL в QR кода; празно = промо страницата)','text')+'<div class="grid">'+field('tickets.partner_bonus','Бонус билети за стандартен партньорски код (кодът за играта носи своя брой)','number')+field('tickets.secret','Тайна за кодовете (празно = автоматична)','text')+'</div>'+field('tickets.email','имейл с кода при издаване','check')+'<div class="hint">Стикерът за пратката и QR-ът са в поръчката (meta box „🎁 ansa™ Промо“).</div>'}
    if(mode==='fit'){out+='<h3>🔗 FIT (препоръчани към основния)</h3><div class="hint">За всеки продукт: ключове със запетая.</div>';D.products.forEach(function(pr){out+='<div class="f"><label>'+esc(pr.name)+' ('+pr.key+')</label><input type="text" data-fit="'+pr.key+'" value="'+esc(((cfg.fit||{})[pr.key]||[]).join(', '))+'"></div>'});
      out+='<h3>❓ „С какво да ти помогнем?“</h3><div class="hint">проблем · подзаглавие · защо (резерва, ако продуктът е без описание) · продукт</div><div id="apProbs">';(cfg.problems||[]).forEach(function(x,i){out+='<div class="box"><input type="text" data-pb="'+i+'.t" value="'+esc(x.t)+'" placeholder="проблем"><input type="text" data-pb="'+i+'.sub" value="'+esc(x.sub)+'" placeholder="подзаглавие" style="margin-top:4px"><input type="text" data-pb="'+i+'.why" value="'+esc(x.why)+'" placeholder="защо този продукт" style="margin-top:4px"><div class="pr" style="margin-top:4px"><select data-pb="'+i+'.key">'+D.products.map(function(pr){return '<option value="'+pr.key+'"'+(pr.key===x.key?' selected':'')+'>'+esc(pr.name)+'</option>'}).join('')+'</select><button class="sm" data-pbdel="'+i+'">✕</button></div></div>'});out+='</div><button class="sm" id="apProbAdd">+ проблем</button>'}
    out+='</div>';p.innerHTML=out;
    if(mode==='game'){var od=$('[data-p="_order_desktop"]',p),om=$('[data-p="_order_mobile"]',p);od.value=(cfg.order.desktop||[]).join(', ');om.value=(cfg.order.mobile||[]).join(', ');od.onchange=function(){set('order.desktop',od.value.split(',').map(function(x){return x.trim()}).filter(Boolean));postCfg()};om.onchange=function(){set('order.mobile',om.value.split(',').map(function(x){return x.trim()}).filter(Boolean));postCfg()};$$('[data-p^="_order"]',p).forEach(function(x){x.removeAttribute('data-p')})}
    bindFields(p,function(path){if(/^boxes\.\d+\.(ic|name)$/.test(path))renderCanvas()});
    $$('[data-rw]',p).forEach(function(c){c.onchange=function(){var i=+c.dataset.rw,b=cfg.boxes[i];b.rw=Object.keys(D.rewards).filter(function(r){var el=$('[data-rw="'+i+'"][value="'+r+'"]',p);return el&&el.checked});markDirty();postCfg()}});
    $$('[data-no]',p).forEach(function(c){c.onchange=function(){var i=+c.dataset.no,b=cfg.boxes[i];b.no=Object.keys(D.rewards).filter(function(r){var el=$('[data-no="'+i+'"][value="'+r+'"]',p);return el&&el.checked});markDirty();postCfg()}});
    $$('[data-fit]',p).forEach(function(el){el.onchange=function(){if(!cfg.fit)cfg.fit={};cfg.fit[el.dataset.fit]=el.value.split(',').map(function(x){return x.trim()}).filter(Boolean);markDirty();postCfg()}});
    $$('[data-pb]',p).forEach(function(el){el.onchange=function(){var a=el.dataset.pb.split('.');cfg.problems[+a[0]][a[1]]=el.value;markDirty();postCfg()}});
    $$('[data-pbdel]',p).forEach(function(b){b.onclick=function(){cfg.problems.splice(+b.dataset.pbdel,1);markDirty();postCfg();renderInsp()}});
    var add=$('#apProbAdd');if(add)add.onclick=function(){cfg.problems=cfg.problems||[];cfg.problems.push({t:'',sub:'',why:'',key:D.products[0]?D.products[0].key:''});markDirty();renderInsp()}}

  /* ── 1.24.21: 📄 Страници — секциите на /info (how) и /kozmetika (li): ред (влачене / ↑ ↓), скрити на живо, снимка по секция ──
     Конфиг: cfg.pages[pg] = {order:[id…], off:[id…], images:{id: attachment id | URL}}. Снимката по подразбиране е слотът от имейл-попъпа (D.pages[pg].sections[id].img →
     D.gate_images). Канвасът получава pages в runtime-а (postCfg) и рендира наново; лентата над всяка секция в канваса праща {ansaPromo:'sec'} → secAct. */
  function pageEd(pg){var M=D.pages[pg],ids=Object.keys(M.sections);if(!cfg.pages||Array.isArray(cfg.pages))cfg.pages={};var P=cfg.pages[pg];if(!P||typeof P!=='object'||Array.isArray(P))P=cfg.pages[pg]={};
    var order=[];(Array.isArray(P.order)?P.order:[]).forEach(function(id){if(ids.indexOf(id)>-1&&order.indexOf(id)<0)order.push(id)});ids.forEach(function(id){if(order.indexOf(id)<0)order.push(id)});P.order=order;
    P.off=(Array.isArray(P.off)?P.off:[]).filter(function(id){return ids.indexOf(id)>-1});if(!P.images||typeof P.images!=='object'||Array.isArray(P.images))P.images={};
    Object.keys(P.images).forEach(function(id){if(!M.sections[id]||!M.sections[id].img||P.images[id]==='')delete P.images[id]});return P}
  /* своята снимка на секцията като URL: URL → както е; attachment id → току-що избраната (picked) или от сървъра след записа (state.pages_images); '' = няма/още не е резолвнат */
  function ownUrl(pg,id){var v=pageEd(pg).images[id];if(!v)return '';v=String(v);if(!/^\d+$/.test(v))return v;return picked[v]||(((state.pages_images||{})[pg]||{})[id])||''}
  function secUrl(pg,id){return ownUrl(pg,id)||(D.gate_images||{})[D.pages[pg].sections[id].img]||''}
  function pagesRuntime(){var o={};Object.keys(D.pages||{}).forEach(function(pg){var M=D.pages[pg],P=pageEd(pg),im={},lb={},ig={};Object.keys(M.sections).forEach(function(id){lb[id]=M.sections[id].l;ig[id]=M.sections[id].img;var u=ownUrl(pg,id);if(u)im[id]=u});o[pg]={order:P.order.slice(),off:P.off.slice(),images:im,labels:lb,img:ig}});return o}
  /* 1.24.22: петте козметични продукта (cfg.li.items — снимка id/URL + blur); URL-ите на записаните идват от state.li_items, на току-що избраните — от picked */
  function liItems(){if(!cfg.li||typeof cfg.li!=='object')cfg.li={};var a=Array.isArray(cfg.li.items)?cfg.li.items:[];while(a.length<5)a.push({img:'',blur:a.length>=3});a.length=5;a.forEach(function(it,i){if(!it||typeof it!=='object')a[i]={img:'',blur:i>=3}});cfg.li.items=a;return a}
  function liItemUrl(i){var v=String(liItems()[i].img||'');if(!v)return '';if(!/^\d+$/.test(v))return v;return picked[v]||(((state.li_items||[])[i]||{}).img)||''}
  function liItemsRuntime(){return liItems().map(function(it,i){return {img:liItemUrl(i),blur:!!it.blur}})}
  function setLiItem(i,f,v){liItems()[i][f]=v;markDirty();postCfg();renderRail();if(mode==='sections')renderInsp();setTimeout(function(){post({ansaPromo:'sec',id:'prods'})},250)}
  function pickLiImg(i){if(!window.wp||!wp.media){toast('Медийната библиотека не е заредена — презареди страницата или въведи URL/attachment ID.',true);return}
    var fr=wp.media({title:'Снимка на козметичен продукт '+(i+1),library:{type:'image'},multiple:false,button:{text:'Използвай'}});
    fr.on('select',function(){var a=fr.state().get('selection').first().toJSON();var sz=a.sizes&&(a.sizes.medium_large||a.sizes.large||a.sizes.full);picked[String(a.id)]=sz?sz.url:a.url;setLiItem(i,'img',String(a.id))});fr.open()}
  function liItemsHtml(){var out='<div class="pic items"><div class="hint" style="width:100%">Петте продукта — снимка (📁 библиотеката или URL / attachment ID; празно = емоджи) и „замъглена“ за изненадите. Същото е и в Настройки → 💄 Козметичните продукти. Имената/описанията — от канваса (li.pr.N).</div>';
    liItems().forEach(function(it,i){var u=liItemUrl(i),t=copyVal('li.pr.'+(i+1)+'.t');out+='<div class="item"><div class="th'+(it.blur?' blur':'')+'">'+(u?'<img src="'+esc(u)+'" alt="">':'<span>'+(it.blur?'🎁':'🧴')+'</span>')+'</div><div class="pt"><b>'+(i+1)+' · '+esc(t||'(без заглавие — картата не се показва)')+'</b><div class="row"><button class="sm" data-lipick="'+i+'">🖼 Избери</button>'+(it.img?'<button class="sm" data-liclr="'+i+'">✕ махни</button>':'')+'<label class="cb"><input type="checkbox" data-liblur="'+i+'"'+(it.blur?' checked':'')+'> замъглена</label></div><input type="text" data-liurl="'+i+'" placeholder="URL / attachment ID" value="'+esc(it.img||'')+'"></div></div>'});
    return out+'</div>'}
  /* 1.24.23: текстовете на всяка секция в панела „Страници“ (ключовете по D.pages[pg].sections[id].keys — префикс с точка или точен ключ; „＋ допълнителен текст“ = {pg}.x.{id});
     клик върху текст в канваса отваря реда на секцията и фокусира полето, без да сменя панела. Празно поле = скрит текст (само how/li). */
  var openSecs={};
  function secKeys(pg,id){var S=D.pages[pg].sections[id],pre=(S&&S.keys)||[],out=[];Object.keys(KEYS).forEach(function(k){if(KEYS[k].g!==pg||k===pg+'.x.'+id)return;for(var i=0;i<pre.length;i++){var x=pre[i];if(x.slice(-1)==='.'?k.indexOf(x)===0:k===x){out.push(k);return}}});return out}
  function secOther(pg){var used={};Object.keys(D.pages[pg].sections).forEach(function(id){secKeys(pg,id).forEach(function(k){used[k]=1});used[pg+'.x.'+id]=1});return groupKeys(pg).filter(function(k){return !used[k]})}
  function secOfKey(pg,k){var ids=Object.keys(D.pages[pg].sections);for(var i=0;i<ids.length;i++){if(k===pg+'.x.'+ids[i]||secKeys(pg,ids[i]).indexOf(k)>-1)return ids[i]}return '_other'}
  function kid(k){return k.replace(/[^a-z0-9]/gi,'-')}
  function taStat(k){var u=utmKey();if(u)return hasOvr(k)?'<b style="color:#c2410c">✎ за '+esc(utmLabel())+'</b>'+(ovrLive(k)?' · публикуван':' · непубликуван'):'<span style="color:#6b5f58">общият текст</span>';
    if(isHidden(k))return '<b style="color:#b91c1c">🚫 скрит</b>'+(isLive(k)?'':' · непубликуван');if(changed(k))return isLive(k)?'<span style="color:#15803d">● публикуван</span>':'<span style="color:#e8722a">● непубликуван</span>';return unpublished(k)?'<span style="color:#e8722a">● дефолт · непубликуван</span>':''}
  function taHtml(k,label){var v=copyVal(k),u=utmKey();return '<div class="tk'+(isHidden(k)?' hid':'')+(changed(k)||hasOvr(k)?' ch':'')+'" data-tk="'+k+'"><div class="kh">'+(label?'<b>'+label+'</b> ':'')+'<code>'+esc(k)+'</code><span class="st" id="st-'+kid(k)+'">'+taStat(k)+'</span><span class="sp" style="flex:1"></span><button class="lnk" data-tr="'+k+'" title="'+(u?'ползвай общия текст':'върни дефолта')+'">⟲</button></div><textarea rows="1" data-ta="'+k+'" placeholder="'+(canEmpty(k)&&!u?'(празно = скрит)':'')+'">'+esc(v)+'</textarea><div class="err" id="er-'+kid(k)+'">'+esc(invalid[k]||'')+'</div></div>'}
  function secTextsHtml(pg,id,keys){var hid=keys.filter(isHidden).length,ch=keys.filter(function(k){return changed(k)||hasOvr(k)}).length,xk=id==='_other'?'':pg+'.x.'+id;
    return '<details class="txt"'+(openSecs[pg+'.'+id]?' open':'')+' data-det="'+id+'"><summary>✎ текстове <small>'+keys.length+(ch?' · <b>'+ch+' променени</b>':'')+(hid?' · <b style="color:#b91c1c">'+hid+' скрити</b>':'')+(xk&&copyVal(xk)!==''?' · <b style="color:#c2410c">＋ допълнителен</b>':'')+'</small></summary>'
      +'<div class="hint">Цък върху текст в канваса го отваря тук. <b>Празно поле = текстът се скрива</b> от страницата (⟲ го връща). Ctrl+S = запази.</div>'
      +keys.map(function(k){return taHtml(k)}).join('')+(xk?'<div class="xk">'+taHtml(xk,'＋ допълнителен текст')+'<div class="hint">В края на секцията. Всеки ред е блок: „# “ заглавие · „- “ точка · „> “ бележка · друго = абзац.</div></div>':'')+'</details>'}
  function bindTexts(p){
    $$('[data-ta]',p).forEach(function(ta){var k=ta.dataset.ta;function grow(){ta.style.height='auto';ta.style.height=Math.min(360,ta.scrollHeight+4)+'px'}grow();
      ta.oninput=function(){setCopy(k,ta.value);var st=$('#st-'+kid(k),p),er=$('#er-'+kid(k),p),w=ta.closest('.tk');if(st)st.innerHTML=taStat(k);if(er)er.textContent=invalid[k]||'';if(w){w.classList.toggle('hid',isHidden(k));w.classList.toggle('ch',changed(k)||hasOvr(k))}grow()};
      ta.onfocus=function(){post({ansaPromo:'mark',key:k,scroll:true})};
      ta.onkeydown=function(e){if((e.ctrlKey||e.metaKey)&&e.key==='s'){e.preventDefault();save()}}});
    $$('[data-tr]',p).forEach(function(b){b.onclick=function(){var k=b.dataset.tr,ta=$('[data-ta="'+k+'"]',p);if(!ta)return;ta.value=utmKey()?copyValGlobal(k):KEYS[k].d;ta.oninput();ta.focus()}});
    $$('details.txt',p).forEach(function(d){d.ontoggle=function(){openSecs[secPage+'.'+d.dataset.det]=d.open}})}
  function focusSecKey(pg,k){var id=secOfKey(pg,k);openSecs[pg+'.'+id]=true;var p=$('#apEdInsp'),det=$('details.txt[data-det="'+id+'"]',p);if(det)det.open=true;var ta=$('[data-ta="'+k+'"]',p);if(!ta){renderInsp();p=$('#apEdInsp');ta=$('[data-ta="'+k+'"]',p)}if(!ta)return false;
    $$('.tk.on',p).forEach(function(x){x.classList.remove('on')});ta.closest('.tk').classList.add('on');try{ta.scrollIntoView({block:'center',behavior:'smooth'})}catch(e){}ta.focus();return true}
  function openSections(pg){mode='sections';secPage=pg;sel=null;if(scen.screen!==pg){scen.screen=pg;stepDefaults(true);renderCanvas();postScenario()}renderRail();renderInsp()}
  function secChanged(pg,id){markDirty();postCfg();renderRail();if(mode==='sections')renderInsp();if(id)setTimeout(function(){post({ansaPromo:'sec',id:id})},250)}
  function moveSec(pg,id,dir){var P=pageEd(pg),i=P.order.indexOf(id),j=i+dir;if(i<0||j<0||j>=P.order.length)return;P.order.splice(i,1);P.order.splice(j,0,id);secChanged(pg,id)}
  function moveSecTo(pg,id,before){var P=pageEd(pg),i=P.order.indexOf(id);if(i<0||id===before)return;P.order.splice(i,1);var j=before?P.order.indexOf(before):P.order.length;if(j<0)j=P.order.length;P.order.splice(j,0,id);secChanged(pg,id)}
  function toggleSec(pg,id){var P=pageEd(pg),i=P.off.indexOf(id);if(i<0)P.off.push(id);else P.off.splice(i,1);secChanged(pg,id)}
  function setSecImg(pg,id,v){var P=pageEd(pg);v=String(v==null?'':v).trim();if(v==='')delete P.images[id];else P.images[id]=v;secChanged(pg,id)}
  function pickSecImg(pg,id){if(!window.wp||!wp.media){toast('Медийната библиотека не е заредена — презареди страницата или въведи URL/attachment ID в полето.',true);return}
    var fr=wp.media({title:'Снимка за секцията „'+(D.pages[pg].sections[id]||{}).l+'“',library:{type:'image'},multiple:false,button:{text:'Използвай'}});
    fr.on('select',function(){var a=fr.state().get('selection').first().toJSON();var sz=a.sizes&&(a.sizes.medium_large||a.sizes.large||a.sizes.full);picked[String(a.id)]=sz?sz.url:a.url;setSecImg(pg,id,String(a.id))});fr.open()}
  function secAct(pg,id,act){if(!D.pages[pg].sections[id])return;if(mode!=='sections'||secPage!==pg){mode='sections';secPage=pg;sel=null;renderRail();renderInsp()}
    if(act==='up')moveSec(pg,id,-1);else if(act==='down')moveSec(pg,id,1);else if(act==='off')toggleSec(pg,id);else if(act==='img')pickSecImg(pg,id)}
  function renderSections(p){var pg=secPage,M=D.pages[pg],P=pageEd(pg),GL=D.gate_slots||{};
    var out='<div class="ap-ed-eng ap-ed-secs"><h3>📄 '+esc(M.label)+'</h3><div class="hint">Редът на секциите на страницата: влачи реда или ↑ ↓. 👁 скрива секцията на живо (в канваса стои избледняла, за да редактираш текстовете ѝ). 🖼 сменя снимката на секцията — по подразбиране е снимката от имейл-попъпа (⚙️ Наградите · 🧰 Инструменти). Текстовете се редактират от канваса. Влиза с „Запази“ → „Публикувай“.</div><ol class="ap-ed-seclist" id="apSecList">';
    P.order.forEach(function(id,i){var s=M.sections[id];if(!s)return;var off=P.off.indexOf(id)>-1,own=!!P.images[id];
      out+='<li class="sec'+(off?' off':'')+'" draggable="true" data-id="'+id+'"><span class="drag" title="влачи, за да преместиш">⋮⋮</span><span class="i">'+(i+1)+'</span><span class="l">'+esc(s.l)+(off?' <em>скрита на живо</em>':'')+'</span><span class="bt"><button class="sm" data-mv="-1" data-id="'+id+'" title="нагоре"'+(i?'':' disabled')+'>↑</button><button class="sm" data-mv="1" data-id="'+id+'" title="надолу"'+(i<P.order.length-1?'':' disabled')+'>↓</button><button class="sm" data-off="'+id+'" title="'+(off?'покажи на живо':'скрий на живо')+'">'+(off?'👁 покажи':'👁‍🗨 скрий')+'</button></span>'
        +secTextsHtml(pg,id,secKeys(pg,id))
        +(pg==='li'&&id==='prods'?liItemsHtml():'')
        +(s.img?'<div class="pic"><img src="'+esc(secUrl(pg,id))+'" alt=""><div class="pt"><div class="hint">'+(own?(ownUrl(pg,id)?'<b style="color:#c2410c">своя снимка</b>':'<b style="color:#c2410c">своя снимка</b> (attachment '+esc(P.images[id])+' — вижда се след „Запази“)'):'снимката от имейл-попъпа („'+esc(GL[s.img]||s.img)+'“)')+'</div><div class="row"><button class="sm" data-pick="'+id+'">🖼 Избери от библиотеката</button>'+(own?'<button class="sm" data-clr="'+id+'">⟲ като в попъпа</button>':'')+'</div><input type="text" data-iurl="'+id+'" placeholder="или URL на снимка / attachment ID" value="'+esc(P.images[id]||'')+'"></div></div>':'')+'</li>'});
    var oth=secOther(pg);if(oth.length)out+='<li class="sec other" data-id="_other"><span class="drag" style="opacity:.3">⋮⋮</span><span class="i">·</span><span class="l">Други текстове <em>(заглавие/описание на страницата, залепеният бутон, седмични)</em></span><span class="bt"></span>'+secTextsHtml(pg,'_other',oth)+'</li>';
    out+='</ol><div class="row"><button class="sm" id="apSecReset">⟲ Ред по подразбиране · всички видими</button><span class="sp" style="flex:1"></span><button class="sm" id="apSecGo">👁 покажи в канваса</button></div></div>';p.innerHTML=out;
    bindTexts(p); /* 1.24.23 */
    $$('[data-mv]',p).forEach(function(b){b.onclick=function(){moveSec(pg,b.dataset.id,+b.dataset.mv)}});
    $$('[data-off]',p).forEach(function(b){b.onclick=function(){toggleSec(pg,b.dataset.off)}});
    $$('[data-pick]',p).forEach(function(b){b.onclick=function(){pickSecImg(pg,b.dataset.pick)}});
    $$('[data-clr]',p).forEach(function(b){b.onclick=function(){setSecImg(pg,b.dataset.clr,'')}});
    $$('[data-iurl]',p).forEach(function(el){el.onchange=function(){setSecImg(pg,el.dataset.iurl,el.value)}});
    $$('[data-lipick]',p).forEach(function(b){b.onclick=function(){pickLiImg(+b.dataset.lipick)}});$$('[data-liclr]',p).forEach(function(b){b.onclick=function(){setLiItem(+b.dataset.liclr,'img','')}});
    $$('[data-liurl]',p).forEach(function(el){el.onchange=function(){setLiItem(+el.dataset.liurl,'img',el.value.trim())}});$$('[data-liblur]',p).forEach(function(el){el.onchange=function(){setLiItem(+el.dataset.liblur,'blur',el.checked)}});
    $('#apSecReset').onclick=function(){P.order=Object.keys(M.sections);P.off=[];secChanged(pg,P.order[0])};
    $('#apSecGo').onclick=function(){openSections(pg);post({ansaPromo:'sec',id:P.order[0]})};
    /* влачене на редовете */
    var dragId=null;$$('.sec:not(.other)',p).forEach(function(li){
      li.addEventListener('dragstart',function(e){dragId=li.dataset.id;li.classList.add('dragging');try{e.dataTransfer.setData('text/plain',dragId);e.dataTransfer.effectAllowed='move'}catch(x){}});
      li.addEventListener('dragend',function(){dragId=null;$$('.sec',p).forEach(function(x){x.classList.remove('dragging','drag-over')})});
      li.addEventListener('dragover',function(e){if(!dragId||dragId===li.dataset.id)return;e.preventDefault();try{e.dataTransfer.dropEffect='move'}catch(x){}$$('.sec',p).forEach(function(x){x.classList.toggle('drag-over',x===li)})});
      li.addEventListener('dragleave',function(){li.classList.remove('drag-over')});
      li.addEventListener('drop',function(e){e.preventDefault();if(!dragId||dragId===li.dataset.id)return;var P2=pageEd(pg),from=P2.order.indexOf(dragId),to=P2.order.indexOf(li.dataset.id),id=dragId;dragId=null;
        if(from<0||to<0)return;P2.order.splice(from,1);P2.order.splice(to,0,id);secChanged(pg,id)})})}

  /* ── горна лента ── */
  function renderTop(){var t=$('#apEdTop'),nch=Object.keys(changedMap()).length,bad=state.checks.some(function(c){return c.level==='bad'});
    t.innerHTML='<span class="t">✏️ ansa™ Промо</span><span class="st'+(dirty?' dirty':'')+'">'+(dirty?'● незаписани промени':'чернова')+' · '+Object.keys(changedMap()).filter(function(k){return changed(k)}).length+' променени текста'+(ovrTotal()?' · <b style="color:#c2410c">✎ '+ovrTotal()+' по UTM</b>':'')+(function(){var u=unpublishedCount();return u?' · <b style="color:#e8722a">'+u+' непубликувани</b>':' · всичко е на живо'})()+' · '+(saving?'записва…':'запазена '+fmtTime(savedAt))+' · публикувано v'+state.version+(state.live?' · на живо':' · играта е изключена')+'</span><span class="sp"></span>'
      +'<button id="apSave"'+(dirty&&!saving?'':' disabled')+'>💾 Запази</button><button class="pri" id="apPub" title="'+(bad?'Doctor спира публикуването — виж 🩺':'')+'">🚀 Публикувай</button><button id="apDoc">🩺'+(bad?' ⛔':'')+'</button><button id="apDiscard">↶ Откажи черновата</button>';
    $('#apSave').onclick=function(){save()};$('#apPub').onclick=function(){publish(false)};$('#apDoc').onclick=function(){mode='doctor';sel=null;renderRail();renderInsp()};$('#apDiscard').onclick=discard}
  function doctorModal(blocking){var bad=state.checks.filter(function(c){return c.level==='bad'});var m=h('<div class="ap-ed-modal"><div class="m"><h2>🩺 Doctor спира публикуването</h2><ul class="ap-ed-doc">'+state.checks.map(function(c){return '<li class="'+c.level+'">'+({bad:'⛔',warn:'⚠',ok:'✓',info:'ℹ'}[c.level]||'')+' '+esc(c.msg)+'</li>'}).join('')+'</ul><p><button id="apMClose">Затвори</button> <button class="lnk" id="apMForce">Публикувай въпреки '+bad.length+' ⛔ (на твоя отговорност)</button></p></div></div>');document.body.appendChild(m);$('#apMClose').onclick=function(){m.remove()};$('#apMForce').onclick=function(){m.remove();if(confirm('Сигурна ли си? Страницата може да се счупи за клиентите.'))publish(true)}}
  function renderAll(){renderTop();renderRail();renderCanvas();renderInsp()}

  document.addEventListener('keydown',function(e){if((e.ctrlKey||e.metaKey)&&(e.key==='s'||e.key==='S')){e.preventDefault();save()}});
  window.addEventListener('beforeunload',function(e){if(dirty){e.preventDefault();e.returnValue=''}});
  cfg=fixCfg(cfg);lastSaveJSON=JSON.stringify(cfg);Object.keys(cfg.copy||{}).forEach(validate);
  renderAll();
  window.AnsaPromoEditorApp={get cfg(){return cfg},save:save,select:select,state:function(){return state},scenario:function(o){Object.assign(scen,o||{});renderCanvas();postScenario()}};
})();
