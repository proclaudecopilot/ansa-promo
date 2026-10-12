<?php
/**
 * ansa™ Промо — QR код (чист PHP, без зависимости).
 *
 * Byte mode, версии 1–10, ниво на корекция M (по подразбиране) или L/Q/H; Reed–Solomon върху GF(256), 8 маски с избор по
 * наказателните правила. Достатъчно за URL до ~270 знака (v10-L). Рендер: SVG (винаги) и PNG през GD (ако има).
 * Проверено с jsQR (tests/qr).
 *
 * @package AnsaPromo
 * @since 1.2.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class QR {

	/* [version][level] => [total data codewords, ec per block, [блокове => брой data cw]] ; level: L=0 M=1 Q=2 H=3 */
	private static $table = array(
		1  => array( array( 19, 7, array( 19 ) ), array( 16, 10, array( 16 ) ), array( 13, 13, array( 13 ) ), array( 9, 17, array( 9 ) ) ),
		2  => array( array( 34, 10, array( 34 ) ), array( 28, 16, array( 28 ) ), array( 22, 22, array( 22 ) ), array( 16, 28, array( 16 ) ) ),
		3  => array( array( 55, 15, array( 55 ) ), array( 44, 26, array( 44 ) ), array( 34, 18, array( 17, 17 ) ), array( 26, 22, array( 13, 13 ) ) ),
		4  => array( array( 80, 20, array( 80 ) ), array( 64, 18, array( 32, 32 ) ), array( 48, 26, array( 24, 24 ) ), array( 36, 16, array( 9, 9, 9, 9 ) ) ),
		5  => array( array( 108, 26, array( 108 ) ), array( 86, 24, array( 43, 43 ) ), array( 62, 18, array( 15, 15, 16, 16 ) ), array( 46, 22, array( 11, 11, 12, 12 ) ) ),
		6  => array( array( 136, 18, array( 68, 68 ) ), array( 108, 16, array( 27, 27, 27, 27 ) ), array( 76, 24, array( 19, 19, 19, 19 ) ), array( 60, 28, array( 15, 15, 15, 15 ) ) ),
		7  => array( array( 156, 20, array( 78, 78 ) ), array( 124, 18, array( 31, 31, 31, 31 ) ), array( 88, 18, array( 14, 14, 15, 15, 15, 15 ) ), array( 66, 26, array( 13, 13, 13, 13, 14 ) ) ),
		8  => array( array( 194, 24, array( 97, 97 ) ), array( 154, 22, array( 38, 38, 39, 39 ) ), array( 110, 22, array( 18, 18, 18, 18, 19, 19 ) ), array( 86, 26, array( 14, 14, 14, 14, 15, 15 ) ) ),
		9  => array( array( 232, 30, array( 116, 116 ) ), array( 182, 22, array( 36, 36, 36, 37, 37 ) ), array( 132, 20, array( 16, 16, 16, 16, 17, 17, 17, 17 ) ), array( 100, 24, array( 12, 12, 12, 12, 13, 13, 13, 13 ) ) ),
		10 => array( array( 274, 18, array( 68, 68, 69, 69 ) ), array( 216, 26, array( 43, 43, 43, 43, 44 ) ), array( 154, 24, array( 19, 19, 19, 19, 19, 19, 20, 20 ) ), array( 122, 28, array( 15, 15, 15, 15, 15, 15, 16, 16 ) ) ),
	);
	private static $align = array( 1 => array(), 2 => array( 6, 18 ), 3 => array( 6, 22 ), 4 => array( 6, 26 ), 5 => array( 6, 30 ), 6 => array( 6, 34 ), 7 => array( 6, 22, 38 ), 8 => array( 6, 24, 42 ), 9 => array( 6, 26, 46 ), 10 => array( 6, 28, 50 ) );
	private static $exp = null, $log = null;

	/** Матрица от редове с 0/1 (true = тъмен модул). $level: 'L'|'M'|'Q'|'H'. */
	public static function matrix( $text, $level = 'M' ) {
		$lv = array( 'L' => 0, 'M' => 1, 'Q' => 2, 'H' => 3 )[ strtoupper( $level ) ] ?? 1;
		$bytes = array_values( unpack( 'C*', (string) $text ) ); $n = count( $bytes );
		$version = 0;
		for ( $v = 1; $v <= 10; $v++ ) { $cap = self::$table[ $v ][ $lv ][0] * 8; $need = 4 + ( $v >= 10 ? 16 : 8 ) + 8 * $n; if ( $need <= $cap ) { $version = $v; break; } }
		if ( ! $version ) { return null; }
		list( $data_cw, $ec_len, $blocks ) = self::$table[ $version ][ $lv ];
		/* bitstream */
		$bits = '0100' . str_pad( decbin( $n ), $version >= 10 ? 16 : 8, '0', STR_PAD_LEFT );
		foreach ( $bytes as $b ) { $bits .= str_pad( decbin( $b ), 8, '0', STR_PAD_LEFT ); }
		$cap = $data_cw * 8; $bits .= str_repeat( '0', min( 4, $cap - strlen( $bits ) ) );
		if ( strlen( $bits ) % 8 ) { $bits .= str_repeat( '0', 8 - strlen( $bits ) % 8 ); }
		$pads = array( 0xEC, 0x11 ); $i = 0; while ( strlen( $bits ) < $cap ) { $bits .= str_pad( decbin( $pads[ $i++ % 2 ] ), 8, '0', STR_PAD_LEFT ); }
		$cw = array(); for ( $i = 0; $i < $data_cw; $i++ ) { $cw[] = bindec( substr( $bits, $i * 8, 8 ) ); }
		/* блокове + EC */
		self::gf(); $dblocks = array(); $eblocks = array(); $pos = 0;
		foreach ( $blocks as $len ) { $d = array_slice( $cw, $pos, $len ); $pos += $len; $dblocks[] = $d; $eblocks[] = self::rs( $d, $ec_len ); }
		$out = array(); $maxd = max( $blocks );
		for ( $i = 0; $i < $maxd; $i++ ) { foreach ( $dblocks as $d ) { if ( isset( $d[ $i ] ) ) { $out[] = $d[ $i ]; } } }
		for ( $i = 0; $i < $ec_len; $i++ ) { foreach ( $eblocks as $e ) { $out[] = $e[ $i ]; } }
		$stream = ''; foreach ( $out as $c ) { $stream .= str_pad( decbin( $c ), 8, '0', STR_PAD_LEFT ); }
		$stream .= str_repeat( '0', ( $version >= 2 && $version <= 6 ) ? 7 : 0 );
		/* матрица: null = свободно, 0/1 = функционален модул */
		$size = 17 + 4 * $version; $m = array_fill( 0, $size, array_fill( 0, $size, null ) ); $fn = array_fill( 0, $size, array_fill( 0, $size, false ) );
		$set = function ( $r, $c, $v ) use ( &$m, &$fn, $size ) { if ( $r < 0 || $c < 0 || $r >= $size || $c >= $size ) { return; } $m[ $r ][ $c ] = $v ? 1 : 0; $fn[ $r ][ $c ] = true; };
		$finder = function ( $r0, $c0 ) use ( $set ) { for ( $r = -1; $r <= 7; $r++ ) { for ( $c = -1; $c <= 7; $c++ ) { $on = ( $r >= 0 && $r <= 6 && $c >= 0 && $c <= 6 ) && ( 0 === $r || 6 === $r || 0 === $c || 6 === $c || ( $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4 ) ); $set( $r0 + $r, $c0 + $c, $on ); } } };
		$finder( 0, 0 ); $finder( 0, $size - 7 ); $finder( $size - 7, 0 );
		for ( $i = 8; $i < $size - 8; $i++ ) { $set( 6, $i, 0 === $i % 2 ); $set( $i, 6, 0 === $i % 2 ); }
		$al = self::$align[ $version ];
		foreach ( $al as $ar ) { foreach ( $al as $ac ) {
			if ( ( $ar <= 8 && $ac <= 8 ) || ( $ar <= 8 && $ac >= $size - 9 ) || ( $ar >= $size - 9 && $ac <= 8 ) ) { continue; }
			for ( $r = -2; $r <= 2; $r++ ) { for ( $c = -2; $c <= 2; $c++ ) { $set( $ar + $r, $ac + $c, 2 === abs( $r ) || 2 === abs( $c ) || ( 0 === $r && 0 === $c ) ); } }
		} }
		$set( $size - 8, 8, 1 ); /* тъмният модул */
		/* резервирани за format/version — попълват се после */
		for ( $i = 0; $i < 9; $i++ ) { if ( 6 !== $i ) { $set( 8, $i, 0 ); $set( $i, 8, 0 ); } }
		for ( $i = 0; $i < 8; $i++ ) { $set( 8, $size - 1 - $i, 0 ); $set( $size - 1 - $i, 8, 0 ); }
		if ( $version >= 7 ) { for ( $i = 0; $i < 6; $i++ ) { for ( $j = 0; $j < 3; $j++ ) { $set( $i, $size - 11 + $j, 0 ); $set( $size - 11 + $j, $i, 0 ); } } }
		/* данните — зигзаг отдясно наляво */
		$bi = 0; $len = strlen( $stream ); $data = array_fill( 0, $size, array_fill( 0, $size, false ) );
		for ( $col = $size - 1; $col >= 1; $col -= 2 ) {
			if ( 6 === $col ) { $col--; }
			for ( $k = 0; $k < $size; $k++ ) {
				/* двойките колони се четат отдясно наляво, посоката се редува: първата (най-дясната) нагоре */
				$upward = ( ( ( ( $size - 1 - $col ) >> 1 ) & 1 ) === 0 ); $row = $upward ? $size - 1 - $k : $k;
				foreach ( array( $col, $col - 1 ) as $c ) {
					if ( $fn[ $row ][ $c ] ) { continue; }
					$bit = $bi < $len ? ( '1' === $stream[ $bi ] ) : false; $bi++;
					$m[ $row ][ $c ] = $bit ? 1 : 0; $data[ $row ][ $c ] = true;
				}
			}
		}
		/* маска: най-малко наказание */
		$best = null; $best_pen = PHP_INT_MAX;
		for ( $mask = 0; $mask < 8; $mask++ ) {
			$mm = $m;
			for ( $r = 0; $r < $size; $r++ ) { for ( $c = 0; $c < $size; $c++ ) { if ( $data[ $r ][ $c ] && self::mask_on( $mask, $r, $c ) ) { $mm[ $r ][ $c ] ^= 1; } } }
			self::format( $mm, $size, $lv, $mask, $version );
			$pen = self::penalty( $mm, $size );
			if ( $pen < $best_pen ) { $best_pen = $pen; $best = $mm; }
		}
		return $best;
	}

	private static function mask_on( $mask, $r, $c ) {
		switch ( $mask ) {
			case 0: return 0 === ( $r + $c ) % 2;
			case 1: return 0 === $r % 2;
			case 2: return 0 === $c % 3;
			case 3: return 0 === ( $r + $c ) % 3;
			case 4: return 0 === ( intdiv( $r, 2 ) + intdiv( $c, 3 ) ) % 2;
			case 5: return 0 === ( ( $r * $c ) % 2 + ( $r * $c ) % 3 );
			case 6: return 0 === ( ( ( $r * $c ) % 2 + ( $r * $c ) % 3 ) % 2 );
			default: return 0 === ( ( ( $r + $c ) % 2 + ( $r * $c ) % 3 ) % 2 );
		}
	}

	/** Format info (BCH 15,5 ⊕ 0x5412) + version info (BCH 18,6) в матрицата. */
	private static function format( &$m, $size, $lv, $mask, $version ) {
		$ecbits = array( 0 => 1, 1 => 0, 2 => 3, 3 => 2 )[ $lv ]; /* L=01 M=00 Q=11 H=10 */
		$data = ( $ecbits << 3 ) | $mask; $rem = $data;
		for ( $i = 0; $i < 10; $i++ ) { $rem = ( $rem << 1 ) ^ ( ( ( $rem >> 9 ) & 1 ) ? 0x537 : 0 ); }
		$bits = ( ( $data << 10 ) | ( $rem & 0x3FF ) ) ^ 0x5412;
		for ( $i = 0; $i < 15; $i++ ) {
			$b = ( $bits >> $i ) & 1;
			/* около горния ляв finder */
			if ( $i < 6 ) { $m[ $i ][8] = $b; } elseif ( 6 === $i ) { $m[7][8] = $b; } elseif ( 7 === $i ) { $m[8][8] = $b; } elseif ( 8 === $i ) { $m[8][7] = $b; } else { $m[8][ 14 - $i ] = $b; }
			/* другите два: под горния десен (ред 8) и вдясно от долния ляв (колона 8) */
			if ( $i < 8 ) { $m[8][ $size - 1 - $i ] = $b; } else { $m[ $size - 15 + $i ][8] = $b; }
		}
		$m[ $size - 8 ][8] = 1;
		if ( $version >= 7 ) {
			$rem = $version; for ( $i = 0; $i < 12; $i++ ) { $rem = ( $rem << 1 ) ^ ( ( ( $rem >> 11 ) & 1 ) ? 0x1F25 : 0 ); }
			$vb = ( $version << 12 ) | ( $rem & 0xFFF );
			for ( $i = 0; $i < 18; $i++ ) { $b = ( $vb >> $i ) & 1; $r = intdiv( $i, 3 ); $c = $size - 11 + $i % 3; $m[ $r ][ $c ] = $b; $m[ $c ][ $r ] = $b; }
		}
	}

	private static function penalty( $m, $n ) {
		$p = 0;
		/* 1: поредици ≥5 еднакви в ред/колона */
		for ( $r = 0; $r < $n; $r++ ) { $run = 1; $runc = 1; for ( $c = 1; $c < $n; $c++ ) { if ( $m[ $r ][ $c ] === $m[ $r ][ $c - 1 ] ) { $run++; if ( 5 === $run ) { $p += 3; } elseif ( $run > 5 ) { $p++; } } else { $run = 1; } if ( $m[ $c ][ $r ] === $m[ $c - 1 ][ $r ] ) { $runc++; if ( 5 === $runc ) { $p += 3; } elseif ( $runc > 5 ) { $p++; } } else { $runc = 1; } } }
		/* 2: блокове 2×2 */
		for ( $r = 0; $r < $n - 1; $r++ ) { for ( $c = 0; $c < $n - 1; $c++ ) { $v = $m[ $r ][ $c ]; if ( $v === $m[ $r ][ $c + 1 ] && $v === $m[ $r + 1 ][ $c ] && $v === $m[ $r + 1 ][ $c + 1 ] ) { $p += 3; } } }
		/* 3: finder-подобни шаблони 1011101 с 4 светли от едната страна */
		$pat = array( 1, 0, 1, 1, 1, 0, 1 );
		for ( $r = 0; $r < $n; $r++ ) { for ( $c = 0; $c < $n - 6; $c++ ) {
			$ok1 = true; $ok2 = true; for ( $k = 0; $k < 7; $k++ ) { if ( $m[ $r ][ $c + $k ] !== $pat[ $k ] ) { $ok1 = false; } if ( $m[ $c + $k ][ $r ] !== $pat[ $k ] ) { $ok2 = false; } }
			if ( $ok1 && ( self::light( $m, $r, $c - 4, 0, 1, $n ) || self::light( $m, $r, $c + 7, 0, 1, $n ) ) ) { $p += 40; }
			if ( $ok2 && ( self::light( $m, $c - 4, $r, 1, 0, $n ) || self::light( $m, $c + 7, $r, 1, 0, $n ) ) ) { $p += 40; }
		} }
		/* 4: баланс тъмни */
		$dark = 0; for ( $r = 0; $r < $n; $r++ ) { $dark += array_sum( $m[ $r ] ); }
		$pct = $dark * 100 / ( $n * $n ); $p += 10 * (int) ( abs( $pct - 50 ) / 5 );
		return $p;
	}
	private static function light( $m, $r, $c, $dr, $dc, $n ) { for ( $k = 0; $k < 4; $k++ ) { $rr = $r + $dr * $k; $cc = $c + $dc * $k; if ( $rr < 0 || $cc < 0 || $rr >= $n || $cc >= $n || 0 !== $m[ $rr ][ $cc ] ) { return false; } } return true; }

	/* ── GF(256), Reed–Solomon ── */
	private static function gf() {
		if ( null !== self::$exp ) { return; }
		self::$exp = array_fill( 0, 512, 0 ); self::$log = array_fill( 0, 256, 0 ); $x = 1;
		for ( $i = 0; $i < 255; $i++ ) { self::$exp[ $i ] = $x; self::$log[ $x ] = $i; $x <<= 1; if ( $x & 0x100 ) { $x ^= 0x11D; } }
		for ( $i = 255; $i < 512; $i++ ) { self::$exp[ $i ] = self::$exp[ $i - 255 ]; }
	}
	private static function mul( $a, $b ) { return ( 0 === $a || 0 === $b ) ? 0 : self::$exp[ self::$log[ $a ] + self::$log[ $b ] ]; }
	private static function rs( $data, $ec ) {
		$gen = array( 1 ); for ( $i = 0; $i < $ec; $i++ ) { $ng = array_fill( 0, count( $gen ) + 1, 0 ); foreach ( $gen as $j => $g ) { $ng[ $j ] ^= $g; $ng[ $j + 1 ] ^= self::mul( $g, self::$exp[ $i ] ); } $gen = $ng; }
		$res = array_merge( $data, array_fill( 0, $ec, 0 ) );
		for ( $i = 0; $i < count( $data ); $i++ ) { $coef = $res[ $i ]; if ( 0 === $coef ) { continue; } for ( $j = 1; $j < count( $gen ); $j++ ) { $res[ $i + $j ] ^= self::mul( $gen[ $j ], $coef ); } }
		return array_slice( $res, count( $data ) );
	}

	/* ── рендер ── */
	public static function svg( $text, $scale = 6, $margin = 4, $level = 'M' ) {
		$m = self::matrix( $text, $level ); if ( ! $m ) { return ''; }
		$n = count( $m ); $w = ( $n + 2 * $margin ) * $scale; $d = '';
		for ( $r = 0; $r < $n; $r++ ) { for ( $c = 0; $c < $n; $c++ ) { if ( $m[ $r ][ $c ] ) { $d .= 'M' . ( ( $c + $margin ) * $scale ) . ' ' . ( ( $r + $margin ) * $scale ) . 'h' . $scale . 'v' . $scale . 'h-' . $scale . 'z'; } } }
		return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $w . '" viewBox="0 0 ' . $w . ' ' . $w . '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path d="' . $d . '" fill="#000"/></svg>';
	}
	/** PNG като string (GD); '' ако няма GD. */
	public static function png( $text, $scale = 8, $margin = 4, $level = 'M' ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) { return ''; }
		$m = self::matrix( $text, $level ); if ( ! $m ) { return ''; }
		$n = count( $m ); $w = ( $n + 2 * $margin ) * $scale; $im = imagecreatetruecolor( $w, $w ); $white = imagecolorallocate( $im, 255, 255, 255 ); $black = imagecolorallocate( $im, 0, 0, 0 ); imagefill( $im, 0, 0, $white );
		for ( $r = 0; $r < $n; $r++ ) { for ( $c = 0; $c < $n; $c++ ) { if ( $m[ $r ][ $c ] ) { imagefilledrectangle( $im, ( $c + $margin ) * $scale, ( $r + $margin ) * $scale, ( $c + $margin + 1 ) * $scale - 1, ( $r + $margin + 1 ) * $scale - 1, $black ); } } }
		ob_start(); imagepng( $im ); imagedestroy( $im ); return (string) ob_get_clean();
	}
	public static function data_uri( $text, $scale = 6, $margin = 4 ) { return 'data:image/svg+xml;base64,' . base64_encode( self::svg( $text, $scale, $margin ) ); }
}
