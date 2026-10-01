# ansa™ Промо

WordPress/WooCommerce плъгин на LOGADOR за ansa.bg: промо играта с трите кутии (страница `[ansa_promo]`, визуален редактор на текстовете, количка/чекаут, дигитални билети, Insights). Самостоятелен плъгин — ansa™ Shrine остава активен за продуктовите страници; неговият вътрешен промо модул (6.18/6.19) е пенсиониран и не се зарежда, щом този плъгин е активен (guard в Shrine ≥ 6.19.22).

Планът на целия проект (фази, домейн модел, редактор, Doctor, въпроси към човека): [`docs/ansa-promo-plugin-plan-v1.md`](docs/ansa-promo-plugin-plan-v1.md). Дизайн: [`docs/ansa-promo-mockup-v73.html`](docs/ansa-promo-mockup-v73.html) — портва се 1:1, не се редизайнва.

## Нова версия
1. Вдигни версията на **две места** в `ansa-promo.php`: header `Version:` и `define( 'ANSA_PROMO_VER', ... )`.
2. Push в `main`.

GitHub Action-ът (`.github/workflows/release.yml`) прави `php -l`, `ansa-promo.zip` (папка `ansa-promo/` вътре; `docs/`, `snippets/` и `*.md` не влизат), тага `vX.Y.Z` и Release-а, после пингва сайта (secrets `LOGADOR_HOOK_URL` / `LOGADOR_HOOK_SECRET` от Settings → GitHub ъпдейти; без тях само предупреждава). Сайтът вижда новата версия през LOGADOR GitHub Updater (mu-plugin, инсталира се сам от плъгина) → Plugins → Update now / auto-updates. Zip се качва ръчно само за първата инсталация.

## Структура
```
ansa-promo.php          header, константи (ANSA_PROMO_VER, ANSA_PROMO_PLUGIN_VER), autoloader AnsaPromo\, cache flush
                        при смяна на версията, HPOS compat, boot на plugins_loaded (5), mu-updater инсталатор
includes/class-plugin.php    boot, activate/deactivate, cron, Shrine helpers (shrine_version, shrine_promo_module_loaded, shortcode_owner)
includes/class-schema.php    таблици {prefix}ansa_promo_tickets / _leads (dbDelta при активация и при смяна на ANSA_PROMO_DB_VER)
includes/class-config.php    option ansa_promo_store {draft, published, published_at, version, history[10]}; normalize; publish/discard/restore; export/import
includes/class-copy.php      копи-регистърът (мокъп v73): groups → keys → defaults; effective/diff/validate/render; vars()
includes/class-catalog.php   WC адаптери: wc_line (вариация „1 брой“), search, find_by_name; runtime transient + purge
includes/class-frontend.php  [ansa_promo] (init 20), enqueue, runtime JSON (window.AnsaPromoRuntime), ?ansa_promo=draft, noindex, ajax runtime
includes/class-leads.php     gate имейл → таблица leads (ajax ansa_promo_lead, nonce + rate limit), cron 180 дни
includes/class-seed.php      началната чернова от мокъпа (продукти, FIT, проблеми); product_id по име в WC
includes/class-admin.php     меню „🎁 ansa Промо“: Състояние · 🧰 Инструменти (страница, игра, срок, gate, публикуване, история, seed) · JSON
includes/class-doctor.php    env() — редовете на екрана „Състояние“; Фаза 2 добавя checks()
includes/mu/                 LOGADOR GitHub Updater (копира се в mu-plugins)
assets/promo.css             порт на CSS-а от мокъпа — генерира се с docs/port-css.py, не се пипа на ръка
assets/promo-extra.css       добавките на плъгина върху порта: WC снимки, z-index над темата, бадж/цени в пълненето, цял екран
templates/fullscreen.php     темплейт „ansa™ Промо — цял екран“ (Page Attributes → Template) — без хедър/футър на темата
assets/promo.js              порт на app.js от мокъпа — PROD/BOXES/копи от runtime JSON, всеки текст през T(key)
templates/page.php           скелетът (id-та apS1/apOv/… — мокъп s1/ov/… с префикс ap)
tests/                       wpstub.php + harness/ (статична страница без WP + Playwright скрийншоти/проверки за {{ }}, undefined, JS грешки)
snippets/phase-N.php         Code Snippet за всяка фаза — self-check на живия сайт, човекът праща отчета
docs/                        планът + мокъпът + port-css.py + shots/ (не влизат в zip-а)
```

