<?php
/**
 * ansa™ Промо 1.54.4 — IP-то на посетителя за лимитите (Leads::rate_limited: ticket, tombola, book, lead, add, beacon,
 * bf_claim, bf_lead, Insights API).
 *
 * Досега Leads::ip() четеше `CF-Connecting-IP` на доверие. Header-ът се праща от клиента: ansa.bg не е зад Cloudflare
 * (директно nginx), така че всеки сам си избираше „IP“ и всеки лимит се нулираше на всяка заявка — 12 кода на
 * ваучер в минута ставаха колкото поискаш. Сега header-ът важи само когато заявката наистина идва от Cloudflare
 * (REMOTE_ADDR е в техните мрежи); иначе IP-то е REMOTE_ADDR.
 *
 * Мрежите са публичният списък на Cloudflare (cloudflare.com/ips); filter `ansa_promo_trusted_proxies` добавя свои
 * (напр. собствен load balancer) — CIDR-и, от които се вярва на `CF-Connecting-IP` / `X-Forwarded-For`.
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

class Client_IP {

    const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** IP-то на посетителя; '' ако няма валидно. $server = $_SERVER (параметър за офлайн тест). */
    public static function get( $server = null ) {
        if ( null === $server ) $server = $_SERVER;
        $remote = self::clean( $server['REMOTE_ADDR'] ?? '' );
        if ( '' === $remote ) return '';
        if ( ! self::is_trusted_proxy( $remote ) ) return $remote;
        $cf = self::clean( $server['HTTP_CF_CONNECTING_IP'] ?? '' );
        if ( '' !== $cf ) return $cf;
        /* свой proxy (от filter-а): първият адрес в X-Forwarded-For */
        $xff = explode( ',', (string) ( $server['HTTP_X_FORWARDED_FOR'] ?? '' ) );
        $first = self::clean( $xff[0] );
        return '' !== $first ? $first : $remote;
    }

    public static function is_trusted_proxy( $ip ) {
        $nets = self::CLOUDFLARE;
        if ( function_exists( 'apply_filters' ) ) $nets = (array) apply_filters( 'ansa_promo_trusted_proxies', $nets );
        foreach ( $nets as $cidr ) {
            if ( self::in_cidr( $ip, (string) $cidr ) ) return true;
        }
        return false;
    }

    /** Лични / локални адреси (127.0.0.1, 10.x, 192.168.x, ::1 …) — знак, че сървърът стои зад proxy, което не подава истинското IP. */
    public static function is_private( $ip ) {
        $ip = self::clean( $ip );
        if ( '' === $ip ) return true;
        return false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
    }

    /** $ip в мрежата $cidr ("a.b.c.d/n" или IPv6); единичен адрес = /32 (/128). */
    public static function in_cidr( $ip, $cidr ) {
        $parts = explode( '/', trim( $cidr ), 2 );
        $a = @inet_pton( (string) $ip );
        $n = @inet_pton( $parts[0] );
        if ( false === $a || false === $n || strlen( $a ) !== strlen( $n ) ) return false;
        $max  = strlen( $a ) * 8;
        $bits = isset( $parts[1] ) && '' !== $parts[1] ? (int) $parts[1] : $max;
        if ( $bits < 0 || $bits > $max ) return false;
        $full = intdiv( $bits, 8 );
        if ( $full && substr( $a, 0, $full ) !== substr( $n, 0, $full ) ) return false;
        $rest = $bits % 8;
        if ( ! $rest ) return true;
        $mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
        return ( ord( $a[ $full ] ) & $mask ) === ( ord( $n[ $full ] ) & $mask );
    }

    private static function clean( $ip ) {
        $ip = trim( (string) $ip );
        return ( '' !== $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) ? $ip : '';
    }
}
