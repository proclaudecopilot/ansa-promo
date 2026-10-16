/**
 * ansa™ Промо 1.15.0 — 📦 Продукти: приложение в стила на ansa™ Shrine admin (Partner тема).
 *
 * Ляво: списък (търсене, филтри, drag & drop ред, точка вкл./изкл.). Дясно: редактор с табове
 * 🔗 WooCommerce · ✏️ Текстове · 💸 Отстъпки · ❓ Проблем & FIT · 🎨 Още, Doctor чипове, „В промото“, 💾 Запази (Ctrl+S), 🚀 Публикувай.
 * „＋ Добави продукт“ = търсачка в WC (ansa_promo_admin_search). Записът праща products/problems/fit → Config::save_draft и
 * UI-ят се зарежда от записаното (koliki модел — не дрейфва). Без jQuery.
 */
(function () {
  'use strict';
  var A = window.AnsaPromoProducts || {}; if (!A.data) { return; }
  var D = A.data; var S = { active: null, filter: 'all', q: '', tab: 'wc', dirty: false, busy: false };
  var root = document.getElementById('ansa-promo-products'); if (!root) { return; }

  /* ── helpers ── */
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function $(id) { return document.getElementById(id); }
  function $$(sel, el) { return Array.prototype.slice.call((el || root).querySelectorAll(sel)); }
  function money(n) { return '€ ' + (Math.round((Number(n) || 0) * 100) / 100).toFixed(2).replace('.', ','); }
  function pctf(n) { n = Number(n) || 0; return String(Math.round(n * 100) / 100).replace('.', ','); }
  function r2(n) { return Math.round(n * 100) / 100; }
  function prod(k) { for (var i = 0; i < D.products.length; i++) { if (D.products[i].key === k) return D.products[i]; } return null; }
  function idx(k) { for (var i = 0; i < D.products.length; i++) { if (D.products[i].key === k) return i; } return -1; }
  function active() { return S.active ? prod(S.active) : null; }
  function boxPct(p, b) { var v = p.pct && p.pct[b.id] != null ? Number(p.pct[b.id]) : 0; return v > 0 && v < 100 ? v : Number(b.pct) || 0; }
  function hasOverride(p, b) { var v = p.pct && p.pct[b.id] != null ? Number(p.pct[b.id]) : 0; return v > 0 && v < 100 && v !== Number(b.pct); }
  function problemOf(k) { for (var i = 0; i < D.problems.length; i++) { if (D.problems[i].key === k) return D.problems[i]; } return null; }
  function isLive(k) { var p = prod(k); return !!(p && p.enabled && p.wc && p.wc.ok); } /* показва се = включен + WC ред с цена (както Frontend::products) */
  function dispName(p) { return p.name || (p.wc && p.wc.texts && p.wc.texts.name) || (p.wc && p.wc.name) || p.key || 'нов продукт'; }
  function thumbOf(p) { return p.img_url || (p.wc && p.wc.img) || ''; }
  function translit(s) {
    var m = { а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ж: 'zh', з: 'z', и: 'i', й: 'y', к: 'k', л: 'l', м: 'm', н: 'n', о: 'o', п: 'p', р: 'r', с: 's', т: 't', у: 'u', ф: 'f', х: 'h', ц: 'ts', ч: 'ch', ш: 'sh', щ: 'sht', ъ: 'a', ь: 'y', ю: 'yu', я: 'ya' };
    return String(s || '').toLowerCase().replace(/[а-я]/g, function (c) { return m[c] || ''; }).replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }
  function uniqueKey(base) { base = base || 'produkt'; var k = base, n = 2; while (prod(k)) { k = base + '-' + n++; } return k; }

  /* ── toast / modal ── */
  function toast(msg, type) {
    var ex = document.querySelector('.ap-toast'); if (ex) ex.remove();
    var el = document.createElement('div'); el.className = 'ap-toast ap-toast--' + (type || 'success');
    el.textContent = (type === 'error' ? '✕ ' : type === 'info' ? 'ℹ ' : '✓ ') + msg; document.body.appendChild(el);
    requestAnimationFrame(function () { el.classList.add('ap-toast--show'); });
    setTimeout(function () { el.classList.remove('ap-toast--show'); setTimeout(function () { el.remove(); }, 300); }, type === 'error' ? 4200 : 2600);
  }
  function modal(html, wide) {
    closeModal();
    var ov = document.createElement('div'); ov.className = 'ap-modal-overlay'; ov.id = 'apModal';
    ov.innerHTML = '<div class="ap-modal' + (wide ? ' ap-modal--wide' : '') + '">' + html + '</div>';
    document.body.appendChild(ov); requestAnimationFrame(function () { ov.classList.add('ap-modal-overlay--show'); });
    ov.addEventListener('click', function (e) { if (e.target === ov) closeModal(); });
    return ov;
  }
  function closeModal() { var ov = $('apModal'); if (ov) ov.remove(); }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

  /* ── ajax ── */
  function ajax(action, data, cb, err) {
    var fd = new FormData(); fd.append('action', 'ansa_promo_products_' + action); fd.append('nonce', A.nonce);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(A.ajax, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).then(function (r) {
      if (r && r.success) { cb(r.data); } else { var d = (r && r.data) || {}; if (err) { err(d); } else { toast(d.msg || 'Грешка', 'error'); } }
    }).catch(function () { toast('Мрежова грешка — опитай пак.', 'error'); if (err) err({}); });
  }
  function wcSearch(q, cb) {
    fetch(A.ajax + '?action=ansa_promo_admin_search&nonce=' + encodeURIComponent(A.search_nonce) + '&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); }).then(function (r) { cb(r && r.success ? r.data : []); }).catch(function () { cb([]); });
  }

  /* ── dirty ── */
  function markDirty() { S.dirty = true; refreshDirty(); }
  function refreshDirty() { var b = $('apSave'); if (!b) return; b.classList.toggle('ap-btn--dirty', S.dirty); b.innerHTML = S.dirty ? '💾 Запази ●' : '💾 Запази <span class="ap-kbd">Ctrl+S</span>'; }
  window.addEventListener('beforeunload', function (e) { if (!S.dirty) return; e.preventDefault(); e.returnValue = ''; });
  document.addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && String(e.key).toLowerCase() === 's') { e.preventDefault(); save(); } });

  /* ══════════ SHELL ══════════ */
  function shell() {
    root.className = ''; root.innerHTML = ''
      + '<aside class="ap-side">'
      + (A.embedded ? '' : '<div class="ap-brand"><span class="dot"></span>ansa™ Промо<small>📦 Продукти · v' + esc(A.ver) + '</small></div>'
      + '<div class="ap-side__links"><a class="ap-side__lnk" href="' + esc(A.tools) + '">🧰 Настройки</a><a class="ap-side__lnk" href="' + esc(A.editor) + '">✏️ Редактор</a>' + (A.draft_url ? '<a class="ap-side__lnk" href="' + esc(A.draft_url) + '" target="_blank" rel="noopener">👁 Черновата ↗</a>' : '') + '</div>')
      + '<div class="ap-search"><input type="text" id="apQ" placeholder="Търси в списъка…" autocomplete="off"></div>'
      + '<div class="ap-fchips" id="apFchips"></div>'
      + '<div class="ap-side__label">Продукти <button type="button" class="ap-side__new" id="apAdd" title="Търси продукт в WooCommerce и го добави в промото">＋ Добави продукт</button></div>'
      + '<div class="ap-plist" id="apList"></div>'
      + '<div class="ap-plist__hint">влачи ⋮⋮ за да смениш реда на страницата</div>'
      + (A.embedded ? '' : '<a class="ap-side__foot" href="' + esc(A.back) + '">↩ Обратно към ansa™ Промо</a>')
      + '</aside><div class="ap-main" id="apMain"></div>';
    $('apQ').addEventListener('input', function () { S.q = this.value.trim().toLowerCase(); renderList(); });
    $('apAdd').addEventListener('click', addModal);
    renderList(); renderMain();
  }

  /* ══════════ LIST ══════════ */
  function issues(p) {
    var out = [];
    if (!p.wc || !p.wc.pid) out.push({ l: 'err', t: 'няма WC продукт', h: 'Не е свързан с продукт от WooCommerce — не се показва на страницата' });
    else if (!p.wc.ok) out.push({ l: 'err', t: p.wc.note || 'проблем с WC реда', h: 'WooCommerce редът не става: ' + (p.wc.note || '') });
    else if (!p.wc.stock) out.push({ l: 'warn', t: 'изчерпан', h: 'Не е наличен в WooCommerce — показва се, но не може да се поръча' });
    if (p.sku && p.wc && p.wc.sku_found === false) out.push({ l: 'warn', t: 'SKU не е намерен', h: 'SKU „' + p.sku + '“ го няма в WooCommerce — ползва се WC ID' });
    return out;
  }
  function filterChips() {
    var all = D.products.length, on = 0, off = 0, bad = 0;
    D.products.forEach(function (p) { if (p.enabled) on++; else off++; if (p.enabled && issues(p).some(function (i) { return i.l === 'err'; })) bad++; });
    var chips = [{ v: 'all', l: 'Всички · ' + all }, { v: 'on', l: '✓ В промото · ' + on }, { v: 'off', l: 'Изключени · ' + off }];
    if (bad) chips.push({ v: 'bad', l: '⚠ Проблем · ' + bad, w: true });
    $('apFchips').innerHTML = chips.map(function (c) { return '<div class="ap-fchip' + (c.w ? ' warn' : '') + (S.filter === c.v ? ' on' : '') + '" data-f="' + c.v + '">' + c.l + '</div>'; }).join('');
    $$('#apFchips .ap-fchip').forEach(function (c) { c.onclick = function () { S.filter = c.dataset.f; renderList(); }; });
  }
  function renderList() {
    var el = $('apList'); if (!el) return; var html = '';
    D.products.forEach(function (p) {
      var name = dispName(p), iss = issues(p), err = iss.some(function (i) { return i.l === 'err'; });
      if (S.q && (name + ' ' + p.key + ' ' + (p.cat || '')).toLowerCase().indexOf(S.q) < 0) return;
      if (S.filter === 'on' && !p.enabled) return; if (S.filter === 'off' && p.enabled) return; if (S.filter === 'bad' && !(p.enabled && err)) return;
      var th = thumbOf(p), chips = '';
      if (!p.enabled) chips += '<span class="ap-prod__mode">изключен</span>';
      else if (isLive(p.key)) chips += (D.live_pub.indexOf(p.key) > -1 && D.published[p.key] ? '<span class="ap-prod__mode ap-prod__mode--good" title="Публикуван и на страницата">на живо</span>' : '<span class="ap-prod__mode ap-prod__mode--warn" title="Само в черновата — публикувай, за да излезе">чернова</span>');
      iss.forEach(function (i) { chips += '<span class="ap-prod__mode ap-prod__mode--' + (i.l === 'err' ? 'bad' : 'warn') + '" title="' + esc(i.h) + '">' + esc(i.t) + '</span>'; });
      var ov = D.boxes.filter(function (b) { return hasOverride(p, b); });
      if (ov.length) chips += '<span class="ap-prod__mode ap-prod__mode--info" title="Своя отстъпка: ' + esc(ov.map(function (b) { return b.ic + ' −' + pctf(boxPct(p, b)) + '%'; }).join(' · ')) + '">−% свой</span>';
      if (problemOf(p.key)) chips += '<span class="ap-prod__mode" title="Има ред в „С какво да ти помогнем?“">❓</span>';
      var price = p.wc && p.wc.ok ? '<span class="ap-prod__price">' + esc(money(p.wc.price)) + '</span>' : '';
      html += '<div class="ap-prod' + (p.key === S.active ? ' active' : '') + (p.enabled ? '' : ' off') + '" data-k="' + esc(p.key) + '" draggable="true">'
        + '<span class="ap-prod__grip" title="влачи, за да смениш реда">⋮⋮</span>'
        + '<div class="ap-prod__thumb">' + (th ? '<img src="' + esc(th) + '" alt="">' : esc(p.ph || '📦')) + '</div>'
        + '<div class="ap-prod__meta"><div class="ap-prod__name">' + esc(name) + '</div><div class="ap-prod__sub">' + chips + price + '</div></div>'
        + '<div class="ap-prod__dot ' + (!p.enabled ? 'ap-prod__dot--off' : err ? 'ap-prod__dot--bad' : 'ap-prod__dot--on') + '"></div></div>';
    });
    el.innerHTML = html || '<div class="ap-plist__empty">' + (D.products.length ? 'Няма продукти по този филтър' : 'Още няма продукти — „＋ Добави продукт“') + '</div>';
    $$('#apList .ap-prod').forEach(function (row) {
      row.addEventListener('click', function () { select(row.dataset.k); });
      row.addEventListener('dragstart', function (e) { S.drag = row.dataset.k; row.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', row.dataset.k); } catch (x) {} });
      row.addEventListener('dragend', function () { S.drag = null; $$('#apList .ap-prod').forEach(function (r) { r.classList.remove('dragging', 'drop-before', 'drop-after'); }); });
      row.addEventListener('dragover', function (e) { if (!S.drag || S.drag === row.dataset.k) return; e.preventDefault(); var r = row.getBoundingClientRect(), before = e.clientY < r.top + r.height / 2; row.classList.toggle('drop-before', before); row.classList.toggle('drop-after', !before); });
      row.addEventListener('dragleave', function () { row.classList.remove('drop-before', 'drop-after'); });
      row.addEventListener('drop', function (e) { e.preventDefault(); if (!S.drag || S.drag === row.dataset.k) return; var r = row.getBoundingClientRect(), before = e.clientY < r.top + r.height / 2; move(S.drag, row.dataset.k, before); });
    });
    filterChips();
  }
  function move(k, targetK, before) {
    var i = idx(k), j = idx(targetK); if (i < 0 || j < 0) return;
    var it = D.products.splice(i, 1)[0]; j = idx(targetK); D.products.splice(before ? j : j + 1, 0, it);
    markDirty(); renderList(); refreshTop();
  }
  function shift(k, dir) { var i = idx(k), j = i + dir; if (i < 0 || j < 0 || j >= D.products.length) return; var t = D.products[i]; D.products[i] = D.products[j]; D.products[j] = t; markDirty(); renderList(); refreshTop(); }
  function select(k) { S.active = k; renderList(); renderMain(); }

  /* ══════════ MAIN ══════════ */
  function renderMain() {
    var m = $('apMain'), p = active();
    if (!p) {
      m.innerHTML = '<div class="ap-empty"><div class="ap-empty__icon">📦</div><div class="ap-empty__t">' + (D.products.length ? 'Избери продукт' : 'Няма продукти в играта') + '</div>'
        + '<div class="ap-empty__s">' + (D.products.length ? 'Избери продукт от списъка вляво, за да го свържеш с WooCommerce, да нагласиш текстовете, отстъпките по кутия и мястото му в „С какво да ти помогнем?“.' : 'Добави първия продукт от WooCommerce с бутона вляво.') + '</div>'
        + '<button type="button" class="ap-btn ap-btn--primary" id="apAdd2">＋ Добави продукт от WooCommerce</button></div>';
      $('apAdd2').onclick = addModal; return;
    }
    m.innerHTML = '<div class="ap-top" id="apTop"></div><div class="ap-doctor" id="apDoctor"></div><div class="ap-tabs" id="apTabs"></div><div class="ap-panels"><div class="ap-panels-inner" id="apPanel"></div></div>';
    refreshTop(); renderTabs(); renderPanel();
  }
  function refreshTop() {
    var p = active(), t = $('apTop'); if (!p || !t) return;
    var i = idx(p.key), th = thumbOf(p), wc = p.wc || {};
    var st = !p.enabled ? '<span class="ap-top__st ap-top__st--off">изключен</span>' : isLive(p.key) ? (D.live_pub.indexOf(p.key) > -1 && D.published[p.key] ? '<span class="ap-top__st ap-top__st--live">на живо</span>' : '<span class="ap-top__st ap-top__st--draft">само в черновата</span>') : '<span class="ap-top__st ap-top__st--draft">не се показва</span>';
    t.innerHTML = '<div class="ap-top__thumb">' + (th ? '<img src="' + esc(th) + '" alt="">' : esc(p.ph || '📦')) + '</div>'
      + '<h1>' + esc(dispName(p)) + st + '<small>ключ <b>' + esc(p.key) + '</b> · ' + (wc.pid ? 'WC #' + wc.pid + (wc.vid ? ' · вариация #' + wc.vid : '') + (wc.ok ? ' · ' + esc(money(wc.price)) : '') + (wc.edit ? ' · <a href="' + esc(wc.edit) + '" target="_blank" rel="noopener">редактирай в WC ↗</a>' : '') : 'не е свързан с WooCommerce') + ' · позиция ' + (i + 1) + ' от ' + D.products.length + '</small></h1>'
      + '<div class="ap-top__actions">'
      + '<span class="ap-order"><button type="button" id="apUp" title="по-напред"' + (i <= 0 ? ' disabled' : '') + '>↑</button><button type="button" id="apDown" title="по-назад"' + (i >= D.products.length - 1 ? ' disabled' : '') + '>↓</button></span>'
      + '<label class="ap-tglwrap' + (p.enabled ? ' on' : '') + '" title="Включен = показва се на страницата (ако има WC продукт с цена)"><input type="checkbox" id="apEnabled"' + (p.enabled ? ' checked' : '') + '><span class="ap-tgl"></span>' + (p.enabled ? 'В промото' : 'Изключен') + '</label>'
      + '<button type="button" class="ap-btn ap-btn--danger ap-btn--sm" id="apDel" title="Премахва продукта от играта (WooCommerce продуктът остава)">🗑</button>'
      + '<button type="button" class="ap-btn ap-btn--secondary" id="apSave"></button>'
      + '<button type="button" class="ap-btn ap-btn--primary" id="apPublish" title="Doctor проверка → публикува черновата (версия ' + (D.state.version + 1) + ')">🚀 Публикувай</button>'
      + '</div>';
    refreshDirty();
    $('apUp').onclick = function () { shift(p.key, -1); }; $('apDown').onclick = function () { shift(p.key, 1); };
    $('apEnabled').onchange = function () { p.enabled = this.checked; markDirty(); renderList(); refreshTop(); doctorChips(); };
    $('apDel').onclick = function () { delModal(p); }; $('apSave').onclick = save; $('apPublish').onclick = publish;
    doctorChips();
  }
  function doctorChips() {
    var p = active(), el = $('apDoctor'); if (!p || !el) return; var wc = p.wc || {}, h = '';
    var chip = function (l, t, tip, tab) { return '<span class="ap-hchip ap-hchip--' + l + (tab ? ' ap-hchip--btn' : '') + '"' + (tab ? ' data-tab="' + tab + '"' : '') + ' title="' + esc(tip || '') + '">' + t + '</span>'; };
    if (!wc.pid) h += chip('err', '🔗 няма WC продукт', 'Свържи го в таб WooCommerce', 'wc');
    else if (!wc.ok) h += chip('err', '🔗 ' + esc(wc.note || 'WC проблем'), 'Виж таб WooCommerce', 'wc');
    else h += chip('ok', '🔗 WC #' + wc.pid + ' · ' + esc(money(wc.price)), esc(wc.name), 'wc');
    if (wc.pid && wc.ok) h += chip(wc.stock ? 'ok' : 'warn', wc.stock ? '✓ наличен' : '⚠ изчерпан', wc.stock ? 'В наличност в WooCommerce' : 'Не е наличен — показва се, но не може да се поръча', 'wc');
    h += chip(thumbOf(p) ? 'ok' : 'warn', thumbOf(p) ? '🖼 снимка' : '🖼 без снимка', thumbOf(p) ? (p.img_url ? 'Своя снимка (attachment #' + p.img + ')' : 'Снимката на WC продукта') : 'Няма снимка — на страницата ще е емоджито', 'more');
    var ov = D.boxes.filter(function (b) { return hasOverride(p, b); });
    h += chip(ov.length ? 'info' : 'ok', ov.length ? '💸 своя отстъпка: ' + esc(ov.map(function (b) { return b.ic + ' −' + pctf(boxPct(p, b)) + '%'; }).join(' · ')) : '💸 по кутията −' + esc(D.boxes.map(function (b) { return pctf(b.pct); }).join('/−')) + '%', 'Таб Отстъпки', 'pct');
    var pr = problemOf(p.key);
    h += chip(pr ? 'ok' : 'info', pr ? '❓ „' + esc(pr.t || '…') + '“' : '❓ без свой проблем', pr ? 'Има ред в „С какво да ти помогнем?“' : 'Няма ред в „С какво да ти помогнем?“ — влиза в каталога с категорията си', 'fit');
    var f = (D.fit && D.fit[p.key]) || []; h += chip(f.length ? 'ok' : 'info', '🤝 FIT ' + f.length, f.length ? 'Препоръчани към него: ' + f.join(', ') : 'Няма препоръчани продукти към него', 'fit');
    el.innerHTML = h;
    $$('#apDoctor [data-tab]').forEach(function (c) { c.onclick = function () { S.tab = c.dataset.tab; renderTabs(); renderPanel(); }; });
  }
  var TABS = [{ id: 'wc', l: '🔗 WooCommerce' }, { id: 'text', l: '✏️ Текстове' }, { id: 'pct', l: '💸 Отстъпки' }, { id: 'fit', l: '❓ Проблем & FIT' }, { id: 'more', l: '🎨 Още' }];
  function renderTabs() { var el = $('apTabs'); if (!el) return; el.innerHTML = TABS.map(function (t) { return '<div class="ap-tab' + (S.tab === t.id ? ' on' : '') + '" data-t="' + t.id + '">' + t.l + '</div>'; }).join(''); $$('#apTabs .ap-tab').forEach(function (t) { t.onclick = function () { S.tab = t.dataset.t; renderTabs(); renderPanel(); }; }); }

  /* ── fields ── */
  function sec(title, hint, body) { return '<div class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">' + title + '</span></div>' + (hint ? '<div class="ap-sec__hint">' + hint + '</div>' : '') + body + '</div>'; }
  function fin(p, f, label, ph, extra) { return '<div class="ap-field"><label class="ap-field__l">' + label + '</label><input type="text" class="ap-in" data-f="' + f + '" value="' + esc(p[f]) + '" placeholder="' + esc(ph || '') + '"' + (extra || '') + '></div>'; }
  function fta(p, f, label, ph, rows) { return '<div class="ap-field"><label class="ap-field__l">' + label + '</label><textarea class="ap-ta" data-f="' + f + '" rows="' + (rows || 3) + '" placeholder="' + esc(ph || '') + '">' + esc(p[f]) + '</textarea></div>'; }
  function bindFields(p, el, after) {
    $$('[data-f]', el).forEach(function (inp) {
      inp.addEventListener('input', function () { p[inp.dataset.f] = inp.value; markDirty(); if (after) after(inp.dataset.f, inp); });
    });
  }

  function renderPanel() {
    var p = active(), el = $('apPanel'); if (!p || !el) return;
    if (S.tab === 'wc') panelWc(p, el); else if (S.tab === 'text') panelText(p, el); else if (S.tab === 'pct') panelPct(p, el); else if (S.tab === 'fit') panelFit(p, el); else panelMore(p, el);
  }

  /* ── 🔗 WooCommerce ── */
  function wcCard(p) {
    var wc = p.wc || {};
    if (!wc.pid) return '<div class="ap-wccard bad"><div class="ap-wccard__img">🔗</div><div class="ap-wccard__b"><b>Не е свързан с WooCommerce</b>Потърси продукта по-долу (име или ID) или въведи SKU на вариацията „1 опаковка“. Без WC продукт не се показва на страницата.</div></div>';
    if (!wc.ok) return '<div class="ap-wccard bad"><div class="ap-wccard__img">' + (wc.img ? '<img src="' + esc(wc.img) + '" alt="">' : '⚠') + '</div><div class="ap-wccard__b"><b>WC #' + wc.pid + (wc.name ? ' · ' + esc(wc.name) : '') + '</b>' + esc(wc.note || 'редът не става') + '<small>Избери друг продукт или оправи цената/вариацията в WooCommerce.</small></div><button type="button" class="ap-wccard__x" id="apWcClear">✕ махни</button></div>';
    return '<div class="ap-wccard' + (wc.stock ? '' : ' warn') + '"><div class="ap-wccard__img">' + (wc.img ? '<img src="' + esc(wc.img) + '" alt="">' : '📦') + '</div><div class="ap-wccard__b"><b>' + esc(wc.name) + '</b>WC #' + wc.pid + (wc.vid ? ' · вариация #' + wc.vid : ' · simple') + ' · <b style="display:inline">' + esc(money(wc.price)) + '</b>' + (wc.sale != null ? ' <s>' + esc(money(wc.sale)) + ' sale се пренебрегва</s>' : '') + ' · ' + (wc.stock ? '✓ наличен' : '⚠ НЕ Е НАЛИЧЕН') + '<small>' + (wc.edit ? '<a href="' + esc(wc.edit) + '" target="_blank" rel="noopener">редактирай в WooCommerce ↗</a>' : '') + (wc.url ? ' · <a href="' + esc(wc.url) + '" target="_blank" rel="noopener">страницата му ↗</a>' : '') + '</small></div><button type="button" class="ap-wccard__x" id="apWcClear">✕ махни</button></div>';
  }
  function searchBox(id, ph) { return '<div class="ap-ws"><input type="text" class="ap-in" id="' + id + '" placeholder="' + esc(ph) + '" autocomplete="off"><div class="ap-ws__res" id="' + id + 'Res"></div></div>'; }
  function bindSearch(id, onPick, inline) {
    var q = $(id), res = $(id + 'Res'), t; if (!q) return;
    var render = function (list) {
      res.innerHTML = list.length ? list.map(function (x) { return '<button type="button" class="ap-ws__it" data-id="' + x.id + '">' + (x.img ? '<img class="ap-ws__thumb" src="' + esc(x.img) + '" alt="">' : '<span class="ap-ws__thumb">📦</span>') + '<span class="ap-ws__lbl">' + esc(x.name) + '<small>' + esc(x.type) + (x.price ? ' · ' + esc(money(x.price)) : '') + (x.note ? ' · ' + esc(x.note) : '') + '</small></span><span class="ap-ws__id">#' + x.id + '</span></button>'; }).join('') : '<div class="ap-ws__empty">Нищо не е намерено — опитай друга дума или WC ID.</div>';
      res.classList.add('open');
      $$('.ap-ws__it', res).forEach(function (b) { b.onclick = function () { var x = list.filter(function (y) { return String(y.id) === b.dataset.id; })[0]; res.classList.remove('open'); res.innerHTML = ''; q.value = ''; onPick(x); }; });
    };
    q.addEventListener('input', function () { clearTimeout(t); var v = q.value.trim(); if (v.length < 2) { res.classList.remove('open'); res.innerHTML = ''; return; } res.innerHTML = '<div class="ap-ws__empty">Търся…</div>'; res.classList.add('open'); t = setTimeout(function () { wcSearch(v, render); }, 280); });
    if (!inline) document.addEventListener('click', function (e) { if (!res.contains(e.target) && e.target !== q) res.classList.remove('open'); });
  }
  function refreshWc(p, cb) {
    ajax('wc', { pid: p.product_id || 0, sku: p.sku || '', img: p.img || 0 }, function (d) { p.wc = d.wc; p.img_url = d.img_url || ''; renderList(); refreshTop(); if (cb) cb(); else renderPanel(); });
  }
  function panelWc(p, el) {
    var wc = p.wc || {};
    el.innerHTML = sec('🔗 Продуктът в WooCommerce', 'Цената, наличността и (по подразбиране) снимката и текстовете идват оттук. При variable продукт се взема вариацията „1 опаковка“ (атрибут с „1“). <b>SKU печели над WC ID.</b>',
      '<div id="apWcCard">' + wcCard(p) + '</div>'
      + '<div class="ap-field"><label class="ap-field__l">Смени / избери продукт <em>· търси по име или WC ID</em></label>' + searchBox('apWs', 'напр. YoniBiotic, Sakura, 15836…') + '</div>'
      + '<div class="ap-row ap-row--2">'
      + fin(p, 'sku', 'SKU на вариацията „1 опаковка“ <em>· печели над ID</em>', 'напр. dgr001') + '<div class="ap-field"><label class="ap-field__l">WC ID <em>· резерва, ако няма SKU</em></label><input type="number" class="ap-in" data-f="product_id" value="' + (p.product_id || '') + '" placeholder="0"></div>'
      + '</div>'
      + '<div class="ap-note" id="apSkuNote">' + skuNote(p) + '</div>')
      + sec('🔑 Ключ в играта', 'Ключът е стойността на UTM параметъра (<code>?' + esc(D.utm_param) + '=' + esc(p.key) + '</code>) и името в количката/билетите. Само латиница, цифри, тире. Смяната му прекъсва старите UTM линкове.',
      '<div class="ap-row ap-row--kn">' + fin(p, 'key', 'ключ (UTM)', 'напр. yoni') + '<div class="ap-note" id="apKeyNote" style="padding-left:0">' + (A.page_url ? 'линк с UTM: <a href="' + esc(A.page_url + (A.page_url.indexOf('?') > -1 ? '&' : '?') + D.utm_param + '=' + p.key) + '" target="_blank" rel="noopener">' + esc(A.page_url.replace(/^https?:\/\//, '') + '?' + D.utm_param + '=' + p.key) + '</a>' : '') + '</div></div>');
    bindFields(p, el, function (f, inp) {
      if (f === 'key') {
        var k = inp.value.toLowerCase().replace(/[^a-z0-9_\-]/g, ''); if (k !== inp.value) inp.value = k;
        var dup = D.products.filter(function (x) { return x !== p && x.key === k; }).length;
        inp.classList.toggle('bad', !k || dup); $('apKeyNote').innerHTML = !k ? '<span class="bad">Ключът не може да е празен.</span>' : dup ? '<span class="bad">Вече има продукт с този ключ.</span>' : '';
        if (k && !dup) { var old = p.key; p.key = k; renameKey(old, k); S.active = k; renderList(); refreshTop(); }
      }
      if (f === 'product_id') { p.product_id = parseInt(inp.value, 10) || 0; clearTimeout(S._wct); S._wct = setTimeout(function () { refreshWc(p, function () { $('apWcCard').innerHTML = wcCard(p); $('apSkuNote').innerHTML = skuNote(p); bindClear(p); }); }, 500); }
      if (f === 'sku') { clearTimeout(S._wct); S._wct = setTimeout(function () { refreshWc(p, function () { $('apWcCard').innerHTML = wcCard(p); $('apSkuNote').innerHTML = skuNote(p); bindClear(p); }); }, 500); }
    });
    bindSearch('apWs', function (x) { if (!x) return; p.product_id = x.id; p.sku = ''; markDirty(); refreshWc(p); toast('Избран WC #' + x.id + ' — запази, за да влезе в черновата', 'info'); });
    bindClear(p);
  }
  function skuNote(p) {
    var wc = p.wc || {};
    if (p.sku) return wc.sku_found ? '<span class="good">SKU „' + esc(p.sku) + '“ → WC #' + wc.pid + (wc.base ? ' · ' + esc(wc.base) : '') + (wc.vid ? ' · вариация #' + wc.vid : '') + '</span>' : '<span class="bad">SKU „' + esc(p.sku) + '“ не е намерен в WooCommerce' + (p.product_id ? ' — ползва се WC ID #' + p.product_id : '') + '.</span>';
    return p.product_id ? 'Свързан по WC ID #' + p.product_id + '. По-сигурно е със SKU на вариацията „1 опаковка“ — не зависи от ID-то.' : '';
  }
  function bindClear(p) { var b = $('apWcClear'); if (b) b.onclick = function () { p.product_id = 0; p.sku = ''; markDirty(); refreshWc(p); }; }
  function renameKey(old, k) {
    D.problems.forEach(function (x) { if (x.key === old) x.key = k; });
    var fit = {}; Object.keys(D.fit || {}).forEach(function (a) { var b = a === old ? k : a; fit[b] = (D.fit[a] || []).map(function (x) { return x === old ? k : x; }); }); D.fit = fit;
  }

  /* ── ✏️ Текстове ── */
  function panelText(p, el) {
    var t = (p.wc && p.wc.texts) || { name: '', ds: '', desc: '' };
    el.innerHTML = sec('✏️ Текстовете на страницата', '<b>Попълненото тук печели.</b> Празно поле се пълни от WooCommerce (марката без „ansa™“, заглавието на Shrine, краткото описание), когато отметката е включена — placeholder-ът показва какво би дал WC сега.',
      '<div class="ap-field"><label class="ap-tglwrap' + (p.wc_text ? ' on' : '') + '" style="display:inline-flex"><input type="checkbox" id="apWcText"' + (p.wc_text ? ' checked' : '') + '><span class="ap-tgl"></span>празните полета се пълнят от WooCommerce</label></div>'
      + fin(p, 'name', 'Име на страницата', t.name || 'напр. Yonibiotic')
      + fin(p, 'ds', 'За какво е <em>· кратко, под името</em>', t.ds || 'напр. интимен комфорт и баланс')
      + fta(p, 'desc', 'Описание <em>· 1–2 изречения (до 260 знака)</em>', t.desc || '', 3)
      + fin(p, 'cat', 'Категория <em>· заглавие в каталога „Продукт за …“ и групата в „Виж всички“</em>', 'напр. Храносмилане и интимно здраве'))
      + sec('👀 Как ще се види', '', '<div class="ap-note" id="apTextPrev"></div>');
    var prev = function () { var n = p.name || (p.wc_text ? t.name : '') || p.key, ds = p.ds || (p.wc_text ? t.ds : ''), d = p.desc || (p.wc_text ? t.desc : ''); $('apTextPrev').innerHTML = '<b>' + esc(n) + '</b>' + (ds ? ' — ' + esc(ds) : '') + '<br>' + (d ? esc(d) : '<i>без описание</i>') + (p.cat ? '<br><span style="color:#a86b09">Продукт за ' + esc(p.cat.charAt(0).toLowerCase() + p.cat.slice(1)) + '</span>' : '<br><span class="bad">без категория — в каталога ще е без заглавие</span>'); };
    bindFields(p, el, function () { prev(); renderList(); refreshTop(); }); prev();
    $('apWcText').onchange = function () { p.wc_text = this.checked; markDirty(); this.parentNode.classList.toggle('on', this.checked); prev(); renderList(); refreshTop(); };
  }

  /* ── 💸 Отстъпки ── */
  function panelPct(p, el) {
    var price = p.wc && p.wc.ok ? p.wc.price : 0;
    el.innerHTML = sec('💸 Отстъпка по кутия', 'По подразбиране всяка кутия дава своя процент (' + D.boxes.map(function (b) { return b.ic + ' −' + pctf(b.pct) + '%'; }).join(' · ') + '). Тук може да го смениш <b>само за този продукт</b> — напр. −42% в Голямата. <b>Лейбълите на страницата остават процентът на кутията</b>; променя се само цената и спестяването.' + (price ? '' : ' <span style="color:#d64545">Без WC цена няма преглед.</span>'),
      '<div class="ap-boxes">' + D.boxes.map(function (b) {
        var v = p.pct && p.pct[b.id] != null && Number(p.pct[b.id]) > 0 ? Number(p.pct[b.id]) : '', eff = boxPct(p, b), cust = hasOverride(p, b);
        return '<div class="ap-box' + (cust ? ' custom' : '') + '" data-b="' + b.id + '"><div class="ap-box__h"><span class="ic">' + b.ic + '</span>' + esc(b.name) + '<span class="ap-box__lbl">лейбъл −' + pctf(b.pct) + '%</span></div>'
          + '<div class="ap-box__in"><span>−</span><input type="number" class="ap-in" step="0.5" min="0.5" max="99.5" data-pct="' + b.id + '" value="' + (v === '' ? '' : pctf(v).replace(',', '.')) + '" placeholder="' + pctf(b.pct).replace(',', '.') + '"><span>%</span>' + (cust ? '<button type="button" class="ap-box__reset" data-reset="' + b.id + '">↺ на кутията</button>' : '') + '</div>'
          + '<div class="ap-box__p" data-prev="' + b.id + '">' + (price ? '<b>' + esc(money(price * (1 - eff / 100))) + '</b> <s>' + esc(money(price)) + '</s><br>' + (cust ? '<em>своя отстъпка −' + pctf(eff) + '%</em>' : 'по кутията −' + pctf(eff) + '%') + ' · спестява ' + esc(money(price - r2(price * (1 - eff / 100)))) : 'няма WC цена') + '</div></div>';
      }).join('') + '</div>');
    var upd = function (bid, val) { if (!p.pct || typeof p.pct !== 'object' || Array.isArray(p.pct)) p.pct = {}; var n = parseFloat(String(val).replace(',', '.')); if (n > 0 && n < 100) p.pct[bid] = n; else delete p.pct[bid]; markDirty(); };
    $$('[data-pct]', el).forEach(function (inp) { inp.addEventListener('input', function () { upd(inp.dataset.pct, inp.value); panelPct(p, el); $$('[data-pct="' + inp.dataset.pct + '"]', el)[0].focus(); renderList(); doctorChips(); }); });
    $$('[data-reset]', el).forEach(function (b) { b.onclick = function () { upd(b.dataset.reset, ''); panelPct(p, el); renderList(); doctorChips(); }; });
  }

  /* ── ❓ Проблем & FIT ── */
  function panelFit(p, el) {
    var pr = problemOf(p.key);
    var others = D.products.filter(function (x) { return x.key !== p.key; }), fit = (D.fit && D.fit[p.key]) || [];
    var recBy = D.products.filter(function (x) { return x.key !== p.key && ((D.fit && D.fit[x.key]) || []).indexOf(p.key) > -1; });
    el.innerHTML = sec('❓ „С какво да ти помогнем?“', 'Продуктите с ред тук са <b>първи</b> в „Напълни кутията“ и във въпросника, със свой текст. Без ред продуктът пак влиза в каталога — след тях, с категорията си като заглавие.',
      '<div class="ap-field"><label class="ap-tglwrap' + (pr ? ' on' : '') + '" style="display:inline-flex"><input type="checkbox" id="apProbOn"' + (pr ? ' checked' : '') + '><span class="ap-tgl"></span>' + (pr ? 'има свой ред' : 'без свой ред') + '</label></div>'
      + (pr ? '<div class="ap-row ap-row--2"><div class="ap-field"><label class="ap-field__l">Проблем <em>· заглавие</em></label><input type="text" class="ap-in" data-pb="t" value="' + esc(pr.t) + '" placeholder="напр. Интимен дискомфорт"></div><div class="ap-field"><label class="ap-field__l">Подзаглавие</label><input type="text" class="ap-in" data-pb="sub" value="' + esc(pr.sub) + '" placeholder="напр. баланс, сърбеж, повтарящи се инфекции"></div></div>'
        + '<div class="ap-field"><label class="ap-field__l">Защо този продукт <em>· под името в каталога</em></label><input type="text" class="ap-in" data-pb="why" value="' + esc(pr.why) + '" placeholder="напр. 12 щама лактобацили за интимния баланс"></div>'
        + '<div class="ap-note">позиция ' + (D.problems.indexOf(pr) + 1) + ' от ' + D.problems.length + ' · <button type="button" class="ap-box__reset" id="apProbUp">↑ по-напред</button> <button type="button" class="ap-box__reset" id="apProbDown">↓ по-назад</button></div>' : ''))
      + sec('🤝 Препоръчани към него (FIT)', 'Когато клиентката е дошла с този продукт (UTM), тези се показват като „подходящи с него“ в билдъра. Поредността = редът на цъкане.',
      '<div class="ap-chips">' + (others.length ? others.map(function (x) { var on = fit.indexOf(x.key) > -1; return '<span class="ap-chip' + (on ? ' on' : '') + (x.enabled ? '' : ' dim') + '" data-fit="' + esc(x.key) + '" title="' + esc(x.enabled ? '' : 'изключен от промото') + '">' + esc(x.ph || '') + ' ' + esc(dispName(x)) + (on ? ' <b>' + (fit.indexOf(x.key) + 1) + '</b>' : '') + '</span>'; }).join('') : '<span class="ap-note" style="padding:0">няма други продукти</span>') + '</div>'
      + (recBy.length ? '<div class="ap-note">Той е препоръчан към: ' + esc(recBy.map(dispName).join(', ')) + '</div>' : ''));
    $('apProbOn').onchange = function () {
      if (this.checked) { D.problems.push({ t: p.cat || dispName(p), sub: '', why: p.ds || '', key: p.key }); } else { D.problems = D.problems.filter(function (x) { return x.key !== p.key; }); }
      markDirty(); panelFit(p, el); renderList(); doctorChips();
    };
    $$('[data-pb]', el).forEach(function (inp) { inp.addEventListener('input', function () { pr[inp.dataset.pb] = inp.value; markDirty(); doctorChips(); }); });
    var pup = $('apProbUp'), pdn = $('apProbDown');
    if (pup) pup.onclick = function () { var i = D.problems.indexOf(pr); if (i > 0) { D.problems.splice(i, 1); D.problems.splice(i - 1, 0, pr); markDirty(); panelFit(p, el); } };
    if (pdn) pdn.onclick = function () { var i = D.problems.indexOf(pr); if (i < D.problems.length - 1) { D.problems.splice(i, 1); D.problems.splice(i + 1, 0, pr); markDirty(); panelFit(p, el); } };
    $$('[data-fit]', el).forEach(function (c) { c.onclick = function () { if (!D.fit || Array.isArray(D.fit)) D.fit = {}; var f = (D.fit[p.key] || []).slice(), k = c.dataset.fit, i = f.indexOf(k); if (i > -1) f.splice(i, 1); else f.push(k); D.fit[p.key] = f; markDirty(); panelFit(p, el); doctorChips(); }; });
  }

  /* ── 🎨 Още ── */
  function panelMore(p, el) {
    var th = p.theme && p.theme.length === 3 ? p.theme : ['#fff0f6', '#ffd1e3', '#8a1147'];
    el.innerHTML = sec('🖼 Снимка и емоджи', 'Снимката по подразбиране е тази на WC продукта; тук може да сложиш друга от медийната библиотека. Емоджито е резерва без снимка и стои до името в поръчката.',
      '<div class="ap-imgrow"><div class="ap-imgrow__pic" id="apPic">' + (thumbOf(p) ? '<img src="' + esc(thumbOf(p)) + '" alt="">' : esc(p.ph || '📦')) + '</div><div><button type="button" class="ap-btn ap-btn--secondary ap-btn--sm" id="apPickImg">📷 Избери снимка</button> ' + (p.img ? '<button type="button" class="ap-btn ap-btn--secondary ap-btn--sm" id="apClearImg">✕ обратно към WC снимката</button>' : '') + '<div class="ap-note" style="padding:6px 0 0">' + (p.img ? 'своя снимка · attachment #' + p.img : (p.wc && p.wc.img ? 'снимката на WC продукта' : 'няма снимка')) + '</div></div>'
      + '<div class="ap-field" style="padding:0 0 0 12px;max-width:120px"><label class="ap-field__l">емоджи</label><input type="text" class="ap-in" data-f="ph" value="' + esc(p.ph) + '" placeholder="🌿"></div></div>')
      + sec('📋 Продуктовият попъп („За продукта ›“)', 'Съставки, за кого е, опаковка, рейтинг и отзиви — показват се в попъпа на продукта в каталога.',
      '<div class="ap-row ap-row--2">' + fta(p, 'ing', 'Какво съдържа', 'напр. 12 щама лактобацили, пребиотик, постбиотик', 3) + fta(p, 'who', 'За кого е', 'напр. при интимен дискомфорт, след антибиотик', 3) + '</div>'
      + '<div class="ap-row ap-row--2">' + fin(p, 'pack', 'Опаковка', 'напр. 30 капсули · за 30 дни') + fin(p, 'rating', 'Рейтинг', 'напр. 4.9 · 1 120 отзива') + '</div>'
      + fta(p, 'reviews', 'Отзиви <em>· по един на ред: име|звезди|текст</em>', 'Мария, 47|5|От втората седмица…', 4))
      + sec('🎁 Имейл-попъпът (gate)', 'Когато клиентката идва с този продукт в UTM-а, попъпът носи неговото пълно име, подзаглавие и цветове.',
      '<div class="ap-row ap-row--2">' + fin(p, 'gname', 'Пълно име', 'напр. YoniBiotic 3в1') + fin(p, 'gsub', 'Подзаглавие', 'напр. Пробиотикът, направен специално за жени') + '</div>'
      + '<div class="ap-colors"><label>фон <input type="color" data-th="0" value="' + esc(th[0]) + '"></label><label>акцент <input type="color" data-th="1" value="' + esc(th[1]) + '"></label><label>текст <input type="color" data-th="2" value="' + esc(th[2]) + '"></label><div class="sw" id="apSw" style="background:' + esc(th[0]) + ';border:2px solid ' + esc(th[1]) + ';color:' + esc(th[2]) + '">' + esc(p.gname || dispName(p)) + '</div></div>');
    bindFields(p, el, function (f) { if (f === 'ph') { $('apPic').innerHTML = thumbOf(p) ? '<img src="' + esc(thumbOf(p)) + '" alt="">' : esc(p.ph || '📦'); renderList(); refreshTop(); } if (f === 'gname') $('apSw').textContent = p.gname || dispName(p); });
    $$('[data-th]', el).forEach(function (c) { c.addEventListener('input', function () { p.theme = th = [$$('[data-th]', el)[0].value, $$('[data-th]', el)[1].value, $$('[data-th]', el)[2].value]; var sw = $('apSw'); sw.style.background = th[0]; sw.style.borderColor = th[1]; sw.style.color = th[2]; markDirty(); }); });
    $('apPickImg').onclick = function () {
      if (!window.wp || !wp.media) { toast('Медийната библиотека не е заредена — презареди страницата.', 'error'); return; }
      var fr = wp.media({ title: 'Снимка на продукта в промото', button: { text: 'Избери' }, multiple: false, library: { type: 'image' } });
      fr.on('select', function () { var a = fr.state().get('selection').first().toJSON(); p.img = a.id; p.img_url = (a.sizes && (a.sizes.medium || a.sizes.full) ? (a.sizes.medium || a.sizes.full).url : a.url) || ''; markDirty(); panelMore(p, el); renderList(); refreshTop(); });
      fr.open();
    };
    var ci = $('apClearImg'); if (ci) ci.onclick = function () { p.img = 0; p.img_url = ''; markDirty(); panelMore(p, el); renderList(); refreshTop(); };
  }

  /* ══════════ ADD / DELETE ══════════ */
  function addModal() {
    var ov = modal('<div class="ap-modal__t">＋ Добави продукт от WooCommerce</div><div class="ap-modal__s">Търси по име или WC ID. Продуктът влиза в черновата <b>включен</b>, с име, „за какво е“ и описание от WooCommerce — после ги нагласяш в редактора. При variable продукт се взема вариацията „1 опаковка“.</div>'
      + '<div class="ap-field">' + searchBox('apAddQ', 'напр. YoniBiotic, AHCC, 15836…') + '</div>'
      + '<div class="ap-modal__a"><button type="button" class="ap-btn ap-btn--secondary" id="apAddCancel">Отказ</button></div>', true);
    $('apAddCancel').onclick = closeModal; setTimeout(function () { var q = $('apAddQ'); if (q) q.focus(); }, 50);
    bindSearch('apAddQ', function (x) {
      if (!x) return;
      var dup = D.products.filter(function (y) { return (y.wc && (y.wc.pid === x.id || y.wc.parent === x.id)) || y.product_id === x.id; })[0];
      if (dup) { closeModal(); select(dup.key); toast('Този продукт вече е в играта — „' + dispName(dup) + '“', 'info'); return; }
      var base = translit(String(x.name).replace(/^\s*ansa\s*(™|tm)?\s*[-–—|:]?\s*/i, '').split(/\s+[|\-–—:]\s+/)[0]).split('-').slice(0, 2).join('-');
      var np = { key: uniqueKey(base), product_id: x.id, sku: '', ph: '🌿', img: 0, img_url: '', name: '', gname: '', gsub: '', ds: '', desc: '', ing: '', who: '', rating: '', reviews: '', cat: '', pack: '', theme: ['#fff0f6', '#ffd1e3', '#8a1147'], enabled: true, wc_text: true, pct: {}, wc: null };
      D.products.push(np); closeModal(); markDirty(); S.active = np.key; S.tab = 'wc'; renderList(); renderMain();
      refreshWc(np, function () { renderList(); refreshTop(); renderPanel(); toast('„' + dispName(np) + '“ е добавен — провери ключа и категорията, после 💾 Запази', 'success'); });
    }, true);
  }
  function delModal(p) {
    modal('<div class="ap-modal__t">🗑 Премахни „' + esc(dispName(p)) + '“ от играта?</div><div class="ap-modal__s">Маха го от черновата (и реда му в „С какво да ти помогнем?“ и FIT връзките). WooCommerce продуктът не се пипа. Ако само искаш да не се показва, изключи го с превключвателя.</div>'
      + '<div class="ap-modal__a"><button type="button" class="ap-btn ap-btn--secondary" id="apDelNo">Отказ</button><button type="button" class="ap-btn ap-btn--primary" id="apDelYes">Премахни</button></div>');
    $('apDelNo').onclick = closeModal;
    $('apDelYes').onclick = function () {
      D.products = D.products.filter(function (x) { return x !== p; }); D.problems = D.problems.filter(function (x) { return x.key !== p.key; });
      var fit = {}; Object.keys(D.fit || {}).forEach(function (a) { if (a !== p.key) fit[a] = (D.fit[a] || []).filter(function (x) { return x !== p.key; }); }); D.fit = fit;
      S.active = null; closeModal(); markDirty(); renderList(); renderMain(); toast('Премахнат — запази, за да остане така', 'info');
    };
  }

  /* ══════════ SAVE / PUBLISH ══════════ */
  function payload() { return JSON.stringify({ products: D.products.map(function (p) { var c = {}; Object.keys(p).forEach(function (k) { if (k !== 'wc' && k !== 'img_url') c[k] = p[k]; }); return c; }), problems: D.problems, fit: D.fit || {} }); }
  function validate() {
    var seen = {}, bad = null;
    D.products.forEach(function (p) { if (!p.key) bad = 'Има продукт без ключ.'; else if (seen[p.key]) bad = 'Два продукта с ключ „' + p.key + '“.'; seen[p.key] = 1; });
    return bad;
  }
  function apply(d) { var k = S.active; D = d; S.dirty = false; if (k && !prod(k)) S.active = null; renderList(); renderMain(); }
  function save(cb) {
    if (S.busy) return; var v = validate(); if (v) { toast(v, 'error'); return; }
    S.busy = true; var b = $('apSave'); if (b) { b.disabled = true; b.textContent = '… записвам'; }
    ajax('save', { data: payload() }, function (d) { S.busy = false; apply(d); toast('Записано в черновата · ' + d.products.length + ' продукта', 'success'); if (cb) cb(); }, function () { S.busy = false; if (b) { b.disabled = false; refreshDirty(); } });
  }
  function publish() {
    if (S.dirty) { save(function () { publish(); }); return; }
    var go = function (force) {
      if (S.busy) return; S.busy = true; var b = $('apPublish'); if (b) { b.disabled = true; b.textContent = '… публикувам'; }
      ajax('publish', force ? { force: 1 } : {}, function (d) { S.busy = false; closeModal(); apply(d); toast('Публикувано — версия ' + d.state.version + (d.state.enabled ? '' : ' (играта е изключена — 🧰 Инструменти)'), 'success'); }, function (e) {
        S.busy = false; if (b) { b.disabled = false; b.textContent = '🚀 Публикувай'; }
        if (e.code !== 'doctor') { toast(e.msg || 'Грешка при публикуване', 'error'); return; }
        var ch = e.checks || [], bad = ch.filter(function (c) { return c.level === 'bad'; }).length;
        modal('<div class="ap-modal__t">🩺 Doctor спира публикуването</div><div class="ap-modal__s">' + bad + ' проблем(а) трябва да се оправят — или публикувай въпреки това, ако знаеш какво правиш.</div>'
          + '<div class="ap-checks">' + ch.filter(function (c) { return c.level !== 'ok'; }).map(function (c) { return '<div class="ap-check ap-check--' + c.level + '">' + (c.level === 'bad' ? '⛔' : c.level === 'warn' ? '⚠' : 'ℹ') + ' <span>' + esc(c.msg) + '</span></div>'; }).join('') + '</div>'
          + '<div class="ap-modal__a"><button type="button" class="ap-btn ap-btn--secondary" id="apPubNo">Назад</button><button type="button" class="ap-btn ap-btn--primary" id="apPubForce">Публикувай въпреки това</button></div>', true);
        $('apPubNo').onclick = closeModal; $('apPubForce').onclick = function () { go(true); };
      });
    };
    go(false);
  }

  /* ══════════ BOOT ══════════ */
  shell();
  if (D.products.length && !S.active) { /* отваря първия с проблем, иначе първия */
    var first = D.products.filter(function (p) { return p.enabled && issues(p).some(function (i) { return i.l === 'err'; }); })[0] || D.products[0];
    select(first.key);
  }
  window.AnsaPromoProductsApp = { data: function () { return D; }, select: select, save: save };
})();
