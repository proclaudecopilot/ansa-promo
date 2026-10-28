#!/usr/bin/env python3
"""ansa™ Промо 1.35.0 — генерира assets/flow.css от <style> на docs/ansa-flow-mockup-v5.html (вариант B „потокът без кутии“, десктоп + телефон v5).

Всеки селектор се слага под .ansa-flow (както promo.css е под .ansa-promo); :root → .ansa-flow; body → .ansa-flow (контейнерът);
html,body (скролът на страницата по ширина) → html.ansa-flow-html / body.ansa-flow-page; @font-face пада (шрифтът идва от Google Fonts
през wp_enqueue_style); правилата за конкретни снимки от мокъпа ([data-product=…]) падат. Пуска се от корена на репото:
    python3 docs/flow-css.py
"""
import re, sys, os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'docs', 'ansa-flow-mockup-v5.html')
DST = os.path.join(ROOT, 'assets', 'flow.css')
P = '.ansa-flow'


# Допълнителни правила на плъгина (не са в мокъпа): емоджи-плейсхолдъри без снимка, лв. в сумата, избор на плащане, продукт без наличност.
EXTRA = """
.ansa-flow .pack-picture .ph{display:grid;place-items:center;width:100%;height:100%;font-size:46px;line-height:1}
.ansa-flow .cart-item img,.ansa-flow .cart-pic{width:55px;height:60px;object-fit:cover;border-radius:8px;flex-shrink:0}
.ansa-flow .cart-pic.ph{display:grid;place-items:center;font-size:30px;background:#fff3ea}
.ansa-flow .detail-image.ph{display:grid;place-items:center;font-size:72px;line-height:1}
.ansa-flow .cart-sum .sum-total>span:last-child{text-align:right}.ansa-flow .cart-sum .bgn{display:block;font-size:11px;font-weight:600;color:#8b786b}
.ansa-flow .pay-choice{display:flex;gap:12px 16px;align-items:center;flex-wrap:wrap;margin-top:14px;font-size:13px;font-weight:700}.ansa-flow .pay-choice b{font-size:13px;font-weight:900}
.ansa-flow .pay-choice label{display:flex;align-items:center;gap:7px;cursor:pointer;min-height:32px}.ansa-flow .pay-choice input{accent-color:var(--coral);width:18px;height:18px;margin:0}
.ansa-flow .product.is-out{opacity:.55}.ansa-flow .product.is-out .quantity{pointer-events:none}
.ansa-flow .detail-image{height:175px;width:175px;object-fit:contain;border-radius:16px;margin:4px auto 15px;background:#fff1e8;padding:10px}
.ansa-flow .logo-link{display:block;line-height:0}.ansa-flow .logo{height:30px;width:auto;max-width:150px;display:block;object-fit:contain}
@media(min-width:900px){.ansa-flow .logo{height:46px;max-width:220px}}
.ansa-flow .benefit-thumbs .thumb-fallback{position:absolute;inset:0;display:grid;place-items:center;color:#a5462a}.ansa-flow .benefit-thumbs .thumb-fallback svg{width:30px;height:30px}
.ansa-flow .selected-item .sel-pic{width:31px;height:35px;object-fit:contain;mix-blend-mode:multiply;border-radius:4px;flex-shrink:0}.ansa-flow .selected-item .sel-pic.ph{display:grid;place-items:center;font-size:22px}
.ansa-flow .modal-cta.secondary{background:#f1e4d8;color:#5a3f30;margin-top:14px}
.ansa-flow .cart-tickets{display:flex;gap:10px;font-size:11px;align-items:center;background:#edf3fc;border-radius:10px;padding:10px;margin:12px 0}.ansa-flow .cart-tickets svg{color:#3b5f8f;flex-shrink:0}.ansa-flow .cart-tickets small{display:block;color:#5f7593;font-size:10px}
.ansa-flow .yacht-hero{background:linear-gradient(135deg,#dbe8f7,#eef4fb);border-radius:14px;padding:16px;text-align:center;margin:4px 0 12px}.ansa-flow .yacht-hero svg{width:38px;height:38px;color:#3b5f8f;margin:0 auto 8px}.ansa-flow .yacht-hero strong{display:block;font-size:17px;letter-spacing:-.4px;color:#1f3a5f;line-height:1.25}.ansa-flow .yacht-hero span{display:block;font-size:11px;color:#4d6688;margin-top:4px}
.ansa-flow .ticket-now{display:flex;justify-content:space-between;align-items:center;background:#edf3fc;border:1px solid #bfd1ea;border-radius:10px;padding:10px 12px;font-size:12px;font-weight:800;color:#2f4c73;margin-top:4px}.ansa-flow .ticket-now strong{font-size:20px;color:#1f3a5f}
.ansa-flow .ticket-list{display:grid;gap:10px;padding:14px 0 4px}.ansa-flow .ticket-list>div{display:flex;gap:10px;font-size:12px;align-items:flex-start}.ansa-flow .ticket-list svg{width:17px;height:17px;flex-shrink:0;color:#3b5f8f;margin-top:1px}.ansa-flow .ticket-list b{display:block;font-size:12px}.ansa-flow .ticket-list span{color:#786254;font-size:11px;line-height:1.5}
.ansa-flow .rules-link{display:inline-block;margin-top:10px;font-size:11px;font-weight:800;color:#3b5f8f}.ansa-flow .rules-grid small{display:block;font-size:10px;color:var(--muted);font-weight:600}
.ansa-flow .mini-tag.max{background:#eaf2e9;color:#2e6953}
.ansa-flow .offer-pill{display:none}
/* 1.35.1: иконата на количката на FunnelKit и плаващите елементи на темата са скрити и на страницата B (както на A в promo-extra.css) */
/* 1.35.2 (човекът): телефонът — по-нисък панел: без реда „N опаковки в количката / −x% сега“, съобщението на един ред, картата на сета е един ред
   (разгъва се с докосване и сама при 5), без надписа под бутона */
@media(max-width:899px){
.ansa-flow .offer-status{display:none}
.ansa-flow .offer-message{min-height:0;margin:2px 0 0;display:flex;align-items:center;justify-content:space-between;gap:10px}.ansa-flow .offer-text{min-width:0}.ansa-flow .offer-headline{font-size:18px;letter-spacing:-.4px;line-height:1.2}.ansa-flow .offer-description{font-size:11.5px;line-height:1.3;margin-top:2px}.ansa-flow .offer-description:empty{display:none}
.ansa-flow .offer-pill{display:inline-flex;align-items:center;gap:4px;flex-shrink:0;font-size:12px;font-weight:900;line-height:1;background:#fff7f0;border:1px solid #e5bd9e;color:#964d31;border-radius:100px;padding:8px 10px;white-space:nowrap}.ansa-flow .offer-pill.is-best{background:#eaf2e9;border-color:#c6d8c4;color:#36684f}.ansa-flow .offer-pill svg{width:13px;height:13px}.ansa-flow .offer-pill:empty{display:none}
.ansa-flow .benefit-preview.is-collapsed .benefit-action>span{display:none}
.ansa-flow .benefit-preview.unlock-anim{animation:pop .5s ease,softGlow 1.2s ease}
.ansa-flow .offer-complete .offer-message{margin:1px 0 6px}.ansa-flow .offer-progress{margin:8px 0 9px}
.ansa-flow .benefit-preview{min-height:0;padding:6px 9px;margin-bottom:9px;grid-template-columns:40px minmax(0,1fr) auto;gap:8px;transition:none}
.ansa-flow .benefit-thumbs{width:40px;height:38px}.ansa-flow .benefit-thumbs img{width:34px;height:38px}.ansa-flow .benefit-thumbs img:nth-child(1){left:-6px}.ansa-flow .benefit-thumbs img:nth-child(2){left:5px}.ansa-flow .benefit-thumbs img:nth-child(3){left:15px}
.ansa-flow .reward-state-icon{width:22px;height:22px;left:9px;top:8px}.ansa-flow .reward-state-icon svg{width:13px;height:13px}.ansa-flow .is-unlocked .reward-state-icon{width:18px;height:18px;top:-2px;right:-4px;left:auto}
.ansa-flow .benefit-preview.is-collapsed .benefit-detail,.ansa-flow .benefit-preview.is-collapsed .book-benefit{display:none}
.ansa-flow .benefit-preview .benefit-title,.ansa-flow .benefit-preview.is-locked .benefit-title{font-size:12px}.ansa-flow .benefit-preview.is-collapsed .benefit-title{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ansa-flow .benefit-preview:not(.is-collapsed){padding:9px 9px}.ansa-flow .benefit-preview:not(.is-collapsed) .benefit-thumbs{height:50px}.ansa-flow .benefit-preview:not(.is-collapsed) .benefit-thumbs img{height:50px;width:40px}
.ansa-flow .benefit-action{padding:6px 0 6px 3px}
.ansa-flow .dock-foot{display:none}
.ansa-flow .checkout-button{min-height:52px}
/* 1.35.4 (човекът): без подзаглавието под „Нашите продукти“; картата на продукта на два реда — 1) снимка + категория/заглавие/описание, 2) цена + бутон */
.ansa-flow .catalog-head p{display:none}
.ansa-flow .product{grid-template-columns:100px minmax(0,1fr);grid-template-rows:auto auto;align-items:start;padding:10px 10px 9px}
.ansa-flow .product-info{min-width:0;align-self:center}.ansa-flow .product-desc{min-height:0}
.ansa-flow .purchase-row{grid-column:1/-1;margin-top:9px;padding-top:9px;border-top:1px solid #f3e9e1;align-items:center;gap:8px}
.ansa-flow .purchase-row>div:first-child{min-height:0;display:flex;flex-wrap:wrap;align-items:baseline;column-gap:8px;row-gap:1px;min-width:0}
.ansa-flow .price{flex-wrap:nowrap}.ansa-flow .price strong{font-size:20px}.ansa-flow .price s{font-size:11px}
.ansa-flow .price-note{margin-top:0;font-size:10px;white-space:nowrap}.ansa-flow .lowest-price{flex-basis:100%;font-size:9.5px}
/* 1.35.5 (човекът): без реда „€ X при 5+ · −40%“; бутонът „Добави“ и стъпките са по-големи */
.ansa-flow .quantity{flex-shrink:0;height:46px;border-radius:12px}.ansa-flow .quantity button{height:46px;width:40px;font-size:24px}.ansa-flow .quantity output{min-width:26px;font-size:16px}
.ansa-flow .quantity .add-only{width:124px;font-size:14.5px;gap:7px}.ansa-flow .quantity .add-only svg{width:17px;height:17px}
.ansa-flow .cart-item .quantity,.ansa-flow .cart-item .quantity button{height:34px}.ansa-flow .cart-item .quantity button{width:30px;font-size:20px}
.ansa-flow .pack-picture{height:98px}.ansa-flow .pack-picture .ph{font-size:40px}
}
/* 1.36.0 (човекът): приветственият попъп „Ти попадна в Сезона на ANSA“ — в чекмеджето, с тъмна глава и стъпалата на отстъпката */
.ansa-flow .drawer.is-welcome .drawer-top{display:none}.ansa-flow .drawer.is-welcome .drawer-content{padding:0 0 18px}
.ansa-flow .pop-top{background:linear-gradient(135deg,#2b1e19,#4a2d22);color:#fff;padding:22px 22px 18px;position:relative}
.ansa-flow .pop-eyebrow{font-size:10.5px;font-weight:900;letter-spacing:.14em;text-transform:uppercase;color:#f6b18f;padding-right:40px}
.ansa-flow .pop-top h3{margin:6px 0 0;font-size:24px;line-height:1.1;font-weight:900;letter-spacing:-.6px;color:#fff}.ansa-flow .pop-top p{margin:8px 0 0;font-size:13px;color:#e9d5c8;font-weight:700}
.ansa-flow .pop-x{position:absolute;top:12px;right:12px;width:34px;height:34px;border-radius:50%;background:#ffffff22;color:#fff;display:grid;place-items:center;padding:0}.ansa-flow .pop-x svg{width:16px;height:16px}
.ansa-flow .pop-intro{margin:14px 18px 0;font-size:13.5px;font-weight:700;color:#5a4a42;line-height:1.4}.ansa-flow .pop-intro strong{display:block;font-size:12px;font-weight:900;letter-spacing:.06em;color:#a93e28;margin-bottom:3px}
.ansa-flow .pop-tiers{display:flex;flex-direction:column;gap:8px;padding:10px 18px 0}
.ansa-flow .pop-tier{display:grid;grid-template-columns:1fr auto;align-items:center;gap:12px;background:#fff;border:1px solid #f0e3db;border-radius:14px;padding:11px 14px}
.ansa-flow .pop-tier-n{font-size:14px;font-weight:900}.ansa-flow .pop-tier-n small{display:block;font-size:11.5px;font-weight:700;color:#7d6a61;margin-top:1px}
.ansa-flow .pop-tier-pct{font-size:22px;font-weight:900;color:#a93e28;letter-spacing:-.6px;white-space:nowrap;text-align:right}
.ansa-flow .pop-tier.best{background:linear-gradient(135deg,#fff0e7,#ffe3d2);border-color:#f0b9a2}.ansa-flow .pop-tier.best .pop-tier-pct{color:#ef6b4d}
.ansa-flow .pop-gift{grid-column:1/-1;display:flex;gap:10px;align-items:center;border-top:1px dashed #efc9b7;padding-top:9px;margin-top:2px;font-size:13px;font-weight:800;color:#5a3f30;line-height:1.3}
.ansa-flow .pop-gift.book{border-top:0;padding-top:0;margin-top:-2px}.ansa-flow .pop-gift b{color:#a93e28;font-weight:900;white-space:nowrap}.ansa-flow .pop-gift small{display:block;font-size:11px;font-weight:700;color:#8e7766;line-height:1.3;margin-top:1px}.ansa-flow .pop-gift s{color:#9b8a80;font-weight:700}
.ansa-flow .pop-gift-ico{width:34px;height:34px;border-radius:10px;background:#fff;display:grid;place-items:center;flex-shrink:0;color:#a93e28}.ansa-flow .pop-gift-ico svg{width:18px;height:18px}
.ansa-flow .pop-tomb{margin:12px 18px 0;display:flex;gap:10px;align-items:center;background:#edf3fc;border:1px solid #c9d9ee;border-radius:12px;padding:10px 12px;font-size:12.5px;font-weight:800;color:#2f4c73;line-height:1.35}.ansa-flow .pop-tomb .pop-gift-ico{background:#fff;color:#3b5f8f}
.ansa-flow .pop-ask{padding:0 0 6px;text-align:center;font-size:16px;font-weight:900}
/* 1.36.2 (човекът, S25 Ultra): бутонът е залепен долу в попъпа — вижда се винаги, съдържанието скролва над него */
.ansa-flow .pop-foot{position:sticky;bottom:0;margin:12px 0 0;padding:10px 18px 4px;background:#fffaf7;box-shadow:0 -8px 18px #3a24160d}
.ansa-flow .modal-cta.pop-cta{margin:0;width:100%;background:var(--coral);border-radius:14px;padding:16px;font-size:16px;letter-spacing:.02em;box-shadow:0 10px 22px #ef6b4d55}
.ansa-flow .drawer.is-welcome .drawer-content{padding-bottom:0}
@media(min-width:900px){.ansa-flow .drawer.is-welcome{max-width:440px}.ansa-flow .pop-foot{padding-bottom:18px}}
/* 1.36.4 (човекът): количката — „Как ще платиш?“ + „Продължи към плащане“ са залепени долу с реда „Общо“; на телефон по-сбито */
.ansa-flow .cart-foot{position:sticky;bottom:0;margin:10px -18px 0;padding:8px 18px 14px;background:#fffaf7;border-top:1px solid var(--line);box-shadow:0 -8px 18px #3a24160d}
.ansa-flow .cart-foot .pay-choice{margin-top:2px}.ansa-flow .cart-foot .modal-cta{margin-top:10px}
.ansa-flow .drawer.is-cart .drawer-content{padding-bottom:0}.ansa-flow .cart-sum .sum-total{padding-top:8px}
@media(min-width:900px){.ansa-flow .cart-foot{margin:8px -24px 0;padding:8px 24px 18px}}
@media(max-width:899px){.ansa-flow .drawer.is-cart{max-height:96%}
.ansa-flow .cart-item{padding:8px 0;gap:9px}.ansa-flow .cart-item img,.ansa-flow .cart-pic{width:46px;height:50px}
.ansa-flow .cart-gift,.ansa-flow .cart-tickets{padding:8px 10px;margin:8px 0;font-size:11px}
.ansa-flow .gift-option{padding:6px 0}.ansa-flow .gift-locked-notice{padding:9px 11px}
.ansa-flow .cart-sum{gap:4px;margin:10px 0 8px;font-size:12px}.ansa-flow .cart-sum .sum-total{font-size:17px}
.ansa-flow .cart-sum .due-later{font-size:11px}.ansa-flow .notice{padding:9px 11px;line-height:1.45}
.ansa-flow .cart-foot{padding:8px 16px 12px;margin:10px -18px 0}.ansa-flow .cart-foot .pay-choice{gap:6px 10px;font-size:12.5px;flex-wrap:nowrap}.ansa-flow .cart-foot .pay-choice b{font-size:12.5px;white-space:nowrap}.ansa-flow .cart-foot .pay-choice label{gap:5px;min-height:28px;white-space:nowrap}.ansa-flow .cart-foot .pay-choice input{width:17px;height:17px}
.ansa-flow .cart-foot .modal-cta{margin-top:8px}.ansa-flow .cart-foot .demo-notice{margin-top:6px}
}
@media(max-width:899px){.ansa-flow .drawer.is-welcome{max-height:96%}
.ansa-flow .pop-top{padding:16px 18px 14px}.ansa-flow .pop-eyebrow{font-size:9.5px}.ansa-flow .pop-top h3{font-size:20px;margin-top:4px}.ansa-flow .pop-top p{margin-top:4px;font-size:12.5px}
.ansa-flow .pop-x{top:10px;right:10px;width:30px;height:30px}
.ansa-flow .pop-intro{margin:11px 16px 0;font-size:12.5px;line-height:1.35}.ansa-flow .pop-intro strong{font-size:11px;margin-bottom:2px}
.ansa-flow .pop-tiers{gap:6px;padding:8px 16px 0}.ansa-flow .pop-tier{padding:8px 12px;border-radius:12px;gap:10px}
.ansa-flow .pop-tier-n{font-size:13.5px}.ansa-flow .pop-tier-n small{display:none}.ansa-flow .pop-tier.best .pop-tier-n small{display:block;font-size:11px}
.ansa-flow .pop-tier-pct{font-size:20px}
.ansa-flow .pop-gift{gap:8px;padding-top:7px;font-size:12.5px}.ansa-flow .pop-gift.book{margin-top:-1px}.ansa-flow .pop-gift-ico{width:28px;height:28px;border-radius:8px}.ansa-flow .pop-gift-ico svg{width:15px;height:15px}
.ansa-flow .pop-tomb{margin:8px 16px 0;padding:8px 10px;font-size:11.5px;gap:8px}
.ansa-flow .pop-foot{padding:8px 16px 4px}.ansa-flow .pop-ask{font-size:15px;padding-bottom:5px}.ansa-flow .modal-cta.pop-cta{padding:14px;min-height:50px}
}
/* 1.39.2 (човекът: „бутонът се скрива от долната граница, бели отстояния“): главната страница е в Elementor контейнер с padding 10px — приложението е
   фиксирано на целия екран (inset:0), а обвивките на Elementor са без отстояния; iOS: fixed + inset следва видимия viewport, не 100vh */
body.ansa-flow-page .ansa-flow{position:fixed;inset:0;width:100%;height:100%;min-height:0;margin:0;padding:0;z-index:5;overflow:hidden}
body.ansa-flow-page .elementor-shortcode,body.ansa-flow-page .elementor-widget-container,body.ansa-flow-page .elementor-widget,body.ansa-flow-page .e-con,body.ansa-flow-page .e-con-inner,body.ansa-flow-page .elementor,body.ansa-flow-page .elementor-section,body.ansa-flow-page .elementor-container,body.ansa-flow-page #content,body.ansa-flow-page #main,body.ansa-flow-page .site-content,body.ansa-flow-page .content-area{padding:0!important;margin:0!important;min-height:0!important;max-width:none!important}
body.ansa-flow-page #fkcart-floating-toggler,body.ansa-flow-page .fkcart-floating-toggler,body.ansa-flow-page .fkcart-toggler,body.ansa-flow-page .fkcart-floating-icon,body.ansa-flow-page #scroll-top,body.ansa-flow-page .claspo-widget{display:none!important;visibility:hidden!important}
"""

