<?php

/*
 * AuditLog.php - dziennik zmian wszystkich modułów.
 *
 * Każda zmiana rekordu, zarówno przez użytkownika, jak i wykryta przy
 * synchronizacji z LibreNMS, to jeden wiersz z pełnym stanem przed i po.
 * Wiersze są tylko dopisywane - nie ma API do ich edycji ani usuwania.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Core;

final class AuditLog
{
    public const SOURCE_USER = 'user';
    public const SOURCE_SYNC = 'sync';

    /** Akcje użytkownika. */
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const DELETE = 'delete';
    public const SETTINGS = 'settings';
    public const RESTORE = 'restore';

    /** Akcje synchronizacji. */
    public const LNMS_APPEARED = 'lnms_appeared';
    public const LNMS_CHANGED = 'lnms_changed';
    public const LNMS_GONE = 'lnms_gone';
    public const ADOPTED = 'adopted';
    public const EXPIRED = 'expired';
    public const INITIAL_IMPORT = 'initial_import';

    public const LABELS = [
        self::CREATE => 'dodano',
        self::UPDATE => 'zmieniono',
        self::DELETE => 'usunięto',
        self::SETTINGS => 'ustawienia',
        self::RESTORE => 'przywrócono kopię',
        self::LNMS_APPEARED => 'pojawił się w LibreNMS',
        self::LNMS_CHANGED => 'zmienił się w LibreNMS',
        self::LNMS_GONE => 'zniknął z LibreNMS',
        self::ADOPTED => 'przejęty przez LibreNMS',
        self::EXPIRED => 'termin minął',
        self::INITIAL_IMPORT => 'pierwszy import',
    ];

    /**
     * Zmiana wprowadzona przez zalogowanego użytkownika.
     *
     * $range = [od, do]: wpis grupowy dotyczy całego zakresu liczbowego (np. VLAN-ów
     * dodanych naraz). Jedna akcja użytkownika to jeden wpis, a historia
     * pojedynczego numeru znajduje go przez filtr "covers".
     */
    public static function user(
        string $module,
        string $entityType,
        string $entityKey,
        string $action,
        ?array $before,
        ?array $after,
        ?string $comment = null,
        ?array $range = null,
    ): void {
        $ip = null;
        try {
            $ip = request()->ip();
        } catch (\Throwable) {
            // poza żądaniem HTTP (lnms)
        }

        Db::connection()->table(Db::AUDIT)->insert(self::row(
            $module, $entityType, $entityKey, $action, self::SOURCE_USER, $before, $after, $comment,
            Access::userId(), Access::username(), $ip, null, $range
        ));
    }

    /**
     * Wiersz zmiany z synchronizacji. Zwracany zamiast zapisywany, żeby
     * Snapshot mógł wstawić całą paczkę jednym zapytaniem.
     */
    public static function row(
        string $module,
        string $entityType,
        string $entityKey,
        string $action,
        string $source,
        ?array $before,
        ?array $after,
        ?string $comment = null,
        ?int $userId = null,
        ?string $username = null,
        ?string $ip = null,
        ?\DateTimeInterface $ts = null,
        ?array $range = null,
    ): array {
        return [
            'ts' => ($ts ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'module' => $module,
            'entity_type' => $entityType,
            'entity_key' => mb_substr($entityKey, 0, 191),
            'range_from' => $range === null ? null : (int) $range[0],
            'range_to' => $range === null ? null : (int) $range[1],
            'action' => $action,
            'source' => $source,
            'user_id' => $userId,
            'username' => $username,
            'ip' => $ip,
            'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'comment' => $comment,
        ];
    }

    /** @param list<array> $rows */
    public static function insertMany(array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            Db::connection()->table(Db::AUDIT)->insert($chunk);
        }
    }

    /**
     * Strona dziennika z filtrami.
     *
     * key_prefix i covers łączy OR: "wpisy tego rekordu albo wpisy grupowe,
     * których zakres go obejmuje".
     *
     * @param  array{module?:string,action?:string,source?:string,q?:string,key_prefix?:string,covers?:int,keys?:list<string>}  $filters
     * @return array{rows:list<array>,total:int}
     */
    public static function page(array $filters, int $page = 1, int $perPage = 50): array
    {
        $q = Db::connection()->table(Db::AUDIT);

        foreach (['module', 'action', 'source'] as $f) {
            if (! empty($filters[$f])) {
                $q->where($f, $filters[$f]);
            }
        }

        if (! empty($filters['keys'])) {
            $q->whereIn('entity_key', $filters['keys']);
        }

        // key_prefix (napis albo lista), covers (liczba) i overlaps ([od, do]) łączy OR.
        $prefixes = array_filter((array) ($filters['key_prefix'] ?? []), fn ($p) => $p !== '');
        $covers = $filters['covers'] ?? null;
        $overlaps = $filters['overlaps'] ?? null;
        if ($prefixes !== [] || $covers !== null || $overlaps !== null) {
            $q->where(function ($w) use ($prefixes, $covers, $overlaps) {
                foreach ($prefixes as $prefix) {
                    $w->orWhere('entity_key', 'like', addcslashes($prefix, '%_\\') . '%');
                }
                if ($covers !== null) {
                    $w->orWhere(fn ($r) => $r->where('range_from', '<=', (int) $covers)->where('range_to', '>=', (int) $covers));
                }
                if ($overlaps !== null) {
                    $w->orWhere(fn ($r) => $r->where('range_from', '<=', (int) $overlaps[1])->where('range_to', '>=', (int) $overlaps[0]));
                }
            });
        }

        if (! empty($filters['q'])) {
            $like = '%' . addcslashes((string) $filters['q'], '%_\\') . '%';
            $q->where(function ($w) use ($like) {
                $w->where('entity_key', 'like', $like)
                    ->orWhere('username', 'like', $like)
                    ->orWhere('before', 'like', $like)
                    ->orWhere('after', 'like', $like)
                    ->orWhere('comment', 'like', $like);
            });
        }

        $total = (clone $q)->count();
        $perPage = max(1, min(500, $perPage));
        $rows = $q->orderByDesc('id')->forPage(max(1, $page), $perPage)->get()
            ->map(function ($r) {
                $r = (array) $r;
                $r['before'] = $r['before'] === null ? null : json_decode($r['before'], true);
                $r['after'] = $r['after'] === null ? null : json_decode($r['after'], true);
                $r['action_label'] = self::LABELS[$r['action']] ?? $r['action'];

                return $r;
            })->all();

        return ['rows' => $rows, 'total' => $total];
    }
}
