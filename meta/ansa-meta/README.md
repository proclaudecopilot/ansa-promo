# ansa™ Meta

WordPress/WooCommerce плъгин за ansa.bg: **Meta Pixel + Conversions API** с максимално пълни данни за Meta. Всяко събитие тръгва и от браузъра (Pixel), и от сървъра (CAPI) с **един и същ `event_id`**, така че Meta ги дедуплицира и брои веднъж, но вижда и тези, които браузърът губи (iOS без проследяване, ad blocker, Safari, затворена страница „Благодаря“). Самостоятелен плъгин — не зависи от ansa™ Промо, но го ползва, когато е активен.

Живее в repo-то `ansa-promo` под `meta/ansa-meta/` (по модела на `probe/ansa-probe/`). Release-ът на промото носи и `ansa-meta-X.Y.Z.zip`; на сайта плъгинът се обновява през proclaudecopilot GitHub Updater (header `GitHub Release Asset: ansa-meta`). Първата инсталация е ръчна (zip).

## Какво праща

| Събитие | Откъде | Данни |
|---|---|---|
| `PageView` | браузър → REST → сървър | url, всички идентификатори |
| `ViewContent` | продуктова страница; страницата на играта (всички продукти); `/kozmetichen-set-promo` (Sakura) и listicle (комплектът) | content_ids, content_name, content_category, value, currency, contents, product_group_id |
| `ViewCategory` (custom) | страница на категория | content_category, content_ids на първите 10 продукта |
| `Search` | търсене | search_string, content_ids на резултатите |
| `AddToCart` | сървър (`woocommerce_add_to_cart`) + браузър (флаш/фрагменти); **кутията на промото = едно събитие** (`ansa_promo_added`) | contents на цялата количка, value, num_items, promo_box |
| `InitiateCheckout` | чекаут (вкл. FunnelKit) | количката от сървъра |
| `AddPaymentInfo` | избор на плащане / „Поръчай“ | количката + **попълнените billing полета** (имейл, телефон, имена, град, пощенски код) → хешират се на сървъра |
| `Purchase` | **сървър при създаване на поръчката** (или при плащане — настройка) + браузър на „Благодаря“ | contents, value = total, currency, order_id, num_items, coupon, payment_method, shipping, delivery_category |
| `Lead` | попъпът на промото (`ansa_promo_lead_captured`) | имейлът; помни се в сесията за следващите събития |
| `CompleteRegistration` | нова клиентска регистрация | — |

**Данни за съвпадение (user_data) при всяко събитие** — каквото е налично: `em`, `ph` (с код 359), `fn`, `ln`, `ct`, `zp`, `country`, `st` (само САЩ), `external_id` (клиент `u<id>` / наш visitor id / имейл), `client_ip_address` (Cloudflare/X-Forwarded-For), `client_user_agent`, `fbp`, `fbc` (от бисквитката или от `?fbclid`). Хешове SHA-256 след нормализацията на Meta (вж. `class-identity.php`). Източници по приоритет: поръчката → логнатият потребител → WC клиентът в сесията → попълненото в чекаута → имейлът от попъпа.

**Бисквитки от сървъра.** REST отговорите подновяват `_fbp`, `_fbc` (90 дни) и `ansa_meta_vid` (2 години) като first-party HTTP бисквитки — Safari реже на 7 дни само бисквитки, сложени от JavaScript. Ако Pixel-ът е блокиран, `meta.js` сам слага `_fbp`, така че сървърните събития пак носят идентификатор.

**Самостоятелните страници на промото** (`/kozmetichen-set-promo`, `/info`, listicle, `/tombola`, `/kniga`) се рендират без wp_head/wp_footer и до 1.31.3 нямаха никакъв Pixel. ansa™ Промо ≥ 1.31.4 пуска `ansa_promo_standalone_footer( '', $mode, $cfg )` преди `</body>`; ansa™ Meta слага там конфигурацията и `meta.js` → PageView + ViewContent (Sakura ×5 на `sx`, комплектът на `li`). Самата страница на играта (`/sezonut-na-ansa`, с wp_footer) праща PageView + ViewContent с всички продукти на играта; кутията → AddToCart от сървъра; попъпът → Lead. Страниците са кеширани, затова в HTML-а няма лични данни — те идват от сървъра през REST.

