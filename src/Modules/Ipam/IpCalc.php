<?php

/*
 * IpCalc.php - obliczenia na adresach i prefiksach.
 *
 * IPv4 liczymy na liczbach całkowitych (PHP 64-bit), bez GMP, więc działa
 * wszędzie. IPv6 tylko parsujemy i normalizujemy (model gotowy, drzewo IPv6
 * dojdzie później - decyzja z 2026-10-27).
 *
 * Czyste, bez Laravela.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Ipam;

final class IpCalc
{
    public const V4_MAX = 4294967295;

    public static function ip2int(string $ip): ?int
    {
        $ip = trim($ip);
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        $n = ip2long($ip);

        return $n === false ? null : ($n & self::V4_MAX);
    }

    public static function int2ip(int $n): string
    {
        return long2ip($n & self::V4_MAX);
    }

    public static function mask(int $len): int
    {
        return $len <= 0 ? 0 : ((self::V4_MAX << (32 - $len)) & self::V4_MAX);
    }

    public static function size(int $len): int
    {
        return 1 << (32 - $len);
    }

    /** Pierwszy adres sieci, w której jest $ip przy masce $len. */
    public static function network(int $ip, int $len): int
    {
        return $ip & self::mask($len);
    }

    public static function cidr(int $start, int $len): string
    {
        return self::int2ip($start) . '/' . $len;
    }

    /**
     * "10.0.0.0/24" albo "10.0.0.5" (=/32) -> zakres IPv4.
     * $strict: przy prefiksie z ustawionymi bitami hosta (10.0.0.5/24) wyjątek
     * z podpowiedzią, zamiast cichej normalizacji.
     *
     * @return array{family:int,start:int,end:int,len:int,cidr:string}
     */
    public static function parseV4(string $text, bool $strict = false): array
    {
        $text = trim($text);
        if (! preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})(?:/(\d{1,2}))?$#', $text, $m)) {
            throw new \InvalidArgumentException("„{$text}” to nie jest adres ani prefiks IPv4.");
        }
        $ip = self::ip2int($m[1]);
        $len = isset($m[2]) ? (int) $m[2] : 32;
        if ($ip === null || $len > 32) {
            throw new \InvalidArgumentException("„{$text}” to nie jest poprawny adres ani prefiks IPv4.");
        }
        $start = self::network($ip, $len);
        if ($strict && $start !== $ip) {
            throw new \InvalidArgumentException("„{$text}” ma ustawione bity hosta. Chodziło o " . self::cidr($start, $len) . '?');
        }

        return ['family' => 4, 'start' => $start, 'end' => $start + self::size($len) - 1, 'len' => $len, 'cidr' => self::cidr($start, $len)];
    }

    /** Postać kanoniczna adresu IPv6 albo null. */
    public static function normalizeV6(string $ip): ?string
    {
        $ip = trim($ip);
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return null;
        }
        $bin = inet_pton($ip);

        return $bin === false ? null : inet_ntop($bin);
    }

    /** Czy $ip (v4 int) jest w zakresie [$start, $end]. */
    public static function contains(int $start, int $end, int $ip): bool
    {
        return $ip >= $start && $ip <= $end;
    }

    /**
     * Zakres [start, end] jako najmniejsza lista wyrównanych bloków CIDR.
     *
     * @return list<array{start:int,len:int}>
     */
    public static function rangeToCidrs(int $start, int $end): array
    {
        $out = [];
        while ($start <= $end) {
            // Największy blok wyrównany do $start...
            $len = 32;
            while ($len > 0) {
                $bigger = $len - 1;
                if (($start & ~self::mask($bigger) & self::V4_MAX) !== 0) {
                    break;
                }
                // ...który mieści się w zakresie.
                if ($start + self::size($bigger) - 1 > $end) {
                    break;
                }
                $len = $bigger;
            }
            $out[] = ['start' => $start, 'len' => $len];
            $start += self::size($len);
        }

        return $out;
    }

    /**
     * Wolne przedziały w [$start, $end] po odjęciu zajętych. Zajęte mogą się
     * nakładać i wychodzić poza zakres.
     *
     * @param  list<array{0:int,1:int}>  $busy
     * @return list<array{0:int,1:int}>
     */
    public static function freeIntervals(int $start, int $end, array $busy): array
    {
        usort($busy, fn ($a, $b) => $a[0] <=> $b[0]);
        $out = [];
        $cur = $start;
        foreach ($busy as [$a, $b]) {
            if ($b < $cur) {
                continue;
            }
            if ($a > $end) {
                break;
            }
            if ($a > $cur) {
                $out[] = [$cur, min($a - 1, $end)];
            }
            $cur = max($cur, $b + 1);
            if ($cur > $end) {
                return $out;
            }
        }
        if ($cur <= $end) {
            $out[] = [$cur, $end];
        }

        return $out;
    }

    /**
     * Scala nakładające się i sąsiednie przedziały.
     *
     * @param  list<array{0:int,1:int}>  $intervals
     * @return list<array{0:int,1:int}>
     */
    public static function merge(array $intervals): array
    {
        usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
        $out = [];
        foreach ($intervals as [$a, $b]) {
            $last = count($out) - 1;
            if ($last >= 0 && $a <= $out[$last][1] + 1) {
                $out[$last][1] = max($out[$last][1], $b);
            } else {
                $out[] = [$a, $b];
            }
        }

        return $out;
    }

    /**
     * Suma długości przedziałów po scaleniu nakładających się.
     *
     * @param  list<array{0:int,1:int}>  $intervals
     */
    public static function coveredCount(array $intervals): int
    {
        usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
        $sum = 0;
        $cs = $ce = null;
        foreach ($intervals as [$a, $b]) {
            if ($cs === null) {
                [$cs, $ce] = [$a, $b];
            } elseif ($a <= $ce + 1) {
                $ce = max($ce, $b);
            } else {
                $sum += $ce - $cs + 1;
                [$cs, $ce] = [$a, $b];
            }
        }

        return $cs === null ? $sum : $sum + $ce - $cs + 1;
    }

    /**
     * Pierwsze $limit wolnych bloków /$len w [$start, $end] (wyrównanych do
     * granicy bloku), z pominięciem zajętych przedziałów.
     *
     * @param  list<array{0:int,1:int}>  $busy
     * @return list<int> początki bloków
     */
    public static function findFreeBlocks(int $start, int $end, array $busy, int $len, int $limit = 5): array
    {
        $size = self::size($len);
        $out = [];
        foreach (self::freeIntervals($start, $end, $busy) as [$a, $b]) {
            // Pierwszy wyrównany początek w wolnym przedziale.
            $s = $a % $size === 0 ? $a : $a + ($size - $a % $size);
            for (; $s + $size - 1 <= $b; $s += $size) {
                $out[] = $s;
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /** Ile adresów użytecznych ma sieć (bez adresu sieci i rozgłoszeniowego dla /30 i krótszych). */
    public static function usable(int $len): int
    {
        return $len >= 31 ? self::size($len) : self::size($len) - 2;
    }
}
