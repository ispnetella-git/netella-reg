<?php

/*
 * VlanIndex.php - status każdego numeru 1-4094 w całej sieci.
 *
 * Wejście: wiersze migawki (jeden na parę urządzenie + VLAN) i aktywne wpisy
 * ręczne. Numer jest zajęty, jeśli występuje na którymkolwiek urządzeniu
 * albo ma wpis ręczny. Priorytet statusów (dane LibreNMS mają pierwszeństwo):
 *
 *   l  LibreNMS      jest na aktywnym urządzeniu
 *   s  wyłączone     jest tylko na hostach wyłączonych w LibreNMS
 *                    (gdy ustawienie disabled_devices = ignore - pomijany)
 *   m  ręczny        wpis ręczny ze statusem "używany"
 *   r  rezerwacja    wpis ręczny ze statusem "rezerwacja"
 *   g  zniknął       zniknął z LibreNMS i trwa karencja (grace_days)
 *   f  wolny
 *
 * Wpis ręczny przy numerze z LibreNMS nie zmienia statusu, ale jest widoczny
 * jako adnotacja (manualMask, manual()). Wpis ręczny po terminie ważności
 * dalej zajmuje numer (expiredMask oznacza go na mapie).
 *
 * Oznaczenia (VlanFlag, np. QinQ) numerów z LibreNMS nie zmieniają statusu.
 * Liczą się, dopóki LibreNMS widzi numer albo trwa karencja (flaggable).
 *
 * Czyste, bez Laravela.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Vlan;

use Ispnetella\NetellaReg\Core\Lang;

final class VlanIndex
{
    public const FREE = 'f';
    public const LNMS = 'l';
    public const STALE = 's';
    public const GONE = 'g';
    public const MANUAL = 'm';
    public const RESERVED = 'r';

    public const LABELS = [
        self::FREE => 'wolny',
        self::LNMS => 'LibreNMS',
        self::STALE => 'tylko na wyłączonych hostach',
        self::MANUAL => 'wpis ręczny',
        self::RESERVED => 'rezerwacja',
        self::GONE => 'zniknął z LibreNMS (karencja)',
    ];

    /** Etykiety statusów w języku interfejsu (stałe LABELS zostają po polsku). */
    public static function labels(): array
    {
        return array_map(fn (string $l) => Lang::t($l), self::LABELS);
    }

    /** Większa liczba wygrywa przy kilku źródłach. */
    private const RANK = [
        self::FREE => 0, self::GONE => 1, self::RESERVED => 2, self::MANUAL => 3, self::STALE => 4, self::LNMS => 5,
    ];

    /** @var array<int,string> numer => status */
    private array $status = [];

    /** @var array<int,list<array>> numer => wystąpienia */
    private array $occurrences = [];

    /** @var array<int,array> numer => aktywny wpis ręczny */
    private array $manual = [];

    /** @var array<int,array> numer => oznaczenie (VlanFlag) */
    private array $flags = [];

    /**
     * @param  list<array{key:string,data:array,first_seen:string,last_seen:string,gone_at:?string}>  $rows
     * @param  array<int,array{status:string}>  $manual  numer => wpis ręczny
     * @param  array<int,array{qinq:bool}>  $flags  numer => oznaczenie (VlanFlag)
     */
    public function __construct(array $rows, \DateTimeImmutable $now, int $graceDays, string $disabledMode, array $manual = [], array $flags = [])
    {
        $this->flags = $flags;

        foreach ($manual as $vlan => $entry) {
            $vlan = (int) $vlan;
            if ($vlan < VlanName::MIN || $vlan > VlanName::MAX) {
                continue;
            }
            $this->manual[$vlan] = $entry;
            $this->raise($vlan, ($entry['status'] ?? '') === 'reserved' ? self::RESERVED : self::MANUAL);
        }

        $graceLimit = $now->modify("-{$graceDays} days")->format('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $vlan = (int) ($row['data']['vlan'] ?? 0);
            if ($vlan < VlanName::MIN || $vlan > VlanName::MAX) {
                continue;
            }

            $state = self::rowState($row, $graceLimit, $disabledMode);
            $row['state'] = $state;
            $this->occurrences[$vlan][] = $row;

            $this->raise($vlan, $state);
        }
    }

    private function raise(int $vlan, string $state): void
    {
        if ($state !== self::FREE && self::RANK[$state] > self::RANK[$this->status[$vlan] ?? self::FREE]) {
            $this->status[$vlan] = $state;
        }
    }

    /** Aktywny wpis ręczny numeru albo null. */
    public function manual(int $vlan): ?array
    {
        return $this->manual[$vlan] ?? null;
    }

    /**
     * Numery, których opis zawiera tekst (bez rozróżniania wielkości liter):
     * nazwa VLAN-u w LibreNMS (bez wystąpień po karencji) albo nazwa, opis
     * i właściciel wpisu ręcznego.
     *
     * @return array<int,array{names:list<string>,manual:?array}> numer => pasujące nazwy z LibreNMS i wpis ręczny
     */
    public function search(string $q): array
    {
        $needle = mb_strtolower(trim($q));
        if ($needle === '') {
            return [];
        }
        $out = [];
        foreach ($this->occurrences as $vlan => $rows) {
            foreach ($rows as $r) {
                $name = (string) ($r['data']['name'] ?? '');
                if ($r['state'] !== self::FREE && $name !== '' && str_contains(mb_strtolower($name), $needle)) {
                    $out[$vlan]['names'][$name] = true;
                }
            }
        }
        foreach ($this->manual as $vlan => $m) {
            $text = implode(' ', [$m['name'] ?? '', $m['description'] ?? '', $m['owner'] ?? '']);
            if (str_contains(mb_strtolower($text), $needle)) {
                $out[$vlan]['manual'] = $m;
            }
        }
        ksort($out);

        return array_map(fn ($h) => ['names' => array_keys($h['names'] ?? []), 'manual' => $h['manual'] ?? null], $out);
    }

    /** Czy numer jest teraz na aktywnym urządzeniu w LibreNMS. */
    public function inLibreNms(int $vlan): bool
    {
        return $this->status($vlan) === self::LNMS;
    }

    /**
     * Czy LibreNMS widzi numer teraz: na aktywnym urządzeniu albo tylko na
     * wyłączonych (gdy są liczone, ustawienie disabled_devices = stale). Takiego numeru nie da
     * się dodać ręcznie. Zniknięcie w karencji się nie liczy.
     */
    public function knownToLibreNms(int $vlan): bool
    {
        return in_array($this->status($vlan), [self::LNMS, self::STALE], true);
    }

    /**
     * 4094 znaki: "1" gdy numer zajmuje wpis ręczny, któremu minął termin
     * (pole "expiry" z Core\Expiry::present). Numery z LibreNMS się nie liczą.
     */
    public function expiredMask(): string
    {
        $s = '';
        for ($v = VlanName::MIN; $v <= VlanName::MAX; $v++) {
            $st = $this->status($v);
            $s .= ($this->manual[$v]['expiry'] ?? null) === 'expired' && ($st === self::MANUAL || $st === self::RESERVED) ? '1' : '0';
        }

        return $s;
    }

    /**
     * Czy numer może mieć oznaczenie bez wpisu ręcznego: LibreNMS go widzi
     * (także tylko na wyłączonych hostach) albo trwa karencja po zniknięciu.
     */
    public function flaggable(int $vlan): bool
    {
        return in_array($this->status($vlan), [self::LNMS, self::STALE, self::GONE], true);
    }

    /** Oznaczenie numeru, gdy się liczy (patrz flaggable i staleFlags), albo null. */
    public function flag(int $vlan): ?array
    {
        return isset($this->flags[$vlan]) && $this->flaggable($vlan) && ! isset($this->manual[$vlan]) ? $this->flags[$vlan] : null;
    }

    /**
     * Numery z oznaczeniem, które przestało się liczyć: minęła karencja albo
     * numer dostał wpis ręczny. sync() je zdejmuje (VlanFlag::prune).
     *
     * @return list<int>
     */
    public function staleFlags(): array
    {
        return array_values(array_filter(array_map('intval', array_keys($this->flags)), fn (int $v) => $this->flag($v) === null));
    }

    /** Czy numer jest oznaczony jako QinQ: we wpisie ręcznym (także przejętym) albo oznaczeniem numeru z LibreNMS. */
    public function qinq(int $vlan): bool
    {
        return ! empty($this->manual[$vlan]['qinq']) || ! empty($this->flag($vlan)['qinq']);
    }

    /** 4094 znaki: "1" gdy numer jest oznaczony jako QinQ (qinq()). */
    public function qinqMask(): string
    {
        $s = '';
        for ($v = VlanName::MIN; $v <= VlanName::MAX; $v++) {
            $s .= $this->qinq($v) ? '1' : '0';
        }

        return $s;
    }

    /** 4094 znaki: "1" gdy numer ma wpis ręczny. */
    public function manualMask(): string
    {
        $s = '';
        for ($v = VlanName::MIN; $v <= VlanName::MAX; $v++) {
            $s .= isset($this->manual[$v]) ? '1' : '0';
        }

        return $s;
    }

    private static function rowState(array $row, string $graceLimit, string $disabledMode): string
    {
        if ($row['gone_at'] !== null) {
            return $row['gone_at'] >= $graceLimit ? self::GONE : self::FREE;
        }

        if (! empty($row['data']['disabled'])) {
            return $disabledMode === 'ignore' ? self::FREE : self::STALE;
        }

        return self::LNMS;
    }

    public function status(int $vlan): string
    {
        return $this->status[$vlan] ?? self::FREE;
    }

    public function isFree(int $vlan): bool
    {
        return $this->status($vlan) === self::FREE;
    }

    /** Wszystkie wystąpienia numeru, także historyczne (po karencji mają state = f). */
    public function occurrences(int $vlan): array
    {
        return $this->occurrences[$vlan] ?? [];
    }

    /** 4094 znaki, znak i-ty to status VLAN-u i+1. Zwięzłe do przesłania mapy. */
    public function statusString(): string
    {
        $s = '';
        for ($v = VlanName::MIN; $v <= VlanName::MAX; $v++) {
            $s .= $this->status($v);
        }

        return $s;
    }

    /** @return array<string,int> status => liczba numerów */
    public function counts(): array
    {
        $c = array_fill_keys(array_keys(self::LABELS), 0);
        for ($v = VlanName::MIN; $v <= VlanName::MAX; $v++) {
            $c[$this->status($v)]++;
        }

        return $c;
    }
}
