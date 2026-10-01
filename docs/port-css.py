#!/usr/bin/env python3
"""Порт на CSS-а от мокъпа → assets/promo.css.

python3 docs/port-css.py docs/ansa-promo-mockup-vNN.html

Правила: всеки селектор се префиксва с `.ansa-promo `; :root/body → .ansa-promo; * → .ansa-promo *;
html/body{height} и :root{safe-area} отпадат; .dev (демо лентата) отпада; id-тата на екраните се
преименуват по IDMAP (мокъп → плъгин); @keyframes се копират; @media се обхождат рекурсивно.
Накрая се добавя WP-обвивката (фонът е на root-а, не на body).
"""
import re, sys

IDMAP = {'s1': 'apS1', 's2': 'apS2', 's4': 'apS4', 's5': 'apS5'}
P = '.ansa-promo'


def prefix_sel(sel):
    parts = [s.strip() for s in sel.split(',')]
    res = []
    for s in parts:
        if not s or s.startswith('.dev'):
            continue
        if s == ':root' or s == 'body':
            res.append(P); continue
        if s == '*':
            res.append(P + ' *'); continue
        if s in ('html', 'html,body'):
            continue
        if s == 'body::before':
            res.append(P + '::before'); continue
        if s.startswith('body '):
            res.append(P + ' ' + s[5:]); continue
        s = re.sub(r'#([a-zA-Z][a-zA-Z0-9]*)', lambda m: '#' + IDMAP.get(m.group(1), m.group(1)), s)
        res.append(P + ' ' + s)
    return ','.join(res)


def process(block):
    o = ''; j = 0; L = len(block)
    while j < L:
        m = re.match(r'\s+', block[j:])
        if m:
            j += m.end(); continue
        if block.startswith('/*', j):
            j = block.find('*/', j) + 2; continue
        b = block.find('{', j)
        if b < 0:
            break
        sel = block[j:b].strip()
        d = 1; k = b + 1
        while k < L and d > 0:
            if block[k] == '{': d += 1
            elif block[k] == '}': d -= 1
            k += 1
        body = block[b + 1:k - 1]
        if sel.startswith('@media') or sel.startswith('@supports'):
            o += sel + '{' + process(body) + '}\n'
        elif sel.startswith('@'):
            o += sel + '{' + body + '}\n'
        else:
            if sel == ':root' and 'padding-top:env' in body:
                j = k; continue
            ps = prefix_sel(sel)
            if ps:
                if sel == 'body':
                    body = body.replace('overflow-x:hidden', 'overflow-x:clip')
                o += ps + '{' + body + '}\n'
        j = k
    return o


def main(path):
    src = open(path, encoding='utf-8').read()
    css = src.split('<style>', 1)[1].split('</style>', 1)[0]
    res = process(css)
    assert res.count('{') == res.count('}'), 'небалансирани скоби'
    hdr = ("/* ansa™ Промо — фронт (порт на мокъп " + re.sub(r'.*-(v\d+).*', r'\1', path) +
           ", 1:1; всеки селектор е под .ansa-promo).\n   Генериран от " + path +
           " с docs/port-css.py — не се редактира на ръка, редактира се мокъпът. */\n")
    tail = ("\n/* ── обвивка в WP: фонът не е на body, а на root-а ── */\n"
            ".ansa-promo{position:relative;z-index:0;min-height:60vh}\n"
            ".ansa-promo::before{position:absolute}\n")
    open('assets/promo.css', 'w', encoding='utf-8').write(hdr + res + tail)
    print('rules', res.count('{'), 'from', css.count('{'))


if __name__ == '__main__':
    main(sys.argv[1] if len(sys.argv) > 1 else 'docs/ansa-promo-mockup-v73.html')
