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
