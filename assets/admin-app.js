/**
 * ansa™ Промо 1.16.0 — рамката на админа: тост за съобщението след действие, scroll-spy за подменюто на Настройки,
 * предупреждение за незаписана форма, Ctrl+S = „Запази“ на екрана. Без jQuery, без промяна на формите/action-ите.
 */
(function () {
  'use strict';
  var app = document.getElementById('ansa-promo-app'); if (!app) { return; }
  var A = window.AnsaPromoApp || {};

  function toast(msg, type) {
    var ex = document.querySelector('.ap-toast'); if (ex) ex.remove();
    var el = document.createElement('div'); el.className = 'ap-toast ap-toast--' + (type || 'success');
    el.textContent = (type === 'error' ? '✕ ' : type === 'info' ? 'ℹ ' : '✓ ') + msg; document.body.appendChild(el);
    requestAnimationFrame(function () { el.classList.add('ap-toast--show'); });
    setTimeout(function () { el.classList.remove('ap-toast--show'); setTimeout(function () { el.remove(); }, 300); }, 3200);
  }
  if (A.msg) { toast(A.msg, /греш|невалид|няма какво|провал/i.test(A.msg) ? 'error' : 'success'); }
  var m = document.querySelector('.ap-msg button'); if (m) m.onclick = function () { m.parentNode.remove(); };

  /* подменюто на Настройки: активната секция според скрола */
  var body = document.querySelector('.ap-body'), subs = Array.prototype.slice.call(document.querySelectorAll('.ap-nav__sub a[href^="#"]'));
  if (body && subs.length) {
    var secs = subs.map(function (a) { return document.getElementById(a.getAttribute('href').slice(1)); });
    var spy = function () {
      var top = body.scrollTop + 80, cur = 0;
      secs.forEach(function (s, i) { if (s && s.offsetTop <= top) cur = i; });
      subs.forEach(function (a, i) { a.classList.toggle('on', i === cur); });
    };
    body.addEventListener('scroll', spy); spy();
    subs.forEach(function (a) { a.addEventListener('click', function (e) { var s = document.getElementById(a.getAttribute('href').slice(1)); if (!s) return; e.preventDefault(); body.scrollTo({ top: s.offsetTop - 10, behavior: 'smooth' }); history.replaceState(null, '', a.getAttribute('href')); }); });
    if (location.hash) { var h = document.getElementById(location.hash.slice(1)); if (h) setTimeout(function () { body.scrollTop = h.offsetTop - 10; }, 50); }
  }

  /* главната форма на екрана: незаписани промени + Ctrl+S */
  var main = document.querySelector('form[data-ap-main]');
  if (main) {
    var dirty = false, btn = main.querySelector('[data-ap-save]');
    var mark = function () { if (dirty) return; dirty = true; if (btn) { btn.classList.add('ap-btn--dirty'); btn.textContent = btn.textContent.replace(/ ●$/, '') + ' ●'; } };
    main.addEventListener('input', mark); main.addEventListener('change', mark);
    main.addEventListener('submit', function () { dirty = false; });
    window.addEventListener('beforeunload', function (e) { if (!dirty) return; e.preventDefault(); e.returnValue = ''; });
    document.addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && String(e.key).toLowerCase() === 's') { e.preventDefault(); dirty = false; main.submit(); } });
    Array.prototype.slice.call(document.querySelectorAll('[data-ap-save-top]')).forEach(function (b) { b.addEventListener('click', function () { dirty = false; main.submit(); }); });
    var all = Array.prototype.slice.call(document.querySelectorAll('[data-ap-save]')); var mark0 = mark;
    mark = function () { if (dirty) return; mark0(); all.forEach(function (b) { if (b !== btn) { b.classList.add('ap-btn--dirty'); b.textContent = b.textContent.replace(/ ●$/, '') + ' ●'; } }); };
  }
  /* всички форми с confirm: родният confirm(), както досега — нищо друго не се пипа */
  window.AnsaPromoAppToast = toast;
})();
