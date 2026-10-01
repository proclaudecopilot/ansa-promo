# ansa-promo — standalone plugin: architecture · visual copy editor · phased build

**Audience:** the AI session that builds the plugin. Not written for the human. Read fully before writing code.
**Date:** 2026-10-01. Supersedes `ansa-promo-architecture-v2-boxes.md` and the Shrine-internal promo module plan (6.18–6.19).
**Design source of truth:** mockup **v73** — `/mnt/user-data/outputs/ansa-promo-mockup-v73.html` (also artifact `claude.ai/artifact/QUVWLHS4Gwtj5v1VKVk8wE`, parts in `/home/claude/mockup-v71/{index.html,style.css,app.js}` — the folder name is stale, the content is v73). Port it 1:1. Do not redesign. Design is still being iterated by the user; every later mockup version replaces the port's HTML/CSS, so keep the port mechanical (see §4.3).
**Business spec source of truth:** project memory `/projects/…/areas/promo-board.md` (the newest `[stated]` lines win when they conflict). Key facts restated in §2.

---

## 0. Decisions already made (do not reopen)

| # | Decision | Source |
|---|----------|--------|
| D1 | Separate plugin `ansa-promo`. Shrine stays active on the site for product pages. The promo module inside Shrine (`includes/promo/`, `assets/promo/`) is **retired**, not maintained. | user, 2026-09-27 / 10-01 |
| D2 | Three fixed boxes: Малка 1×/−20%/1 ticket · Средна 3×/−30%/3 tickets + сет €64,50 + книга + free ship · Голяма 5×/−40%/5 tickets + сет €1 + книга + free ship. Set value **€129**. Ship paid only on Малка (€2,55). | promo-board |
| D3 | Main path: email gate (soft) → three boxes → "Напълни кутията си" (modal) → "Честито" → payment. Builder/табло is **out** of the main path. "Три любимци" order bump **off** by default. | promo-board 25 Sep |
| D4 | Tickets are **digital**: QR in the parcel activates it on a separate portal; counts = box tickets (+1 if affiliate code). No printed scratch cards. Draw 25.01.2027. | promo-board 25 Sep |
| D5 | No coupons in promo checkout (filter stays for ansa Partner attribution, see §6.4). Card + COD. FunnelKit checkout. Launch on ansa.bg only, 16.10.2026 → 31.12.2026. | promo-board 24–25 Sep |
| D6 | Cosmetics set = hidden WC product; reservation automatic with the order (☑ on checkout, default on); voucher emailed at `completed`. Book = WC product delivered by email, €0 line. | promo-board 24–25 Sep |
| D7 | Editor must be a Proof-Shrine-class visual editor: copy registry with `{{placeholders}}`, draft/publish, regions + live canvas + inspector. | promo-board 25 Sep |
| D8 | Delivery = GitHub repo `proclaudecopilot/ansa-promo` (private) + LOGADOR GitHub Updater mu-plugin, exactly like `proclaudecopilot/ansa-shrine`. Release = bump version in two places + push `main`. No zips after the first install. | ways-of-working 29 Sep |
| D9 | Testing: user never tests manually. Every phase ships with a Code Snippet that self-checks on the live site and prints a report; user pastes it back. | ways-of-working 25 Sep |
| D10 | Pricing is server-side only. Client sends IDs and quantities, never prices/percentages. | technical-rules |
| D11 | Frontend copy rules: със/с, във/в by next word; never "Козм. сет"; set always "Новият козметичен сет на ansa™" + "5 уникални продукта на стойност €129. Стартира през 2027 година." + price as badge ~~€129~~ €1/€64,50, never inline; yacht = "5 дни почивка с яхта в Гърция за теб и 4 твои близки, с включена храна и напитки, стойност €8 800", never "за €8 800". "всички награди отключени" only for Голяма. | promo-board |