## Фази
| Фаза | Версия | Какво | Снипет |
|------|--------|-------|--------|
| 0 | 1.0.0 · 1.0.1 | репо, скелет, updater, таблици, guard в Shrine 6.19.22; 1.0.1 = LOGADOR updater 1.1.2 (поглъща форка на Partner) | `snippets/phase-0.php` — отчет за средата |
| 1 | 1.0.2 … 1.0.6 | конфиг + копи-регистър + порт на мокъп v73 + gate/leads + runtime ajax; 1.0.3 = цял екран, WC снимки, бадж с цена, таб 📦 Продукти | `snippets/phase-1.php` |
| 2 | 1.0.3 | визуалният редактор | — |
| 3 | 1.1.0 | количка и чекаут | — |
| 4 | 1.2.0 | награди: билети, QR, REST, ваучер, книга | — |
| 5 | 1.3.0 | Insights + ansa Partner | — |
| 6 | 1.3.x | hardening | — |

### Фаза 0 (1.0.0)
**Какво има:** главен файл с header + `GitHub Plugin URI`, autoloader (`AnsaPromo\Foo_Bar` → `includes/class-foo-bar.php`), OPcache/object-cache flush при смяна на версията (от Shrine v6.12), HPOS compat декларация, mu-updater инсталатор (LOGADOR 1.1.1 — с webhook), таблици `tickets` и `leads`, top-level меню „🎁 ansa Промо“ с екран „Състояние“, `[ansa_promo]` → „ansa™ Промо — скоро.“ (+ един ред бележка само за админи). Option `ansa_promo_store` НЕ се пипа — Фаза 1 я чете/нормализира (съществуваща чернова от Shrine мигрира).

**Какво доказва Snippet 0:** плъгинът е активен и зареден (константи, класове), shortcode-ът е на `AnsaPromo\Frontend`, Shrine версия ≥ 6.19.22 и промо модулът му НЕ е зареден (guard), таблиците ги има, updater-ът вижда и двете репота (token + latest release), FunnelKit/HPOS/gateways/кеш за следващите фази, анонимен fetch на страницата с shortcode-а (ако има такава).

**Решения на билдъра (без питане):** таблици `{prefix}ansa_promo_tickets` (колона `tickets`, не `count`; + `box`) и `{prefix}ansa_promo_leads`; schema версия в option `ansa_promo_db_ver`; shortcode на `init` 20; mu-updater 1.1.1 вместо 1.0.1 на ansa-shrine (инсталаторът го качва само ако е по-нов — сайтът получава и webhook-а); `Requires Plugins: woocommerce` в header-а; `docs/` и `snippets/` извън zip-а.

### Фаза 0 · 1.0.1 (hotfix) — LOGADOR GitHub Updater 1.1.2
На ansa.bg има два форка на updater-а: LOGADOR (Shrine, Промо, Proof) и „proclaudecopilot“ (ANSA Partner 5.8.5, `proclaudecopilot-github-updater.php`, клас `PCC_GitHub_Updater`, страница `pcc-github`, собствен token option; при инсталация трие `logador-github-updater.php`). Двата заедно = две менюта „GitHub ъпдейти“ и двойни заявки. LOGADOR е по-новата линия (webhook, 29.09 следобед; D8 от плана) и затова печели: 1.1.2 дефинира guard-константата на форка (mu-plugins се зареждат по азбучен ред, l < p), той се връща на първия си ред; `page=pcc-github` се пренасочва към нашата страница. Token-ът се въвежда на страницата на LOGADOR (форкът го пази в свой option, който не четем). **Истинската поправка е в репото на Partner** (следващ release на affiliate-portal-ansa да носи LOGADOR 1.1.2 вместо форка) — иначе при ъпдейт на Partner с PCC > 1.0.2 инсталаторът му ще изтрие LOGADOR файла за една заявка (Промо го връща в същата заявка).

**Отворени въпроси (с дефолт):** (P0) репото е `proclaudecopilot/ansa-promo` — потвърдено от човека. Останалите (§9 от плана) се питат във фазата, която ги иска.

### Фаза 1 (1.0.2) — конфиг · копи-регистър · порт на мокъп v73
**Какво има:** `Config` (чернова/публикувано/история 10/export/import), `Copy` (300 ключа в 13 групи, плейсхолдъри, validate), `Catalog` (wc_line по правилото „1 брой“, runtime transient 5 мин + purge при продукт/публикуване), `Frontend` (скелет + inline runtime + promo.css/js; `?ansa_promo=draft`, `&ansa_editor=1`; noindex при чернова/изключена игра; ajax `ansa_promo_runtime` за кеширани страници), `Leads` (ajax `ansa_promo_lead`, nonce + 10/мин/IP, hook `ansa_promo_lead_captured`, cron 180 дни), `Seed` (черновата от мокъпа при първо зареждане; product_id по име в WC), 🧰 Инструменти + JSON таб. promo.js: порт на v73 с T() навсякъде, sessionStorage на кутията, gate веднъж на сесия, `window.AnsaPromo.scenario()` + postMessage copy/cfg/scenario за редактора (Фаза 2), „Завърши поръчката“ → тост `ms.preview` (cart=false до Фаза 3).

