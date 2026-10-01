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
includes/class-plugin.php    boot, activate, Shrine helpers (shrine_version, shrine_promo_module_loaded, shortcode_owner)
includes/class-schema.php    таблици {prefix}ansa_promo_tickets / _leads (dbDelta при активация и при смяна на ANSA_PROMO_DB_VER)
includes/class-frontend.php  [ansa_promo] (init 20 — печели над Shrine дори без guard)
includes/class-admin.php     меню „🎁 ansa Промо“ → екран „Състояние“
includes/class-doctor.php    env() — редовете на екрана „Състояние“; Фаза 2 добавя checks()
includes/mu/                 LOGADOR GitHub Updater (копира се в mu-plugins)
snippets/phase-N.php         Code Snippet за всяка фаза — self-check на живия сайт, човекът праща отчета
docs/                        планът + мокъпът (не влизат в zip-а)
```

## Фази
| Фаза | Версия | Какво | Снипет |
|------|--------|-------|--------|
| 0 | 1.0.0 | репо, скелет, updater, таблици, guard в Shrine 6.19.22 | `snippets/phase-0.php` — отчет за средата |
| 1 | 1.0.1 | конфиг + копи-регистър + порт на мокъп v73 | — |
| 2 | 1.0.2 | визуалният редактор | — |
| 3 | 1.1.0 | количка и чекаут | — |
| 4 | 1.2.0 | награди: билети, QR, REST, ваучер, книга | — |
| 5 | 1.3.0 | Insights + ansa Partner | — |
| 6 | 1.3.x | hardening | — |

### Фаза 0 (1.0.0)
**Какво има:** главен файл с header + `GitHub Plugin URI`, autoloader (`AnsaPromo\Foo_Bar` → `includes/class-foo-bar.php`), OPcache/object-cache flush при смяна на версията (от Shrine v6.12), HPOS compat декларация, mu-updater инсталатор (LOGADOR 1.1.1 — с webhook), таблици `tickets` и `leads`, top-level меню „🎁 ansa Промо“ с екран „Състояние“, `[ansa_promo]` → „ansa™ Промо — скоро.“ (+ един ред бележка само за админи). Option `ansa_promo_store` НЕ се пипа — Фаза 1 я чете/нормализира (съществуваща чернова от Shrine мигрира).

**Какво доказва Snippet 0:** плъгинът е активен и зареден (константи, класове), shortcode-ът е на `AnsaPromo\Frontend`, Shrine версия ≥ 6.19.22 и промо модулът му НЕ е зареден (guard), таблиците ги има, updater-ът вижда и двете репота (token + latest release), FunnelKit/HPOS/gateways/кеш за следващите фази, анонимен fetch на страницата с shortcode-а (ако има такава).

**Решения на билдъра (без питане):** таблици `{prefix}ansa_promo_tickets` (колона `tickets`, не `count`; + `box`) и `{prefix}ansa_promo_leads`; schema версия в option `ansa_promo_db_ver`; shortcode на `init` 20; mu-updater 1.1.1 вместо 1.0.1 на ansa-shrine (инсталаторът го качва само ако е по-нов — сайтът получава и webhook-а); `Requires Plugins: woocommerce` в header-а; `docs/` и `snippets/` извън zip-а.

**Отворени въпроси (с дефолт):** (P0) репото е `proclaudecopilot/ansa-promo` — потвърдено от човека. Останалите (§9 от плана) се питат във фазата, която ги иска.