def strip_comments(css):
    return re.sub(r'/\*.*?\*/', '', css, flags=re.S)

def parse(css):
    """→ списък от (selector, body) където body е str (декларации) или списък (вложени правила на @media и др.)."""
    out, i, n = [], 0, len(css)
    while i < n:
        j = css.find('{', i)
        if j < 0: break
        sel = css[i:j].strip()
        depth, k = 1, j + 1
        while k < n and depth:
            if css[k] == '{': depth += 1
            elif css[k] == '}': depth -= 1
            k += 1
        body = css[j + 1:k - 1]
        if sel.startswith('@') and not sel.startswith('@font-face') and '{' in body:
            out.append((sel, parse(body)))
        else:
            out.append((sel, body))
        i = k
    return out

def prefix_sel(sel, in_keyframes):
    if in_keyframes: return sel
    parts = []
    for s in sel.split(','):
        s = s.strip()
        if not s: continue
        if s == ':root' or s == 'body' or s == '.studio': parts.append(P)
        elif s.startswith('.studio'): parts.append(P + s[7:])
        elif s == 'html': parts.append('html.ansa-flow-html')
        elif s == 'body.modal-open': parts.append('body.ansa-flow-page.modal-open')
        elif s.startswith('body'): parts.append('body.ansa-flow-page' + s[4:])
        elif s == '*' or s.startswith('*:'): parts.append(P + ' ' + s)
        else: parts.append(P + ' ' + s)
    return ','.join(parts)