Open items that the builder decides alone (report the choice in the phase README, don't ask): option naming, table names, REST namespace, cron intervals, nonce lifetimes.

Open items that need the user (ask **only** when the phase reaches them, with a default): see §9.

---

## 1. Why a separate plugin and what it may borrow from Shrine

Shrine 6.19.21 (`proclaudecopilot/ansa-shrine`) already contains a working Phase A+B promo module written against mockup v71. It is **untested on a real site** (no snippet output ever came back). Reuse it as **source material**, not as a dependency:

- **Copy verbatim (then adapt):** `class-promo-cart.php` (cart engine — the hook list and `perform_add()` flow are correct and match D10), `class-promo-config.php` (draft/published store + normalize), `class-promo-copy.php` (registry shape; keys must be regenerated from v73), `class-promo-admin.php` (ajax skeleton, Doctor checks, gateway list), `promo-admin.js` (regions/canvas/inspector v2 — extend per §5).
- **Do not depend on at runtime:** no `class_exists('Ansa_Shrine_*')` calls in the hot path. Optional integration only: if `function_exists('ansa_shrine_purge_product')` call it after publish for the promo page URL; otherwise no-op. Nothing else.
- **Patterns to keep from Shrine's technical-rules:** variation resolution by first integer in the variation name ("1 брой" → qty 1), `rawurldecode` not `urldecode`, never `sanitize_text_field` on attribute keys, `html_entity_decode` for currency, OPcache/object-cache flush on version change (copy the `admin_init` block at the top of `ansa-shrine.php`), purge the single page URL on publish — never `purge_everything`.
- **Shrine stays active.** It owns product pages. The promo plugin owns exactly one page (shortcode) + the cart/checkout session that starts from that page. They never touch the same cart item: promo items carry `_ansa_promo` cart-item meta; Shrine's gift/box logic must ignore items with that meta (verify with the snippet in Phase 3 — if Shrine's `class-cart-gift.php` adds a gift to a promo cart, add a guard **in Shrine** via a one-line filter and release Shrine 6.19.22).

### 1.1 Collision avoidance (must do in Phase 0)
Shrine's module registers: option `ansa_promo_store`, ajax `ansa_promo_add`, `wp_ajax_ansa_promo_admin_*`, shortcode `[ansa_promo]`, classes `Ansa_Promo_*`, menu under Shrine, query vars `ansa_promo`, `ansa_editor`. The new plugin **takes over all of these names** (so an existing draft on the site migrates for free) and Shrine must stop loading its module when the plugin is present:

1. In Shrine `includes/promo/bootstrap.php` add at the top: `if ( defined( 'ANSA_PROMO_PLUGIN_VER' ) ) { return; }` — release **Shrine 6.19.22** (bump both places, push main). The promo plugin defines that constant in its main file at load, and WP loads plugins alphabetically (`ansa-promo/` before `ansa-shrine/`), but don't rely on order: also add in the promo plugin an `plugins_loaded` priority −1 guard that defines the constant, and in Shrine check the constant at `plugins_loaded` priority 0 instead of file-include time if ordering proves wrong in the snippet.
2. Promo plugin classes use namespace `AnsaPromo\` (PSR-4-ish manual autoloader, `includes/class-*.php` → `AnsaPromo\Foo`) so there is no fatal if both ever load. Keep the *public* names (option, ajax actions, shortcode) identical to Shrine's for migration.

---

## 2. Domain model (what the plugin must represent)

```
Promo (one per site, option ansa_promo_store = {draft, published, version, published_at, history[]})
├─ id 'orange_q4_2026', name, enabled, deadline '2026-12-31 23:59', page_id, utm_param 'utm_content'
├─ gate {enabled, required:false}               // soft email gate before the boxes
├─ timer {minutes:15, warn_under:3}              // visual only, no stock reservation
├─ ship {paid: 2.55}                             // paid shipping on Малка only
├─ products[] {key(UTM value), product_id(parent or variation), ph(emoji fallback), img(attachment id),
│              name, gname, gsub, ds, desc, ing, who, rating, reviews, cat, theme[3 colors], enabled}
├─ fit {key: [key,key,key]}                      // recommended add-ons per core (3 shown, "виж всички")
├─ problems[] {t, sub, p(product key), why}      // "С какво да ти помогнем" categories — one product each
├─ boxes[] {id s|m|l, ic, name, packs, pct, tickets, rw[], no[], tag, cosm_pay, order_mobile, order_desktop}
├─ rewards {yacht{value:8800}, book{product_id,value:19}, cosm{product_id,value:129}, icons{}}
├─ secret {enabled:false, pct:35, count:3}       // order bump — keep the code, default off
├─ checkout {card_gateway, cod_gateway:'cod', block_coupons:true, consent_default:true}
├─ tickets {portal_url, secret(HMAC key), partner_bonus:1}
└─ copy {key: string}                            // only overridden keys; defaults live in the registry
```

Runtime objects (not in the option):
- **Cart group** (`WC()->session['ansa_promo_ctx']` + cart-item meta `_ansa_promo` = {promo, group, box, role base|book|secret, key}). One promo order = one empty-then-filled cart. Exact pack count or the group is invalid → regular prices + checkout blocked with a link back to the page.
- **Order meta** (HPOS-safe, flat keys): `_ansa_promo_id, _ansa_promo_box, _ansa_promo_packs, _ansa_promo_pct, _ansa_promo_tickets, _ansa_promo_partner, _ansa_promo_cosm_status (reserved|declined|voucher_sent), _ansa_promo_email, _ansa_promo_utm, _ansa_promo_gate_email`.
- **Ticket** (table `{prefix}ansa_promo_tickets`): id, order_id, code (12 chars, base32, HMAC-checksummed), count, status (issued|activated|void), activated_at, portal_user (email/phone), ip. One row per order, `count` = tickets. QR encodes `{portal_url}?t={code}`.
- **Lead** (table `{prefix}ansa_promo_leads`): email, utm, box_seen, consent, created_at, converted_order_id. Written at the gate; updated at order.

---

## 3. Plugin layout

```
ansa-promo/
├─ ansa-promo.php                 // header (Version + GitHub Plugin URI), ANSA_PROMO_VER, ANSA_PROMO_PLUGIN_VER, autoloader,
│                                 //   cache-flush-on-version-change block (copied from Shrine), mu-updater installer (from the
│                                 //   LOGADOR instruction, 1:1), requires WC check (admin notice + bail if WC missing)
├─ includes/
│  ├─ class-plugin.php            // boot: load config, register frontend/cart/admin/rest/cron; activation: create tables, seed draft
│  ├─ class-config.php            // store, normalize, draft/publish/discard, history (last 10 published), import/export JSON
│  ├─ class-copy.php              // registry (groups → keys → defaults), effective(), tpl(), placeholders list per key
│  ├─ class-catalog.php           // WC adapters: wc_line(product_id) → purchasable line (variation "1 брой" rule), price, image, stock
│  ├─ class-frontend.php          // shortcode, enqueue, runtime JSON, draft/editor query vars, noindex, cache headers
│  ├─ class-cart.php              // perform_add, pricing, locks, validation, shipping, coupons, checkout rows, order meta, thank-you
│  ├─ class-rewards.php           // tickets: issue at processing/completed, QR, void on cancel; cosm consent → voucher at completed;
│  │                              //   book delivery email; status transitions
│  ├─ class-rest.php              // /ansa-promo/v1/ticket/{code} GET (public, rate-limited) · POST activate · admin list/export
│  ├─ class-leads.php             // gate email → leads table; convert on order; CSV export
│  ├─ class-admin.php             // menu (top-level "🎁 ansa Промо"), ajax get/save/publish/discard/search/pages/probe/history/export/import
│  ├─ class-doctor.php            // publish gate checks (see §7)
│  ├─ class-insights.php          // Phase 5: funnel counters (gate shown/email/box/fill/celeb/checkout/order per box, per utm)
│  ├─ class-partner.php           // Phase 5: ansa Partner hook (+1 ticket when partner coupon/ref present)
│  └─ mu/logador-github-updater.php
├─ assets/
│  ├─ promo.css / promo.js        // the v73 port, scoped under .ansa-promo, every string via T(key)
│  ├─ editor.css / editor.js      // the visual editor (§5)
│  └─ img/                        // reward art placeholders (yacht, set, book, truck) — replaced from Media later
├─ templates/
│  ├─ page.php                    // shortcode skeleton (same DOM ids as app.js expects)
│  ├─ email-book.php, email-voucher.php, email-ticket.php
│  └─ checkout-rewards.php        // FunnelKit review-order rows
├─ snippets/                      // the Code Snippets the user uploads — one per phase, see §8
├─ .github/workflows/release.yml  // from the LOGADOR instruction, with ansa-promo.php / ANSA_PROMO_VER / ansa-promo
├─ .gitignore, README.md
```

Version constant name: `ANSA_PROMO_VER` (the release Action greps it). Start at **1.0.0** for Phase 0 and bump patch per phase delivery (1.0.1, 1.0.2 …); minor when a phase introduces cart/order behaviour change (1.1.0 = cart, 1.2.0 = rewards).

---

## 4. Frontend architecture

### 4.1 Rendering model
Single-page app inside the shortcode root `<div class="ansa-promo" id="ansaPromo">`. State machine identical to mockup `S`: `{box, slots[], core, secret{}, pay, cosm, screen, utm, email, emailDone, timerEnd}`. Screens: `gate` (overlay) → `1 boxes` → fill popup (overlay, modal) → `celeb` (overlay) → `order` (screen 4/5 in mockup = payment handoff). Builder (screen 2) and picker remain as **edit paths** reachable from "Промени продуктите" / "Смени кутията" only.

Runtime JSON (`window.AnsaPromoRuntime`) is printed inline by `class-frontend.php`: config subset (boxes, rewards values, products with **live WC prices/images/stock**, fit, problems, copy effective map, utm param, deadline, timer, gate flags, nonce, ajax url, checkout url, draft flag, editor flag). Prices are display-only; the server recomputes on add.

### 4.2 Persistence of the in-progress box
Store `S` in `sessionStorage['ansa_promo_s']` (not localStorage — a new tab starts clean). Restore on load if `promo.id` matches. On "Продължи към плащане" the server is the truth (`ansa_promo_add` empties cart, adds group, returns `checkoutUrl`).

### 4.3 Porting rule for mockup → plugin
`app.js` from the mockup becomes `promo.js` with three mechanical changes only: (1) `PROD/PROBS/RW/BOXES` come from runtime JSON; (2) every literal Bulgarian string becomes `T('key', vars)` with the key registered in `class-copy.php` (the Shrine registry has 228 keys for v71 — regenerate for v73: gate v2 keys `gate2.*`, mobile box summary `bmini.*`, mobile fill header `mfill.*`, celebrate sticky footer); (3) the `.dev` demo bar and `?utm=` fake landing are removed; UTM comes from `utm_param`. Keep function names (`chooseBox`, `fillPopup`, `celebrate`, `productPopup`, `switchPopup`, `sureDown`…) so future mockup diffs map 1:1. CSS: prefix every selector with `.ansa-promo ` (postcss-prefix or a simple script at build time — commit the prefixed file, no build step on the site). Google Fonts Nunito: enqueue, but the page must not block on it (`font-display: swap`).

### 4.4 Mobile specifics that are already decided in v73 (must survive the port)
- Boxes page: three collapsed summaries (ribbon, name+packs, "Избирам →", reward sentences, "Подробности ▾" accordion one-open-at-a-time), order on mobile **Голяма · Средна · Малка**, on desktop Малка · Голяма · Средна.
- Fill popup: full-screen sheet, sticky header (box + −% / "Избери N опаковки от продуктите по-долу (може и еднакви) — така отключваш наградите:" / reward chips / "↺ смени кутията" + ✕ — both go back to boxes), scrollable product list (＋ Добави → stepper), sticky footer (progress "N от P" + CTA).
- Честито: sticky footer with the three exits; identical products grouped as "5× Sakura".
- Set price everywhere = pill under the text ("получаваш го за ~~€129~~ €1"), never a side column.

### 4.5 Gate (email popup)
v73 "g2" layout. Behaviour: shown once per session (sessionStorage), skippable ("Продължи без имейл"), email → `wp_ajax_nopriv_ansa_promo_lead` → leads table (+ optional hook `ansa_promo_lead_captured` for a mailer integration later). Prefills `billing_email` at checkout from session ctx. Gate `required:true` option exists but defaults false.

### 4.6 Caching
The page is static HTML + runtime JSON. Prices in the JSON are the only cache-sensitive data: print them with a short-TTL transient (`ansa_promo_runtime_{published_version}`, 5 min) and purge it on product save (`woocommerce_update_product`) and on publish. Page cache (FlyingPress/Cloudflare): add `Cache-Control: no-cache` only for draft/editor requests; published page is cacheable — but the runtime JSON must then be fetched by ajax when `S` restore happens after a checkout bounce (stale prices risk) — simplest: on load, `fetch(ajax?action=ansa_promo_runtime)` and re-render prices if changed. Do this from Phase 1 so caching never bites later.

---

## 5. Visual copy editor (Proof-Shrine-class)

Goal: the marketing person edits **every** customer-facing string by clicking on it in a live preview, with placeholders, without touching code, and publishes atomically with a Doctor gate. Shrine's `promo-admin.js` v2 already has regions + iframe canvas + inspector; extend it to this spec.

### 5.1 Layout (admin page, full-bleed, 3 columns)
- **Left rail (260px): Regions.** The groups from the copy registry in screen order, each with a count badge "12 · 3 changed". Below: ⚙️ Двигател (products, fit, problems, boxes, rewards, checkout, tickets), 🧰 Инструменти (Doctor, page picker, status, history, import/export, "Отвори страницата (чернова)").
- **Center: Canvas.** `<iframe src="{page_url}?ansa_promo=draft&ansa_editor=1&ansa_screen=…">`. Device toggle 📱 390 / 💻 1280 (iframe width + CSS zoom to fit). Scenario bar above the canvas: `UTM: [sakura ▾]` · `Кутия: [Голяма ▾]` · `Екран: gate | boxes | fill | celeb | builder | sure | order` · `Пълна кутия ☑`. The editor drives the iframe via `postMessage({ansaPromo:'scenario', ...})`; promo.js in editor mode exposes `window.AnsaPromo.scenario(o)` that sets `S` and renders without animations/timers. Hotspots: every element rendered through `T()` carries `data-ck="key"`; in editor mode hover outlines it, click posts `{ansaPromo:'ck', key}` to the parent; the parent selects the region containing the key and opens the inspector. Changed keys get a dot outline.
- **Right pane (360px): Inspector.** Key label (human title from registry, e.g. "Честито · заглавие"), textarea (auto-grow; `Ctrl+Enter` = apply), live preview as you type (debounced 150 ms `postMessage({ansaPromo:'copy', key, value})` — the iframe re-renders only that key), placeholder chips for this key (click inserts `{{…}}`), "⟲ Върни дефолта", "Къде се използва" (screens list), prev/next key within the region. Validation: unbalanced `{{`, unknown placeholder, empty string for a required key → red, blocks save of that key.
- **Top bar:** draft status ("Чернова · 14 промени · запазена 12:03"), 💾 Запази (Ctrl+S, autosave every 20 s if dirty), 🚀 Публикувай (runs Doctor; disabled on `bad`), ↶ Откажи черновата, 🕘 История (restore any of the last 10 published versions as a new draft), 🔍 Търсене в текстовете (filters regions and jumps to key), "само променените" toggle.

### 5.2 Data flow
- `GET ajax_get` → `{cfg: draft, published_version, checks, registry: {groups, keys:{key:{default, title, group, vars[], screens[]}}}}`.
- Save = whole draft JSON (`ajax_save`), idempotent; server normalizes and returns the stored draft (so the UI never drifts). Copy override is stored only when `value !== default`.
- Publish = `ajax_publish` → copies draft → published, bumps version, appends to history, purges runtime transient + page URL, returns new state.
- Editor-mode runtime: the iframe loads `?ansa_promo=draft` (admin-only, nocache, noindex) and gets **draft** config + **registry defaults merged**, so the canvas is the real page. No second renderer, ever.

### 5.3 Engine tab (structured data, not copy)
Form sections with the same save path: Products (WC search → product_id; auto-fill name/image/price readout; UTM key; category; theme colors; FIT chips; ing/who/rating/reviews), Problems (list; product picker; "why" text), Boxes (packs/pct/tickets/rewards/cosm_pay/tag; drag order per device), Rewards (yacht value, book product, cosm product, images from Media), Checkout (gateway ids from WC, block coupons, consent default), Tickets (portal URL, secret regenerate, partner bonus), Game (deadline, timer, gate enabled/required, utm param). Every field change re-renders the canvas (the iframe gets the whole draft on `postMessage({ansaPromo:'cfg', cfg})`).

### 5.4 Inline editing (v2 of the editor, Phase 2b, only after the user asks)
`contenteditable` on the hotspot inside the iframe with the same key sync. Not in the first editor release — the inspector is enough and safer.

---

## 6. Backend engine details

### 6.1 Add-to-cart (port of Shrine `perform_add()` with these changes)
- Input: `{box, items[product_id…] exactly packs, email, cosm, pay, utm, gate_lead_id}`; nonce + rate limit (10/min/IP).
- Validation order: live → box → count → allowed → purchasable/stock → secret. Errors return `{ok:false, code, msg}` with copy keys `err.*` so the messages are editable.
- Empty cart first (promo order is standalone). Add base lines with `_ansa_promo` meta; book line if box has `book`; secret lines only if `secret.enabled`.
- Session ctx as in Shrine; set `chosen_payment_method` from the box choice; return `checkoutUrl`.
- **Idempotency:** if the cart already holds a valid group with identical composition, don't re-add — return the same checkoutUrl (double-tap on mobile).

### 6.2 Pricing/locks/validation/shipping/coupons
Exactly Shrine's hooks (`woocommerce_before_calculate_totals` 95, `cart_item_quantity`, `cart_item_remove_link`, `check_cart_items`, `package_rates` 50, `coupon_is_valid`). Fixes to carry: compute `pct` from the **published** config at the time of add and store it in the group meta (`pct_at_add`), so a publish mid-session doesn't reprice a cart silently; group invalid → regular prices and a `wc_add_notice(error)` with a link to the promo page.

### 6.3 Checkout (FunnelKit)
Rewards row under cart contents (templates/checkout-rewards.php) and the cosmetics ☑ consent before submit; billing email prefilled from ctx; order meta at `woocommerce_checkout_create_order` (+ Store API variant for block checkout — FunnelKit uses the classic form, but register both). Thank-you block: box, tickets count, "QR-кодът е в пратката" sentence, set status.

### 6.4 ansa Partner (+1 ticket)
Partner attribution works through the partner's coupon applied via `?ref=`. Promo blocks coupons — **except** coupons flagged as partner coupons: implement `apply_filters('ansa_promo_allow_coupon', false, $coupon)` and in Phase 5 add a bridge that returns true for ansa Partner coupons **and zeroes their discount** (`woocommerce_coupon_get_discount_amount` → 0 for promo carts) so attribution survives but no discount stacks. Tickets +1 when such a coupon is present at order creation.

### 6.5 Rewards lifecycle
- `woocommerce_order_status_processing|completed` (first time only, guard meta `_ansa_promo_ticket_id`): issue ticket row, generate QR PNG (endroid/qr-code is heavy — use a tiny pure-PHP QR lib vendored, or render QR client-side on the packing slip page; decide in Phase 4 and note it), attach to order note + expose `GET /wp-json/ansa-promo/v1/order/{id}/qr.png` (admin/packing only, nonce) for the warehouse's packing slip.
- Portal (separate site, later): `GET /ansa-promo/v1/ticket/{code}` → `{valid, count, box, activated}`; `POST …/activate` `{code, email|phone}` → marks activated, fires `ansa_promo_ticket_activated` (notification "Ти се регистрира с X билета" is the portal's job; we only expose the data). HMAC checksum in the code so invalid codes are rejected without a DB hit; rate limit 30/min/IP.
- `completed`: email voucher for the set (template), set `_ansa_promo_cosm_status=voucher_sent`; email the book PDF link (book product's downloadable file) if not already granted by WC downloads.
- `cancelled|refunded`: void the ticket.
- Admin list: 🎁 ansa Промо → Поръчки (table: order, box, packs, tickets, code, activated, set status, partner) + CSV export; filter by status.

### 6.6 Cron
Daily: expire leads older than 180 days without conversion (GDPR hygiene), void tickets of orders cancelled >7 days. Hourly: nothing. Keep WP-Cron; note Action Scheduler is available with WC if needed.

---

## 7. Doctor (publish gate) — checks, level, message key
`bad` blocks publish; `warn` shows.
1. page_id set and page contains `[ansa_promo]` — bad.
2. ≥1 enabled product with purchasable line and price > 0 — bad; every product has image — warn.
3. Every box: packs ≥1, 0 < pct < 100, rewards valid keys, cosm_pay set for boxes with cosm — bad.
4. Book product exists, purchasable, virtual+downloadable — bad if box has `book`.
5. Cosm product exists, hidden (catalog visibility hidden), price 0 — bad.
6. Gateways: card_gateway and cod_gateway ids exist and enabled — bad.
7. Deadline in the future — warn; `enabled` false — warn ("играта е изключена").
8. Copy: no unknown placeholders, no empty required keys — bad; untranslated `{{` left in output — bad.
9. Shrine present and its promo module disabled (constant guard) — bad if Shrine ≥6.19.22 loads the module anyway.
10. Tickets secret set — bad from Phase 4.
11. Problems: each product referenced exists and enabled — bad; FIT references valid — warn.
12. Caching plugin detected (FlyingPress/WP Rocket/LiteSpeed) — info with the exclusion list to configure (jQuery, wc-add-to-cart, promo.js).

---

## 8. Phases (each = one release on `main`, one snippet, wait for the pasted output)

**Phase 0 — Repo + skeleton (1.0.0).** Repo `proclaudecopilot/ansa-promo` (ask the user to create the empty private repo if `add_repo` says it doesn't exist). Main file, updater, Action, autoloader, activation (tables), empty admin page, `[ansa_promo]` printing "ansa Промо — скоро". Shrine 6.19.22 with the bootstrap guard. Snippet 0: env report (WP/WC/PHP versions, HPOS on/off, FunnelKit present + checkout page id, gateways list, caching plugin, Shrine version + whether its promo module loaded, mu-updater version, `ansa_promo_store` option present + its keys, tables exist, page with shortcode).
*Exit:* snippet shows plugin active, Shrine module not loaded, updater sees the repo.

**Phase 1 — Config + copy registry + frontend port (1.0.1).** `class-config/copy/catalog/frontend`, the v73 port, gate + leads, runtime ajax, draft query var. No cart — "Продължи към плащане" shows a toast "Фаза 1 — плащането идва в следващата версия". Snippet 1: loads draft/published, counts registry keys vs `T()` keys used in promo.js (list orphans both ways), resolves each product to its WC line (id, price, stock, image url), prints the runtime JSON size, fetches the page as anonymous and checks the root div + JSON present, checks no `{{` left in rendered HTML. *Also the user looks at the page* — this is the one phase where visual confirmation matters; ask for "Браво" on the live page before Phase 2.

**Phase 2 — Editor (1.0.2).** §5.1–5.3 fully: regions, canvas, scenarios, inspector, engine forms, Doctor, history, import/export. Snippet 2: simulates the ajax round trip (get → modify one key → save → get → assert; publish → assert version+1 and history length; discard → assert), checks the draft page renders the overridden key, verifies nonce/cap guards (nopriv call must fail).

**Phase 3 — Cart + checkout (1.1.0).** §6.1–6.3. Snippet 3 (`?ansa_promo_test=1`, `&keep=1`): for each box: add valid composition → assert line count, prices = regular×(1−pct), book line €0, shipping free/paid, coupon rejected, qty/remove locked, ctx set; tamper (remove a line via cart API) → prices regular + checkout notice; wrong count → error code; idempotent re-add; order meta written on a programmatic `WC_Checkout::create_order` with COD. Also verify Shrine's cart-gift didn't inject anything into the promo cart.

**Phase 4 — Rewards (1.2.0).** Tickets table + issuance + QR + REST + emails (voucher, book) + admin orders list + cron. Snippet 4: create order (COD) → set processing → assert ticket row/code/HMAC valid; GET REST valid/invalid codes; POST activate; completed → assert emails queued (hook into `wp_mail` filter and log) and `_ansa_promo_cosm_status`; cancel → void.

**Phase 5 — Insights + ansa Partner (1.3.0).** Funnel counters (ajax beacon per step, daily rollup table, admin tab with per-box/per-UTM conversion), partner coupon bridge (+1 ticket, zero discount). Snippet 5: fire beacons, assert rollup; apply a partner coupon to a promo cart → assert allowed, discount 0, ticket count +1 on order.

**Phase 6 — Hardening (1.3.x).** Load the published page under FlyingPress/Cloudflare with the exclusion list, double-submit protection, accessibility pass (focus trap in modals, ESC where allowed), `noindex` on draft, translation-ready (`ansa-promo` textdomain for admin strings only — customer copy is the registry), uninstall.php (keeps tables unless `ANSA_PROMO_UNINSTALL_DROP`).

Each phase README section: what changed, what the snippet proves, open questions (with defaults taken).

---

## 9. Questions for the user — ask at the phase that needs them, one message, with defaults

- (P0) Repo name confirmed `proclaudecopilot/ansa-promo`? Default yes.
- (P1) Product photos: Media library attachments exist, or use WC product images? Default WC product image of the resolved line.
- (P1) Reward art (yacht/set/book) — real images or keep emoji until provided? Default emoji placeholders, swappable in ⚙️ Двигател.
- (P3) FunnelKit checkout page id and whether the thank-you is FunnelKit's or WC's. Default: detect via FunnelKit API, fall back to WC.
- (P4) Portal domain for QR links; who builds the portal (if us: separate repo `ansa-promo-portal`). Default: `portal_url` setting, portal out of scope.
- (P4) QR on the packing slip: which packing/label plugin prints the slip (to hook the QR into it). Default: admin endpoint PNG + note on the order.
- (P5) ansa Partner coupon detection: coupon meta key / prefix used by the Partner plugin. Default: filter `ansa_partner_is_partner_coupon` if Partner exposes it, else coupon description prefix `partner:`.

---

## 10. Non-negotiables checklist for every release
- [ ] Version bumped in header **and** `ANSA_PROMO_VER`; `php -l` clean; `GitHub Plugin URI` present.
- [ ] No customer-facing literal strings in PHP/JS — everything through the registry (`grep -n "[А-Яа-я]" assets/promo.js` must return only comments).
- [ ] Prices never trusted from the client; every ajax has nonce + capability (`nopriv` only for add/lead/runtime/beacon).
- [ ] Copy rules D11 applied in registry defaults; `sPrep/vPrep` helpers used for със/във.
- [ ] Mobile screenshots at 390×780 for boxes / fill / celeb attached to the phase note (Playwright, `NODE_PATH=$(npm root -g)`, block Google fonts, `domcontentloaded`).
- [ ] Snippet file in `snippets/phase-N.php` and sent to the user with one sentence what to paste back.
- [ ] Nothing in Shrine touched except the Phase 0 guard (and a Phase 3 cart-gift guard if the snippet proves it's needed).
