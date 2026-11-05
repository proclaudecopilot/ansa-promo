# Черен петък 2026 — адресите за рекламите

Една страница: `https://www.ansa.bg/cheren-petak-2026/`. Какво вижда човекът се решава от параметрите в адреса и от датата. Копирайте адресите точно както са; `utm_content` може да се сменя с името на рекламата, ако продуктът се подаде в отделния параметър `p` (виж най-долу).

## Фазите (сменят се сами по датата, София)
| Период | Какво показва страницата |
|---|---|
| сега → 25.11, 10:00 | **Записване**: ваучер 10 €, имейл, портфейл за 30 мин, бърза поръчка на редовни цени |
| 25.11, 10:00 → 27.11, 10:00 | **Ранен достъп** (само с личния линк от имейла): всяка опаковка = 1 подарък по избор |
| 27.11, 10:00 → 30.11, 10:00 | **Публичен Black Friday** (за всички): всяка опаковка = 1 подарък по избор |
| след 30.11, 10:00 | „Black Friday в ANSA приключи“ |

## 1. Реклами за записване (сега → 25.11)
```
https://www.ansa.bg/cheren-petak-2026/?utm_source=facebook&utm_medium=paid&utm_campaign=bf2026-signup&src=cold
```
`utm_content` = името на рекламата (влиза в отчета и в събитието Lead), например `&utm_content=video_v1`.

## 2. Реклами за публичния Black Friday (27.11 → 30.11) — общата страница
```
https://www.ansa.bg/cheren-petak-2026/?utm_source=facebook&utm_medium=paid&utm_campaign=bf2026-public
```

## 3. Реклами за един продукт (ранен достъп + публичен Black Friday)
Страницата показва само този продукт с избор 1–5 опаковки (= 1–5 подаръка) и връзка „Не искам този продукт. Виж други“.

| Продукт | Адрес |
|---|---|
| Daily Greens | `https://www.ansa.bg/cheren-petak-2026/?utm_source=facebook&utm_medium=paid&utm_campaign=bf2026-public&utm_content=greens` |
| ДихидроБерберин | `https://www.ansa.bg/cheren-petak-2026/?utm_source=facebook&utm_medium=paid&utm_campaign=bf2026-public&utm_content=berber` |
| Sakura SkinTonic | `https://www.ansa.bg/cheren-petak-2026/?utm_source=facebook&utm_medium=paid&utm_campaign=bf2026-public&utm_content=sakura` |
| YoniBiotic 3в1 | `https://www.ansa.bg/cheren-petak-2026/?utm_source=facebook&utm_medium=paid&utm_campaign=bf2026-public&utm_content=yoni` |
| Red Routine | `https://www.ansa.bg/cheren-petak-2026/?utm_source=facebook&utm_medium=paid&utm_campaign=bf2026-public&utm_content=red` |
| MenoStop | `https://www.ansa.bg/cheren-petak-2026/?utm_source=facebook&utm_medium=paid&utm_campaign=bf2026-public&utm_content=meno` |
| Utera+ | `https://www.ansa.bg/cheren-petak-2026/?utm_source=facebook&utm_medium=paid&utm_campaign=bf2026-public&utm_content=utera` |
| ThyroZen | `https://www.ansa.bg/cheren-petak-2026/?utm_source=facebook&utm_medium=paid&utm_campaign=bf2026-public&utm_content=thyro` |
| Ашваганда | `https://www.ansa.bg/cheren-petak-2026/?utm_source=facebook&utm_medium=paid&utm_campaign=bf2026-public&utm_content=ashwa` |

Новите 4 продукта (KeraTonica, Alivia, VenoTonus, AHCC-Бета) са само подаръци и нямат продуктов адрес.

Ако същите адреси се пуснат **преди 25.11**, те показват страницата за записване (ваучера) — продуктовият изглед се включва автоматично от 25.11.

### Ако `utm_content` ви трябва за името на рекламата
Сложете продукта в отделен параметър `p`, а `utm_content` оставете за рекламата:
```
https://www.ansa.bg/cheren-petak-2026/?utm_source=facebook&utm_medium=paid&utm_campaign=bf2026-public&utm_content=berber_video_v2&p=berber
```
Ключовете за `p`: `greens`, `berber`, `sakura`, `yoni`, `red`, `meno`, `utera`, `thyro`, `ashwa`.

## 4. Google / друг платен източник
Същите адреси с `utm_source=google&utm_medium=cpc` (всеки от `utm_medium=paid|cpc|ppc|ads`, `fbclid`, `gclid` се брои за „реклама“).

## 5. Партньори (ansa Partner)
Партньорските линкове са `https://www.ansa.bg/cheren-petak-2026/?ref=КОД` — те се дават от портала на партньорите, не от този списък.

## 6. Имейли
Личният линк за ранния достъп е merge tag-ът `{{ansa_promo_bf_early_url}}` във FunnelKit (носи токена и имейла, ваучерът влиза сам). Публичният адрес за имейл: `{{ansa_promo_bf_url}}`. Не копирайте адреси с `?ea=` от админа в масов имейл.