**Purchase е веднъж на поръчка.** `event_id` и контекстът на браузъра (fbp, fbc, IP, UA, vid, URL) се записват в поръчката още при създаването ѝ (`_ansa_meta_event_id`, `_ansa_meta_ctx`); тръгва при `checkout_order_processed` (наложен платеж/офлайн gateway веднага; карта — веднага при „при създаване“ или след `payment_complete` при „при плащане“), резервно при смяна на статус към processing/completed/on-hold и на „Благодаря“; `_ansa_meta_purchase_sent` пази от второ пращане. Браузърът на „Благодаря“ праща същия `event_id` (и FunnelKit thank-you страници — по `?key=wc_order_…`), веднъж (`_ansa_meta_thankyou`). Поръчки на `probe@example.com` (ansa™ Probe) не се броят; операторските (📞) заявки на промото — също.

## Надеждност

- Събитията се събират в заявката и тръгват на `shutdown` **след** отговора към браузъра (`fastcgi_finish_request`) — потребителят не чака Meta.
- Грешка на мрежата/5xx/лимит/token → опашка (`wp_ansa_meta_queue`); крон на всяка минута (`ansa_meta_flush`) праща партида до 200, backoff 1·2·4·…·128 мин, 8 опита, после отпада с ред в дневника. „Невалиден параметър“ (код 100) не се повтаря; при партида с едно невалидно останалите минават поединично.
- Дневник `wp_ansa_meta_log` (последните N реда): събитие, откъде, ok/HTTP, ms, отговорът на Meta (`events_received`, `fbtrace_id` или грешката), резюме — кои user_data ключове носи, стойност, id-та, URL; при Debug — целият payload (хеширан).
- Кеш: продуктовите/категорийните страници са кеширани (FlyingPress) → `event_id` се прави в браузъра, сървърната половина минава през `POST /wp-json/ansa-meta/v1/event` (не се кешира; лимит 600 заявки / 5 мин на IP). Лични данни в HTML-а има само на некеширани страници (логнат, чекаут, „Благодаря“, акаунт).
- Не се броят: роли (по подразбиране administrator, shop_manager), вътрешни IP/CIDR, ботове, посетители без съгласие (ако е зададена consent бисквитка; при даване meta.js тръгва сам — слуша бисквитката и събитие `ansa-meta:consent`).

## Инсталация и настройка

1. **Zip**: от release-а на `ansa-promo` свали `ansa-meta-X.Y.Z.zip` (или направи zip на папката `meta/ansa-meta/` → `ansa-meta/` вътре) → Plugins → Add New → Upload → активирай. Нататък се обновява сам.
2. **Events Manager** (business.facebook.com/events_manager): Data sources → Pixel-ът на ansa.bg → копирай **Dataset/Pixel ID** → Settings → секция **Conversions API** → **Generate access token** (или System User token с `ads_management`). Включи и **Automatic advanced matching** (всички полета) — Pixel-ът сам чете попълнените форми.
3. **ansa™ Meta → ⚙️ Настройки**: Pixel ID, token (или константи `ANSA_META_PIXEL_ID` / `ANSA_META_TOKEN` в `wp-config.php`), Запази. Останалото може да остане по подразбиране.
4. **Изключи Facebook Pixel-а във FunnelKit** — на ansa.bg (проверено с Probe, 26.10) Pixel 1734259157105331 се зарежда от FunnelKit Funnel Builder: PageView на всички страници, AddToCart/ViewContent глобално, InitiateCheckout, AddPaymentInfo, Purchase с CAPI, **без Advanced Matching** и без изчакване на съгласие. FunnelKit → Settings → Tracking → Facebook Pixel → изтрий Pixel ID-то (TikTok и Google там остават). Pixel Manager има празен Facebook Pixel ID (само Google Ads) — нищо за правене там. 🩺 Състояние предупреждава, докато FunnelKit-ът още праща. Два Pixel-а с различни event_id = двойни събития.
5. **FlyingPress** (или друг „Delay JavaScript“): добави `ansa-meta`, `fbevents`, `connect.facebook.net` в изключенията от отлагане — иначе PageView чака първото докосване на екрана. Скриптът носи и `data-no-delay`/`nowprocket` атрибути за оптимизаторите, които ги четат.
6. **Complianz**: сайтът е в opt-in режим за ЕС. Празна consent бисквитка = Pixel + CAPI за всички (както FunnelKit сега); `cmplz_marketing` съдържа `allow` = чакаме приемането на банера (meta.js тръгва веднага след него). Решението е на човека; 🩺 Състояние го показва.
7. **🧪 Тест**: Events Manager → Test events → кодът `TEST…` → „Изпрати“ → PageView + Purchase се появяват като Server събития с пълни данни. После отвори продукт/чекаут в обикновен браузър и гледай Events Manager → Overview: всяко събитие „Browser · Server · Deduplicated“, **Event Match Quality** на Purchase 8+.

