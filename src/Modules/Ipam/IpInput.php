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
 * Prefiksu nie łączymy z adresami w jednym wpisie - to różne rodzaje rekordów.
 *
 * Czyste, bez Laravela.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Ipam;

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
            throw new \InvalidArgumentException('Podaj adres, zakres albo prefiks, np. 10.0.0.0/24 lub 10.0.0.10-20.');
        }

        $parts = preg_split('/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Pojedynczy prefiks krótszy niż /32.
        if (count($parts) === 1 && preg_match('#/(\d{1,2})$#', $parts[0], $m) && (int) $m[1] < 32) {
            return ['kind' => 'prefix', 'prefix' => IpCalc::parseV4($parts[0], true)];
        }

        $out = [];
        foreach ($parts as $p) {
            if (preg_match('#/(\d{1,2})$#', $p, $m) && (int) $m[1] < 32) {
                throw new \InvalidArgumentException("Prefiks „{$p}” dodaj osobno - nie łączy się go z adresami w jednym wpisie.");
            }
            $p = preg_replace('#/32$#', '', $p);

            if (preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})-(\d{1,3}(?:\.\d{1,3}){3}|\d{1,3})$#', $p, $m)) {
                $a = IpCalc::ip2int($m[1]);
                $b = str_contains($m[2], '.')
                    ? IpCalc::ip2int($m[2])
                    : ($a === null || (int) $m[2] > 255 ? null : (($a & 0xFFFFFF00) | (int) $m[2]));
                if ($a === null || $b === null) {
                    throw new \InvalidArgumentException("Nie rozumiem zakresu „{$p}”.");
                }
                if ($a > $b) {
                    [$a, $b] = [$b, $a];
                }
                if ($b - $a + 1 > self::MAX_ADDRESSES) {
                    throw new \InvalidArgumentException('Najwięcej ' . self::MAX_ADDRESSES . ' adresów naraz. Większy blok dodaj jako prefiks.');
                }
                for ($i = $a; $i <= $b; $i++) {
                    $out[$i] = true;
                }
            } else {
                $ip = IpCalc::ip2int($p);
                if ($ip === null) {
                    throw new \InvalidArgumentException("„{$p}” to nie jest adres IPv4.");
                }
                $out[$ip] = true;
            }

            if (count($out) > self::MAX_ADDRESSES) {
                throw new \InvalidArgumentException('Najwięcej ' . self::MAX_ADDRESSES . ' adresów naraz. Większy blok dodaj jako prefiks.');
            }
        }

        $list = array_keys($out);
        sort($list);

        return ['kind' => 'addresses', 'addresses' => $list];
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
