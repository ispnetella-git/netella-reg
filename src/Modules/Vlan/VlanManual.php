<?php

/*
 * VlanManual.php - ręczne wpisy VLAN-ów (tabela reg_vlan_manual).
 *
 * Jeden wiersz na numer. Zakres dodany naraz to kilka wierszy z tym samym
 * opisem. Numer może mieć najwyżej jeden aktywny wpis, bo zajętość liczymy
 * dla całej sieci.
 *
 * Usunięcie jest miękkie (deleted_at), żeby historia przy numerze była pełna.
 * Pełny stan przed i po każdej zmianie zapisuje też dziennik (reg_audit).
 *
 * Przejęcie przez LibreNMS: gdy numer wpisany ręcznie pojawi się na aktywnym
 * urządzeniu, sync() ustawia adopted_at i dopisuje zdarzenie "adopted".
 * Dane LibreNMS mają wtedy pierwszeństwo, a wpis zostaje jako adnotacja.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Vlan;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Ispnetella\NetellaReg\Core\Access;
use Ispnetella\NetellaReg\Core\ApiException;
use Ispnetella\NetellaReg\Core\AuditLog;

final class VlanManual
{
    public const TABLE = 'reg_vlan_manual';

    public const TYPE = 'manual';

    public const USED = 'used';
    public const RESERVED = 'reserved';

    public const STATUS_LABELS = [self::USED => 'używany', self::RESERVED => 'rezerwacja'];

    /** Najwięcej numerów w jednym dodaniu zakresu. */
    public const MAX_BATCH = 500;

    /** Pola edytowalne i ich limity długości. */
    private const FIELDS = ['name' => 64, 'description' => 1000, 'owner' => 128, 'devices' => 1000];

    public function __construct(private readonly Connection $db)
    {
    }

    public static function migrate(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();

        if ($schema->hasTable(self::TABLE)) {
            return;
        }

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

    public static function key(int $vlan, int $id): string
    {
        return "v{$vlan}:m{$id}";
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

    public function find(int $id): ?array
    {
        $r = $this->db->table(self::TABLE)->where('id', $id)->whereNull('deleted_at')->first();

        return $r === null ? null : self::present((array) $r);
    }

    private static function present(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['vlan'] = (int) $r['vlan'];
        $r['status_label'] = self::STATUS_LABELS[$r['status']] ?? $r['status'];

        return $r;
    }

    /** Stan do dziennika: bez pól technicznych. */
    private static function auditView(array $r): array
    {
        return array_intersect_key($r, array_flip(['vlan', 'name', 'description', 'owner', 'status', 'devices', 'adopted_at']));
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
                throw new ApiException("Pole „{$f}” może mieć najwyżej {$max} znaków.");
            }
            $out[$f] = $v === '' ? null : $v;
        }

        if (array_key_exists('status', $input)) {
            $status = (string) $input['status'];
            if (! isset(self::STATUS_LABELS[$status])) {
                throw new ApiException('Status musi być „used” albo „reserved”.');
            }
            $out['status'] = $status;
        }

        if ($requireDescription && ($out['description'] ?? null) === null) {
            throw new ApiException('Opis jest wymagany.');
        }

        return $out;
    }

    /**
     * Dodaje wpisy dla wszystkich numerów z zakresu.
     *
     * @param  callable(int):bool  $inLibreNms  czy numer jest teraz na aktywnym urządzeniu
     * @return array{created:list<int>,already_in_librenms:list<int>}
     */
    public function create(string $range, array $input, ?string $comment, callable $inLibreNms): array
    {
        try {
            $numbers = VlanRange::parse($range);
        } catch (\InvalidArgumentException $e) {
            throw new ApiException($e->getMessage());
        }

        if (count($numbers) > self::MAX_BATCH) {
            throw new ApiException('Najwięcej ' . self::MAX_BATCH . ' numerów naraz.');
        }

        $fields = self::validate($input) + ['status' => self::USED];

        $taken = $this->db->table(self::TABLE)->whereNull('deleted_at')->whereIn('vlan', $numbers)->pluck('vlan')->all();
        if ($taken !== []) {
            throw new ApiException('Te numery mają już wpis ręczny (edytuj go zamiast dodawać nowy): '
                . VlanRange::compress(array_map('intval', $taken)));
        }

        $now = date('Y-m-d H:i:s');
        $user = Access::username();
        $inLnms = [];

        $this->db->transaction(function () use ($numbers, $fields, $now, $user, $comment, $inLibreNms, &$inLnms) {
            foreach ($numbers as $vlan) {
                // Numer już widoczny w LibreNMS: wpis jest od razu przejęty,
                // bez osobnego zdarzenia "adopted".
                $adopted = $inLibreNms($vlan) ? $now : null;
                if ($adopted !== null) {
                    $inLnms[] = $vlan;
                }

                $row = $fields + [
                    'vlan' => $vlan,
                    'created_at' => $now,
                    'created_by' => $user,
                    'updated_at' => $now,
                    'updated_by' => $user,
                    'adopted_at' => $adopted,
                ];
                $id = (int) $this->db->table(self::TABLE)->insertGetId($row);

                AuditLog::user(VlanModule::KEY, self::TYPE, self::key($vlan, $id), AuditLog::CREATE,
                    null, self::auditView($row), $comment);
            }
        });

        return ['created' => $numbers, 'already_in_librenms' => $inLnms];
    }

    public function update(int $id, array $input, ?string $comment): array
    {
        $before = $this->find($id);
        if ($before === null) {
            throw new ApiException('Nie ma takiego wpisu (mógł zostać usunięty).', 404);
        }

        $fields = self::validate($input);
        if (array_key_exists('description', $fields) && $fields['description'] === null) {
            throw new ApiException('Opis jest wymagany.');
        }

        $changed = array_filter($fields, fn ($v, $k) => ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH);
        if ($changed === []) {
            return $before;
        }

        $this->db->table(self::TABLE)->where('id', $id)->update($changed + [
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => Access::username(),
        ]);

        $after = $this->find($id);
        AuditLog::user(VlanModule::KEY, self::TYPE, self::key($before['vlan'], $id), AuditLog::UPDATE,
            array_intersect_key(self::auditView($before), $changed), array_intersect_key(self::auditView($after), $changed), $comment);

        return $after;
    }

    public function delete(int $id, ?string $comment): void
    {
        $before = $this->find($id);
        if ($before === null) {
            throw new ApiException('Nie ma takiego wpisu (mógł zostać już usunięty).', 404);
        }

        $this->db->table(self::TABLE)->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'deleted_by' => Access::username(),
        ]);

        AuditLog::user(VlanModule::KEY, self::TYPE, self::key($before['vlan'], $id), AuditLog::DELETE,
            self::auditView($before), null, $comment);
    }

    /**
     * Oznacza wpisy, których numer właśnie pojawił się w LibreNMS. Wołane z sync().
     *
     * @param  callable(int):bool  $inLibreNms
     * @return int liczba przejętych
     */
    public function adopt(callable $inLibreNms, \DateTimeImmutable $now): int
    {
        $ts = $now->format('Y-m-d H:i:s');
        $rows = [];

        foreach ($this->db->table(self::TABLE)->whereNull('deleted_at')->whereNull('adopted_at')->get() as $r) {
            if (! $inLibreNms((int) $r->vlan)) {
                continue;
            }
            $this->db->table(self::TABLE)->where('id', $r->id)->update(['adopted_at' => $ts]);
            $rows[] = AuditLog::row(VlanModule::KEY, self::TYPE, self::key((int) $r->vlan, (int) $r->id), AuditLog::ADOPTED,
                AuditLog::SOURCE_SYNC, ['adopted_at' => null], ['adopted_at' => $ts],
                'numer wpisany ręcznie ' . $r->created_at . ($r->created_by ? ' przez ' . $r->created_by : '')
                . ' pojawił się w LibreNMS; dane LibreNMS mają pierwszeństwo', null, null, null, $now);
        }

        AuditLog::insertMany($rows);

        return count($rows);
    }
}