### Настройки (⚙️)

- **content_ids**: ID (по подразбиране) / SKU / `wc_post_id_ID` — да съвпада с каталога в Commerce Manager, ако има динамични реклами.
- **Purchase тръгва**: при създаване на поръчката (най-много данни, както Shopify) / при плащане.
- **PageView от сървъра**: най-много заявки, но най-пълни аудитории.
- **Събития**: всяко се спира поотделно. **Роли / вътрешни IP / ботове / съгласие**: кого не броим. **LDU**: само за САЩ.
- **Дневник**: колко реда; Debug = целият payload.

## Hook-ове за разширение

`ansa_meta_event( $event, $name, $opts )` · `ansa_meta_user_data( $user_data, $plain )` · `ansa_meta_current( $plain )` · `ansa_meta_from_order( $plain, $order )` · `ansa_meta_product_data` / `ansa_meta_cart_data` / `ansa_meta_order_data` · `ansa_meta_excluded( $why )` · `ansa_meta_skip_order( $skip, $order )` · `ansa_meta_offline_gateways( [cod, bacs, cheque] )` · `ansa_meta_rest_events( [...] )` · `ansa_meta_js_config( $cfg )` · `ansa_meta_finish_request( true )` · `ansa_meta_timeout`.

JS: `ansaMeta.track( 'Contact', { content_name: 'телефон' } )` — Pixel + сървър с общ event_id (името трябва да е в `ansa_meta_rest_events`, иначе само Pixel). Промото (promo.js) може да го вика за междинни стъпки.

PHP от друг плъгин: `\AnsaMeta\Capi::track( 'Subscribe', $custom_data, \AnsaMeta\Identity::current(), array( 'source' => 'server' ) )`.

## Файлове

```
ansa-meta.php              header (GitHub Plugin URI: proclaudecopilot/ansa-promo · GitHub Release Asset: ansa-meta), автолоудър, OPcache guard, HPOS, boot
includes/class-plugin.php  boot/activate/deactivate; enabled() = Pixel ID + token + „Включено“ − ANSA_META_DISABLED
includes/class-settings.php option ansa_meta, defaults, sanitize, константи
includes/class-schema.php  таблиците ansa_meta_queue / ansa_meta_log, purge
includes/class-identity.php нормализация + SHA-256 (Meta spec), fbp/fbc/vid бисквитки, IP/UA, роли/IP/бот/съгласие, данни от поръчка/потребител/WC клиент/сесия
includes/class-catalog.php content_ids формат, product/category/search/cart/order custom_data
includes/class-capi.php    event() · track() → буфер → flush на shutdown (fastcgi_finish_request) → send() към graph.facebook.com/{v}/{pixel}/events; test(); log(); stats()
includes/class-queue.php   опашката: push, process (крон / „Изпрати сега“), backoff, отпадане
includes/class-frontend.php meta.js + конфигурацията за страницата (събития, Advanced Matching, флаш от сесията, „Благодаря“ по order key), фрагменти
includes/class-rest.php    POST ansa-meta/v1/event (сървърната половина на браузърните събития), GET ansa-meta/v1/ping
includes/class-woo.php     AddToCart, Purchase (stamp при създаване, веднъж на поръчка), Lead/AddToCart от промото, CompleteRegistration
includes/class-admin.php   менюто ansa™ Meta: ⚙️ Настройки · 🩺 Състояние · 📜 Дневник · 🧪 Тест
includes/class-doctor.php  редовете на Състояние (и за Probe)
includes/class-probe.php   suite meta-env за ansa™ Probe
assets/meta.js             Pixel snippet + fbq с eventID, REST огледало (fetch keepalive), fbclid → _fbc, vid, AddPaymentInfo + billing полета, added_to_cart, consent
tests/                     офлайн: php tests/identity-test.php (73) · php tests/capi-test.php (104)
uninstall.php              трие option-ите, таблиците, крона (order meta остава)
```

## Нова версия

Вдигни `Version:` и `ANSA_META_VER` в `ansa-meta.php`, push в `main` на `ansa-promo`. Workflow-ът на промото прави `ansa-meta-X.Y.Z.zip` и го закача към release-а на текущата версия на промото (нов или вече съществуващ) → сайтът вижда новата версия през updater-а (сравнява по версията в името на файла).