**Какво доказва Snippet 1:** option-ът и черновата (id, кутии, награди, order), всеки продукт → WC ред (id, вариация, цена, наличност, снимка), регистър vs. ключовете в promo.js (липсващи = 0), невалидни плейсхолдъри = 0, забранени фрази (D11/D4) = 0, кирилица в JS само граматични помощници, размер на runtime JSON, анонимен fetch на страницата (root div, JSON, css/js, noindex, TranslatePress следи), `ansa_promo_runtime` като анонимен, `ansa_promo_lead` с лош nonce → 403, cron насрочен.

**Офлайн доказателство (tests/harness):** Playwright на 390×780 и 1280×900 през gate → кутии → пълнене → честито → поръчка → без UTM → малка кутия → табло → „сигурна ли си“ → смяна надолу → продукт → селектор: 0 JS грешки, 0 непопълнени `{{ }}`, 0 undefined/NaN. Скрийншоти: `docs/shots/phase-1/`.

**Решения на билдъра:** `order` е `{desktop:[s,l,m], mobile:[l,m,s]}` (мобилният ред е и в CSS на мокъпа, JS-ът го налага inline); `bgn {show, rate}` за „(… лв.)“ до крайната сума (мокъпът го има); продуктът има поле `pack` („60 капсули · за 30 дни“) с fallback към `prod.pack`; `gate.consent_default`; shortcode-ът на изключена игра печата „скоро“ + линк към черновата за админи; текстовете за билетите казват „QR код в пратката“ (D4), не „скреч карта“ (мокъпът); сетът навсякъде е „Новият козметичен сет на ansa™ · 5 уникални продукта на стойност €129. Стартира през 2027 година.“ (D11); `rw.info.book` казва „по имейл“ (D6), не „печатна книга“.

**Какво човекът трябва да види (единствената визуална фаза):** `/страницата/?ansa_promo=draft&utm_content=sakura` на телефон и десктоп — gate → три кутии → „Напълни кутията си“ → „Честито“ → поръчка (бутонът дава тост „Фаза 1“). Казва „Браво“ или какво не е наред.

### Фаза 1 · 1.0.3 — след първия поглед на човека
Четири забележки от живата страница, всичките оправени: (1) темата се виждаше около играта → темплейт **„ansa™ Промо — цял екран“** за страницата + настройка „Скрий елементите на темата“ (списък селектори за OceanWP/Elementor/FunnelKit Cart/Claspo, редактируем в 🧰 Инструменти); overlay/тост/sticky вече са над хедъра на темата (root-ът не е stacking context). (2) WC снимките в „Честито“ и навсякъде се разливаха → `promo-extra.css`: фиксирани контейнери, бял фон, object-fit:contain. (3) нямаше откъде да се свърже ключ ↔ WC продукт → таб **📦 Продукти**: търсене по име/ID с превю на снимката, всички полета на продукта, добавяне/изтриване. (4) в редовете на „Напълни кутията си“ нямаше отстъпка и цени → бадж „−40%“ горе вдясно + намалена/редовна цена (това НЕ е в мокъп v73 — при v74 да влезе в мокъпа, иначе остава в promo-extra.css). 1.0.4: мобилният ред в пълненето е снимка | текст, а под текста един ред: цена · „Повече ›“ · ＋ Добави/степер (`.fbar`, promo-extra.css 3b). 1.0.5: десктоп — екранът с кутиите се събира на един екран без скрол: контейнерът до 1600px, по-плътни отстъпи (promo-extra.css 5), а ако пак не стига, `fitDesktop()` в promo.js слага zoom на `.wrap` (до 0.72); измерено с tests/harness/measure.js на 1280×720 … 1920×1080. 1.0.6: човекът намери плътната версия за „overwhelming, всичко еднакво“ → йерархия на десктоп (promo-extra.css 5): контейнер 1440px, Голямата кутия е героят (по-голяма, златна рамка, пълна лента с −40%), Малка/Средна са zoom .93 с тиха лента; чиповете са ред с ✓/✕; „за да отключиш“ и „спестяваш до“ са текст, не кутийки; лентата с яхтата е бяла и тиха. Това е предложение на билдъра, НЕ е в мокъп v73 — при v74 човекът решава дали влиза в мокъпа.

**Въпроси (с дефолт, виж §9):** (P1) снимки — дефолт: снимката на WC продукта (поле `img` в продукта може да я замени с attachment id); (P1) арт за яхта/сет/книга — дефолт: емоджитата от мокъпа, сменяеми в ⚙️ Двигател (Фаза 2).
