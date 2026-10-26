#!/usr/bin/env python3
"""Сглобява landing/sakura-5.html от landing/src/sakura-5.src.html: {{IMG:файл}} и {{FONT:файл}} → base64 data URI
от landing/src/img/. Резултатът е един самостоятелен HTML файл (без външни заявки) — качва се като статична страница.

    python3 landing/src/build.py
"""
import base64, mimetypes, pathlib, re, sys

HERE = pathlib.Path(__file__).resolve().parent
SRC = HERE / 'sakura-5.src.html'
OUT = HERE.parent / 'sakura-5.html'
IMG = HERE / 'img'
mimetypes.add_type('image/webp', '.webp')
mimetypes.add_type('font/ttf', '.ttf')


def data_uri(name: str) -> str:
    p = IMG / name
    if not p.is_file():
        sys.exit(f'липсва {p}')
    mime = mimetypes.guess_type(p.name)[0] or 'application/octet-stream'
    return 'data:' + mime + ';base64,' + base64.b64encode(p.read_bytes()).decode('ascii')


html = SRC.read_text(encoding='utf-8')
cache = {}
def sub(m):
    name = m.group(2)
    if name not in cache:
        cache[name] = data_uri(name)
    return cache[name]
html = re.sub(r'\{\{(IMG|FONT):([^}]+)\}\}', sub, html)
left = re.findall(r'\{\{[A-Z]+:[^}]+\}\}', html)
if left:
    sys.exit(f'неразпознати плейсхолдъри: {left}')
OUT.write_text(html, encoding='utf-8')
print(f'{OUT} — {OUT.stat().st_size/1024:.0f} KB, {len(cache)} вградени файла')
