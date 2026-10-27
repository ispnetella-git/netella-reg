<?php

/*
 * VlanIndex.php - status każdego numeru 1-4094 w całej sieci.
 *
 * Wejście: wiersze migawki (jeden na parę urządzenie + VLAN) i aktywne wpisy
 * ręczne. Numer jest zajęty, jeśli występuje na którymkolwiek urządzeniu
 * albo ma wpis ręczny. Priorytet statusów (dane LibreNMS mają pierwszeństwo):
 *
 *   l  LibreNMS      jest na aktywnym urządzeniu
 *   s  nieaktualny   jest tylko na urządzeniach wyłączonych w LibreNMS
 *                    (gdy ustawienie disabled_devices = ignore - pomijany)
 *   m  ręczny        wpis ręczny ze statusem "używany"
 *   r  rezerwacja    wpis ręczny ze statusem "rezerwacja"
 *   g  zniknął       zniknął z LibreNMS i trwa karencja (grace_days)
 *   f  wolny
 *
 * Wpis ręczny przy numerze z LibreNMS nie zmienia statusu, ale jest widoczny
 * jako adnotacja (manualMask, manual()).
 *
 * Czyste, bez Laravela.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Vlan;

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
        self::STALE => 'nieaktualny (urządzenie wyłączone)',
        self::MANUAL => 'wpis ręczny',
        self::RESERVED => 'rezerwacja',
        self::GONE => 'zniknął z LibreNMS (karencja)',
    ];

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

    /**
     * @param  list<array{key:string,data:array,first_seen:string,last_seen:string,gone_at:?string}>  $rows
     * @param  array<int,array{status:string}>  $manual  numer => wpis ręczny
     */
    public function __construct(array $rows, \DateTimeImmutable $now, int $graceDays, string $disabledMode, array $manual = [])
    {
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

    /** Czy numer jest teraz na aktywnym urządzeniu w LibreNMS. */
    public function inLibreNms(int $vlan): bool
    {
        return $this->status($vlan) === self::LNMS;
    }

    /**
     * Czy LibreNMS widzi numer teraz: na aktywnym urządzeniu albo tylko na
     * wyłączonych (gdy są liczone jako nieaktualne). Takiego numeru nie da
     * się dodać ręcznie. Zniknięcie w karencji się nie liczy.
     */
    public function knownToLibreNms(int $vlan): bool
    {
        return in_array($this->status($vlan), [self::LNMS, self::STALE], true);
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
