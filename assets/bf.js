/* ansa™ Промо — Черен петък 2026 (1.45.0): фронтът на /cheren-petak-2026.
   Порт на емулатора (docs/black-friday-2026/emulator.html) към живия сайт: същите екрани (студена страница с ваучер → портфейл → бърза поръчка;
   партньорска страница → подарък по избор; стъпка 1 продукти → стъпка 2 подаръци → чекаутът на сайта), но вариантът идва от адреса/бисквитките,
   фазата и цените от сървъра (window.AnsaBfRuntime + ajax ansa_bf_runtime), а количката се прави от ansa_bf_add и браузърът отива в чекаута.
   Всички текстове са T('ключ') от копи-регистъра (BF\Copy). Нищо тук не определя цени — сървърът ги налага. */
(function () {
  'use strict';
  var R = window.AnsaBfRuntime; if (!R) return;
  var root = document.getElementById('ansaBf'); if (!root) return;
  var P = R.products || {}; var ORDER = R.order || Object.keys(P);
  var copy = R.copy || {};
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); };
  var T = function (k, v) { var t = copy[k] != null ? copy[k] : k; v = v || {}; return String(t).replace(/\{\{\s*([a-z0-9_]+)\s*\}\}/gi, function (m, n) { return v[n] != null ? v[n] : m; }); };
  var fmt = function (n) { n = Math.round(n * 100) / 100; var s = n.toFixed(2).replace('.', ','); return s.replace(/,00$/, '') + ' €'; };
  var fmt2 = function (n) { return (Math.round(n * 100) / 100).toFixed(2).replace('.', ',') + ' €'; };
  var nfmt = function (n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); };
  var plural = function (n, one, many) { return n === 1 ? one : many; };
  var OPK = function (n) { return plural(n, T('shop.opk1'), T('shop.opk')); }, GIFT = function (n) { return plural(n, T('shop.gift1'), T('shop.gift')); };
  var q = new URLSearchParams(location.search);
  var cookie = function (n) { var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + n + '=([^;]*)')); return m ? decodeURIComponent(m[1]) : ''; };
  var setCookie = function (n, v, days) { try { document.cookie = n + '=' + encodeURIComponent(v) + ';path=/;max-age=' + (days * 86400) + ';SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : ''); } catch (e) {} };
  var mmss = function (ms) { ms = Math.max(0, ms); var m = Math.floor(ms / 6e4), s = Math.floor(ms % 6e4 / 1e3); return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s; };
  var hms = function (ms) { ms = Math.max(0, ms); var h = Math.floor(ms / 36e5), m = Math.floor(ms % 36e5 / 6e4), s = Math.floor(ms % 6e4 / 1e3); return [h, m, s].map(function (x) { return (x < 10 ? '0' : '') + x; }); };
  var offset = (R.now ? R.now * 1000 : Date.now()) - Date.now(); /* сървърното време − клиентското */
  var now = function () { return Date.now() + offset; };
  var ASSET = R.assets || '';
  var mqDesk = window.matchMedia ? window.matchMedia('(min-width:1024px)') : null; var isDesk = function () { return !!(mqDesk && mqDesk.matches); };
  if (mqDesk && mqDesk.addEventListener) { mqDesk.addEventListener('change', function () { try { render(); } catch (e) {} }); }
  var pimg = function (p) { return p.img || (ASSET + p.key + '.jpg'); };

  /* ── източникът на посетителя: адресът + бисквитките ── */
  var refParam = (q.get('ref') || '').replace(/[^a-z0-9_\-]/gi, '').toUpperCase();
  var eaParam = (q.get('ea') || '').replace(/[^a-z0-9]/gi, '');
  var uParam = (q.get('u') || '').replace(/[^A-Za-z0-9_\-\.]/g, '');
  var phParam = R.draft ? (q.get('phase') || '').replace(/[^a-z]/g, '') : ''; /* 1.46.2: админският преглед на фаза (само чернова) */
  if (R.draft && q.get('reset')) { try { sessionStorage.removeItem('ansa_bf_s_' + (R.version || 0)); } catch (e) {} } /* 1.46.0: личният линк — подписаният имейл → ваучерът влиза сам */
  if (eaParam) setCookie('ansa_bf_ea', eaParam, 7);
  var utm = (q.get('utm_content') || '').toLowerCase().replace(/[^a-z0-9_\-]/g, '');
  /* 1.47.0: продуктов вход — ?p=berber (или utm_content=berber): един голям продукт с избор 1–5 опаковки = 1–5 подаръка; „Не искам този продукт. Виж други“ → пълният списък */
  var pParam = (q.get('p') || '').toLowerCase().replace(/[^a-z0-9_\-]/g, '');
  var focusKey = function () { if (S.focusOff) return ''; var k = pParam || utm; return (k && P[k] && !P[k].giftOnly && P[k].stock) ? k : ''; };
  var paidMarkers = /(^|&)(utm_medium=(paid|cpc|ppc|ads|paid_social|social)|fbclid=|gclid=|ttclid=|src=cold)/i.test(location.search.replace(/^\?/, ''));
  var isEmailLink = /(^|&)utm_medium=e?-?mail/i.test(location.search.replace(/^\?/, '')) || /utm_source=(email|newsletter|fka|funnelkit|sendgrid)/i.test(location.search);

  /* ── състояние ── */
  var KEY = 'ansa_bf_s_' + (R.version || 0);
  var S0 = function () { return { screen: '', cart: {}, gifts: {}, filter: 'all', email: '', lead: 0, affgift: '', coldExp: 0, ex: false, sheet: null, tab: 'desc', seatsN: 0, demoN: 1, _exp: {}, lucky: false, luckyShown: false, luckyOpen: false, claimErr: '', claimVal: '', coErr: '', emailErr: '', signed: false, focusOff: false }; };
  var S = S0();
  try { var raw = sessionStorage.getItem(KEY); if (raw) { var o = JSON.parse(raw); if (o && typeof o === 'object') S = Object.assign(S0(), o); } } catch (e) {}
  S.sheet = null; S.luckyOpen = false; S._exp = S._exp || {};
  var save = function () { try { sessionStorage.setItem(KEY, JSON.stringify(S)); } catch (e) {} };
  var ctx = { email: '', lead: 0, affgift: '', ref: '', early: false, voucher: null, mode: '' };
  var partner = R.partner || null; var earlyOk = !!R.early_ok; var phase = R.phase || 'prep'; var phaseEnd = (R.phase_end || 0) * 1000; var seats = R.seats || { enabled: false, n: 0, max: 0 };
  var variant = function () { if (partner && R.aff && R.aff.enabled) return 'aff'; if (earlyOk) return 'list'; if (paidMarkers || (!isEmailLink && phase === 'prep')) return 'cold'; return 'direct'; };
  var voucherLive = function () { return !!(ctx.voucher && ctx.voucher.live && ctx.voucher.exp * 1000 > now()); };

  /* ── помощници за количката ── */
  var byId = function (id) { return P[id] || null; };
  var cartList = function () { var a = []; ORDER.forEach(function (k) { if (P[k] && !P[k].giftOnly && P[k].stock) a.push(P[k]); }); return a; };
  var giftList = function () { var a = []; ORDER.forEach(function (k) { if (P[k] && P[k].stock) a.push(P[k]); }); return a; };
  var paidCount = function () { var n = 0; Object.keys(S.cart).forEach(function (k) { n += S.cart[k]; }); return n; };
  var giftCount = function () { var n = 0; Object.keys(S.gifts).forEach(function (k) { n += S.gifts[k]; }); return n; };
  var subtotal = function () { var s = 0; Object.keys(S.cart).forEach(function (k) { if (P[k]) s += P[k].price * S.cart[k]; }); return s; };
  var giftValue = function () { var s = 0; Object.keys(S.gifts).forEach(function (k) { if (P[k]) s += P[k].price * S.gifts[k]; }); return s; };
  var isEarly = function () { return phase === 'early'; }, isPublic = function () { return phase === 'public'; };
  var inWallet = function () { return S.screen === 'wallet'; };
  var affGift = function () { return (isPublic() || R.draft) && partner && ctx.email && (S.affgift || ctx.affgift) ? (S.affgift || ctx.affgift) : ''; };
  var affVoucher = function () { return affGift() === 'v5' && paidCount() ? Math.min(R.aff.voucher, subtotal()) : 0; };
  /* 1.46.0: ваучерът от имейла (възобновен / ВИП) или живият студен ваучер — в промо количката */
  var listDisc = function () { return !inWallet() && voucherLive() && paidCount() ? Math.min(ctx.voucher.amount, subtotal()) : 0; };
  var luckyOn = function () { return !!(R.lucky && R.lucky.enabled && (R.draft || (R.lucky.phases || []).indexOf(phase) >= 0)); };
  var maskPrice = function () { return R.aff && R.aff.mask ? R.aff.mask.price : 0; };
  var walletDisc = function () { return inWallet() && voucherLive() && paidCount() ? Math.min(ctx.voucher.amount, subtotal()) : 0; };
  /* 1.46.1: „Плащаш“ е продуктите минус ваучерите — БЕЗ доставка (човекът: „плащаш е грешно — пише 39,90, че е продуктът, а плащаш 32,45 и минус ваучер −10 €?“ = 39,90 − 10 + 2,55 доставка). Доставката се избира в чекаута. */
  var total = function () { return Math.max(0, subtotal() - walletDisc() - affVoucher() - listDisc()); };
  var savings = function () { var n = paidCount(); if (!n || inWallet()) return null; var g = giftCount(), avg = subtotal() / n; var gifts = giftValue() + Math.max(0, n - g) * avg; var mask = affGift() === 'mask' ? maskPrice() : 0; var tot = gifts + affVoucher() + mask; var full = subtotal() + gifts + mask; return { total: tot, pct: full > 0 ? Math.round(tot / full * 100) : 0, est: g < n }; };
  var saveLine = function (cls) { var sv = savings(); if (!sv || sv.total <= 0) return ''; return '<div class="savebox ' + (cls || '') + '"><span>' + (sv.est ? T('shop.save.est') : T('shop.save')) + '</span><b>' + fmt2(sv.total) + '</b><em>−' + sv.pct + ' %</em></div>'; };
  var trimGifts = function () { var over = giftCount() - paidCount(); if (over <= 0) return; Object.keys(S.gifts).reverse().forEach(function (k) { while (over > 0 && S.gifts[k] > 0) { S.gifts[k]--; over--; } if (!S.gifts[k]) delete S.gifts[k]; }); };
  var dates = R.dates || {};
  var V = function (extra) { return Object.assign({ voucher: fmtNum(R.cold.voucher), minutes: R.cold.minutes, early_date: dates.early_date, early_time: dates.early_time, public_date: dates.public_date, public_time: dates.public_time, partner: partner ? partner.name : '', aff_voucher: fmtNum(R.aff ? R.aff.voucher : 0), mask_price: fmtNum(maskPrice()) }, extra || {}); };
  function fmtNum(n) { return (Math.round(n * 100) / 100).toFixed(2).replace('.', ',').replace(/,00$/, ''); }

  /* ── toast ── */
  var toastT = null;
  /* 1.46.0: тостът е в body, не в корена — иначе render() веднага след toast() го триеше (грешен код, грешка при записване) */
  /* 1.46.4: тостът е долу — над залепения футър на телефона, или 24px от дъното на десктоп — никога върху заглавието/панела */
  var placeToast = function () { var t = document.querySelector('body > .bf-toast'); if (!t) return; var f = root.querySelector('.foot'); var stuck = f && getComputedStyle(f).position === 'sticky' && f.getBoundingClientRect().bottom >= window.innerHeight - 2; t.style.bottom = (stuck ? Math.round(f.getBoundingClientRect().height) + 10 : 24) + 'px'; };
  /* 1.46.5: тостът в стила на ansa — бяла карта с Nunito, икона по вид (ok / подарък / грешка / ваучер / раздаване), центриран, изскача отдолу (човекът: „това съобщение е изключително грозно“) */
  var toast = function (msg, kind) {
    kind = kind || 'ok';
    var t = document.querySelector('body > .bf-toast'); if (!t) { t = document.createElement('div'); document.body.appendChild(t); }
    var ic = { ok: '✓', gift: '🎁', err: '!', lucky: '🍀', vch: '🎟' }[kind] || '✓';
    msg = String(msg || ''); if (msg.length > 42) { msg = msg.slice(0, 40).replace(/\s+\S*$/, '') + '…'; } /* 1.46.6: тостът е ЕДИН ред — дългите текстове са под полето/в картата, не в балон (човекът) */
    t.className = 'toast bf-toast tk-' + kind; t.innerHTML /* tk- : „vch“ съвпадаше с класа на ваучера-билет */ = '<span class="ti">' + ic + '</span><span class="tm">' + esc(msg) + '</span>';
    void t.offsetWidth; t.classList.add('in');
    placeToast(); clearTimeout(toastT); toastT = setTimeout(function () { t.classList.remove('in'); setTimeout(function () { if (!t.classList.contains('in')) t.remove(); }, 220); }, 2400);
  };

  /* ── компоненти ── */
  var logoImg = '<img class="logo" src="' + esc(R.logo) + '" alt="ansa">';
  var stepsBar = function (cur) { var L = T('shop.steps').split('|'); return '<div class="stepsbar">' + L.map(function (l, i) { return '<div class="' + (i + 1 === cur ? 'on' : i + 1 < cur ? 'done' : '') + '">' + esc(l) + '</div>'; }).join('') + '</div>'; };
  var eqHtml = function (k) { var isX = k === 6 && !paidCount(); var one = k === 1; var bx = function (c) { return isX ? Array.apply(null, Array(5)).map(function (_, i) { return '<i class="bx' + c + '" style="opacity:' + (1 - i * 0.18).toFixed(2) + '"></i>'; }).join('') + '<span style="color:#fff;font-weight:900;line-height:20px;margin-left:2px">…</span>' : Array.apply(null, Array(Math.min(k, 5))).map(function () { return '<i class="bx' + c + '"></i>'; }).join('') + (k > 5 ? '<i class="bx' + c + '" style="opacity:.5"></i>' : ''); }; var lbl = isX ? 'X' : k; return '<div class="eq-side"><div class="bxs">' + bx('') + '</div><b>' + lbl + ' ' + OPK(one ? 1 : 2) + '</b><small>' + T('shop.eq.buy') + '</small></div><div class="eq-sign">=</div><div class="eq-side g"><div class="bxs">' + bx(' g') + '</div><b>+' + lbl + ' ' + GIFT(one ? 1 : 2) + '</b><small>' + T('shop.eq.get') + '</small></div>'; };
  var timerPill = function () { var h = hms(phaseEnd - now()); var t = h[0] + ':' + h[1] + ':' + h[2]; return isEarly() ? '<span class="tpill">' + T('shop.pill.early') + ' · <b data-timer>' + t + '</b></span>' : '<span class="tpill hot">' + T('shop.pill.public') + ' · <b data-timer>' + t + '</b></span>'; };
  var catCount = function (pool, k) { return pool.filter(function (p) { return k === 'all' || (k === 'fav' && p.fav) || p.cat === k; }).length; };
  var filtered = function (pool) { var f = S.filter; return pool.filter(function (p) { return f === 'all' || (f === 'fav' && p.fav) || p.cat === f; }); };
  var chips = function (pool) { var cats = [['all', T('shop.filter.all')], ['fav', T('shop.filter.fav')]].concat((R.cats || []).map(function (c) { return [c, c]; })).filter(function (c) { return catCount(pool, c[0]) > 0; }); if (!cats.some(function (c) { return c[0] === S.filter; })) S.filter = 'all'; return '<div class="selwrap"><label for="catSel">' + T('shop.filter.label') + '</label><select id="catSel">' + cats.map(function (c) { return '<option value="' + esc(c[0]) + '"' + (S.filter === c[0] ? ' selected' : '') + '>' + esc(c[1]) + ' (' + catCount(pool, c[0]) + ')</option>'; }).join('') + '</select><span class="selarr">▾</span></div>'; };
  /* 1.46.3: лентата „носи ти +N подарък по избор“ е ВЪРХУ горния ръб на картата (не разширява съдържанието), а при промяна на бройката числото подскача (човекът) */
  var lastCounts = {};
  var productTile = function (p, mode) {
    var qty = mode === 'gift' ? (S.gifts[p.key] || 0) : (S.cart[p.key] || 0); var free = mode === 'gift'; var full = giftCount() >= paidCount();
    var right = mode === 'gift'
      ? (qty ? '<div class="step"><button data-act="gift-" data-id="' + p.key + '" aria-label="минус">−</button><b>' + qty + '</b><button data-act="gift+" data-id="' + p.key + '"' + (full ? ' disabled' : '') + ' aria-label="плюс">+</button></div>' : '<button class="addb" data-act="gift+" data-id="' + p.key + '"' + (full ? ' disabled' : '') + '>' + T('gifts.pick') + '</button>')
      : (qty ? '<div class="step"><button data-act="cart-" data-id="' + p.key + '" aria-label="минус">−</button><b>' + qty + '</b><button data-act="cart+" data-id="' + p.key + '" aria-label="плюс">+</button></div>' : '<button class="addb" data-act="cart+" data-id="' + p.key + '">' + T('shop.add') + '</button>');
    var band = (!free && qty && !inWallet()) ? '<div class="gband' + (lastCounts[p.key] !== qty ? ' pop' : '') + '">' + T('shop.gl', { n: '<b class="gn">+' + qty + '</b>', podaruk: GIFT(qty) }) + '</div>' : '';
    return '<div class="tile' + (qty ? ' on' : '') + (band ? ' banded' : '') + '">' + band + '<img src="' + esc(pimg(p)) + '" alt="" loading="lazy"><div><div class="n">' + (p.isNew ? '<span class="nb">' + T('sheet.new') + '</span>' : '') + esc(p.name) + '<small>' + esc(p.sub) + '</small></div>'
      + '<div class="pr' + (free ? ' free' : '') + '">' + (free ? '<s>' + fmt(p.price) + '</s><b class="g0">🎁 ' + T('gifts.free') + ' · 0 €</b>' : fmt(p.price)) + '</div>'
      + '<button class="more" data-act="sheet" data-id="' + p.key + '">' + T('shop.more') + '</button></div>' + right + '</div>';
  };
  var emailForm = function (cta) { return '<form id="signupForm"><div class="input' + (S.emailErr ? ' err' : '') + '">✉️ <input id="emailIn" type="email" placeholder="' + esc(T('cold.form.ph')) + '" value="' + esc(S.email) + '" autocomplete="email" required></div>' + (S.emailErr ? '<div class="ferr">' + esc(S.emailErr) + '</div>' : '') + '<button class="cta" type="submit">' + cta + '</button></form>'; };
  var bonusBtn = function (id, cur, ic, title, sub) { return '<button type="button" class="bo' + (cur === id ? ' on' : '') + '" data-act="bonus" data-id="' + id + '"><span class="ic">' + ic + '</span><span><b>' + title + '</b>' + (sub ? '<small>' + sub + '</small>' : '') + '</span><span class="r"></span></button>'; };
  var voucherCard = function (o) { var exp = o.locked ? S.coldExp : (ctx.voucher ? ctx.voucher.exp * 1000 : 0); var left = exp - now(); return '<div class="vch' + (o.locked ? '' : ' ok') + '"><img class="vlogo" src="' + esc(R.logo) + '" alt="ansa"><div class="stamp">' + (o.locked ? T('cold.vch.locked') : T('cold.vch.ok')) + '</div><div class="vt" style="margin-top:14px">' + T('cold.vch.title') + '</div><div class="va">' + fmtNum(R.cold.voucher) + '<small>€</small></div><div class="vs">' + (o.locked ? T('cold.vch.note', V()) : T('cold.vch.wallet')) + '</div><div class="vl"></div><div class="vb"><span class="vcode' + (o.locked ? ' lock' : '') + '">' + (o.locked ? 'ANSA' + fmtNum(R.cold.voucher) : esc(ctx.voucher ? ctx.voucher.code : '')) + '</span><span class="vtm">' + (o.locked ? T('cold.vch.exp') : T('cold.vch.valid')) + ' <b data-cd="' + (o.locked ? 'coldExp' : 'walletExp') + '">' + mmss(left) + '</b></span></div></div>'; };
  var howCard = function () { var ex = P.greens || cartList()[0]; var exPay = ex ? ex.price * 2 : 0; var g1 = P.kera || giftList()[0], g2 = P.berber || giftList()[1] || g1; return '<div class="k2">' + T('shop.how.h') + '</div><div class="howcard"><div class="hc-row"><div class="hc-col"><div class="hc-imgs"><span class="hc-im"><img src="' + esc(ex ? pimg(ex) : '') + '" alt=""></span><span class="hc-im"><img src="' + esc(ex ? pimg(ex) : '') + '" alt=""></span></div><b>' + T('shop.how.buy') + '</b><small>' + esc(ex ? ex.name : T('shop.how.buy.sub')) + '</small><em>' + fmt2(exPay) + '</em></div><div class="hc-arr"><span>→</span></div><div class="hc-col g"><div class="hc-imgs"><span class="hc-im g"><img src="' + esc(g1 ? pimg(g1) : '') + '" alt=""><i>🎁</i></span><span class="hc-im g"><img src="' + esc(g2 ? pimg(g2) : '') + '" alt=""><i>🎁</i></span></div><b>' + T('shop.how.get') + '</b><small>' + esc(g1 && g2 ? 'напр. ' + g1.name + ' + ' + g2.name : T('shop.how.get.sub')) + '</small><em>0 €</em></div></div><div class="hc-sum"><span>' + T('shop.how.sum') + '</span><b>' + T('shop.how.pay', { ex_pay: fmtNum(exPay) }) + '</b></div><div class="hc-steps"><div><i>1</i>' + T('shop.how.s1') + '</div><div><i>2</i>' + T('shop.how.s2') + '</div><div><i>3</i>' + T('shop.how.s3') + '</div></div></div>'; };

  /* ── екрани ── */
  /* 1.45.5: големият таймер (човекът: „Таймерът е малко невидим… Трябва да е по-ясно колко време ти остава“) — тъмна лента с 34px цифри, лента, която се източва, червено + пулс под 5 минути, „изтече“ след края */
  function timerBar(kind) {
    var key = kind === 'cold' ? 'coldExp' : 'walletExp'; var exp = kind === 'cold' ? S.coldExp : (ctx.voucher ? ctx.voucher.exp * 1000 : 0);
    var total = (kind === 'cold' ? R.cold.landing_minutes : R.cold.minutes) * 60000; var ms = exp - now(); var live = ms > 0; var pct = live ? Math.max(0, Math.min(100, ms / total * 100)) : 0;
    return '<div class="tbar' + (live ? (ms < 300000 ? ' hot' : '') : ' done') + '" data-tbar="' + key + '" data-total="' + total + '" role="timer" aria-live="off">'
      + '<div class="tbar-l">' + (live ? T(kind + '.tbar', V()) + '<small>' + T(kind + '.tbar.sub', V()) + '</small>' : T(kind + '.tbar.over', V()) + '<small>' + T(kind + '.tbar.over.sub', V()) + '</small>') + '</div>'
      + '<div class="tbar-t"><b data-cd="' + key + '">' + mmss(ms) + '</b>' + (live ? '<small>' + T('cold.tbar.unit') + '</small>' : '') + '</div>'
      + '<div class="tbar-bar"><i style="width:' + pct.toFixed(1) + '%"></i></div></div>';
  }
  function vCold() {
    if (!S.coldExp || S.coldExp < now() - 86400000) S.coldExp = now() + R.cold.landing_minutes * 60000;
    var live = S.coldExp > now(); var left = seats.max - seats.n;
    return '<div class="scroll"><div class="hero">'
      + '<div class="hrow">' + logoImg + '<span class="tpill' + (live ? ' hot' : '') + '">' + (live ? T('cold.timer.live') : T('cold.timer.over')) + ' · <b data-cd="coldExp">' + mmss(S.coldExp - now()) + '</b></span></div>'
      + '<div class="eyebrow">' + T('cold.eyebrow') + '</div><div class="h1" style="font-size:25px">' + T('cold.title') + '</div><p class="sub big">' + T('cold.sub', V()) + '</p>'
      + (seats.enabled ? '<div class="seats"><div class="seats-bar"><i style="width:' + Math.round(seats.n / seats.max * 100) + '%"></i></div><div class="seats-t">' + T('cold.seats', { seats: nfmt(seats.n), max: nfmt(seats.max), left: left }) + '</div></div>' : '')
      + '</div><div class="wrap">' + timerBar('cold') + voucherCard({ locked: true })
      + '<div class="card"><div class="h2" style="font-size:16px;text-align:center">' + T('cold.form.h') + '</div>' + emailForm(T('cold.form.cta') + '<small>' + (live ? T('cold.vch.exp') + ' <span data-cd="coldExp">' + mmss(S.coldExp - now()) + '</span>' : T('cold.form.expired')) + '</small>') + '<div class="trust">' + T('cold.form.trust') + '</div></div>'
      + '<div class="footnote">' + T('cold.foot', V()) + '</div></div></div>';
  }
  /* 1.46.2: „касовата бележка“ (човекът: „РЕДОВНА ЦЕНА / МИНУС ВАУЧЕР / ЩЕ ПЛАТИШ трябва да е супер видимо… целият CTA да наблегне, че имаш 10 € ваучер и трябва да ги използваш ASAP“) */
  function receipt(disc, live, expiredTxt) {
    var reg = subtotal();
    return '<div class="rcpt"><div class="rrow"><span>' + T('rcpt.reg') + '</span><b>' + fmt2(reg) + '</b></div>'
      + (disc ? '<div class="rrow disc"><span>' + T('rcpt.vch') + '</span><b>−' + fmt2(disc) + '</b></div>' : (expiredTxt ? '<div class="rrow exp"><span>' + expiredTxt + '</span><b>—</b></div>' : ''))
      + '<div class="rrow total"><span>' + T('rcpt.pay') + '</span><b>' + fmt2(total()) + '</b></div></div>';
  }
  /* 1.46.9: панелът на десктоп е една карта — тъмна „шапка“ („Черен петък 2026 · Твоята поръчка с подаръци“ + уравнението / ваучерът) и светло тяло (бележката, слотовете, бутонът) (човекът: „тези две секции вдясно трябва да са по-красиво разположени… в един контейнер“) */
  /* 1.47.1: панелът е къс, докато количката е празна, и се разпъва до целия екран с първия продукт; скролва се САМО списъкът с продуктите (.pcart) — редът с подаръците (.pgifts) е закачен над „Плащаш + бутона“ (човекът) */
  function panel(cap, body, parts) {
    var tall = isDesk() && parts && paidCount() > 0;
    return '<div class="foot' + (tall ? ' tall' : '') + '"><button class="morepill" id="morePill" data-act="scrolldown" hidden></button>'
      + (isDesk() ? '<div class="pcap"><div class="pcap-h"><span class="gi">🎁</span><div><small>' + T('panel.eyebrow') + '</small><b>' + T('panel.title') + '</b></div></div>' + (cap || '') + '</div>' : '')
      + (isDesk() && parts ? '<div class="pcart">' + parts.cart + '</div><div class="pgifts">' + parts.gifts + '</div>' : '')
      + '<div class="pbody">' + body + '</div></div>';
  }
  /* 1.47.0: кутията на поръчката в панела (десктоп): продуктите влизат като ги натиснеш (с − N +), под тях местата за подаръци — избраните със снимка, останалите с „?“ */
  function cartBox(screen) {
    var n = paidCount(); var rows = '';
    Object.keys(S.cart).forEach(function (k) { var p = P[k]; if (!p || !S.cart[k]) return; rows += '<div class="crow"><img src="' + esc(pimg(p)) + '" alt=""><div class="cn">' + esc(p.name) + '<small>' + fmt(p.price) + ' × ' + S.cart[k] + '</small></div><div class="step sm"><button data-act="cart-" data-id="' + k + '" aria-label="минус">−</button><b>' + S.cart[k] + '</b><button data-act="cart+" data-id="' + k + '" aria-label="плюс">+</button></div></div>'; });
    if (!rows) rows = '<div class="cempty">' + T('panel.cart.empty') + '</div>';
    var picked = []; Object.keys(S.gifts).forEach(function (k) { for (var i = 0; i < S.gifts[k]; i++) picked.push(k); });
    var slots = ''; for (var i = 0; i < Math.max(n, 1); i++) { var id = picked[i]; slots += id && P[id] ? '<div class="gslot f" title="' + esc(P[id].name) + '"><img src="' + esc(pimg(P[id])) + '" alt=""></div>' : '<div class="gslot' + (n ? ' q' : ' empty') + '">?</div>'; }
    return { cart: '<div class="ph"><span>' + T('panel.cart.h') + '</span><b>' + (n ? n + ' ' + OPK(n) : '') + '</b></div><div class="crows">' + rows + '</div>',
      gifts: '<div class="ph gifts"><span>' + T('panel.gifts.h') + '</span><b>' + (n ? (giftCount() + ' / ' + n) : '') + '</b></div><div class="gslots">' + slots + '</div>'
      + (n ? '<div class="gnote">' + (screen === 'gifts' ? (giftCount() >= n ? T('panel.gifts.note.done') : T('panel.gifts.note.pick', { n: n - giftCount(), podaruk: GIFT(n - giftCount()) })) : T('panel.gifts.note', { n: n, podaruk: GIFT(n) })) + '</div>' : '<div class="gnote">' + T('panel.gifts.note.empty') + '</div>') };
  }
  function vWallet() {
    var live = voucherLive(); var n = paidCount(); var pool = cartList(); var list = filtered(pool); var vl = fmtNum(R.cold.voucher) + ' €';
    var aside = timerBar('wallet') + (live ? voucherCard({ locked: false }) : '')
      + '<div class="strip live" style="justify-content:space-between"><span style="text-align:left"><b style="font-size:14px">' + T('wallet.date', V()) + '</b><small>' + T('wallet.date.sub', V()) + '</small></span><span class="pillg">' + T('wallet.date.pill') + '</span></div>';
    var foot = n
      ? receipt(walletDisc(), live, live ? '' : T('wallet.r.exp'))
        + (walletDisc()
          ? '<button class="cta" data-act="wallet-go">' + T('wallet.cta.use', { total: fmt2(total()) }) + '<small>' + T('wallet.cta.sub', { reg: fmt2(subtotal()) }) + ' <b data-cd="walletExp">' + mmss(ctx.voucher ? ctx.voucher.exp * 1000 - now() : 0) + '</b></small></button>'
          : '<button class="cta" data-act="wallet-go">' + T('wallet.cta.plain', { total: fmt2(total()) }) + '<small>' + T('wallet.cta.plain.sub') + '</small></button>')
      : (live
          ? '<div class="rcpt empty"><div class="rrow disc"><span>' + T('rcpt.vch') + '</span><b>−' + fmt2(R.cold.voucher) + '</b></div><div class="rrow hint"><span>' + T('wallet.r.hint') + '</span></div></div><button class="cta sm" disabled>' + T('wallet.foot.add') + '</button>'
          : '<div class="rcpt empty"><div class="rrow exp"><span>' + T('wallet.r.exp') + '</span><b>—</b></div><div class="rrow hint"><span>' + T('wallet.r.hint.plain') + '</span></div></div><button class="cta sm" disabled>' + T('wallet.foot.add') + '</button>');
    return '<div class="scroll"><div class="hero">'
      + '<div class="hrow">' + logoImg + '<span class="tpill' + (live ? ' hot' : '') + '">' + T('wallet.pill', V()) + ' · <b data-cd="walletExp">' + mmss(ctx.voucher ? ctx.voucher.exp * 1000 - now() : 0) + '</b></span></div>'
      + '<div class="h1">' + (live ? T('wallet.title.ok') : T('wallet.title.over')) + '</div><p class="sub">' + (live ? T('wallet.sub.ok', V()) : T('wallet.sub.over', V())) + '</p></div>'
      /* ваучерът + датата: на телефона под тъмната секция, на десктоп (1.45.3) вдясно над панела „Твоята поръчка“ (човекът: „Премести ваучера вдясно на десктоп, над «Твоята поръчка», за да може да е ясно“) */
      + '<div class="wrap" style="padding-top:10px">' + (isDesk() ? '' : aside)
      + '<div class="k2row"><span class="k2">' + T('wallet.pick') + '</span>' + chips(pool) + '</div>'
      + (list.length ? list.map(function (p) { return productTile(p, 'buy'); }).join('') : '') + '<div class="footnote">' + T('wallet.note') + '</div></div></div>'
      + panel(aside, foot);
  }
  function vAff() {
    var open = isPublic() || R.draft; var cur = S.affgift || ctx.affgift || 'v5'; var news = giftList().filter(function (p) { return p.isNew; });
    return '<div class="scroll"><div class="hero">'
      + '<div class="hrow">' + logoImg + '<span class="tpill">' + T('aff.pill', V()) + '</span></div>'
      + '<div class="eyebrow">' + T('aff.eyebrow') + '</div><div class="h1" style="font-size:25px">' + T('aff.title', V()) + '</div><p class="sub">' + T('aff.sub', V()) + '</p></div>'
      + '<div class="wrap"><div class="card"><div class="h2">' + T('aff.choose', V()) + '</div><div class="bonus">'
      + bonusBtn('v5', cur, '🎟️', T('aff.v.title', V()), '') + (R.aff.mask ? bonusBtn('mask', cur, '<img src="' + esc(R.aff.mask.img) + '" alt="">', T('aff.m.title'), T('aff.m.sub', V())) : '') + '</div>'
      + emailForm(T('aff.cta') + '<small>' + T('aff.cta.sub') + '</small>') + '<div class="trust">' + T('aff.trust') + '</div></div>'
      + '<div class="k2">' + T('aff.how.h') + '</div><div class="steps"><div class="st o"><i>1=2</i><div>' + T('aff.how.1') + '<small>' + T('aff.how.1.sub') + '</small></div></div><div class="st o"><i>2=4</i><div>' + T('aff.how.2') + '</div></div><div class="st o"><i>🎁</i><div>' + T('aff.how.3', V()) + '<small>' + T('aff.how.3.sub', V()) + '</small></div></div></div>'
      + (news.length ? '<div class="k2">' + T('aff.new.h') + '</div><div class="gridp">' + news.map(function (p) { return '<div class="gp"><img src="' + esc(pimg(p)) + '" alt=""><b>' + esc(p.name) + '</b><small>' + esc(p.sub) + '</small></div>'; }).join('') + '</div>' : '')
      + '<div class="footnote">' + T('aff.foot', V()) + '</div></div></div>';
  }
  function vAffWait() {
    return '<div class="scroll"><div class="hero"><div class="hrow">' + logoImg + '<span class="tpill">' + T('aff.pill', V()) + '</span></div><div class="h1">' + T('aff.wait.title') + '</div><p class="sub">' + T('aff.wait.sub', V()) + '</p></div>'
      + '<div class="wrap"><div class="k2">' + T('gifts.new') + '</div><div class="gridp">' + giftList().filter(function (p) { return p.isNew; }).map(function (p) { return '<div class="gp"><img src="' + esc(pimg(p)) + '" alt=""><b>' + esc(p.name) + '</b><small>' + esc(p.sub) + '</small></div>'; }).join('') + '</div>'
      + '<div class="k2">' + T('gifts.best') + '</div>' + cartList().slice(0, 6).map(function (p) { return '<div class="tile"><img src="' + esc(pimg(p)) + '" alt=""><div><div class="n">' + esc(p.name) + '<small>' + esc(p.sub) + '</small></div><div class="pr">' + fmt(p.price) + '</div><button class="more" data-act="sheet" data-id="' + p.key + '">' + T('shop.more') + '</button></div></div>'; }).join('') + '</div></div>';
  }
  function vClosed() {
    return '<div class="scroll"><div class="hero"><div class="hrow">' + logoImg + '</div><div class="h1">' + T(isEarly() ? 'shop.closed.early.title' : 'shop.closed.title') + '</div><p class="sub">' + T(isEarly() ? 'shop.closed.early.sub' : 'shop.closed.sub', V()) + '</p></div><div class="wrap"><div class="k2">' + T('gifts.best') + '</div>' + cartList().map(function (p) { return '<div class="tile"><img src="' + esc(pimg(p)) + '" alt=""><div><div class="n">' + esc(p.name) + '<small>' + esc(p.sub) + '</small></div><div class="pr">' + fmt(p.price) + '</div><button class="more" data-act="sheet" data-id="' + p.key + '">' + T('shop.more') + '</button></div></div>'; }).join('') + '</div></div>';
  }
  function vOver() { return '<div class="scroll"><div class="over">' + logoImg.replace('class="logo"', 'class="logo" style="margin:0 auto 18px;filter:invert(1) brightness(.2)"') + '<div class="h1">' + T('over.title') + '</div><p class="lead">' + T('over.sub') + '</p><a class="cta dark" href="' + esc(R.shop) + '" style="display:inline-block;width:auto;text-decoration:none">' + T('over.cta') + '</a></div></div>'; }
  /* 1.46.0: ваучерът от имейла — зелена лента, ако е в сесията; иначе малка форма „Имаш ваучер от имейла? код → Активирай“ */
  function vchStrip() {
    if (inWallet()) return '';
    if (voucherLive()) return '<div class="vstrip ok">' + T('shop.vch.live', { amount: fmtNum(ctx.voucher.amount) }) + '</div>';
    return ''; /* 1.46.8: без поле „Имаш ваучер от имейла?“ — ваучерът влиза сам през личния линк от имейла (човекът: „Не трябва да има активация на нищо“) */
  }
  function onClaim(e) {
    e.preventDefault(); var inp = document.getElementById('claimIn'); var code = (inp.value || '').trim().toUpperCase(); if (!code) return; S.claimVal = code; var btn = e.target.querySelector('button'); if (btn) btn.classList.add('busy');
    post('ansa_bf_claim', { code: code }).then(function (j) {
      if (!j || !j.success) { var d = j && j.data ? j.data : {}; S.claimErr = d.msg || T('err.code'); render(); return; }
      S.claimErr = ''; S.claimVal = ''; applyCtx(j.data.ctx); toast(T('toast.claimed', { amount: fmtNum(ctx.voucher ? ctx.voucher.amount : 0) }), 'vch'); render();
    }).catch(function () { S.claimErr = T('err.add'); render(); });
  }
  /* 1.46.0: попъпът „Ти си късметлия“ — exit intent / дълго стоене на стъпка 2 */
  var luckyTimer = null;
  var checkingOut = false; /* 1.46.4: след „Продължи към поръчката“ нищо не изскача (човекът: „излезе чак тогава „Ти си късметлия“ — това е забранено“) */
  function luckyEligible() { return !checkingOut && luckyOn() && S.screen === 'gifts' && !S.lucky && !S.luckyShown && !S.luckyOpen && paidCount() > 0 && affGift() !== 'mask'; }
  function openLucky() { if (!luckyEligible()) return; S.luckyOpen = true; S.luckyShown = true; render(); }
  function luckyArm() { if (luckyTimer) { clearTimeout(luckyTimer); luckyTimer = null; } if (luckyEligible() && R.lucky.dwell > 0) { luckyTimer = setTimeout(openLucky, R.lucky.dwell * 1000); } }
  function vLucky() {
    var L = R.lucky || {};
    return '<button class="dim" data-act="lucky-no" aria-label="затвори"></button><div class="lucky" role="dialog" aria-modal="true" aria-label="' + esc(T('lucky.title')) + '"><div class="lucky-ic">🍀</div><div class="lucky-h">' + T('lucky.title') + '</div><p class="lucky-p">' + T('lucky.text') + '</p>'
      + '<div class="lucky-gift">' + (L.img ? '<img src="' + esc(L.img) + '" alt="">' : '') + '<div><b>' + T('lucky.gift') + '</b><small>' + T('lucky.sub') + '</small>' + (L.price ? '<span class="lucky-pr"><s>' + fmt(L.price) + '</s> <em>0 €</em></span>' : '') + '</div></div>'
      + '<button class="cta" data-act="lucky-yes">' + T('lucky.cta') + '</button><button class="more lucky-no" data-act="lucky-no">' + T('lucky.no') + '</button></div>';
  }
  function vFocus(p) {
    var qty = S.cart[p.key] || 0; var opts = '';
    for (var i = 1; i <= 5; i++) { opts += '<button class="fopt' + (qty === i ? ' on' : '') + '" data-act="focus-qty" data-id="' + i + '"><b>' + i + '</b><small>' + OPK(i) + '</small><em><span class="fl">+' + i + ' ' + GIFT(i) + '</span><span class="fs">+' + i + ' 🎁</span></em></button>'; }
    var total = qty ? p.price * qty : p.price;
    return '<div class="focus"><div class="focus-top"><img src="' + esc(pimg(p)) + '" alt=""><div><div class="focus-n">' + esc(p.name) + '</div><div class="focus-s">' + esc(p.sub) + '</div><div class="focus-pr">' + fmt(p.price) + ' <small>' + T('focus.unit') + '</small></div><button class="more" data-act="sheet" data-id="' + p.key + '">' + T('shop.more') + '</button></div></div>'
      + '<div class="focus-h">' + T('focus.h') + '</div><div class="fopts">' + opts + '</div>'
      + '<div class="focus-line">' + (qty ? T('focus.line', { n: qty, opk: OPK(qty), g: qty, podaruk: GIFT(qty), total: fmt2(total) }) : T('focus.line.empty')) + '</div>'
      + '<button class="more focus-other" data-act="focus-off">' + T('focus.other') + '</button></div>';
  }
  function vShop() {
    var n = paidCount(); var pool = cartList(); var list = filtered(pool); var sv = savings(); var fk = focusKey(); var fp = fk ? P[fk] : null;
    var foot = n
      ? ((affVoucher() + listDisc())
          ? '<div class="sline"><span class="ok" style="text-align:left">' + T('shop.foot.r', { n: n, podaruk: GIFT(n), pct: sv ? sv.pct : 0 }) + '</span></div>' + receipt(affVoucher() + listDisc(), true, '')
          : '<div class="sline"><span>' + T('shop.foot.pay') + ' <b>' + fmt2(total()) + '</b></span><span class="ok">' + T('shop.foot.r', { n: n, podaruk: GIFT(n), pct: sv ? sv.pct : 0 }) + '</span></div>')
        + '<button class="cta big" data-act="screen" data-id="gifts">' + T('shop.foot.cta', { sel: n === 1 ? T('shop.sel1') : T('shop.seln', { n: n }) }) + '<small>' + T('shop.foot.cta.sub', { n: n, podaruk: GIFT(n) }) + '</small></button>'
      : '<div class="sline"><span style="color:var(--tan)">' + T('shop.foot.empty') + '</span><span class="ok">' + T('shop.foot.empty.r') + '</span></div><button class="cta sm" disabled>' + T('shop.foot.empty.cta') + '</button>';
    var affnote = (isPublic() && partner) ? '<div class="affnote">' + (ctx.email ? (affGift() === 'v5' ? T('shop.affnote.v', V()) : T('shop.affnote.m', V())) : T('shop.affnote.none', V()) + ' <button class="more" style="color:#fff" data-act="screen" data-id="aff">' + T('shop.affnote.cta') + '</button>') + '</div>' : '';
    /* механиката (уравнението + „Спестяваш“ + бележката за партньора): на телефона в тъмната секция, на десктоп (1.45.4) вдясно над „Твоята поръчка“ — както ваучерът в портфейла */
    var mech = affnote + '<div class="eq pop" id="eq" data-n="' + (n || S.demoN || 1) + '">' + eqHtml(n || S.demoN || 1) + '</div>' + (n ? saveLine('hero') : '<div class="eq-note" id="eqNote">' + T('shop.eq.note') + '</div>');
    return '<div class="scroll"><div class="hero' + (isDesk() ? ' slim' : '') + '">'
      + '<div class="hrow">' + logoImg + timerPill() + '</div>' + stepsBar(1) + (isDesk() ? '' : mech) + '</div>'
      + '<div class="wrap" style="padding-top:10px">' + vchStrip()
      + (fp ? vFocus(fp) : '<div class="k2row"><span class="k2">' + T('shop.step1') + ' <button class="info' + (S.ex ? ' on' : '') + '" data-act="ex" aria-expanded="' + !!S.ex + '">' + T('shop.example.btn') + '</button></span>' + chips(pool) + '</div>'
      + (S.ex ? '<div class="exbox">' + T('shop.example') + '</div>' : '')
      + (list.length ? list.map(function (p) { return productTile(p, 'buy'); }).join('') : '<div class="lead">—</div>'))
      + howCard() + '<div class="footnote">' + T('shop.footnote') + '</div></div></div>'
      + panel(mech, foot, cartBox('shop'));
  }
  function vGifts() {
    var n = paidCount(), g = giftCount(), left = n - g; var news = giftList().filter(function (p) { return p.isNew; }), best = giftList().filter(function (p) { return !p.isNew; });
    var picked = []; Object.keys(S.gifts).forEach(function (k) { for (var i = 0; i < S.gifts[k]; i++) picked.push(k); });
    var slots = ''; for (var i = 0; i < n; i++) { var id = picked[i]; slots += id && P[id] ? '<div class="slot f"><img src="' + esc(pimg(P[id])) + '" alt=""></div>' : '<div class="slot' + (i === g ? ' next' : '') + '">' + (i + 1) + '</div>'; }
    /* 1.46.8: стъпка 2 е „подаръци навсякъде“ (човекът: „да нямаш капка съмнение, че са подаръци“) — зелен хедър с панделка, фон от кутийки, летящи подаръци, „🎁 0 € · подарък“ на всяка карта */
    var floats = ''; for (var f = 0; f < 9; f++) { floats += '<span style="left:' + (4 + f * 11) + '%;animation-delay:' + (-f * 1.7) + 's;animation-duration:' + (13 + (f % 4) * 3) + 's;font-size:' + (26 + (f % 3) * 10) + 'px">' + (f % 3 === 2 ? '🎀' : '🎁') + '</span>'; }
    return '<div class="hdr gifts" style="flex-wrap:wrap"><div><b>' + T('gifts.h', { n: n, podaruk: GIFT(n) }) + '</b><small>' + T('gifts.hsub', { n: n, podaruk: GIFT(n) }) + '</small></div><button class="back" data-act="screen" data-id="shop">' + T('gifts.back.short') + '</button>' + stepsBar(2) + '</div>'
      + '<div class="scroll gifts-bg" id="giftScroll"><div class="gfloat" aria-hidden="true">' + floats + '</div><div class="wrap" style="padding-top:8px"><div class="bar2"><i style="width:' + (n ? Math.round(g / n * 100) : 0) + '%"></i></div>'
      + '<div class="sline"><span>' + T('gifts.picked') + ' <b>' + g + ' ' + T('gifts.of') + ' ' + n + '</b></span></div>'
      + (news.length ? '<div class="k2" style="margin-top:6px">' + T('gifts.new') + '</div>' + news.map(function (p) { return productTile(p, 'gift'); }).join('') : '')
      + '<div class="k2">' + T('gifts.best') + '</div>' + best.map(function (p) { return productTile(p, 'gift'); }).join('') + '<div style="height:8px"></div></div></div>'
      + panel('<div class="eq">' + eqHtml(n) + '</div>' + saveLine('hero'), (isDesk() ? '' : saveLine('foot')) + ((affVoucher() + listDisc()) ? '<div class="sline"><span class="ok" style="text-align:left">' + T('gifts.foot.r', { n: n, podaruk: GIFT(n) }) + '</span></div>' + receipt(affVoucher() + listDisc(), true, '') : '<div class="sline"><span>' + T('shop.foot.pay') + ' <b>' + fmt2(total()) + '</b></span><span class="ok">' + T('gifts.foot.r', { n: n, podaruk: GIFT(n) }) + '</span></div>') + '<div class="slots mobile">' + slots + '</div>' + (S.lucky ? '<div class="luckyline">' + T('lucky.line') + '</div>' : '')
      + (left > 0 ? '<button class="cta big" disabled>' + T('gifts.cta.more', { n: left, podaruk: GIFT(left) }) + '</button>' : '<button class="cta big" data-act="checkout">' + T('gifts.cta.go') + '</button>')
      + '<button class="more backlink" data-act="screen" data-id="shop">' + T('gifts.back') + '</button>', cartBox('gifts'));
  }
  function vSheet() {
    var p = byId(S.sheet); if (!p) return '';
    var tabs = T('sheet.tabs').split('|'); var keys = ['desc', 'ing', 'how', 'who']; var avail = keys.filter(function (k, i) { return !!p[k] || k === 'desc'; });
    if (avail.indexOf(S.tab) < 0) S.tab = 'desc';
    var body = p[S.tab] ? '<p>' + p[S.tab] + '</p>' : '<p>' + T('sheet.empty') + '</p>';
    var inGifts = S.screen === 'gifts'; var canGift = giftCount() < paidCount(); var giftOnly = !!p.giftOnly; var buying = S.screen === 'shop' || S.screen === 'wallet';
    var foot = inGifts ? '<button class="cta" data-act="gift+" data-id="' + p.key + '"' + (canGift ? '' : ' disabled') + '>' + T('gifts.sheet.cta') + '</button>'
      : (giftOnly ? '<button class="cta" data-act="giftonly" data-id="' + p.key + '">' + (paidCount() ? T('sheet.giftonly.cta') : T('sheet.giftonly.first')) + '</button>'
      : (buying ? '<button class="cta" data-act="cart+" data-id="' + p.key + '">' + T('sheet.add', { price: fmtNum(p.price) }) + '</button>' + (inWallet() ? '' : '<div class="trust">' + T('sheet.add.sub') + '</div>') : ''));
    return '<button class="dim" data-act="sheet" data-id="" aria-label="затвори"></button><div class="sheet" role="dialog" aria-label="' + esc(p.name) + '"><div class="grab"></div><button class="shx" data-act="sheet" data-id="" aria-label="затвори">✕</button><div class="sbody">'
      + '<div class="ph"><img src="' + esc(pimg(p)) + '" alt=""><div><div class="n">' + esc(p.name) + ' ' + (p.isNew ? '<span class="pillo">' + T('sheet.new') + '</span>' : '') + '<small>' + esc(p.sub) + '</small></div><div class="pr">' + fmt(p.price) + ' <span style="font-size:12px;color:var(--tan);font-weight:700">' + T('sheet.unit') + '</span></div></div></div>'
      + (avail.length > 1 ? '<div class="tabs">' + avail.map(function (k) { return '<button class="' + (S.tab === k ? 'on' : '') + '" data-act="tab" data-id="' + k + '">' + esc(tabs[keys.indexOf(k)] || k) + '</button>'; }).join('') + '</div>' : '')
      + body + '<p style="color:var(--tan);font-size:12.5px">' + (inGifts ? T('gifts.sheet.note') : (giftOnly ? T('sheet.giftonly') : T('sheet.other'))) + '</p></div>' + (foot ? '<div class="sfoot">' + foot + '</div>' : '') + '</div>';
  }

  /* 1.46.2: лентата на админския преглед (само в чернова): фазите + входовете; всеки бутон започва на чисто */
  function vAdminBar() {
    var pv = R.preview || {}; var cur = phParam || ''; var base = location.pathname + '?ansa_bf=draft&reset=1';
    var ph = [['', 'по датите (' + (pv.real || phase) + ')'], ['prep', 'Подготовка'], ['early', 'Ранен достъп'], ['public', 'Публичен'], ['over', 'Приключил']];
    var ways = [['', 'директно'], ['src=cold&utm_medium=paid', 'реклама'], ['utm_source=email&utm_medium=email', 'имейл']]; if (pv.ref) ways.push(['ref=' + encodeURIComponent(pv.ref), 'партньор ' + pv.ref]);
    var way = refParam ? 'ref' : (paidMarkers ? 'src=cold' : (isEmailLink ? 'utm_source=email' : ''));
    var link = function (p, w, label, on) { var href = base + (p ? '&phase=' + p : '') + (w ? '&' + w : ''); return '<a class="' + (on ? 'on' : '') + '" href="' + esc(href) + '">' + esc(label) + '</a>'; };
    var curWay = refParam ? 'ref=' : (paidMarkers ? 'src=cold' : (isEmailLink ? 'utm_source=email' : ''));
    return '<div class="bfadm"><b>ЧЕРНОВА</b><span class="bfadm-g">' + ph.map(function (x) { return link(x[0], curWay && curWay !== 'ref=' ? ways.filter(function (w) { return w[0].indexOf(curWay) === 0; }).map(function (w) { return w[0]; })[0] || '' : (refParam && pv.ref ? 'ref=' + encodeURIComponent(pv.ref) : ''), x[1], x[0] === cur); }).join('') + '</span>'
      + '<span class="bfadm-g">' + ways.map(function (w) { return link(cur, w[0], w[1], w[0] === '' ? !curWay : w[0].indexOf(curWay || '§') === 0); }).join('') + '</span>'
      + '<a class="bfadm-x" href="' + esc(location.pathname) + '">✕ изход от прегледа</a></div>';
  }
  /* ── кой екран ── */
  var signed = function () { return !!(ctx.email || S.signed); };
  function decide() {
    var v = variant();
    if (phase === 'over') return 'over';
    /* 1.46.7: „записан“ = успешно записване (S.signed) или имейл в сесията — НЕ написаното в полето (S.email): грешен имейл местеше партньорската страница на „Подаръкът ти е запазен“ */
    if (phase === 'public') { if (v === 'aff' && !signed()) return 'aff'; return 'shop'; }
    if (phase === 'early' && (earlyOk || R.draft)) return 'shop';
    if (v === 'aff') return signed() ? 'affwait' : 'aff';
    var coldOn = !!(R.cold && R.cold.enabled);
    if (coldOn && ctx.email && ctx.voucher) return 'wallet';           /* вече записана (ваучерът ѝ е в сесията) */
    if (coldOn && v === 'cold') return 'cold';                          /* от реклама или директно преди старта */
    return 'closed';                                                    /* от имейл без личния линк / в ранния достъп без токен */
  }
  function screenFor() { var base = decide(); var cur = S.screen; if (base === 'shop' && (cur === 'gifts' || cur === 'shop' || cur === 'aff')) return cur === 'aff' && signed() ? 'shop' : cur; if (base === 'wallet' && cur === 'wallet') return cur; if (base === 'cold' && cur === 'wallet' && ctx.voucher) return 'wallet'; return base; }

  /* ── рендер ── */
  var lastScreen = '';
  function render() {
    var sc = screenFor(); var keep = sc === lastScreen ? window.scrollY : 0; lastScreen = sc; S.screen = sc;
    var act = document.activeElement; var refocus = null, sel = null; if (act && root.contains(act) && act.id && (act.tagName === 'INPUT' || act.tagName === 'SELECT')) { refocus = act.id; try { sel = [act.selectionStart, act.selectionEnd]; } catch (e) {} }
    var html = sc === 'cold' ? vCold() : sc === 'wallet' ? vWallet() : sc === 'aff' ? vAff() : sc === 'affwait' ? vAffWait() : sc === 'shop' ? vShop() : sc === 'gifts' ? vGifts() : sc === 'over' ? vOver() : vClosed();
    if (S.sheet) html += vSheet();
    if (S.luckyOpen) html += vLucky();
    if (R.draft) html += vAdminBar();
    if (S.coErr) { html = html.replace(/(<button class="cta[^"]*" data-act="(?:checkout|wallet-go)")/, '<div class="ferr co">' + esc(S.coErr) + '</div>$1'); }
    root.innerHTML = html; document.body.classList.toggle('bf-gifts', sc === 'gifts');
    if (keep) window.scrollTo(0, keep); else window.scrollTo(0, 0);
    if (refocus) { var el = document.getElementById(refocus); if (el) { el.focus({ preventScroll: true }); try { if (sel && sel[0] != null) el.setSelectionRange(sel[0], sel[1]); } catch (e) {} } }
    var f = document.getElementById('signupForm'); if (f) { f.addEventListener('submit', onSignup); var inp = document.getElementById('emailIn'); if (inp) inp.addEventListener('input', function () { S.email = inp.value; }); }
    var cs = document.getElementById('catSel'); if (cs) cs.addEventListener('change', function () { S.filter = cs.value; render(); });
    var cf = document.getElementById('claimForm'); if (cf) { cf.addEventListener('submit', onClaim); var ci = document.getElementById('claimIn'); if (ci) ci.addEventListener('input', function () { S.claimVal = ci.value; if (S.claimErr) { S.claimErr = ''; var e = cf.querySelector('.claim-err'); if (e) e.remove(); cf.classList.remove('err'); } }); }
    luckyArm();
    /* 1.46.2: заключването на скрола без отместване — компенсираме ширината на скролбара (човекът: „леко се размества всичко и мърда малко вдясно“) */
    var lock = !!(S.sheet || S.luckyOpen);
    if (lock) { if (document.body.style.overflow !== 'hidden') { var sbw = window.innerWidth - document.documentElement.clientWidth; document.body.style.paddingRight = sbw > 0 ? sbw + 'px' : ''; } document.body.style.overflow = 'hidden'; }
    else { document.body.style.overflow = ''; document.body.style.paddingRight = ''; }
    lastCounts = {}; Object.keys(S.cart).forEach(function (k) { lastCounts[k] = S.cart[k]; });
    updatePill(); placeToast(); fitPanel(); save();
  }
  function updatePill() { var pill = document.getElementById('morePill'); if (!pill) return; var tiles = Array.prototype.slice.call(root.querySelectorAll('.scroll .tile')); var below = tiles.filter(function (t) { return t.getBoundingClientRect().top > window.innerHeight - 20; }).length; pill.hidden = below <= 0; if (below > 0) pill.textContent = T('shop.scroll', { n: below, produkt: plural(below, 'продукт', 'продукта') }); }
  window.addEventListener('scroll', updatePill, { passive: true });

  /* ── таймери ── */
  setInterval(function () {
    root.querySelectorAll('[data-cd]').forEach(function (el) { var k = el.getAttribute('data-cd'); var exp = k === 'coldExp' ? S.coldExp : (ctx.voucher ? ctx.voucher.exp * 1000 : 0); var ms = exp - now(); el.textContent = mmss(ms); if (ms <= 0 && !S._exp[k]) { S._exp[k] = true; if (k === 'walletExp' && ctx.voucher) ctx.voucher.live = false; render(); } });
    root.querySelectorAll('[data-tbar]').forEach(function (el) { var k = el.getAttribute('data-tbar'); var exp = k === 'coldExp' ? S.coldExp : (ctx.voucher ? ctx.voucher.exp * 1000 : 0); var ms = exp - now(); var total = +el.getAttribute('data-total') || 1; var bar = el.querySelector('.tbar-bar i'); if (bar) { bar.style.width = Math.max(0, Math.min(100, ms / total * 100)).toFixed(1) + '%'; } el.classList.toggle('hot', ms > 0 && ms < 300000); });
    var t = root.querySelector('[data-timer]'); if (t) { var h = hms(phaseEnd - now()); t.textContent = h[0] + ':' + h[1] + ':' + h[2]; if (phaseEnd && phaseEnd - now() <= 0 && !S._exp.phase) { S._exp.phase = true; refresh(); } }
  }, 1000);
  setInterval(function () { var eq = document.getElementById('eq'); if (!eq || paidCount()) return; S.demoN = (S.demoN || 1) % 6 + 1; eq.classList.remove('pop'); void eq.offsetWidth; eq.innerHTML = eqHtml(S.demoN); eq.classList.add('pop'); }, 2200);

  /* ── ajax ── */
  /* 1.45.4: страницата е кеширана, а nonce-ът в нея живее 12–24 ч. → свеж nonce от ansa_bf_runtime при зареждане (refresh) и при грешка „nonce“ — един повторен опит със свеж, без човекът да разбере (както promo.js v1.1.0) */
  function freshNonce() { return fetch(R.ajax + '?action=ansa_bf_runtime&_=' + Date.now(), { method: 'POST', body: new FormData(), credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) { if (j && j.success && j.data && j.data.nonce) { R.nonce = j.data.nonce; } }).catch(function () {}); }
  function post(action, data, _retry) { var fd = new FormData(); fd.append('action', action); fd.append('nonce', R.nonce); if (R.draft) fd.append('draft', '1'); Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); }); return fetch(R.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) { if (!_retry && j && !j.success && j.data && j.data.code === 'nonce') { return freshNonce().then(function () { return post(action, data, true); }); } return j; }); }
  function applyCtx(c) { if (!c) return; ctx = Object.assign(ctx, c); if (c.email) S.signed = true; if (c.voucher) { S._exp.walletExp = !c.voucher.live; } if (c.affgift) S.affgift = c.affgift; if (c.email && !S.email) S.email = c.email; if (c.lead) S.lead = c.lead; }
  function refresh() {
    var d = { view: '1', variant: variant() }; if (refParam) d.ref = refParam; if (eaParam) d.ea = eaParam; if (uParam) d.u = uParam; if (phParam) d.phase = phParam;
    var url = R.ajax + '?action=ansa_bf_runtime' + (R.draft ? '&ansa_bf=draft' : '') + '&_=' + Date.now();
    var fd = new FormData(); Object.keys(d).forEach(function (k) { fd.append(k, d[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.success || !j.data) return;
      var rt = j.data; P = rt.products || P; ORDER = rt.order || ORDER; copy = rt.copy || copy; phase = rt.phase || phase; phaseEnd = (rt.phase_end || 0) * 1000; seats = rt.seats || seats; offset = (rt.now ? rt.now * 1000 : Date.now()) - Date.now(); R.cats = rt.cats || R.cats; R.aff = rt.aff || R.aff; R.cold = rt.cold || R.cold; R.ship = rt.ship || R.ship; R.dates = rt.dates || R.dates; dates = R.dates;
      if (rt.nonce) { R.nonce = rt.nonce; }
      if (rt.partner) { partner = rt.partner; setCookie('ansa_bf_aff', partner.code, (R.aff && R.aff.cookie_days) || 7); }
      earlyOk = !!rt.early_ok; applyCtx(rt.ctx); S._exp.phase = false; render();
    }).catch(function () {});
  }
  /* ansa Partner: кликът и ref-ът в неговата сесия (страницата е кеширана и неговият скрипт не е тук) */
  if (refParam) { try { var fd0 = new FormData(); fd0.append('action', 'ansa_partner_ref'); fd0.append('code', refParam); fetch('/?wc-ajax=ansa_partner_ref', { method: 'POST', body: fd0, credentials: 'same-origin' }).catch(function () {}); } catch (e) {} }

  function onSignup(e) {
    e.preventDefault(); var v = (document.getElementById('emailIn').value || '').trim();
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v)) { S.emailErr = T('err.email'); S.email = v; render(); return; }
    S.email = v; S.emailErr = false; var kind = S.screen === 'aff' ? 'aff' : 'cold'; var btn = e.target.querySelector('button'); if (btn) btn.classList.add('busy');
    post('ansa_bf_lead', { email: v, kind: kind, bonus: S.affgift || ctx.affgift || 'v5', consent: '1', utm: utm, ref: partner ? partner.code : refParam, sid: '' }).then(function (j) {
      if (!j || !j.success) { var code = j && j.data && j.data.code; S.emailErr = code === 'rate' ? T('err.rate') : code === 'email' ? T('err.email') : code === 'live' ? T('err.live') : T('err.add'); render(); return; }
      applyCtx(j.data.ctx); S.signed = true; S.emailErr = ''; if (j.data.lead) S.lead = j.data.lead; if (j.data.partner) partner = j.data.partner;
      if (kind === 'aff') { toast(T('aff.toast'), 'ok'); S.screen = isPublic() ? 'shop' : 'affwait'; }
      else { S._exp.walletExp = false; S.screen = 'wallet'; }
      render();
    }).catch(function () { S.emailErr = T('err.add'); render(); });
  }
  /* 1.46.4: ясно състояние на зареждане — бутонът се върти, екранът е покрит с „Правим количката ти…“ (човекът: „по никакъв начин не се разбира, че се чака зареждане“) */
  function setWait(on, msg) {
    var w = document.querySelector('body > .bfwait');
    if (!on) { if (w) w.remove(); root.classList.remove('busy'); root.querySelectorAll('.cta.wait').forEach(function (b) { b.classList.remove('wait'); b.disabled = false; }); return; }
    root.classList.add('busy'); root.querySelectorAll('[data-act="checkout"],[data-act="wallet-go"]').forEach(function (b) { b.classList.add('wait'); b.disabled = true; });
    if (!w) { w = document.createElement('div'); w.className = 'bfwait'; document.body.appendChild(w); }
    w.innerHTML = '<div class="bfwait-c"><div class="spin"></div><b>' + esc(msg || '') + '</b><small>' + T('wait.sub') + '</small></div>';
  }
  window.addEventListener('pageshow', function (e) { if (e.persisted) { checkingOut = false; setWait(false); } });
  function checkout(mode) {
    var items = Object.keys(S.cart).map(function (k) { return { id: P[k] ? P[k].id : 0, q: S.cart[k] }; }).filter(function (x) { return x.id; });
    var gifts = mode === 'wallet' ? [] : Object.keys(S.gifts).map(function (k) { return { id: P[k] ? P[k].id : 0, q: S.gifts[k] }; }).filter(function (x) { return x.id; });
    if (!items.length || checkingOut) return;
    S.coErr = ''; checkingOut = true; if (luckyTimer) { clearTimeout(luckyTimer); luckyTimer = null; } S.luckyOpen = false;
    setWait(true, mode === 'wallet' ? T('wait.wallet') : T('wait.promo'));
    post('ansa_bf_add', { mode: mode, items: JSON.stringify(items), gifts: JSON.stringify(gifts), pay: 'card', email: S.email || ctx.email || '', variant: variant(), utm: utm, lead: S.lead || ctx.lead || 0, ref: partner ? partner.code : '', affgift: S.affgift || ctx.affgift || '', lucky: S.lucky ? '1' : '0' }).then(function (j) {
      if (!j || !j.success || !j.data || !j.data.checkoutUrl) { setWait(false); checkingOut = false; var d = j && j.data ? j.data : {}; S.coErr = d.msg || T('err.add'); if (d.code === 'early') { earlyOk = false; } render(); return; }
      setWait(true, T('wait.go')); location.href = j.data.checkoutUrl;
    }).catch(function () { setWait(false); checkingOut = false; S.coErr = T('err.add'); render(); });
  }

  /* ── действия ── */
  root.addEventListener('click', function (e) {
    var b = e.target.closest('[data-act]'); if (!b || b.disabled) return; var act = b.getAttribute('data-act'), id = b.getAttribute('data-id');
    if (act === 'bonus') { S.affgift = id; }
    else if (act === 'ex') { S.ex = !S.ex; }
    else if (act === 'focus-qty') { var fk2 = focusKey(); if (fk2) { var want = +id; if (S.cart[fk2] === want) { delete S.cart[fk2]; } else { S.cart[fk2] = want; } trimGifts(); } }
    else if (act === 'focus-off') { S.focusOff = true; lastScreen = ''; }
    else if (act === 'cart+') { S.cart[id] = (S.cart[id] || 0) + 1; if (S.sheet) S.sheet = null; var p = P[id]; if (p && !inWallet()) toast(T('toast.added', { name: p.name, n: paidCount(), podaruk: GIFT(paidCount()) }), 'gift'); }
    else if (act === 'cart-') { if (S.cart[id] > 1) S.cart[id]--; else delete S.cart[id]; trimGifts(); }
    else if (act === 'gift+') { if (giftCount() < paidCount()) { S.gifts[id] = (S.gifts[id] || 0) + 1; } S.sheet = null; if (giftCount() === paidCount()) toast(T('toast.gifts')); }
    else if (act === 'gift-') { if (S.gifts[id] > 1) S.gifts[id]--; else delete S.gifts[id]; }
    else if (act === 'giftonly') { S.sheet = null; if (paidCount()) { S.screen = 'gifts'; } else { toast(T('toast.giftonly'), 'gift'); } }
    else if (act === 'sheet') { S.sheet = id || null; S.tab = 'desc'; }
    else if (act === 'tab') { S.tab = id; }
    else if (act === 'screen') { if (id === 'gifts' && !paidCount()) return; S.screen = id; lastScreen = ''; }
    else if (act === 'checkout') { checkout('promo'); return; }
    else if (act === 'lucky-yes') { S.lucky = true; S.luckyOpen = false; toast(T('lucky.toast'), 'lucky'); }
    else if (act === 'lucky-no') { S.luckyOpen = false; }
    else if (act === 'wallet-go') { checkout('wallet'); return; }
    else if (act === 'scrolldown') { window.scrollBy({ top: window.innerHeight * 0.8, behavior: 'smooth' }); return; }
    render();
  });

  /* 1.47.1: високият панел се събира точно в екрана — мери реалното си отстояние отгоре (полето на корена, лентата на прегледа…) и ползва същото отдолу (човекът: „трябва да има равни отстояния отгоре и отдолу, а не да трябва да го скролваш“) */
  function fitPanel() {
    var f = root.querySelector('.foot.tall'); if (!f || !isDesk()) return;
    var prev = f.style.position; f.style.position = 'static'; var gap = Math.max(12, Math.round(f.getBoundingClientRect().top + window.scrollY)); f.style.position = prev;
    f.style.setProperty('--pgap', gap + 'px');
  }
  window.addEventListener('resize', fitPanel);
  /* 1.46.4: Escape затваря листа / попъпа */
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && (S.sheet || S.luckyOpen)) { S.sheet = null; S.luckyOpen = false; render(); } });
  /* 1.46.0: exit intent — мишката излиза през горния ръб (десктоп) или връщане в таба (телефон) */
  document.addEventListener('mouseout', function (e) { if (R.lucky && R.lucky.exit && !e.relatedTarget && e.clientY <= 0) openLucky(); });
  document.addEventListener('visibilitychange', function () { if (R.lucky && R.lucky.exit && document.visibilityState === 'visible') openLucky(); });

  render(); refresh();
})();
