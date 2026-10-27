#!/usr/bin/env python3
"""1.34.0: статичен харнес на вариант B „потокът без кутии“ без WP: runtime.json от tests/harness/runtime.php (Seed), скелетът от
templates/flow.php, flow.css/flow.js от assets/. python3 tests/harness/build-flow.py OUTDIR → OUTDIR/harness/flow.html (+ ANSA_WEEK=0 за без седмици)."""
import re, subprocess, sys, os, json
root = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
out = sys.argv[1] if len(sys.argv) > 1 else '/tmp/ansa-promo-harness'
os.makedirs(out + '/harness', exist_ok=True); os.makedirs(out + '/shots', exist_ok=True)
rt = json.loads(subprocess.check_output(['php', root + '/tests/harness/runtime.php', 'sakura']).decode())
rt['variant'] = 'flow'; rt['logo'] = 'https://www.ansa.bg/wp-content/uploads/2025/02/Ansa-Website-Logo-1.png'; rt['home'] = 'https://www.ansa.bg/'; rt['deadline_ts'] = 1798757999; rt['refresh'] = False; rt['tombola_url'] = 'https://ansa.bg/sezonut-na-ansa/tombola/'; rt['draw'] = '25 януари'
t = open(root + '/templates/flow.php', encoding='utf-8').read()
t = re.sub(r'<\?php.*?\?>', '', t, flags=re.S)
html = ('<!doctype html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>flow harness</title>'
        '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800;900&display=swap">'
        '<link rel="stylesheet" href="file://' + root + '/assets/flow.css"></head><body style="margin:0">' + t +
        '<script>window.AnsaPromoRuntime=' + json.dumps(rt, ensure_ascii=False) + ';</script><script src="file://' + root + '/assets/flow.js"></script></body></html>')
open(out + '/harness/flow.html', 'w', encoding='utf-8').write(html)
print(out + '/harness/flow.html')
