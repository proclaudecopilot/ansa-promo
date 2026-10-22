#!/usr/bin/env python3
"""Статичен харнес на страницата без WP: php runtime.php → runtime.json; скелетът от templates/page.php; promo.css/js от assets/.
python3 tests/harness/build.py OUTDIR && NODE_PATH=$(npm root -g) node tests/harness/shoot.js OUTDIR  (Playwright, Chromium в /opt/pw-browsers)"""
import re, subprocess, sys, os
root = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
out = sys.argv[1] if len(sys.argv) > 1 else '/tmp/ansa-promo-harness'
os.makedirs(out + '/harness', exist_ok=True); os.makedirs(out + '/shots', exist_ok=True)
rt = subprocess.check_output(['php', root + '/tests/harness/runtime.php', 'sakura']).decode()
open(out + '/harness/runtime.json', 'w').write(rt)
t = open(root + '/templates/page.php', encoding='utf-8').read()
t = re.sub(r'<\?php.*?\?>', '', t, flags=re.S)
html = ('<!doctype html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>harness</title>'
        '<link rel="stylesheet" href="file://' + root + '/assets/promo.css"><link rel="stylesheet" href="file://' + root + '/assets/promo-extra.css"></head><body style="margin:0">' + t +
        '<script>window.AnsaPromoRuntime=' + rt + ';</script><script src="file://' + root + '/assets/promo.js"></script></body></html>')
open(out + '/harness/index.html', 'w', encoding='utf-8').write(html)
# v1.8.0: самостоятелната страница „Как работи“ (runtime.mode = info) → harness/info.html
rt2 = subprocess.check_output(['php', root + '/tests/harness/runtime.php', 'sakura', '', 'info']).decode()
open(out + '/harness/info.html', 'w', encoding='utf-8').write(html.replace('<script>window.AnsaPromoRuntime=' + rt + ';</script>', '<script>window.AnsaPromoRuntime=' + rt2 + ';</script>'))
# 1.24.20: страницата „Комплектът за 1 €“ (runtime.mode = li) → harness/kozmetika.html
rt3 = subprocess.check_output(['php', root + '/tests/harness/runtime.php', '', '', 'li']).decode()
open(out + '/harness/kozmetika.html', 'w', encoding='utf-8').write(html.replace('<script>window.AnsaPromoRuntime=' + rt + ';</script>', '<script>window.AnsaPromoRuntime=' + rt3 + ';</script>'))
# 1.24.21: редакторът — harness/editor.html (editor.css/js + window.AnsaPromoEditor от editor-boot.php) с канвас harness/canvas.html (runtime editor=1, data-editor на root)
rt4 = subprocess.check_output(['php', root + '/tests/harness/runtime.php', 'sakura', '1']).decode()
open(out + '/harness/canvas.html', 'w', encoding='utf-8').write(html.replace('<script>window.AnsaPromoRuntime=' + rt + ';</script>', '<script>window.AnsaPromoRuntime=' + rt4 + ';</script>').replace('id="ansaPromo"', 'id="ansaPromo" data-editor="1"', 1))
boot = subprocess.check_output(['php', root + '/tests/harness/editor-boot.php', 'file://' + out + '/harness/canvas.html']).decode()
ed = ('<!doctype html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>editor harness</title>'
      '<link rel="stylesheet" href="file://' + root + '/assets/editor.css"></head><body style="margin:0"><div id="wpcontent"><div id="apEd" class="ap-ed">'
      '<div class="ap-ed-top" id="apEdTop"></div><div class="ap-ed-body"><aside class="ap-ed-rail" id="apEdRail"></aside><main class="ap-ed-canvas" id="apEdCanvas"></main><aside class="ap-ed-insp" id="apEdInsp"></aside></div><div class="ap-ed-toast" id="apEdToast" hidden></div></div></div>'
      '<script>window.AnsaPromoEditor=' + boot + ';</script><script src="file://' + root + '/assets/editor.js"></script></body></html>')
open(out + '/harness/editor.html', 'w', encoding='utf-8').write(ed)
print('harness →', out + '/harness/index.html', '+ info.html + kozmetika.html')