def render(rules, in_keyframes=False):
    o = []
    for sel, body in rules:
        if sel.startswith('@font-face'): continue
        if '[data-product=' in sel: continue
        if isinstance(body, list):
            kf = in_keyframes or sel.startswith('@keyframes')
            o.append(sel + '{' + render(body, kf) + '}')
        else:
            b = body.strip()
            if not b: continue
            s = prefix_sel(sel, in_keyframes)
            # html,body → двете поотделно
            if sel.strip() == 'html,body':
                s = 'html.ansa-flow-html,body.ansa-flow-page'
            o.append(s + '{' + b + '}')
    return '\n'.join(o)

def main():
    html = open(SRC, encoding='utf-8').read()
    css = html[html.index('<style>') + 7:html.index('</style>')]
    css = strip_comments(css)
    out = render(parse(css))
    out = out.replace('.ansa-flow .product>div{', '.ansa-flow .product-info{')
    head = ('/* ansa™ Промо — вариант B „потокът без кутии“ (1.35.0, v5). Генериран от docs/ansa-flow-mockup-v5.html с docs/flow-css.py —\n'
            '   не се редактира на ръка, редактира се мокъпът. Всеки селектор е под .ansa-flow; под 900px мобилният изглед (скролът е в\n'
            '   каталога, офертата е долу), над 900px десктоп с лепкав панел вдясно. */\n')
    open(DST, 'w', encoding='utf-8').write(head + out + '\n' + EXTRA)
    print('flow.css:', len(out), 'bytes')

if __name__ == '__main__':
    main()
