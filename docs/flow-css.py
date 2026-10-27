#!/usr/bin/env python3
"""ansa™ Промо 1.34.0 — генерира assets/flow.css от <style> на docs/ansa-desktop-mockup.html (вариант B „потокът без кутии“).

Всеки селектор се слага под .ansa-flow (както promo.css е под .ansa-promo); :root → .ansa-flow; body → .ansa-flow (контейнерът);
html,body (скролът на страницата по ширина) → html.ansa-flow-html / body.ansa-flow-page; @font-face пада (шрифтът идва от Google Fonts
през wp_enqueue_style); правилата за конкретни снимки от мокъпа ([data-product=…]) падат. Пуска се от корена на репото:
    python3 docs/flow-css.py
"""
import re, sys, os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'docs', 'ansa-desktop-mockup.html')
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
        if s == ':root' or s == 'body': parts.append(P)
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
    head = ('/* ansa™ Промо — вариант B „потокът без кутии“ (1.34.0). Генериран от docs/ansa-desktop-mockup.html с docs/flow-css.py —\n'
            '   не се редактира на ръка, редактира се мокъпът. Всеки селектор е под .ansa-flow; под 900px мобилният изглед (скролът е в\n'
            '   каталога, офертата е долу), над 900px десктоп с лепкав панел вдясно. */\n')
    open(DST, 'w', encoding='utf-8').write(head + out + '\n' + EXTRA)
    print('flow.css:', len(out), 'bytes')

if __name__ == '__main__':
    main()
