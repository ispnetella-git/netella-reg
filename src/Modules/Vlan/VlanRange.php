<?php

/*
 * VlanRange.php - zakresy numerów VLAN: parsowanie, zapis skrócony, szukanie wolnych.
 *
 * Czyste, bez Laravela.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Vlan;

use Ispnetella\NetellaReg\Core\Lang;

final class VlanRange
{
    /**
     * "1400-1450, 1500 1600 to 1610" -> posortowana lista numerów.
     * Akceptuje "a-b", "a to b" (Huawei), przecinki i spacje.
     *
     * @return list<int>
     * @throws \InvalidArgumentException przy błędnym zapisie lub numerze spoza 1-4094
     */
    public static function parse(string $text): array
    {
        $text = trim(preg_replace('/\s+to\s+/i', '-', $text));

        if ($text === '') {
            throw new \InvalidArgumentException(Lang::t('Podaj numer lub zakres VLAN-ów, np. 1400-1450.'));
        }

        $out = [];

        foreach (preg_split('/[\s,;]+/', $text) as $part) {
            if ($part === '') {
                continue;
            }

            if (! preg_match('/^(\d{1,4})(?:-(\d{1,4}))?$/', $part, $m)) {
                throw new \InvalidArgumentException(Lang::t('Nie rozumiem fragmentu „{part}”.', ['part' => $part]));
            }

            $a = (int) $m[1];
            $b = isset($m[2]) ? (int) $m[2] : $a;

            if ($a > $b) {
                [$a, $b] = [$b, $a];
            }

            if ($a < VlanName::MIN || $b > VlanName::MAX) {
                throw new \InvalidArgumentException(Lang::t('Numer VLAN poza zakresem 1-4094: {part}.', ['part' => $part]));
            }

            for ($v = $a; $v <= $b; $v++) {
                $out[$v] = true;
            }
        }

        $list = array_keys($out);
        sort($list);

        return $list;
    }

    /**
     * [1400,1401,1402,1500] -> "1400-1402,1500".
     *
     * @param  list<int>  $numbers
     */
    public static function compress(array $numbers): string
    {
        $numbers = array_values(array_unique(array_map('intval', $numbers)));
        sort($numbers);

        $parts = [];
        $start = $prev = null;

        foreach ($numbers as $n) {
            if ($start === null) {
                $start = $prev = $n;
            } elseif ($n === $prev + 1) {
                $prev = $n;
            } else {
                $parts[] = $start === $prev ? (string) $start : "$start-$prev";
                $start = $prev = $n;
            }
        }

        if ($start !== null) {
            $parts[] = $start === $prev ? (string) $start : "$start-$prev";
        }

        return implode(',', $parts);
    }

    /**
     * Pierwszy ciąg $count kolejnych wolnych numerów w przedziale [$from, $to].
     *
     * @param  callable(int):bool  $isFree
     * @return list<int>|null
     */
    public static function findFreeRun(callable $isFree, int $count, int $from = VlanName::MIN, int $to = VlanName::MAX): ?array
    {
        $from = max(VlanName::MIN, $from);
        $to = min(VlanName::MAX, $to);

        if ($count < 1 || $from > $to) {
            return null;
        }

        $run = 0;

        for ($v = $from; $v <= $to; $v++) {
            $run = $isFree($v) ? $run + 1 : 0;

            if ($run === $count) {
                return range($v - $count + 1, $v);
            }
        }

        return null;
    }
}
