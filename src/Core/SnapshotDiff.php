<?php

/*
 * SnapshotDiff.php - czyste porównanie migawki z bieżącym stanem LibreNMS.
 *
 * Bez Laravela, żeby dało się to testować w tests/run_tests.php.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Core;

final class SnapshotDiff
{
    /**
     * Hash niezależny od kolejności kluczy w tablicach asocjacyjnych.
     * Kolejność elementów list ma znaczenie, więc kolektory sortują listy same.
     */
    public static function hash(array $data): string
    {
        return sha1(json_encode(self::canonical($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function canonical(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }

        if (! array_is_list($v)) {
            ksort($v, SORT_STRING);
        }

        return array_map([self::class, 'canonical'], $v);
    }

    /**
     * @param  array<string,array{hash:string,gone:bool,data:array}>  $previous  migawka
     * @param  array<string,array>  $current  bieżący stan: klucz => dane
     * @return array{
     *     appeared: array<string,array>,
     *     returned: array<string,array{0:array,1:array}>,
     *     changed: array<string,array{0:array,1:array}>,
     *     gone: array<string,array>,
     *     unchanged: list<string>
     * }
     */
    public static function compute(array $previous, array $current): array
    {
        $out = ['appeared' => [], 'returned' => [], 'changed' => [], 'gone' => [], 'unchanged' => []];

        foreach ($current as $key => $data) {
            $key = (string) $key;
            $prev = $previous[$key] ?? null;

            if ($prev === null) {
                $out['appeared'][$key] = $data;
            } elseif ($prev['gone']) {
                $out['returned'][$key] = [$prev['data'], $data];
            } elseif ($prev['hash'] !== self::hash($data)) {
                $out['changed'][$key] = [$prev['data'], $data];
            } else {
                $out['unchanged'][] = $key;
            }
        }

        foreach ($previous as $key => $prev) {
            $key = (string) $key;
            if (! $prev['gone'] && ! array_key_exists($key, $current)) {
                $out['gone'][$key] = $prev['data'];
            }
        }

        return $out;
    }

    /**
     * Tylko pola, które się zmieniły - do czytelnego dziennika.
     *
     * @return array{0:array,1:array}
     */
    public static function changedFields(array $before, array $after): array
    {
        $b = [];
        $a = [];

        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $k) {
            $x = $before[$k] ?? null;
            $y = $after[$k] ?? null;
            if (json_encode(self::canonical($x)) !== json_encode(self::canonical($y))) {
                $b[$k] = $x;
                $a[$k] = $y;
            }
        }

        return [$b, $a];
    }
}
