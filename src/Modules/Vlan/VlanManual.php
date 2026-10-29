<?php

/*
 * VlanManual.php - ręczne wpisy VLAN-ów (tabela reg_vlan_manual).
 *
 * Jeden wiersz na numer. Numery dodane jednym kliknięciem (zakres) tworzą
 * grupę: wspólne batch_id = id pierwszego wiersza. Grupę edytuje się
 * i usuwa w całości, a w dzienniku każda taka akcja to JEDEN wpis z zakresem
 * (range_from/range_to, lista numerów w polu "vlans"). Edycja lub usunięcie
 * pojedynczego numeru odłącza go od grupy.
 *
 * Numer może mieć najwyżej jeden aktywny wpis, bo zajętość liczymy dla całej
 * sieci. Numeru, który LibreNMS już widzi, nie da się dodać ręcznie.
 *
 * Usunięcie jest miękkie (deleted_at), żeby historia przy numerze była pełna.
 *
 * Termin ważności (valid_until, schemat 4): po terminie wpis dalej zajmuje
 * numer, ale jest oznaczony jako "termin minął" (Core\Expiry), a sync()
 * zapisuje jedno zdarzenie "expired" na grupę.
 *
 * Przejęcie przez LibreNMS: gdy numer wpisany ręcznie pojawi się na aktywnym
 * urządzeniu, sync() ustawia adopted_at i dopisuje zdarzenie "adopted"
 * (jedno na grupę). Dane LibreNMS mają wtedy pierwszeństwo.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Vlan;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Ispnetella\NetellaReg\Core\Access;
use Ispnetella\NetellaReg\Core\ApiException;
use Ispnetella\NetellaReg\Core\AuditLog;
use Ispnetella\NetellaReg\Core\Db;
use Ispnetella\NetellaReg\Core\Expiry;
use Ispnetella\NetellaReg\Core\Lang;

final class VlanManual
{
    public const TABLE = 'reg_vlan_manual';

    public const TYPE = 'manual';

    public const USED = 'used';
    public const RESERVED = 'reserved';

    public const STATUS_LABELS = [self::USED => 'używany', self::RESERVED => 'rezerwacja'];

    /** Etykiety statusów w języku interfejsu (stałe STATUS_LABELS zostają po polsku). */
    public static function statusLabels(): array
    {
        return array_map(fn (string $l) => Lang::t($l), self::STATUS_LABELS);
    }

    /** Najwięcej numerów w jednym dodaniu zakresu. */
    public const MAX_BATCH = 500;

    /** Pola edytowalne i ich limity długości. */
    private const FIELDS = ['name' => 64, 'description' => 1000, 'owner' => 128, 'devices' => 1000];

    /** Pola opisowe - wspólne dla całej grupy. */
    private const DATA_FIELDS = ['name', 'description', 'owner', 'status', 'devices', Expiry::FIELD];

    public function __construct(private readonly Connection $db)
    {
    }

    public static function migrate(Connection $db, int $from): void
    {
        $schema = $db->getSchemaBuilder();

        if ($from < 2 && ! $schema->hasTable(self::TABLE)) {
            $schema->create(self::TABLE, function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedSmallInteger('vlan');
                $t->string('name', 64)->nullable();
                $t->text('description');
                $t->string('owner', 128)->nullable()->comment('klient, operator, osoba');
                $t->string('status', 16)->default(self::USED)->comment('used albo reserved');
                $t->text('devices')->nullable()->comment('tekst: urządzenia, lokalizacja');
                $t->dateTime('created_at');
                $t->string('created_by', 191)->nullable();
                $t->dateTime('updated_at');
                $t->string('updated_by', 191)->nullable();
                $t->dateTime('adopted_at')->nullable()->comment('kiedy numer pojawił się w LibreNMS');
                $t->dateTime('deleted_at')->nullable();
                $t->string('deleted_by', 191)->nullable();
                $t->index(['vlan', 'deleted_at']);
            });
        }

        if ($from < 3) {
            if (! $schema->hasColumn(self::TABLE, 'batch_id')) {
                $schema->table(self::TABLE, function (Blueprint $t) {
                    $t->unsignedBigInteger('batch_id')->nullable()->after('id')->comment('grupa dodana jednym kliknięciem');
                    $t->index('batch_id');
                });
            }
            self::consolidateLegacy($db);
        }

        if ($from < 4 && ! $schema->hasColumn(self::TABLE, Expiry::FIELD)) {
            $schema->table(self::TABLE, function (Blueprint $t) {
                $t->date(Expiry::FIELD)->nullable()->after('devices')->comment('termin ważności wpisu');
                $t->dateTime('expired_at')->nullable()->after('adopted_at')->comment('kiedy sync zapisał, że termin minął');
            });
        }
    }

    /**
     * Jednorazowo po aktualizacji do schematu 3: wpisy z wersji 0.2.x dodane
     * jednym kliknięciem (ten sam czas, autor i opis) stają się grupą,
     * a ich pojedyncze wpisy "create" w dzienniku jednym wpisem grupowym.
     * Na życzenie użytkownika (2026-10-27): jedna akcja = jedna linia dziennika.
     */
    private static function consolidateLegacy(Connection $db): void
    {
        $groups = [];
        foreach ($db->table(self::TABLE)->whereNull('batch_id')->orderBy('id')->get() as $r) {
            $sig = json_encode([$r->created_at, $r->created_by, $r->name, $r->description, $r->owner, $r->status, $r->devices]);
            $groups[$sig][] = $r;
        }

        foreach ($groups as $rows) {
            $batch = (int) $rows[0]->id;
            $db->table(self::TABLE)->whereIn('id', array_map(fn ($r) => $r->id, $rows))->update(['batch_id' => $batch]);

            if (count($rows) < 2) {
                continue;
            }

            $keys = array_map(fn ($r) => self::key((int) $r->vlan, (int) $r->id), $rows);
            $audit = $db->table(Db::AUDIT)->where('module', VlanModule::KEY)->where('entity_type', self::TYPE)
                ->where('action', AuditLog::CREATE)->whereIn('entity_key', $keys)->orderBy('id')->get();
            if (count($audit) < 2) {
                continue;
            }

            $first = $audit[0];
            $vlans = array_map(fn ($r) => (int) $r->vlan, $rows);
            $after = self::groupView((array) $rows[0], $vlans);

            $db->transaction(function () use ($db, $audit, $first, $after, $vlans, $batch) {
                $db->table(Db::AUDIT)->where('id', $first->id)->update([
                    'entity_key' => self::batchKey($batch),
                    'range_from' => min($vlans),
                    'range_to' => max($vlans),
                    'after' => json_encode($after, JSON_UNESCAPED_UNICODE),
                    'comment' => trim(($first->comment ? $first->comment . '; ' : '') . 'scalono ' . count($audit) . ' wpisów'),
                ]);
                $db->table(Db::AUDIT)->whereIn('id', array_map(fn ($r) => $r->id, array_slice($audit->all(), 1)))->delete();
            });
        }
    }

    public static function key(int $vlan, int $id): string
    {
        return "v{$vlan}:m{$id}";
    }

    public static function batchKey(int $batch): string
    {
        return "vbatch:{$batch}";
    }

    /**
     * Aktywne wpisy: numer => wiersz.
     *
     * @return array<int,array>
     */
    public function active(): array
    {
        $out = [];
        foreach ($this->db->table(self::TABLE)->whereNull('deleted_at')->orderBy('vlan')->get() as $r) {
            $out[(int) $r->vlan] = self::present((array) $r);
        }

        return $out;
    }

    /**
     * Aktywne wpisy pogrupowane: jedna pozycja na grupę (albo pojedynczy numer).
     *
     * @return list<array>
     */
    public function grouped(): array
    {
        $groups = [];
        foreach ($this->active() as $vlan => $r) {
            $g = $r['batch_id'] ?? $r['id'];
            $groups[$g] ??= $r + ['vlans_list' => []];
            $groups[$g]['vlans_list'][] = $vlan;
        }

        return array_values(array_map(function (array $g) {
            $g['vlans'] = VlanRange::compress($g['vlans_list']);
            $g['count'] = count($g['vlans_list']);
            unset($g['vlan']);

            return $g;
        }, $groups));
    }

    public function find(int $id): ?array
    {
        $r = $this->db->table(self::TABLE)->where('id', $id)->whereNull('deleted_at')->first();

        return $r === null ? null : self::present((array) $r);
    }

    /** @return list<array> aktywne wiersze grupy */
    private function batchRows(int $batch): array
    {
        return $this->db->table(self::TABLE)->where('batch_id', $batch)->whereNull('deleted_at')->orderBy('vlan')
            ->get()->map(fn ($r) => self::present((array) $r))->all();
    }

    private static function present(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['vlan'] = (int) $r['vlan'];
        $r['batch_id'] = isset($r['batch_id']) ? (int) $r['batch_id'] : null;
        $r['status_label'] = Lang::t(self::STATUS_LABELS[$r['status']] ?? $r['status']);

        return Expiry::present($r, Expiry::today());
    }

    /** Stan pojedynczego wpisu do dziennika: bez pól technicznych. */
    private static function auditView(array $r): array
    {
        return array_intersect_key($r, array_flip(['vlan', 'name', 'description', 'owner', 'status', 'devices', Expiry::FIELD, 'adopted_at']));
    }

    /** Stan grupy do dziennika: pola wspólne + lista numerów. */
    private static function groupView(array $r, array $vlans): array
    {
        return ['vlans' => VlanRange::compress($vlans), 'count' => count($vlans)]
            + array_intersect_key($r, array_flip(self::DATA_FIELDS));
    }

    /**
     * Pola z formularza po walidacji.
     *
     * @return array<string,?string>
     */
    public static function validate(array $input, bool $requireDescription = true): array
    {
        $out = [];

        foreach (self::FIELDS as $f => $max) {
            if (! array_key_exists($f, $input)) {
                continue;
            }
            $v = trim((string) ($input[$f] ?? ''));
            if (mb_strlen($v) > $max) {
                throw new ApiException(Lang::t('Pole „{f}” może mieć najwyżej {max} znaków.', ['f' => $f, 'max' => $max]));
            }
            $out[$f] = $v === '' ? null : $v;
        }

        if (array_key_exists('status', $input)) {
            $status = (string) $input['status'];
            if (! isset(self::STATUS_LABELS[$status])) {
                throw new ApiException(Lang::t('Status musi być „used” albo „reserved”.'));
            }
            $out['status'] = $status;
        }

        // Termin w przeszłości odrzucają create() i update() (tylko przy zmianie terminu).
        if (array_key_exists(Expiry::FIELD, $input)) {
            $out[Expiry::FIELD] = Expiry::parse($input[Expiry::FIELD], Expiry::today(), true);
        }

        if ($requireDescription && ($out['description'] ?? null) === null) {
            throw new ApiException(Lang::t('Opis jest wymagany.'));
        }

        return $out;
    }

    /** Nowy termin ważności nie może być w przeszłości. */
    private static function checkFuture(?string $validUntil): void
    {
        if ($validUntil !== null) {
            Expiry::parse($validUntil, Expiry::today());
        }
    }

    /**
     * Numery, których nie wolno dodać ręcznie, bo LibreNMS już je widzi.
     *
     * @param  list<int>  $numbers
     * @param  callable(int):bool  $knownToLibreNms
     * @return list<int>
     */
    public static function blocked(array $numbers, callable $knownToLibreNms): array
    {
        return array_values(array_filter($numbers, fn (int $v) => $knownToLibreNms($v)));
    }

    /**
     * Dodaje wpisy dla wszystkich numerów z zakresu jako jedną grupę.
     *
     * Blokada: numer widoczny w LibreNMS (na aktywnym albo wyłączonym urządzeniu)
     * nie może dostać wpisu ręcznego - LibreNMS jest dla niego źródłem prawdy.
     * Jeden zablokowany numer odrzuca całe żądanie, żeby nie powstał zakres
     * z dziurami, o których użytkownik nie wie.
     *
     * @param  callable(int):bool  $knownToLibreNms
     * @return array{created:list<int>,batch_id:int}
     */
    public function create(string $range, array $input, ?string $comment, callable $knownToLibreNms): array
    {
        try {
            $numbers = VlanRange::parse($range);
        } catch (\InvalidArgumentException $e) {
            throw new ApiException($e->getMessage());
        }

        if (count($numbers) > self::MAX_BATCH) {
            throw new ApiException(Lang::t('Najwięcej {max} numerów naraz.', ['max' => self::MAX_BATCH]));
        }

        $fields = self::validate($input) + ['status' => self::USED];
        self::checkFuture($fields[Expiry::FIELD] ?? null);

        $taken = $this->db->table(self::TABLE)->whereNull('deleted_at')->whereIn('vlan', $numbers)->pluck('vlan')->all();
        if ($taken !== []) {
            throw new ApiException(Lang::t('Te numery mają już wpis ręczny (edytuj go zamiast dodawać nowy): {list}',
                ['list' => VlanRange::compress(array_map('intval', $taken))]));
        }

        $blocked = self::blocked($numbers, $knownToLibreNms);
        if ($blocked !== []) {
            $free = array_values(array_diff($numbers, $blocked));
            throw new ApiException(Lang::t('Tych numerów nie można dodać ręcznie, bo są już w LibreNMS: {list}.', ['list' => VlanRange::compress($blocked)])
                . ($free !== [] ? ' ' . Lang::t('Bez nich zostaje: {list}.', ['list' => VlanRange::compress($free)]) : ''), 409);
        }

        $now = date('Y-m-d H:i:s');
        $user = Access::username();
        $batch = 0;

        $this->db->transaction(function () use ($numbers, $fields, $now, $user, $comment, &$batch) {
            foreach ($numbers as $vlan) {
                $row = $fields + [
                    'vlan' => $vlan,
                    'batch_id' => $batch ?: null,
                    'created_at' => $now,
                    'created_by' => $user,
                    'updated_at' => $now,
                    'updated_by' => $user,
                    'adopted_at' => null,
                ];
                $id = (int) $this->db->table(self::TABLE)->insertGetId($row);
                if ($batch === 0) {
                    $batch = $id;
                    $this->db->table(self::TABLE)->where('id', $id)->update(['batch_id' => $id]);
                }
            }

            // Jedna akcja = jeden wpis w dzienniku, niezależnie od liczby numerów.
            $one = count($numbers) === 1;
            AuditLog::user(VlanModule::KEY, self::TYPE,
                $one ? self::key($numbers[0], $batch) : self::batchKey($batch),
                AuditLog::CREATE, null, self::groupView($fields, $numbers), $comment,
                [min($numbers), max($numbers)]);
        });

        return ['created' => $numbers, 'batch_id' => $batch];
    }

    /**
     * Edycja. $group = true: wszystkie numery grupy wpisu $id naraz (jeden wpis
     * w dzienniku). $group = false: tylko ten numer, który przy okazji
     * odłącza się od grupy.
     */
    public function update(int $id, array $input, ?string $comment, bool $group = false): array
    {
        $before = $this->find($id);
        if ($before === null) {
            throw new ApiException(Lang::t('Nie ma takiego wpisu (mógł zostać usunięty).'), 404);
        }

        // Przy edycji opis jest wymagany tylko wtedy, gdy przyszedł w żądaniu
        // (nie można go wyczyścić, ale można zmienić same inne pola).
        $fields = self::validate($input, false);
        if (array_key_exists('description', $fields) && $fields['description'] === null) {
            throw new ApiException(Lang::t('Opis jest wymagany.'));
        }

        $rows = $group && $before['batch_id'] ? $this->batchRows($before['batch_id']) : [$before];
        $changed = array_filter($fields, fn ($v, $k) => ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH);
        if (array_key_exists(Expiry::FIELD, $changed)) {
            self::checkFuture($changed[Expiry::FIELD]);
        }

        // Pojedyncza edycja numeru z wieloelementowej grupy odłącza go od niej.
        $batchSize = $before['batch_id'] ? count($this->batchRows($before['batch_id'])) : 1;
        $detach = ! $group && $batchSize > 1 && $changed !== [];

        if ($changed === [] && ! $detach) {
            return $before;
        }

        $vlans = array_map(fn ($r) => $r['vlan'], $rows);
        $ids = array_map(fn ($r) => $r['id'], $rows);

        $this->db->transaction(function () use ($changed, $ids, $detach, $before, $rows, $vlans, $comment, $group) {
            $set = $changed + ['updated_at' => date('Y-m-d H:i:s'), 'updated_by' => Access::username()];
            if (array_key_exists(Expiry::FIELD, $changed)) {
                $set['expired_at'] = null; // nowy termin: "termin minął" zapisze się ponownie po nim
            }
            if ($detach) {
                $set['batch_id'] = $before['id'];
                // Gdy odłączany jest "pierwszy" wpis grupy, reszta dostaje nowe batch_id.
                if ($before['batch_id'] === $before['id']) {
                    $rest = $this->db->table(self::TABLE)->where('batch_id', $before['id'])->where('id', '!=', $before['id'])
                        ->whereNull('deleted_at')->orderBy('id')->pluck('id')->all();
                    if ($rest !== []) {
                        $this->db->table(self::TABLE)->whereIn('id', $rest)->update(['batch_id' => min($rest)]);
                    }
                }
            }
            $this->db->table(self::TABLE)->whereIn('id', $ids)->update($set);

            $isGroup = $group && count($rows) > 1;
            AuditLog::user(VlanModule::KEY, self::TYPE,
                $isGroup ? self::batchKey((int) $before['batch_id']) : self::key($before['vlan'], $before['id']),
                AuditLog::UPDATE,
                ['vlans' => VlanRange::compress($vlans)] + array_intersect_key($before, $changed),
                ['vlans' => VlanRange::compress($vlans)] + $changed,
                trim(($comment ?? '') . ($detach ? ($comment ? '; ' : '') . 'odłączony od grupy' : '')) ?: null,
                [min($vlans), max($vlans)]);
        });

        return $this->find($id);
    }

    /** Usunięcie numeru albo (przy $group) całej grupy. Jeden wpis w dzienniku. */
    public function delete(int $id, ?string $comment, bool $group = false): array
    {
        $before = $this->find($id);
        if ($before === null) {
            throw new ApiException(Lang::t('Nie ma takiego wpisu (mógł zostać już usunięty).'), 404);
        }

        $rows = $group && $before['batch_id'] ? $this->batchRows($before['batch_id']) : [$before];
        $vlans = array_map(fn ($r) => $r['vlan'], $rows);
        $isGroup = count($rows) > 1;

        $this->db->transaction(function () use ($rows, $vlans, $before, $comment, $isGroup) {
            $this->db->table(self::TABLE)->whereIn('id', array_map(fn ($r) => $r['id'], $rows))->update([
                'deleted_at' => date('Y-m-d H:i:s'),
                'deleted_by' => Access::username(),
            ]);

            AuditLog::user(VlanModule::KEY, self::TYPE,
                $isGroup ? self::batchKey((int) $before['batch_id']) : self::key($before['vlan'], $before['id']),
                AuditLog::DELETE, self::groupView($before, $vlans), null, $comment, [min($vlans), max($vlans)]);
        });

        return $vlans;
    }

    /**
     * Oznacza wpisy, których numer właśnie pojawił się w LibreNMS. Wołane z sync().
     * Numery jednej grupy przejęte w tym samym przebiegu dają jeden wpis.
     *
     * @param  callable(int):bool  $inLibreNms
     * @return int liczba przejętych numerów
     */
    public function adopt(callable $inLibreNms, \DateTimeImmutable $now): int
    {
        $ts = $now->format('Y-m-d H:i:s');
        $byGroup = [];

        foreach ($this->db->table(self::TABLE)->whereNull('deleted_at')->whereNull('adopted_at')->orderBy('vlan')->get() as $r) {
            if ($inLibreNms((int) $r->vlan)) {
                $byGroup[(int) ($r->batch_id ?? $r->id)][] = $r;
            }
        }

        $audit = [];
        $count = 0;
        foreach ($byGroup as $batch => $rows) {
            $this->db->table(self::TABLE)->whereIn('id', array_map(fn ($r) => $r->id, $rows))->update(['adopted_at' => $ts]);
            $vlans = array_map(fn ($r) => (int) $r->vlan, $rows);
            $count += count($vlans);
            $first = $rows[0];
            $audit[] = AuditLog::row(VlanModule::KEY, self::TYPE,
                count($rows) > 1 ? self::batchKey($batch) : self::key((int) $first->vlan, (int) $first->id),
                AuditLog::ADOPTED, AuditLog::SOURCE_SYNC,
                ['vlans' => VlanRange::compress($vlans), 'adopted_at' => null],
                ['vlans' => VlanRange::compress($vlans), 'adopted_at' => $ts],
                'wpisany ręcznie ' . $first->created_at . ($first->created_by ? ' przez ' . $first->created_by : '')
                . ', pojawił się w LibreNMS; dane LibreNMS mają pierwszeństwo',
                null, null, null, $now, [min($vlans), max($vlans)]);
        }

        AuditLog::insertMany($audit);

        return $count;
    }

    /**
     * Zapisuje "termin minął" dla wpisów po terminie. Wołane z sync().
     * Wpisy przejęte przez LibreNMS pomijamy - ich termin nie ma już znaczenia.
     * Jedna grupa = jeden wpis w dzienniku.
     *
     * @return int liczba numerów, którym właśnie minął termin
     */
    public function expire(\DateTimeImmutable $now): int
    {
        $ts = $now->format('Y-m-d H:i:s');
        $byGroup = [];
        foreach ($this->db->table(self::TABLE)->whereNull('deleted_at')->whereNull('adopted_at')->whereNull('expired_at')
            ->whereNotNull(Expiry::FIELD)->where(Expiry::FIELD, '<', Expiry::today($now))->orderBy('vlan')->get() as $r) {
            $byGroup[(int) ($r->batch_id ?? $r->id)][] = $r;
        }

        $audit = [];
        $count = 0;
        foreach ($byGroup as $batch => $rows) {
            $this->db->table(self::TABLE)->whereIn('id', array_map(fn ($r) => $r->id, $rows))->update(['expired_at' => $ts]);
            $vlans = array_map(fn ($r) => (int) $r->vlan, $rows);
            $count += count($vlans);
            $first = $rows[0];
            $audit[] = AuditLog::row(VlanModule::KEY, self::TYPE,
                count($rows) > 1 ? self::batchKey($batch) : self::key((int) $first->vlan, (int) $first->id),
                AuditLog::EXPIRED, AuditLog::SOURCE_SYNC,
                null, ['vlans' => VlanRange::compress($vlans), Expiry::FIELD => substr((string) $first->valid_until, 0, 10)],
                'wpis nadal zajmuje numer; przedłuż termin albo usuń wpis',
                null, null, null, $now, [min($vlans), max($vlans)]);
        }

        AuditLog::insertMany($audit);

        return $count;
    }
}
