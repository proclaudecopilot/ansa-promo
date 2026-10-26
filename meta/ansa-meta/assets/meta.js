/*! ansa™ Meta — Pixel в браузъра + сървърната половина през REST (общ event_id). Конфигурацията идва в window.ansaMeta. */
(function () {
  'use strict';
  var C = window.ansaMeta || {};
  if (!C.pixel && !C.rest) return;
  var sent = {};
  var STD = {}; (C.std || []).forEach(function (n) { STD[n] = 1; });
  var pixelOn = !!(C.browser && C.pixel);

  function log() { if (C.debug && window.console) { try { console.log.apply(console, ['[ansa-meta]'].concat([].slice.call(arguments))); } catch (e) {} } }
  function cookie(n) { var m = document.cookie.match('(?:^|; )' + n.replace(/[$()*+.?[\\\]^{|}]/g, '\\$&') + '=([^;]*)'); return m ? decodeURIComponent(m[1]) : ''; }
  function setCookie(n, v, days) {
    var host = location.hostname.replace(/^www\./, ''); var dom = /^\d+\.\d+\.\d+\.\d+$/.test(host) || host.indexOf('.') < 0 ? '' : '; domain=.' + host;
    document.cookie = n + '=' + encodeURIComponent(v) + '; expires=' + new Date(Date.now() + days * 864e5).toUTCString() + '; path=/' + dom + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
  }
  function uuid() {
    if (window.crypto && crypto.randomUUID) { try { return crypto.randomUUID(); } catch (e) {} }
    var b = new Array(16); if (window.crypto && crypto.getRandomValues) { crypto.getRandomValues(b = new Uint8Array(16)); } else { for (var i = 0; i < 16; i++) b[i] = Math.floor(Math.random() * 256); }
    b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
    var h = []; for (var j = 0; j < 16; j++) h.push((b[j] + 256).toString(16).slice(1));
    return h.slice(0, 4).join('') + '-' + h.slice(4, 6).join('') + '-' + h.slice(6, 8).join('') + '-' + h.slice(8, 10).join('') + '-' + h.slice(10).join('');
  }
  function consent() {
    if (!C.consent || !C.consent.cookie) return true;
    var v = cookie(C.consent.cookie); if (!v) return false;
    return !C.consent.value || v.toLowerCase().indexOf(String(C.consent.value).toLowerCase()) !== -1;
  }

  /* ── идентификатори: fbclid → _fbc, наш visitor id; _fbp резерва, ако Pixel-ът е блокиран ── */
  var q = (function () { try { return new URLSearchParams(location.search); } catch (e) { return { get: function () { return ''; } }; } })();
  var fbclid = q.get('fbclid') || '';
  if (fbclid && !/^fb\.\d\.\d+\./.test(cookie('_fbc'))) setCookie('_fbc', 'fb.1.' + Date.now() + '.' + fbclid, 90);
  if (!/^[a-f0-9]{32}$/.test(cookie('ansa_meta_vid'))) setCookie('ansa_meta_vid', uuid().replace(/-/g, ''), 730);
  function ensureFbp() { if (!cookie('_fbp')) setCookie('_fbp', 'fb.1.' + Date.now() + '.' + (Math.floor(Math.random() * 9e9) + 1e9), 90); }

  /* ── Pixel ── */
  function loadPixel() {
    if (window.fbq && window.fbq.__ansa) return;
    if (!window.fbq) {
      /* стандартният snippet на Meta */
      !function (f, b, e, v, n, t, s) { if (f.fbq) return; n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); }; if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = []; t = b.createElement(e); t.async = !0; t.src = v; s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s); }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
    }
    try { var st = window.fbq.getState && window.fbq.getState(); if (st && st.pixels && st.pixels.some(function (p) { return String(p.id) === String(C.pixel); })) { window.fbq.__ansa = 1; log('Pixel-ът вече е зареден от друг плъгин — не го init-ваме повторно'); return; } } catch (e) {}
    try { window.fbq('set', 'autoConfig', true, C.pixel); } catch (e) {}
    var adv = C.adv && typeof C.adv === 'object' && Object.keys(C.adv).length ? C.adv : undefined;
    try { adv ? window.fbq('init', C.pixel, adv) : window.fbq('init', C.pixel); window.fbq.__ansa = 1; } catch (e) {}
  }

  /* ── попълнените billing полета (чекаут) → пътуват със сървърната половина, за да се хешират там ── */
  var FIELDS = { billing_email: 'em', billing_phone: 'ph', billing_first_name: 'fn', billing_last_name: 'ln', billing_city: 'ct', billing_postcode: 'zp', billing_country: 'country', billing_state: 'st' };
  function typed() {
    var u = {}, any = false;
    Object.keys(FIELDS).forEach(function (id) { var el = document.getElementById(id) || document.querySelector('[name="' + id + '"]'); if (el && el.value && String(el.value).trim()) { u[FIELDS[id]] = String(el.value).trim(); any = true; } });
    return any ? u : null;
  }

  /* ── изпращане ── */
  function mirror(name, id, data, user) {
    if (!C.rest) return;
    if (name === 'PageView' && !C.mirrorPV) return;
    var body = { name: name, id: id, url: location.href, data: data || {}, fbclid: fbclid };
    if (user) body.user = user;
    var h = { 'Content-Type': 'application/json' }; if (C.nonce) h['X-WP-Nonce'] = C.nonce;
    try { fetch(C.rest, { method: 'POST', credentials: 'same-origin', keepalive: true, headers: h, body: JSON.stringify(body) }).then(function (r) { log('rest', name, r.status); }).catch(function () {}); } catch (e) {}
  }
  function fire(name, data, o) {
    o = o || {}; var id = o.id || uuid(); var key = name + ':' + (o.key || id);
    if (sent[key]) return id; sent[key] = 1;
    data = data && typeof data === 'object' && !Array.isArray(data) ? data : {};
    if (pixelOn && window.fbq) { try { window.fbq(o.custom || !STD[name] ? 'trackCustom' : 'track', name, data, { eventID: id }); } catch (e) {} }
    log('fire', name, id, data);
    if (o.mirror !== false) mirror(name, id, data, o.user || (C.checkout ? typed() : null));
    return id;
  }

  /* ── чекаут: AddPaymentInfo при избор на плащане / „Поръчай“; данните за количката идват от сървъра ── */
  function checkout() {
    if (!window.jQuery) return;
    var $ = window.jQuery, done = false, last = '';
    function payInfo() { if (done) return; done = true; fire('AddPaymentInfo', C.cart || {}, { user: typed() }); }
    $(document.body).on('change', 'input[name="payment_method"]', function () { if (this.value !== last) { last = this.value; payInfo(); } });
    $('form.checkout, form.woocommerce-checkout').on('checkout_place_order', function () { payInfo(); });
    $(document.body).on('click', '#place_order, button[name="woocommerce_checkout_place_order"], .wfacp-order-place-btn', payInfo);
    /* попълнен имейл + телефон → сървърът ги помни в сесията за следващите събития (без да чака „Поръчай“) */
    var t; $(document.body).on('change', '#billing_email, #billing_phone', function () { clearTimeout(t); t = setTimeout(function () { var u = typed(); if (u && (u.em || u.ph)) mirror('InitiateCheckout', uuid(), C.cart || {}, u); }, 600); });
  }

  function run() {
    if (pixelOn) loadPixel();
    setTimeout(ensureFbp, 1500);
    (C.events || []).forEach(function (e) { fire(e.name, e.data, { id: e.id, mirror: e.mirror !== false, key: e.id || 'page' }); });
    (C.flash || []).forEach(function (e) { fire(e.name, e.data, { id: e.id, mirror: false }); });
    if (window.jQuery) {
      window.jQuery(document.body).on('added_to_cart', function (ev, fragments) {
        var list = fragments && fragments.ansa_meta_events;
        if (list && list.length) list.forEach(function (e) { fire(e.name, e.data, { id: e.id, mirror: false }); });
      });
    }
    if (C.checkout) checkout();
  }

  /* публичен API: ansaMeta.track('Contact', {content_name: 'телефон'}) — Pixel + сървър */
  C.track = function (name, data, opts) { return fire(String(name), data, opts || {}); };
  C.fire = C.track;

  if (consent()) { run(); }
  else {
    log('чака съгласие', C.consent);
    var started = false, start = function () { if (!started && consent()) { started = true; run(); } };
    document.addEventListener('ansa-meta:consent', start);
    document.addEventListener('cmplz_fire_categories', start); document.addEventListener('cmplz_status_change', start); /* Complianz */
    var tries = 0, iv = setInterval(function () { start(); if (started || ++tries > 300) clearInterval(iv); }, 1000);
  }
})();
