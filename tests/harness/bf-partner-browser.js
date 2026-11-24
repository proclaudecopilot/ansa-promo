// Черен петък × партньори в истински браузър (1.57.0): bf.js срещу живия PHP на страницата, лийдовете и количката (tests/harness/bf-server.php).
// node tests/harness/bf-partner-browser.js   → „OK n проверки“ или списък с грешки (exit 1). SHOTS=<папка> пази снимки на екраните.
// Нищо не излиза извън 127.0.0.1: всяка друга заявка (шрифтове, живият сайт) се спира.
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path');
const PORT = 8800 + Math.floor(Math.random() * 150), B = 'http://127.0.0.1:' + PORT, BF = B + '/cheren-petak-2026/';
const state = fs.mkdtempSync(path.join(os.tmpdir(), 'bf-state-')), OUT = process.env.SHOTS || '';
const fails = []; let n = 0; const ok = (name, cond, info) => { n++; if (!cond) fails.push(name + (info !== undefined ? ' — ' + JSON.stringify(info).slice(0, 400) : '')); };
(async () => {
  const srv = spawn('php', ['-S', '127.0.0.1:' + PORT, path.join(__dirname, 'bf-server.php')], { env: Object.assign({}, process.env, { BF_STATE: state, BF_PHASE: 'pause' }), stdio: 'ignore' });
  await new Promise(r => setTimeout(r, 1200));
  const b = await chromium.launch(); const jsErr = [];
  const open = async (vp) => { const c = await b.newContext({ viewport: vp || { width: 390, height: 844 }, deviceScaleFactor: OUT ? 2 : 1 }); await c.route('**/*', r => (new URL(r.request().url()).hostname === '127.0.0.1') ? r.continue() : r.abort()); const p = await c.newPage(); p.on('pageerror', e => jsErr.push(e.message)); return [c, p]; };
  const go = async (p, u) => { await p.goto(u, { waitUntil: 'networkidle' }); await p.waitForTimeout(350); };
  const text = async p => (await p.innerText('#ansaBf')).replace(/\s+/g, ' ');
  const shot = async (p, name) => { if (OUT) await p.screenshot({ path: path.join(OUT, name + '.png') }); };
  const api = async (p, u) => (await p.request.get(B + u)).json();
  const cartOf = async p => JSON.parse(await p.innerText('#cart'));
  /* Избира продукта и подаръците и стига до чекаута. Страницата помни предишния избор (sessionStorage) — връща се на стъпка 1, ако е на друга. */
  const buy = async (p, key) => {
    if (!(await p.$('[data-act="cart+"][data-id="' + key + '"]'))) { await p.click('[data-act="screen"][data-id="shop"]'); await p.waitForTimeout(200); }
    await p.click('[data-act="cart+"][data-id="' + key + '"]'); await p.click('[data-act="screen"][data-id="gifts"]'); await p.waitForTimeout(200);
    for (let i = 0; i < 8 && !(await p.$('[data-act="checkout"]:not([disabled])')); i++) { await p.click('[data-act="gift+"][data-id="kera"]'); await p.waitForTimeout(150); }
    await Promise.all([p.waitForURL('**/checkout/**'), p.click('[data-act="checkout"]')]); return cartOf(p);
  };
  try {
    /* ── 1. Паузата: партньорски линк → ваучер → кодът на екрана и по имейл ── */
    let [c1, p] = await open(); await go(p, BF + '?ref=ANSSID5ANSA'); let t = await text(p);
    ok('паузата: страницата на партньора („подарък от Мария“)', /подарък от Мария/.test(t) && /Подаръкът от Мария — избери/.test(t), t.slice(0, 200));
    ok('ваучерът е описан като код със срок', /Ваучер 5 €/.test(t) && /с код · важи 90 дни, и след Черния петък/.test(t));
    await shot(p, '1-partner-izbor');
    await p.fill('#emailIn', 'browser1@example.com'); await p.click('#signupForm button[type=submit]'); await p.waitForTimeout(900); t = await text(p);
    const code = (t.match(/BFP5-[A-Z0-9]{6}/) || [''])[0];
    ok('след имейла: „Подаръкът ти е запазен“ + кодът и срокът', /Подаръкът ти е запазен/.test(t) && code !== '' && /важи до \d\d\.\d\d\.20\d\d/.test(t), t.slice(0, 300));
    await shot(p, '2-kodut-e-zapazen');
    let st = await api(p, '/__state'); let lead = st.leads.find(l => l.email === 'browser1@example.com');
    ok('лийдът носи кода и партньора', lead && lead.voucher === code && lead.ref_code === 'ANSSID5ANSA' && lead.bonus === 'v5', lead);
    ok('WooCommerce купонът е 5 € еднократен', (st.coupons.find(x => x.code === code.toLowerCase()) || {}).amount === 5 && (st.coupons.find(x => x.code === code.toLowerCase()) || {}).limit === 1);
    ok('имейлът с кода е изпратен', st.mails.some(m => m.to === 'browser1@example.com' && m.subject.includes(code) && m.html.includes(code)), st.mails.map(m => m.subject));
    /* ── 2. Публичната фаза: връща се БЕЗ ?ref= → магазинът, ваучерът се приспада, кодът на партньора е в количката ── */
    await api(p, '/__phase?p=public'); await go(p, BF); t = await text(p);
    ok('публичната фаза: бележката с кода в магазина', /Идваш от Мария/.test(t) && t.includes(code) && /се приспада в количката/.test(t), t.slice(0, 300));
    await shot(p, '3-magazin-s-koda');
    let cart = await buy(p, 'greens');
    ok('количка 1: кодът на партньора + „Ваучер 5 € от Мария“ −5 € + подаръкът на 0 €', cart.coupons.includes('anssid5ansa') && cart.fees.length === 1 && cart.fees[0][1] === -5 && /Мария/.test(cart.fees[0][0]) && Math.abs(cart.total - 34.9) < 0.01 && cart.items.some(i => i.role === 'gift' && i.total === 0), cart);
    await go(p, BF); cart = await buy(p, 'berber');
    ok('количка 2 (върна се и добави още): кодът на партньора ПАК е в нея, ваучерът също', cart.coupons.includes('anssid5ansa') && cart.fees.length === 1 && cart.fees[0][1] === -5 && Math.abs(cart.total - 84.8) < 0.01, cart);
    await go(p, B + '/checkout/?pay=ok'); const paid = await cartOf(p);
    ok('поръчката е платена и носи кода', paid.order && paid.order.status === 'processing' && paid.order.coupons.includes('anssid5ansa'), paid.order);
    st = await api(p, '/__state'); const o = st.orders.find(x => x.id === paid.order.id); lead = st.leads.find(l => l.voucher === code);
    ok('ваучерът е използван от тази поръчка', lead.voucher_order_id == paid.order.id && (st.coupons.find(x => x.code === code.toLowerCase()) || {}).count === 1 && o.bf.affv.code === code, [lead, o && o.bf.affv]);
    if (o.lock !== null || st.orders.some(x => x.lock)) ok('ansa Partner: комисионата е заключена за Мария (15 %, източник линк)', o.lock && o.lock.partner_id == 1 && o.lock.code === 'anssid5ansa' && +o.lock.rate === 15, o.lock);
    await go(p, BF); if (await p.$('[data-act="screen"][data-id="shop"]')) { await p.click('[data-act="screen"][data-id="shop"]'); await p.waitForTimeout(250); } t = await text(p); await shot(p, '5-vaucherut-e-izpolzvan');
    ok('след поръчката страницата казва, че ваучерът е използван', t.includes(code) && /вече е използван/.test(t), t.slice(0, 250));
    await c1.close();
    /* ── 3. Маската ── */
    let [c2, p2] = await open(); await go(p2, BF + '?ref=IVA5ANSA'); await p2.click('[data-act="bonus"][data-id="mask"]'); await p2.fill('#emailIn', 'browser2@example.com'); await p2.click('#signupForm button[type=submit]'); await p2.waitForTimeout(900); t = await text(p2);
    ok('маската: направо в магазина, „маска CollaMask е добавена“', /Идваш от Ива/.test(t) && /CollaMask е добавена/.test(t), t.slice(0, 250));
    cart = await buy(p2, 'sakura');
    ok('количката: маската на 0 €, без ваучер, кодът на Ива', cart.items.some(i => i.role === 'pgift' && i.total === 0) && cart.fees.length === 0 && cart.coupons.includes('iva5ansa') && Math.abs(cart.total - 44.9) < 0.01, cart);
    st = await api(p2, '/__state'); ok('при маска не се издава ваучер', !st.leads.some(l => l.email === 'browser2@example.com' && l.voucher), st.leads.filter(l => l.email === 'browser2@example.com'));
    /* ── 4. Бисквитката на партньора: WC сесията е изтекла, партньорът остава ── */
    const ck = await c2.cookies(); ok('бисквитката ansa_bf_aff е записана', ck.some(x => x.name === 'ansa_bf_aff' && x.value === 'IVA5ANSA'), ck.map(x => x.name));
    await c2.clearCookies({ name: 'sid' }); await p2.evaluate(() => sessionStorage.clear()); await go(p2, BF); t = await text(p2);
    ok('нова сесия, без ?ref=: страницата пак е на Ива (от бисквитката)', /линк на Ива/.test(t) && /Подаръкът от Ива — избери/.test(t), t.slice(0, 200));
    await p2.fill('#emailIn', 'browser2@example.com'); await p2.click('#signupForm button[type=submit]'); await p2.waitForTimeout(900); cart = await buy(p2, 'greens');
    ok('и количката ѝ носи кода на Ива (комисионата)', cart.coupons.includes('iva5ansa'), cart);
    await c2.close();
    /* ── 5. Кратките линкове ── */
    let [c3, p3] = await open(); await go(p3, B + '/p/IVA5ANSA'); t = await text(p3).catch(() => '');
    ok('ansa.bg/p/IVA5ANSA → Черния петък с ?ref=, страницата на Ива', /\/cheren-petak-2026\/\?ref=IVA5ANSA/.test(p3.url()) && /подарък от Ива/.test(t), p3.url());
    await go(p3, B + '/p/ANSIANSAEA'); ok('кодът за играта не отива към Черния петък', !/cheren-petak/.test(p3.url()), p3.url());
    await api(p3, '/__phase?p=over'); await go(p3, B + '/p/IVA5ANSA'); ok('след края: кратката връзка пак е за началната', !/cheren-petak/.test(p3.url()) && /ref=IVA5ANSA/i.test(p3.url()), p3.url());
    await api(p3, '/__phase?p=public'); await c3.close();
    /* ── 6. Десктоп ── */
    let [c4, p4] = await open({ width: 1280, height: 900 }); await go(p4, BF + '?ref=ANSSID5ANSA'); await p4.fill('#emailIn', 'browser3@example.com'); await p4.click('#signupForm button[type=submit]'); await p4.waitForTimeout(900); t = await text(p4);
    ok('десктоп: бележката с кода', /BFP5-[A-Z0-9]{6}/.test(t) && /се приспада в количката/.test(t), t.slice(0, 200)); await shot(p4, '4-desktop-magazin');
    const wide = await p4.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1); ok('десктоп: няма хоризонтален скрол', !wide);
    await c4.close();
    ok('няма грешки в JavaScript', jsErr.length === 0, jsErr);
  } catch (e) { fails.push('ИЗКЛЮЧЕНИЕ: ' + e.message.split('\n').slice(0, 4).join(' | ')); }
  await b.close(); srv.kill(); try { fs.rmSync(state, { recursive: true, force: true }); } catch (e) {}
  if (fails.length) { console.log('FAIL ' + fails.length + ' от ' + n + ':\n - ' + fails.join('\n - ')); process.exit(1); }
  console.log('OK ' + n + ' проверки в браузър');
})();
