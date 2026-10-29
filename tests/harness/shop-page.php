<?php
/**
 * 1.44.0: статична страница /shop/ (13 продукта като на живо, снимки = плейсхолдъри) за Playwright скрийншоти и проверки.
 * php tests/harness/shop-page.php > /tmp/shop.html ; node tests/harness/shop-check.js /tmp/shop.html [shots-dir]
 */
ob_start(); require __DIR__ . '/mega-menu-page.php'; ob_end_clean(); /* същите стъбове и продукти */
use AnsaPromo\Shop;
$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/shop.css' );
echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><style>body{margin:0;font-family:system-ui}.hdr{height:70px;background:#fff;border-bottom:2px solid #ef6b4d}' . $css . '</style></head><body><div class="hdr"></div>' . Shop::render() . '</body></html>';
