const fs = require('fs'), path = require('path'); const jsQR = require('jsqr'); const { PNG } = require('pngjs');
const dir = process.argv[2]; let ok = 0, bad = 0;
for (const f of fs.readdirSync(dir).filter(x => x.endsWith('.png'))) {
  const png = PNG.sync.read(fs.readFileSync(path.join(dir, f))); const want = fs.readFileSync(path.join(dir, f.replace('.png', '.txt')), 'utf8');
  const r = jsQR(new Uint8ClampedArray(png.data), png.width, png.height);
  const got = r ? r.data : null; if (got === want) { ok++; } else { bad++; console.log('FAIL', f, 'got:', got && got.slice(0, 60), 'want:', want.slice(0, 60)); }
}
console.log('decoded ok', ok, 'bad', bad);
