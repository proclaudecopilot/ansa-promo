<?php
/* php tests/qr/gen.php OUTDIR → PNG файлове за всяка проба; node tests/qr/decode.js OUTDIR ги чете с jsQR и сравнява. */
define( 'ABSPATH', __DIR__ . '/' ); require __DIR__ . '/../../includes/class-qr.php';
$out = $argv[1] ?? sys_get_temp_dir(); @mkdir( $out, 0777, true );
$samples = array( 'short' => 'ANSA-7K3Q2M9X', 'url' => 'https://ansa.bg/promo/?ticket=7K3Q2M9XAB&v=1', 'url2' => 'https://portal.ansa.bg/t/ABCDEFGHJKLMNPQRSTUVWXYZ234567', 'long' => str_repeat( 'https://ansa.bg/x?y=', 5 ) . str_repeat( 'Z', 60 ), 'v10' => str_repeat( 'abcdefghij', 25 ) . 'END', 'utf8' => 'Сезонът на ansa™ — билет 3' );
$levels = array( 'M', 'L', 'Q', 'H' );
$i = 0;
foreach ( $samples as $k => $text ) { foreach ( $levels as $lv ) {
	$m = AnsaPromo\QR::matrix( $text, $lv ); if ( ! $m ) { echo "$k/$lv: няма версия (" . strlen( $text ) . " B)\n"; continue; }
	$png = AnsaPromo\QR::png( $text, 6, 4, $lv ); file_put_contents( "$out/$k-$lv.png", $png ); file_put_contents( "$out/$k-$lv.txt", $text );
	echo "$k/$lv: v=" . ( ( count( $m ) - 17 ) / 4 ) . " size=" . count( $m ) . " png=" . strlen( $png ) . "B\n"; $i++;
} }
file_put_contents( "$out/sample.svg", AnsaPromo\QR::svg( $samples['url'] ) );
echo "generated $i\n";
