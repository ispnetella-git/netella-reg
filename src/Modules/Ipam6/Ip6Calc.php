<?php

/*
 * Ip6Calc.php - obliczenia na adresach i prefiksach IPv6 (GMP).
 *
 * Adres to liczba 128-bitowa (\GMP). Do sortowania i zapisu w bazie służy
 * postać szesnastkowa o stałej długości 32 znaków (hex()), która porównana
 * jako tekst daje tę samą kolejność co liczby.
 *
 * Wymaga rozszerzenia PHP gmp (LibreNMS ma je w pakietach instalacyjnych).
 * Czyste, bez Laravela.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Ipam6;

final class Ip6Calc
{
    /** Długość prefiksu, w której blokach liczymy zajętość (sieć IPv6 to zwykle /64). */
    public const UNIT_LEN = 64;

    public static function available(): bool
    {
        return extension_loaded('gmp');
    }

    /** Adres (dowolna poprawna postać) -> liczba albo null. */
    public static function ip2gmp(string $ip): ?\GMP
    {
        $ip = trim($ip);
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return null;
        }
        $bin = inet_pton($ip);

        return $bin === false ? null : gmp_init(bin2hex($bin), 16);
    }

    /** Liczba -> adres w postaci skróconej (RFC 5952, jak inet_ntop). */
    public static function gmp2ip(\GMP $n): string
    {
        return (string) inet_ntop(hex2bin(self::hex($n)));
    }

    /** 32 znaki szesnastkowe, małe litery - do sortowania i bazy. */
    public static function hex(\GMP $n): string
    {
        return str_pad(gmp_strval($n, 16), 32, '0', STR_PAD_LEFT);
    }

    public static function fromHex(string $hex): \GMP
    {
        return gmp_init($hex === '' ? '0' : $hex, 16);
    }

    /** Liczba adresów w prefiksie /len. */
    public static function size(int $len): \GMP
    {
        return gmp_pow(2, 128 - $len);
    }

    /** Adres sieci prefiksu /len zawierającego $ip. */
    public static function network(\GMP $ip, int $len): \GMP
    {
        $host = gmp_sub(self::size($len), 1);

        return gmp_and($ip, gmp_xor(gmp_sub(gmp_pow(2, 128), 1), $host));
    }

    public static function cidr(\GMP $start, int $len): string
    {
        return self::gmp2ip($start) . '/' . $len;
    }

    /**
     * "2a0e:39c0::/29" -> zakres. $strict: przy ustawionych bitach hosta wyjątek
     * z podpowiedzią zamiast cichej normalizacji. Bez maski = /128.
     *
     * @return array{start:\GMP,end:\GMP,len:int,cidr:string,start_hex:string,end_hex:string}
     *
     * @throws \InvalidArgumentException
     */
    public static function parse(string $text, bool $strict = false): array
    {
        $text = trim($text);
        if (! preg_match('#^([0-9a-fA-F:.]+)(?:/(\d{1,3}))?$#', $text, $m)) {
            throw new \InvalidArgumentException("„{$text}” to nie jest adres ani prefiks IPv6.");
        }
        $ip = self::ip2gmp($m[1]);
        $len = isset($m[2]) ? (int) $m[2] : 128;
        if ($ip === null || $len > 128) {
            throw new \InvalidArgumentException("„{$text}” to nie jest poprawny adres ani prefiks IPv6.");
        }
        $start = self::network($ip, $len);
        if ($strict && gmp_cmp($start, $ip) !== 0) {
            throw new \InvalidArgumentException("„{$text}” ma ustawione bity hosta. Chodziło o " . self::cidr($start, $len) . '?');
        }

        return self::block($start, $len);
    }

    /** @return array{start:\GMP,end:\GMP,len:int,cidr:string,start_hex:string,end_hex:string} */
    public static function block(\GMP $start, int $len): array
    {
        $end = gmp_add($start, gmp_sub(self::size($len), 1));

        return ['start' => $start, 'end' => $end, 'len' => $len, 'cidr' => self::cidr($start, $len),
            'start_hex' => self::hex($start), 'end_hex' => self::hex($end)];
    }

    /** Czy [$s2, $e2] leży w [$s1, $e1]. */
    public static function within(\GMP $s1, \GMP $e1, \GMP $s2, \GMP $e2): bool
    {
        return gmp_cmp($s2, $s1) >= 0 && gmp_cmp($e2, $e1) <= 0;
    }

    /**
     * Scalenie przedziałów (nachodzących i sąsiednich).
     *
     * @param  list<array{0:\GMP,1:\GMP}>  $iv
     * @return list<array{0:\GMP,1:\GMP}>
     */
    public static function merge(array $iv): array
    {
        usort($iv, fn ($a, $b) => gmp_cmp($a[0], $b[0]));
        $out = [];
        foreach ($iv as [$a, $b]) {
            $n = count($out);
            if ($n > 0 && gmp_cmp($a, gmp_add($out[$n - 1][1], 1)) <= 0) {
                if (gmp_cmp($b, $out[$n - 1][1]) > 0) {
                    $out[$n - 1][1] = $b;
                }
            } else {
                $out[] = [$a, $b];
            }
        }

        return $out;
    }

    /**
     * Wolne przedziały w [$start, $end] po odjęciu zajętych.
     *
     * @param  list<array{0:\GMP,1:\GMP}>  $busy
     * @return list<array{0:\GMP,1:\GMP}>
     */
    public static function freeIntervals(\GMP $start, \GMP $end, array $busy): array
    {
        $out = [];
        $cur = $start;
        foreach (self::merge($busy) as [$a, $b]) {
            if (gmp_cmp($b, $cur) < 0) {
                continue;
            }
            if (gmp_cmp($a, $end) > 0) {
                break;
            }
            if (gmp_cmp($a, $cur) > 0) {
                $out[] = [$cur, gmp_sub($a, 1)];
            }
            $cur = gmp_add($b, 1);
            if (gmp_cmp($cur, $end) > 0) {
                return $out;
            }
        }
        if (gmp_cmp($cur, $end) <= 0) {
            $out[] = [$cur, $end];
        }

        return $out;
    }

    /** Suma długości przedziałów. */
    public static function count(array $iv): \GMP
    {
        $n = gmp_init(0);
        foreach ($iv as [$a, $b]) {
            $n = gmp_add($n, gmp_add(gmp_sub($b, $a), 1));
        }

        return $n;
    }

    /**
     * Przedziały zaokrąglone na zewnątrz do bloków /$len (np. zajętość liczona w /64:
     * adres albo /127 zajmuje całą swoją /64).
     *
     * @return list<array{0:\GMP,1:\GMP}>
     */
    public static function roundOut(array $iv, int $len): array
    {
        $out = [];
        foreach ($iv as [$a, $b]) {
            $out[] = [self::network($a, $len), gmp_add(self::network($b, $len), gmp_sub(self::size($len), 1))];
        }

        return self::merge($out);
    }

    /**
     * Przedział jako najmniejsza lista wyrównanych bloków CIDR, najwyżej $limit.
     *
     * @return list<array{start:\GMP,len:int}>
     */
    public static function rangeToCidrs(\GMP $start, \GMP $end, int $limit = 256): array
    {
        $out = [];
        while (gmp_cmp($start, $end) <= 0 && count($out) < $limit) {
            $len = 128;
            while ($len > 0) {
                $bigger = $len - 1;
                if (gmp_cmp(self::network($start, $bigger), $start) !== 0) {
                    break;
                }
                if (gmp_cmp(gmp_add($start, gmp_sub(self::size($bigger), 1)), $end) > 0) {
                    break;
                }
                $len = $bigger;
            }
            $out[] = ['start' => $start, 'len' => $len];
            $start = gmp_add($start, self::size($len));
        }

        return $out;
    }

    /** Liczba jako tekst z odstępami tysięcy (duże liczby IPv6), np. "34 359 738 368". */
    public static function fmt(\GMP $n): string
    {
        return strrev(implode(' ', str_split(strrev(gmp_strval($n)), 3)));
    }

    /** Liczba wspólnych początkowych bitów dwóch adresów. */
    public static function commonLen(\GMP $a, \GMP $b): int
    {
        $x = gmp_xor($a, $b);

        return gmp_cmp($x, 0) === 0 ? 128 : 128 - strlen(gmp_strval($x, 2));
    }

    /**
     * Zakres "a-b" (pełne adresy, także z półpauzą) -> [początek, koniec].
     *
     * @return array{0:\GMP,1:\GMP}
     *
     * @throws \InvalidArgumentException
     */
    public static function parseRange(string $text): array
    {
        $parts = preg_split('/\s*[-–]\s*/u', trim($text));
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException('Zakres IPv6 zapisz jako pierwszy-ostatni, np. 2001:db8::100-2001:db8::1ff.');
        }
        $a = self::ip2gmp($parts[0]);
        $b = self::ip2gmp($parts[1]);
        if ($a === null || $b === null) {
            throw new \InvalidArgumentException("Nie rozumiem zakresu „{$text}”: oba końce muszą być pełnymi adresami IPv6.");
        }
        if (gmp_cmp($a, $b) > 0) {
            [$a, $b] = [$b, $a];
        }
        if (gmp_cmp($a, $b) === 0) {
            throw new \InvalidArgumentException('Zakres musi mieć co najmniej dwa adresy - pojedynczy adres dodaj bez myślnika.');
        }

        return [$a, $b];
    }

    public static function rangeLabel(\GMP $a, \GMP $b): string
    {
        return self::gmp2ip($a) . '-' . self::gmp2ip($b);
    }

    /**
     * Pierwsze wolne, wyrównane bloki /$len w [$start, $end] poza zajętymi.
     *
     * @param  list<array{0:\GMP,1:\GMP}>  $busy
     * @return list<\GMP> początki bloków
     */
    public static function findFreeBlocks(\GMP $start, \GMP $end, array $busy, int $len, int $limit = 5): array
    {
        $size = self::size($len);
        $out = [];
        foreach (self::freeIntervals($start, $end, $busy) as [$a, $b]) {
            // Pierwszy początek bloku wyrównany do /len, nie wcześniej niż $a.
            $s = self::network($a, $len);
            if (gmp_cmp($s, $a) < 0) {
                $s = gmp_add($s, $size);
            }
            while (count($out) < $limit && gmp_cmp(gmp_add($s, gmp_sub($size, 1)), $b) <= 0) {
                $out[] = $s;
                $s = gmp_add($s, $size);
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
