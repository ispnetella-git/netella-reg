<?php

/*
 * IpInput.php - to, co użytkownik wpisuje w polu "Adres / prefiks".
 *
 *   10.0.0.0/24             prefiks (jeden wpis)
 *   10.0.0.5  10.0.0.5/32   adres
 *   10.0.0.10-10.0.0.20     zakres adresów (grupa wpisów)
 *   10.0.0.10-20            to samo, skrót na ostatni oktet
 *   10.0.0.1, 10.0.0.7      lista adresów (grupa wpisów)
 *
 * Zakres "a-b" można też zapisać jako jeden wpis (pula, parseRange()).
 *
 * Prefiksu nie łączymy z adresami w jednym wpisie - to różne rodzaje rekordów.
 *
 * Czyste, bez Laravela.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Ipam;

use Ispnetella\NetellaReg\Core\Lang;

final class IpInput
{
    public const MAX_ADDRESSES = 1024;

    /**
     * @return array{kind:string,prefix?:array,addresses?:list<int>}
     *         kind = 'prefix' (jeden prefiks) albo 'addresses' (lista adresów v4)
     */
    public static function parse(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException(Lang::t('Podaj adres, zakres albo prefiks, np. 10.0.0.0/24 lub 10.0.0.10-20.'));
        }

        $parts = preg_split('/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Pojedynczy prefiks krótszy niż /32.
        if (count($parts) === 1 && preg_match('#/(\d{1,2})$#', $parts[0], $m) && (int) $m[1] < 32) {
            return ['kind' => 'prefix', 'prefix' => IpCalc::parseV4($parts[0], true)];
        }

        $out = [];
        foreach ($parts as $p) {
            if (preg_match('#/(\d{1,2})$#', $p, $m) && (int) $m[1] < 32) {
                throw new \InvalidArgumentException(Lang::t('Prefiks „{p}” dodaj osobno - nie łączy się go z adresami w jednym wpisie.', ['p' => $p]));
            }
            $p = preg_replace('#/32$#', '', $p);

            if (preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})-(\d{1,3}(?:\.\d{1,3}){3}|\d{1,3})$#', $p, $m)) {
                $a = IpCalc::ip2int($m[1]);
                $b = str_contains($m[2], '.')
                    ? IpCalc::ip2int($m[2])
                    : ($a === null || (int) $m[2] > 255 ? null : (($a & 0xFFFFFF00) | (int) $m[2]));
                if ($a === null || $b === null) {
                    throw new \InvalidArgumentException(Lang::t('Nie rozumiem zakresu „{p}”.', ['p' => $p]));
                }
                if ($a > $b) {
                    [$a, $b] = [$b, $a];
                }
                if ($b - $a + 1 > self::MAX_ADDRESSES) {
                    throw new \InvalidArgumentException(Lang::t('Najwięcej {n} adresów naraz. Większy blok dodaj jako prefiks.', ['n' => self::MAX_ADDRESSES]));
                }
                for ($i = $a; $i <= $b; $i++) {
                    $out[$i] = true;
                }
            } else {
                $ip = IpCalc::ip2int($p);
                if ($ip === null) {
                    throw new \InvalidArgumentException(Lang::t('„{p}” to nie jest adres IPv4.', ['p' => $p]));
                }
                $out[$ip] = true;
            }

            if (count($out) > self::MAX_ADDRESSES) {
                throw new \InvalidArgumentException(Lang::t('Najwięcej {n} adresów naraz. Większy blok dodaj jako prefiks.', ['n' => self::MAX_ADDRESSES]));
            }
        }

        $list = array_keys($out);
        sort($list);

        return ['kind' => 'addresses', 'addresses' => $list];
    }

    /**
     * Jeden ciągły zakres zapisywany jako jeden wpis (pula): "a-b" albo "a-n"
     * (skrót na ostatni oktet). Bez limitu MAX_ADDRESSES.
     *
     * @return array{0:int,1:int}
     */
    public static function parseRange(string $text): array
    {
        $t = preg_replace('/\s*(-|–)\s*/u', '-', trim($text));
        if (! preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})-(\d{1,3}(?:\.\d{1,3}){3}|\d{1,3})$#', $t, $m)) {
            throw new \InvalidArgumentException(Lang::t('Zakres podaj jako początek-koniec, np. 10.0.0.10-10.0.0.200 albo 10.0.0.10-200.'));
        }
        $a = IpCalc::ip2int($m[1]);
        $b = str_contains($m[2], '.')
            ? IpCalc::ip2int($m[2])
            : ($a === null || (int) $m[2] > 255 ? null : (($a & 0xFFFFFF00) | (int) $m[2]));
        if ($a === null || $b === null) {
            throw new \InvalidArgumentException(Lang::t('Nie rozumiem zakresu „{p}”.', ['p' => $text]));
        }
        if ($a > $b) {
            [$a, $b] = [$b, $a];
        }
        if ($a === $b) {
            throw new \InvalidArgumentException(Lang::t('Zakres musi mieć co najmniej dwa adresy. Pojedynczy adres dodaj jako adres.'));
        }

        return [$a, $b];
    }

    /** Zakres w postaci "10.0.0.10-10.0.0.200". */
    public static function rangeLabel(int $start, int $end): string
    {
        return IpCalc::int2ip($start) . '-' . IpCalc::int2ip($end);
    }

    /**
     * Lista adresów w zapisie skróconym: "10.0.0.10-10.0.0.20, 10.0.0.30".
     *
     * @param  list<int>  $ips
     */
    public static function compress(array $ips): string
    {
        $ips = array_values(array_unique($ips));
        sort($ips);
        $parts = [];
        $start = $prev = null;
        $flush = function () use (&$parts, &$start, &$prev) {
            if ($start !== null) {
                $parts[] = $start === $prev ? IpCalc::int2ip($start) : IpCalc::int2ip($start) . '-' . IpCalc::int2ip($prev);
            }
        };
        foreach ($ips as $ip) {
            if ($start !== null && $ip === $prev + 1) {
                $prev = $ip;
                continue;
            }
            $flush();
            $start = $prev = $ip;
        }
        $flush();

        return implode(', ', $parts);
    }
}
