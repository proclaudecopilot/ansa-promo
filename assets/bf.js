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
  var pimg = function (p) { return p.img || (ASSET + p.key + '.jpg'); };

  /* ── източникът на посетителя: адресът + бисквитките ── */
  var refParam = (q.get('ref') || '').replace(/[^a-z0-9_\-]/gi, '').toUpperCase();
  var eaParam = (q.get('ea') || '').replace(/[^a-z0-9]/gi, '');
  if (eaParam) setCookie('ansa_bf_ea', eaParam, 7);
  var utm = (q.get('utm_content') || '').toLowerCase().replace(/[^a-z0-9_\-]/g, '');
  var paidMarkers = /(^|&)(utm_medium=(paid|cpc|ppc|ads|paid_social|social)|fbclid=|gclid=|ttclid=|src=cold)/i.test(location.search.replace(/^\?/, ''));
  var isEmailLink = /(^|&)utm_medium=e?-?mail/i.test(location.search.replace(/^\?/, '')) || /utm_source=(email|newsletter|fka|funnelkit|sendgrid)/i.test(location.search);

  /* ── състояние ── */
  var KEY = 'ansa_bf_s_' + (R.version || 0);
  var S0 = function () { return { screen: '', cart: {}, gifts: {}, filter: 'all', email: '', lead: 0, affgift: '', coldExp: 0, ex: false, sheet: null, tab: 'desc', seatsN: 0, demoN: 1, _exp: {} }; };
  var S = S0();
  try { var raw = sessionStorage.getItem(KEY); if (raw) { var o = JSON.parse(raw); if (o && typeof o === 'object') S = Object.assign(S0(), o); } } catch (e) {}
  S.sheet = null; S._exp = S._exp || {};
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
  var maskPrice = function () { return R.aff && R.aff.mask ? R.aff.mask.price : 0; };
  var shipping = function () { return inWallet() ? R.ship.paid : (paidCount() >= R.ship.free_from ? 0 : R.ship.paid); };
  var walletDisc = function () { return inWallet() && voucherLive() && paidCount() ? Math.min(ctx.voucher.amount, subtotal()) : 0; };
  var total = function () { return Math.max(0, subtotal() - walletDisc() - affVoucher() + shipping()); };
  var savings = function () { var n = paidCount(); if (!n || inWallet()) return null; var g = giftCount(), avg = subtotal() / n; var gifts = giftValue() + Math.max(0, n - g) * avg; var mask = affGift() === 'mask' ? maskPrice() : 0; var tot = gifts + affVoucher() + mask; var full = subtotal() + gifts + mask; return { total: tot, pct: full > 0 ? Math.round(tot / full * 100) : 0, est: g < n }; };
  var saveLine = function (cls) { var sv = savings(); if (!sv || sv.total <= 0) return ''; return '<div class="savebox ' + (cls || '') + '"><span>' + (sv.est ? T('shop.save.est') : T('shop.save')) + '</span><b>' + fmt2(sv.total) + '</b><em>−' + sv.pct + ' %</em></div>'; };
  var trimGifts = function () { var over = giftCount() - paidCount(); if (over <= 0) return; Object.keys(S.gifts).reverse().forEach(function (k) { while (over > 0 && S.gifts[k] > 0) { S.gifts[k]--; over--; } if (!S.gifts[k]) delete S.gifts[k]; }); };
  var dates = R.dates || {};
  var V = function (extra) { return Object.assign({ voucher: fmtNum(R.cold.voucher), minutes: R.cold.minutes, early_date: dates.early_date, early_time: dates.early_time, public_date: dates.public_date, public_time: dates.public_time, partner: partner ? partner.name : '', aff_voucher: fmtNum(R.aff ? R.aff.voucher : 0), mask_price: fmtNum(maskPrice()) }, extra || {}); };
  function fmtNum(n) { return (Math.round(n * 100) / 100).toFixed(2).replace('.', ',').replace(/,00$/, ''); }

  /* ── toast ── */
  var toastT = null;
  var toast = function (msg) { var t = document.querySelector('.ansa-bf .toast'); if (!t) { t = document.createElement('div'); t.className = 'toast'; root.appendChild(t); } t.textContent = msg; clearTimeout(toastT); toastT = setTimeout(function () { t.remove(); }, 1800); };

  /* ── компоненти ── */
  var logoImg = '<img class="logo" src="' + esc(R.logo) + '" alt="ansa">';
  var stepsBar = function (cur) { var L = T('shop.steps').split('|'); return '<div class="stepsbar">' + L.map(function (l, i) { return '<div class="' + (i + 1 === cur ? 'on' : i + 1 < cur ? 'done' : '') + '">' + esc(l) + '</div>'; }).join('') + '</div>'; };
  var eqHtml = function (k) { var isX = k === 6 && !paidCount(); var one = k === 1; var bx = function (c) { return isX ? Array.apply(null, Array(5)).map(function (_, i) { return '<i class="bx' + c + '" style="opacity:' + (1 - i * 0.18).toFixed(2) + '"></i>'; }).join('') + '<span style="color:#fff;font-weight:900;line-height:20px;margin-left:2px">…</span>' : Array.apply(null, Array(Math.min(k, 5))).map(function () { return '<i class="bx' + c + '"></i>'; }).join('') + (k > 5 ? '<i class="bx' + c + '" style="opacity:.5"></i>' : ''); }; var lbl = isX ? 'X' : k; return '<div class="eq-side"><div class="bxs">' + bx('') + '</div><b>' + lbl + ' ' + OPK(one ? 1 : 2) + '</b><small>' + T('shop.eq.buy') + '</small></div><div class="eq-sign">=</div><div class="eq-side g"><div class="bxs">' + bx(' g') + '</div><b>+' + lbl + ' ' + GIFT(one ? 1 : 2) + '</b><small>' + T('shop.eq.get') + '</small></div>'; };
  var timerPill = function () { var h = hms(phaseEnd - now()); var t = h[0] + ':' + h[1] + ':' + h[2]; return isEarly() ? '<span class="tpill">' + T('shop.pill.early') + ' · <b data-timer>' + t + '</b></span>' : '<span class="tpill hot">' + T('shop.pill.public') + ' · <b data-timer>' + t + '</b></span>'; };
  var catCount = function (pool, k) { return pool.filter(function (p) { return k === 'all' || (k === 'fav' && p.fav) || p.cat === k; }).length; };
  var filtered = function (pool) { var f = S.filter; return pool.filter(function (p) { return f === 'all' || (f === 'fav' && p.fav) || p.cat === f; }); };
  var chips = function (pool) { var cats = [['all', T('shop.filter.all')], ['fav', T('shop.filter.fav')]].concat((R.cats || []).map(function (c) { return [c, c]; })).filter(function (c) { return catCount(pool, c[0]) > 0; }); if (!cats.some(function (c) { return c[0] === S.filter; })) S.filter = 'all'; return '<div class="selwrap"><label for="catSel">' + T('shop.filter.label') + '</label><select id="catSel">' + cats.map(function (c) { return '<option value="' + esc(c[0]) + '"' + (S.filter === c[0] ? ' selected' : '') + '>' + esc(c[1]) + ' (' + catCount(pool, c[0]) + ')</option>'; }).join('') + '</select><span class="selarr">▾</span></div>'; };
  var productTile = function (p, mode) {
    var qty = mode === 'gift' ? (S.gifts[p.key] || 0) : (S.cart[p.key] || 0); var free = mode === 'gift'; var full = giftCount() >= paidCount();
    var right = mode === 'gift'
      ? (qty ? '<div class="step"><button data-act="gift-" data-id="' + p.key + '" aria-label="минус">−</button><b>' + qty + '</b><button data-act="gift+" data-id="' + p.key + '"' + (full ? ' disabled' : '') + ' aria-label="плюс">+</button></div>' : '<button class="addb" data-act="gift+" data-id="' + p.key + '"' + (full ? ' disabled' : '') + '>' + T('gifts.pick') + '</button>')
      : (qty ? '<div class="step"><button data-act="cart-" data-id="' + p.key + '" aria-label="минус">−</button><b>' + qty + '</b><button data-act="cart+" data-id="' + p.key + '" aria-label="плюс">+</button></div>' : '<button class="addb" data-act="cart+" data-id="' + p.key + '">' + T('shop.add') + '</button>');
    return '<div class="tile' + (qty ? ' on' : '') + '"><img src="' + esc(pimg(p)) + '" alt="" loading="lazy"><div><div class="n">' + (p.isNew ? '<span class="nb">' + T('sheet.new') + '</span>' : '') + esc(p.name) + '<small>' + esc(p.sub) + '</small></div>'
      + '<div class="pr' + (free ? ' free' : '') + '">' + (free ? '<s>' + fmt(p.price) + '</s>' + T('gifts.free') : fmt(p.price)) + '</div>'
      + ((!free && qty && !inWallet()) ? '<div class="gl">' + T('shop.gl', { n: qty, podaruk: GIFT(qty) }) + '</div>' : '')
      + '<button class="more" data-act="sheet" data-id="' + p.key + '">' + T('shop.more') + '</button></div>' + right + '</div>';
  };
  var emailForm = function (cta) { return '<form id="signupForm"><div class="input' + (S.emailErr ? ' err' : '') + '">✉️ <input id="emailIn" type="email" placeholder="' + esc(T('cold.form.ph')) + '" value="' + esc(S.email) + '" autocomplete="email" required></div>' + (S.emailErr ? '<div style="font-size:12px;color:#dc2626;font-weight:700;margin:-4px 0 8px">' + T('err.email') + '</div>' : '') + '<button class="cta" type="submit">' + cta + '</button></form>'; };
  var bonusBtn = function (id, cur, ic, title, sub) { return '<button type="button" class="bo' + (cur === id ? ' on' : '') + '" data-act="bonus" data-id="' + id + '"><span class="ic">' + ic + '</span><span><b>' + title + '</b>' + (sub ? '<small>' + sub + '</small>' : '') + '</span><span class="r"></span></button>'; };
  var voucherCard = function (o) { var exp = o.locked ? S.coldExp : (ctx.voucher ? ctx.voucher.exp * 1000 : 0); var left = exp - now(); return '<div class="vch' + (o.locked ? '' : ' ok') + '"><img class="vlogo" src="' + esc(R.logo) + '" alt="ansa"><div class="stamp">' + (o.locked ? T('cold.vch.locked') : T('cold.vch.ok')) + '</div><div class="vt" style="margin-top:14px">' + T('cold.vch.title') + '</div><div class="va">' + fmtNum(R.cold.voucher) + '<small>€</small></div><div class="vs">' + (o.locked ? T('cold.vch.note', V()) : T('cold.vch.wallet')) + '</div><div class="vl"></div><div class="vb"><span class="vcode' + (o.locked ? ' lock' : '') + '">' + (o.locked ? 'ANSA' + fmtNum(R.cold.voucher) : esc(ctx.voucher ? ctx.voucher.code : '')) + '</span><span class="vtm">' + (o.locked ? T('cold.vch.exp') : T('cold.vch.valid')) + ' <b data-cd="' + (o.locked ? 'coldExp' : 'walletExp') + '">' + mmss(left) + '</b></span></div></div>'; };
  var howCard = function () { var ex = P.greens || cartList()[0]; var exPay = ex ? ex.price * 2 : 0; var g1 = P.kera || giftList()[0], g2 = P.berber || giftList()[1] || g1; return '<div class="k2">' + T('shop.how.h') + '</div><div class="howcard"><div class="hc-row"><div class="hc-col"><div class="hc-imgs"><span class="hc-im"><img src="' + esc(ex ? pimg(ex) : '') + '" alt=""></span><span class="hc-im"><img src="' + esc(ex ? pimg(ex) : '') + '" alt=""></span></div><b>' + T('shop.how.buy') + '</b><small>' + esc(ex ? ex.name : T('shop.how.buy.sub')) + '</small><em>' + fmt2(exPay) + '</em></div><div class="hc-arr"><span>→</span></div><div class="hc-col g"><div class="hc-imgs"><span class="hc-im g"><img src="' + esc(g1 ? pimg(g1) : '') + '" alt=""><i>🎁</i></span><span class="hc-im g"><img src="' + esc(g2 ? pimg(g2) : '') + '" alt=""><i>🎁</i></span></div><b>' + T('shop.how.get') + '</b><small>' + esc(g1 && g2 ? 'напр. ' + g1.name + ' + ' + g2.name : T('shop.how.get.sub')) + '</small><em>0 €</em></div></div><div class="hc-sum"><span>' + T('shop.how.sum') + '</span><b>' + T('shop.how.pay', { ex_pay: fmtNum(exPay) }) + '</b></div><div class="hc-steps"><div><i>1</i>' + T('shop.how.s1') + '</div><div><i>2</i>' + T('shop.how.s2') + '</div><div><i>3</i>' + T('shop.how.s3') + '</div></div></div>'; };

  /* ── екрани ── */
  function vCold() {
    if (!S.coldExp || S.coldExp < now() - 86400000) S.coldExp = now() + R.cold.landing_minutes * 60000;
    var live = S.coldExp > now(); var left = seats.max - seats.n;
    return '<div class="scroll"><div class="hero">'
      + '<div class="hrow">' + logoImg + '<span class="tpill' + (live ? ' hot' : '') + '">' + (live ? T('cold.timer.live') : T('cold.timer.over')) + ' · <b data-cd="coldExp">' + mmss(S.coldExp - now()) + '</b></span></div>'
      + '<div class="eyebrow">' + T('cold.eyebrow') + '</div><div class="h1" style="font-size:25px">' + T('cold.title') + '</div><p class="sub big">' + T('cold.sub', V()) + '</p>'
      + (seats.enabled ? '<div class="seats"><div class="seats-bar"><i style="width:' + Math.round(seats.n / seats.max * 100) + '%"></i></div><div class="seats-t">' + T('cold.seats', { seats: nfmt(seats.n), max: nfmt(seats.max), left: left }) + '</div></div>' : '')
      + '</div><div class="wrap">' + voucherCard({ locked: true })
      + '<div class="card"><div class="h2" style="font-size:16px;text-align:center">' + T('cold.form.h') + '</div>' + emailForm(T('cold.form.cta') + '<small>' + (live ? T('cold.vch.exp') + ' <span data-cd="coldExp">' + mmss(S.coldExp - now()) + '</span>' : T('cold.form.expired')) + '</small>') + '<div class="trust">' + T('cold.form.trust') + '</div></div>'
      + '<div class="footnote">' + T('cold.foot', V()) + '</div></div></div>';
  }
  function vWallet() {
    var live = voucherLive(); var n = paidCount(); var pool = cartList(); var list = filtered(pool); var vl = fmtNum(R.cold.voucher) + ' €';
    var foot = n
      ? '<div class="sline"><span>' + T('shop.foot.pay') + ' <b>' + fmt2(total()) + '</b></span><span class="ok">' + (walletDisc() ? T('wallet.foot.disc', { amount: fmtNum(walletDisc()) }) : (live ? '' : T('wallet.foot.expired'))) + '</span></div><button class="cta" data-act="wallet-go">' + T('wallet.foot.cta') + '</button>'
      : '<div class="sline"><span style="color:var(--tan)">' + T('wallet.foot.empty') + '</span><span class="ok">' + (live ? T('wallet.foot.voucher', V()) : '') + '</span></div><button class="cta sm" disabled>' + T('wallet.foot.add') + '</button>';
    return '<div class="scroll"><div class="hero">'
      + '<div class="hrow">' + logoImg + '<span class="tpill' + (live ? ' hot' : '') + '">' + T('wallet.pill', V()) + ' · <b data-cd="walletExp">' + mmss(ctx.voucher ? ctx.voucher.exp * 1000 - now() : 0) + '</b></span></div>'
      + '<div class="h1">' + (live ? T('wallet.title.ok') : T('wallet.title.over')) + '</div><p class="sub">' + (live ? T('wallet.sub.ok', V()) : T('wallet.sub.over', V())) + '</p></div>'
      + '<div class="wrap" style="padding-top:10px">' + (live ? voucherCard({ locked: false }) : '')
      + '<div class="strip live" style="justify-content:space-between"><span style="text-align:left"><b style="font-size:14px">' + T('wallet.date', V()) + '</b><small>' + T('wallet.date.sub', V()) + '</small></span><span class="pillg">' + T('wallet.date.pill') + '</span></div>'
      + '<div class="k2row"><span class="k2">' + T('wallet.pick') + '</span>' + chips(pool) + '</div>'
      + (list.length ? list.map(function (p) { return productTile(p, 'buy'); }).join('') : '') + '<div class="footnote">' + T('wallet.note') + '</div></div></div>'
      + '<div class="foot"><button class="morepill" id="morePill" data-act="scrolldown" hidden></button><div class="panelh">' + T('shop.panel.h') + '</div>' + foot + '</div>';
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
  function vShop() {
    var n = paidCount(); var pool = cartList(); var list = filtered(pool); var sv = savings();
    var foot = n
      ? '<div class="sline"><span>' + T('shop.foot.pay') + ' <b>' + fmt2(total()) + '</b>' + (affVoucher() ? ' <span style="font-weight:600;color:var(--tan)">(−' + fmtNum(affVoucher()) + ' €)</span>' : '') + '</span><span class="ok">' + T('shop.foot.r', { n: n, podaruk: GIFT(n), pct: sv ? sv.pct : 0 }) + '</span></div><button class="cta" data-act="screen" data-id="gifts">' + T('shop.foot.cta', { sel: n === 1 ? T('shop.sel1') : T('shop.seln', { n: n }) }) + '</button>'
      : '<div class="sline"><span style="color:var(--tan)">' + T('shop.foot.empty') + '</span><span class="ok">' + T('shop.foot.empty.r') + '</span></div><button class="cta sm" disabled>' + T('shop.foot.empty.cta') + '</button>';
    var affnote = (isPublic() && partner) ? '<div class="affnote">' + (ctx.email ? (affGift() === 'v5' ? T('shop.affnote.v', V()) : T('shop.affnote.m', V())) : T('shop.affnote.none', V()) + ' <button class="more" style="color:#fff" data-act="screen" data-id="aff">' + T('shop.affnote.cta') + '</button>') + '</div>' : '';
    return '<div class="scroll"><div class="hero">'
      + '<div class="hrow">' + logoImg + timerPill() + '</div>' + stepsBar(1) + affnote
      + '<div class="eq pop" id="eq" data-n="' + (n || S.demoN || 1) + '">' + eqHtml(n || S.demoN || 1) + '</div>' + (n ? saveLine('hero') : '<div class="eq-note" id="eqNote">' + T('shop.eq.note') + '</div>') + '</div>'
      + '<div class="wrap" style="padding-top:10px"><div class="k2row"><span class="k2">' + T('shop.step1') + ' <button class="info' + (S.ex ? ' on' : '') + '" data-act="ex" aria-expanded="' + !!S.ex + '">' + T('shop.example.btn') + '</button></span>' + chips(pool) + '</div>'
      + (S.ex ? '<div class="exbox">' + T('shop.example') + '</div>' : '')
      + (list.length ? list.map(function (p) { return productTile(p, 'buy'); }).join('') : '<div class="lead">—</div>')
      + howCard() + '<div class="footnote">' + T('shop.footnote') + '</div></div></div>'
      + '<div class="foot"><button class="morepill" id="morePill" data-act="scrolldown" hidden></button><div class="panelh">' + T('shop.panel.h') + '</div>' + foot + '</div>';
  }
  function vGifts() {
    var n = paidCount(), g = giftCount(), left = n - g; var news = giftList().filter(function (p) { return p.isNew; }), best = giftList().filter(function (p) { return !p.isNew; });
    var picked = []; Object.keys(S.gifts).forEach(function (k) { for (var i = 0; i < S.gifts[k]; i++) picked.push(k); });
    var slots = ''; for (var i = 0; i < n; i++) { var id = picked[i]; slots += id && P[id] ? '<div class="slot f"><img src="' + esc(pimg(P[id])) + '" alt=""></div>' : '<div class="slot' + (i === g ? ' next' : '') + '">' + (i + 1) + '</div>'; }
    return '<div class="hdr" style="flex-wrap:wrap"><div><b>' + T('gifts.h', { n: n, podaruk: GIFT(n) }) + '</b></div><button class="x" data-act="screen" data-id="shop" aria-label="затвори">✕</button>' + stepsBar(2) + '</div>'
      + '<div class="scroll" id="giftScroll"><div class="wrap" style="padding-top:8px"><div class="bar2"><i style="width:' + (n ? Math.round(g / n * 100) : 0) + '%"></i></div>'
      + '<div class="sline"><span>' + T('gifts.picked') + ' <b>' + g + ' ' + T('gifts.of') + ' ' + n + '</b></span><button class="more" data-act="screen" data-id="shop" style="text-decoration:none;color:var(--tan)">' + T('gifts.change') + '</button></div>'
      + (news.length ? '<div class="k2" style="margin-top:6px">' + T('gifts.new') + '</div>' + news.map(function (p) { return productTile(p, 'gift'); }).join('') : '')
      + '<div class="k2">' + T('gifts.best') + '</div>' + best.map(function (p) { return productTile(p, 'gift'); }).join('') + '<div style="height:8px"></div></div></div>'
      + '<div class="foot"><button class="morepill" id="morePill" data-act="scrolldown" hidden></button><div class="panelh">' + T('shop.panel.h') + '</div>' + saveLine('foot') + '<div class="sline"><span>' + T('shop.foot.pay') + ' <b>' + fmt2(total()) + '</b></span><span class="ok">' + T('gifts.foot.r', { n: n, podaruk: GIFT(n) }) + '</span></div><div class="slots">' + slots + '</div>'
      + (left > 0 ? '<button class="cta" disabled>' + T('gifts.cta.more', { n: left, podaruk: GIFT(left) }) + '</button>' : '<button class="cta" data-act="checkout">' + T('gifts.cta.go') + '</button>') + '</div>';
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

  /* ── кой екран ── */
  function decide() {
    var v = variant();
    if (phase === 'over') return 'over';
    if (phase === 'public') { if (v === 'aff' && !ctx.email && !S.email) return 'aff'; return 'shop'; }
    if (phase === 'early' && (earlyOk || R.draft)) return 'shop';
    if (v === 'aff') return (ctx.email || S.email) ? 'affwait' : 'aff';
    var coldOn = !!(R.cold && R.cold.enabled);
    if (coldOn && ctx.email && ctx.voucher) return 'wallet';           /* вече записана (ваучерът ѝ е в сесията) */
    if (coldOn && v === 'cold') return 'cold';                          /* от реклама или директно преди старта */
    return 'closed';                                                    /* от имейл без личния линк / в ранния достъп без токен */
  }
  function screenFor() { var base = decide(); var cur = S.screen; if (base === 'shop' && (cur === 'gifts' || cur === 'shop' || cur === 'aff')) return cur === 'aff' && (ctx.email || S.email) ? 'shop' : cur; if (base === 'wallet' && cur === 'wallet') return cur; if (base === 'cold' && cur === 'wallet' && ctx.voucher) return 'wallet'; return base; }

  /* ── рендер ── */
  var lastScreen = '';
  function render() {
    var sc = screenFor(); var keep = sc === lastScreen ? window.scrollY : 0; lastScreen = sc; S.screen = sc;
    var act = document.activeElement; var refocus = null, sel = null; if (act && root.contains(act) && act.id && (act.tagName === 'INPUT' || act.tagName === 'SELECT')) { refocus = act.id; try { sel = [act.selectionStart, act.selectionEnd]; } catch (e) {} }
    var html = sc === 'cold' ? vCold() : sc === 'wallet' ? vWallet() : sc === 'aff' ? vAff() : sc === 'affwait' ? vAffWait() : sc === 'shop' ? vShop() : sc === 'gifts' ? vGifts() : sc === 'over' ? vOver() : vClosed();
    if (S.sheet) html += vSheet();
    root.innerHTML = html;
    if (keep) window.scrollTo(0, keep); else window.scrollTo(0, 0);
    if (refocus) { var el = document.getElementById(refocus); if (el) { el.focus({ preventScroll: true }); try { if (sel && sel[0] != null) el.setSelectionRange(sel[0], sel[1]); } catch (e) {} } }
    var f = document.getElementById('signupForm'); if (f) { f.addEventListener('submit', onSignup); var inp = document.getElementById('emailIn'); if (inp) inp.addEventListener('input', function () { S.email = inp.value; }); }
    var cs = document.getElementById('catSel'); if (cs) cs.addEventListener('change', function () { S.filter = cs.value; render(); });
    document.body.style.overflow = S.sheet ? 'hidden' : '';
    updatePill(); save();
  }
  function updatePill() { var pill = document.getElementById('morePill'); if (!pill) return; var tiles = Array.prototype.slice.call(root.querySelectorAll('.scroll .tile')); var below = tiles.filter(function (t) { return t.getBoundingClientRect().top > window.innerHeight - 20; }).length; pill.hidden = below <= 0; if (below > 0) pill.textContent = T('shop.scroll', { n: below, produkt: plural(below, 'продукт', 'продукта') }); }
  window.addEventListener('scroll', updatePill, { passive: true });

  /* ── таймери ── */
  setInterval(function () {
    root.querySelectorAll('[data-cd]').forEach(function (el) { var k = el.getAttribute('data-cd'); var exp = k === 'coldExp' ? S.coldExp : (ctx.voucher ? ctx.voucher.exp * 1000 : 0); var ms = exp - now(); el.textContent = mmss(ms); if (ms <= 0 && !S._exp[k]) { S._exp[k] = true; if (k === 'walletExp' && ctx.voucher) ctx.voucher.live = false; render(); } });
    var t = root.querySelector('[data-timer]'); if (t) { var h = hms(phaseEnd - now()); t.textContent = h[0] + ':' + h[1] + ':' + h[2]; if (phaseEnd && phaseEnd - now() <= 0 && !S._exp.phase) { S._exp.phase = true; refresh(); } }
  }, 1000);
  setInterval(function () { var eq = document.getElementById('eq'); if (!eq || paidCount()) return; S.demoN = (S.demoN || 1) % 6 + 1; eq.classList.remove('pop'); void eq.offsetWidth; eq.innerHTML = eqHtml(S.demoN); eq.classList.add('pop'); }, 2200);

  /* ── ajax ── */
  function post(action, data) { var fd = new FormData(); fd.append('action', action); fd.append('nonce', R.nonce); if (R.draft) fd.append('draft', '1'); Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); }); return fetch(R.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }); }
  function applyCtx(c) { if (!c) return; ctx = Object.assign(ctx, c); if (c.voucher) { S._exp.walletExp = !c.voucher.live; } if (c.affgift) S.affgift = c.affgift; if (c.email && !S.email) S.email = c.email; if (c.lead) S.lead = c.lead; }
  function refresh() {
    var d = { view: '1', variant: variant() }; if (refParam) d.ref = refParam; if (eaParam) d.ea = eaParam;
    var url = R.ajax + '?action=ansa_bf_runtime' + (R.draft ? '&ansa_bf=draft' : '') + '&_=' + Date.now();
    var fd = new FormData(); Object.keys(d).forEach(function (k) { fd.append(k, d[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.success || !j.data) return;
      var rt = j.data; P = rt.products || P; ORDER = rt.order || ORDER; copy = rt.copy || copy; phase = rt.phase || phase; phaseEnd = (rt.phase_end || 0) * 1000; seats = rt.seats || seats; offset = (rt.now ? rt.now * 1000 : Date.now()) - Date.now(); R.cats = rt.cats || R.cats; R.aff = rt.aff || R.aff; R.cold = rt.cold || R.cold; R.ship = rt.ship || R.ship; R.dates = rt.dates || R.dates; dates = R.dates;
      if (rt.partner) { partner = rt.partner; setCookie('ansa_bf_aff', partner.code, (R.aff && R.aff.cookie_days) || 7); }
      earlyOk = !!rt.early_ok; applyCtx(rt.ctx); S._exp.phase = false; render();
    }).catch(function () {});
  }
  /* ansa Partner: кликът и ref-ът в неговата сесия (страницата е кеширана и неговият скрипт не е тук) */
  if (refParam) { try { var fd0 = new FormData(); fd0.append('action', 'ansa_partner_ref'); fd0.append('code', refParam); fetch('/?wc-ajax=ansa_partner_ref', { method: 'POST', body: fd0, credentials: 'same-origin' }).catch(function () {}); } catch (e) {} }

  function onSignup(e) {
    e.preventDefault(); var v = (document.getElementById('emailIn').value || '').trim();
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v)) { S.emailErr = true; S.email = v; render(); return; }
    S.email = v; S.emailErr = false; var kind = S.screen === 'aff' ? 'aff' : 'cold'; var btn = e.target.querySelector('button'); if (btn) btn.classList.add('busy');
    post('ansa_bf_lead', { email: v, kind: kind, bonus: S.affgift || ctx.affgift || 'v5', consent: '1', utm: utm, ref: partner ? partner.code : refParam, sid: '' }).then(function (j) {
      if (!j || !j.success) { var code = j && j.data && j.data.code; toast(code === 'rate' ? T('err.rate') : code === 'email' ? T('err.email') : code === 'live' ? T('err.live') : T('err.add')); render(); return; }
      applyCtx(j.data.ctx); if (j.data.lead) S.lead = j.data.lead; if (j.data.partner) partner = j.data.partner;
      if (kind === 'aff') { toast(T('aff.toast', V({ email: v }))); S.screen = isPublic() ? 'shop' : 'affwait'; }
      else { S._exp.walletExp = false; S.screen = 'wallet'; }
      render();
    }).catch(function () { toast(T('err.add')); render(); });
  }
  function checkout(mode) {
    var items = Object.keys(S.cart).map(function (k) { return { id: P[k] ? P[k].id : 0, q: S.cart[k] }; }).filter(function (x) { return x.id; });
    var gifts = mode === 'wallet' ? [] : Object.keys(S.gifts).map(function (k) { return { id: P[k] ? P[k].id : 0, q: S.gifts[k] }; }).filter(function (x) { return x.id; });
    if (!items.length) return; toast(T('toast.sending')); root.classList.add('busy');
    post('ansa_bf_add', { mode: mode, items: JSON.stringify(items), gifts: JSON.stringify(gifts), pay: 'card', email: S.email || ctx.email || '', variant: variant(), utm: utm, lead: S.lead || ctx.lead || 0, ref: partner ? partner.code : '', affgift: S.affgift || ctx.affgift || '' }).then(function (j) {
      if (!j || !j.success || !j.data || !j.data.checkoutUrl) { root.classList.remove('busy'); var d = j && j.data ? j.data : {}; toast(d.msg || T('err.add')); if (d.code === 'early') { earlyOk = false; render(); } return; }
      location.href = j.data.checkoutUrl;
    }).catch(function () { root.classList.remove('busy'); toast(T('err.add')); });
  }

  /* ── действия ── */
  root.addEventListener('click', function (e) {
    var b = e.target.closest('[data-act]'); if (!b || b.disabled) return; var act = b.getAttribute('data-act'), id = b.getAttribute('data-id');
    if (act === 'bonus') { S.affgift = id; }
    else if (act === 'ex') { S.ex = !S.ex; }
    else if (act === 'cart+') { S.cart[id] = (S.cart[id] || 0) + 1; if (S.sheet) S.sheet = null; var p = P[id]; if (p && !inWallet()) toast(T('toast.added', { name: p.name, n: paidCount(), podaruk: GIFT(paidCount()) })); }
    else if (act === 'cart-') { if (S.cart[id] > 1) S.cart[id]--; else delete S.cart[id]; trimGifts(); }
    else if (act === 'gift+') { if (giftCount() < paidCount()) { S.gifts[id] = (S.gifts[id] || 0) + 1; } S.sheet = null; if (giftCount() === paidCount()) toast(T('toast.gifts')); }
    else if (act === 'gift-') { if (S.gifts[id] > 1) S.gifts[id]--; else delete S.gifts[id]; }
    else if (act === 'giftonly') { S.sheet = null; if (paidCount()) { S.screen = 'gifts'; } else { toast(T('toast.giftonly')); } }
    else if (act === 'sheet') { S.sheet = id || null; S.tab = 'desc'; }
    else if (act === 'tab') { S.tab = id; }
    else if (act === 'screen') { if (id === 'gifts' && !paidCount()) return; S.screen = id; lastScreen = ''; }
    else if (act === 'checkout') { checkout('promo'); return; }
    else if (act === 'wallet-go') { checkout('wallet'); return; }
    else if (act === 'scrolldown') { window.scrollBy({ top: window.innerHeight * 0.8, behavior: 'smooth' }); return; }
    render();
  });

  render(); refresh();
})();
