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
print('harness →', out + '/harness/index.html')
