<?php

/*
 * IpamManual.php - ręczne wpisy adresacji (tabela reg_ipam_manual).
 *
 * Rodzaje:
 *   address  pojedynczy adres; zakres albo lista adresów dodana naraz to grupa
 *            (batch_id), tak jak w module VLAN
 *   prefix   prefiks z rolą:
 *              aggregate  agregat - grupuje sieci w drzewie (np. przydział od RIPE)
 *              pool       pula - np. PPPoE, CGNAT; hosty liczone jak w sieci
 *              reserved   rezerwacja - blok trzymany wolny
 *              used       sieć używana, której LibreNMS nie widzi
 *
 * Blokady (LibreNMS jest źródłem prawdy):
 *   - adresu, który LibreNMS widzi (urządzenie aktywne albo wyłączone), nie da
 *     się dodać ręcznie;
 *   - sieci "używanej" identycznej z siecią interfejsu w LibreNMS - też nie;
 *   - rezerwacji prefiksu, w którym LibreNMS ma adresy - też nie.
 *   Agregaty i pule mogą obejmować sieci z LibreNMS - po to są.
 *
 * Przejęcie: adres albo sieć "używana" wpisane ręcznie, które pojawią się
 * w LibreNMS, dostają adopted_at i zdarzenie "adopted" (jedno na grupę).
 *
 * Jedna akcja użytkownika = jeden wpis w dzienniku (range_from/range_to =
 * zakres adresów jako liczby, lista adresów w polu "ips").
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Ipam;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Ispnetella\NetellaReg\Core\Access;
use Ispnetella\NetellaReg\Core\ApiException;
use Ispnetella\NetellaReg\Core\AuditLog;

final class IpamManual
{
    public const TABLE = 'reg_ipam_manual';

    public const TYPE = 'manual';

    public const PREFIX_ROLES = [
        'aggregate' => 'agregat',
        'pool' => 'pula',
        'reserved' => 'rezerwacja',
        'used' => 'sieć używana',
    ];

    public const ADDRESS_ROLES = [
        'used' => 'używany',
        'reserved' => 'rezerwacja',
    ];

    private const FIELDS = ['name' => 64, 'description' => 1000, 'owner' => 128, 'devices' => 1000];

    private const DATA_FIELDS = ['role', 'name', 'description', 'owner', 'vlan', 'devices'];

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
            $t->unsignedBigInteger('batch_id')->nullable()->comment('grupa dodana jednym kliknięciem');
            $t->unsignedInteger('space_id')->default(IpamSpaces::GLOBAL_ID);
            $t->unsignedTinyInteger('family')->default(4);
            $t->string('kind', 8)->comment('address albo prefix');
            $t->string('cidr', 64)->comment('postać kanoniczna, adres jako /32');
            $t->unsignedInteger('start')->nullable()->comment('IPv4 jako liczba');
            $t->unsignedInteger('end')->nullable();
            $t->unsignedTinyInteger('len');
            $t->string('role', 16);
            $t->string('name', 64)->nullable();
            $t->text('description');
            $t->string('owner', 128)->nullable();
            $t->unsignedSmallInteger('vlan')->nullable();
            $t->text('devices')->nullable();
            $t->dateTime('created_at');
            $t->string('created_by', 191)->nullable();
            $t->dateTime('updated_at');
            $t->string('updated_by', 191)->nullable();
            $t->dateTime('adopted_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
            $t->string('deleted_by', 191)->nullable();
            $t->index(['space_id', 'deleted_at']);
            $t->index(['space_id', 'start', 'end']);
            $t->index('batch_id');
        });
    }

    public static function key(array $r): string
    {
        return $r['kind'] === 'prefix' ? "n:{$r['cidr']}:m{$r['id']}" : 'a:' . IpCalc::int2ip((int) $r['start']) . ":m{$r['id']}";
    }

    public static function batchKey(int $batch): string
    {
        return "abatch:{$batch}";
    }

    private static function present(array $r): array
    {
        foreach (['id', 'space_id', 'family', 'len'] as $f) {
            $r[$f] = (int) $r[$f];
        }
        $r['batch_id'] = $r['batch_id'] === null ? null : (int) $r['batch_id'];
        $r['start'] = $r['start'] === null ? null : (int) $r['start'];
        $r['end'] = $r['end'] === null ? null : (int) $r['end'];
        $r['vlan'] = $r['vlan'] === null ? null : (int) $r['vlan'];
        $r['ip'] = $r['kind'] === 'address' && $r['start'] !== null ? IpCalc::int2ip($r['start']) : null;
        $r['role_label'] = ($r['kind'] === 'prefix' ? self::PREFIX_ROLES : self::ADDRESS_ROLES)[$r['role']] ?? $r['role'];

        return $r;
    }

    /** @return list<array> aktywne wpisy przestrzeni */
    public function active(int $space): array
    {
        return $this->db->table(self::TABLE)->where('space_id', $space)->whereNull('deleted_at')
            ->orderBy('start')->orderBy('len')->get()->map(fn ($r) => self::present((array) $r))->all();
    }

    public function find(int $id): ?array
    {
        $r = $this->db->table(self::TABLE)->where('id', $id)->whereNull('deleted_at')->first();

        return $r === null ? null : self::present((array) $r);
    }

    /** @return list<array> */
    private function batchRows(int $batch): array
    {
        return $this->db->table(self::TABLE)->where('batch_id', $batch)->whereNull('deleted_at')->orderBy('start')
            ->get()->map(fn ($r) => self::present((array) $r))->all();
    }

    /**
     * Aktywne wpisy pogrupowane: jedna pozycja na grupę albo prefiks.
     *
     * @return list<array>
     */
    public function grouped(int $space): array
    {
        $groups = [];
        foreach ($this->active($space) as $r) {
            $g = $r['batch_id'] ?? $r['id'];
            $groups[$g] ??= $r + ['ips_list' => []];
            if ($r['kind'] === 'address') {
                $groups[$g]['ips_list'][] = $r['start'];
            }
        }

        return array_values(array_map(function (array $g) {
            $g['count'] = max(1, count($g['ips_list']));
            $g['label'] = $g['kind'] === 'prefix' ? $g['cidr'] : IpInput::compress($g['ips_list']);
            unset($g['ips_list']);

            return $g;
        }, $groups));
    }

    /** Pola z formularza po walidacji. */
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
        if (array_key_exists('vlan', $input)) {
            $v = trim((string) $input['vlan']);
            if ($v === '') {
                $out['vlan'] = null;
            } elseif (ctype_digit($v) && (int) $v >= 1 && (int) $v <= 4094) {
                $out['vlan'] = (int) $v;
            } else {
                throw new ApiException('VLAN musi być liczbą 1-4094.');
            }
        }
        if (array_key_exists('role', $input)) {
            $out['role'] = (string) $input['role'];
        }
        if ($requireDescription && ($out['description'] ?? null) === null) {
            throw new ApiException('Opis jest wymagany.');
        }

        return $out;
    }

    /** Stan grupy do dziennika. */
    private static function groupView(array $fields, string $label, int $count, string $kind): array
    {
        return [($kind === 'prefix' ? 'prefix' : 'ips') => $label, 'count' => $count]
            + array_intersect_key($fields, array_flip(self::DATA_FIELDS));
    }

    /**
     * Dodaje wpis(y). Prefiks to jeden wpis, adresy - grupa.
     *
     * @return array{label:string,count:int,kind:string,batch_id:int}
     */
    public function create(int $space, string $text, array $input, ?string $comment, IpamIndex $index): array
    {
        try {
            $parsed = IpInput::parse($text);
        } catch (\InvalidArgumentException $e) {
            throw new ApiException($e->getMessage());
        }

        $fields = self::validate($input);
        $kind = $parsed['kind'] === 'prefix' ? 'prefix' : 'address';
        $roles = $kind === 'prefix' ? self::PREFIX_ROLES : self::ADDRESS_ROLES;
        $fields['role'] ??= $kind === 'prefix' ? 'aggregate' : 'used';
        if (! isset($roles[$fields['role']])) {
            throw new ApiException('Dozwolone role: ' . implode(', ', array_keys($roles)) . '.');
        }

        $now = date('Y-m-d H:i:s');
        $user = Access::username();
        $rows = [];

        if ($kind === 'prefix') {
            $p = $parsed['prefix'];
            if ($this->db->table(self::TABLE)->where('space_id', $space)->whereNull('deleted_at')
                ->where('kind', 'prefix')->where('cidr', $p['cidr'])->exists()) {
                throw new ApiException("Prefiks {$p['cidr']} ma już wpis ręczny w tej przestrzeni (edytuj go).");
            }
            if ($fields['role'] === 'used' && $index->knownNetwork($p['cidr'])) {
                throw new ApiException("Sieci {$p['cidr']} nie można dodać ręcznie jako używanej, bo jest już w LibreNMS. "
                    . 'Jeśli chcesz ją opisać albo zgrupować, dodaj ją jako agregat.', 409);
            }
            if ($fields['role'] === 'reserved' && ($inside = $index->knownInside($p['start'], $p['end'])) !== []) {
                throw new ApiException("Nie można zarezerwować {$p['cidr']}, bo LibreNMS ma w nim adresy: "
                    . IpInput::compress(array_slice($inside, 0, 50)) . (count($inside) > 50 ? ' …' : '') . '.', 409);
            }
            $rows[] = ['kind' => 'prefix', 'cidr' => $p['cidr'], 'start' => $p['start'], 'end' => $p['end'], 'len' => $p['len']];
            $label = $p['cidr'];
            $range = [$p['start'], $p['end']];
        } else {
            $ips = $parsed['addresses'];
            $taken = $this->db->table(self::TABLE)->where('space_id', $space)->whereNull('deleted_at')
                ->where('kind', 'address')->whereIn('start', $ips)->pluck('start')->map(fn ($v) => (int) $v)->all();
            if ($taken !== []) {
                throw new ApiException('Te adresy mają już wpis ręczny (edytuj go): ' . IpInput::compress($taken) . '.');
            }
            $blocked = array_values(array_filter($ips, fn ($ip) => $index->knownAddress($ip)));
            if ($blocked !== []) {
                $free = array_values(array_diff($ips, $blocked));
                throw new ApiException('Tych adresów nie można dodać ręcznie, bo są już w LibreNMS: '
                    . IpInput::compress($blocked) . '.' . ($free !== [] ? ' Bez nich zostaje: ' . IpInput::compress($free) . '.' : ''), 409);
            }
            foreach ($ips as $ip) {
                $rows[] = ['kind' => 'address', 'cidr' => IpCalc::int2ip($ip) . '/32', 'start' => $ip, 'end' => $ip, 'len' => 32];
            }
            $label = IpInput::compress($ips);
            $range = [min($ips), max($ips)];
        }

        $batch = 0;
        $this->db->transaction(function () use ($rows, $fields, $space, $now, $user, $comment, $label, $range, $kind, &$batch) {
            foreach ($rows as $r) {
                $id = (int) $this->db->table(self::TABLE)->insertGetId($r + $fields + [
                    'batch_id' => $batch ?: null, 'space_id' => $space, 'family' => 4,
                    'created_at' => $now, 'created_by' => $user, 'updated_at' => $now, 'updated_by' => $user,
                ]);
                if ($batch === 0) {
                    $batch = $id;
                    $this->db->table(self::TABLE)->where('id', $id)->update(['batch_id' => $id]);
                }
            }
            $first = $rows[0] + ['id' => $batch];
            AuditLog::user(IpamModule::KEY, self::TYPE, count($rows) === 1 ? self::key($first) : self::batchKey($batch),
                AuditLog::CREATE, null, self::groupView($fields, $label, count($rows), $kind) + ['space' => $space], $comment, $range);
        });

        return ['label' => $label, 'count' => count($rows), 'kind' => $kind, 'batch_id' => $batch];
    }

    /** Edycja wpisu albo (przy $group) całej grupy. Jeden wpis w dzienniku. */
    public function update(int $id, array $input, ?string $comment, bool $group, IpamIndex $index): array
    {
        $before = $this->find($id);
        if ($before === null) {
            throw new ApiException('Nie ma takiego wpisu (mógł zostać usunięty).', 404);
        }
        $fields = self::validate($input, false);
        if (array_key_exists('description', $fields) && $fields['description'] === null) {
            throw new ApiException('Opis jest wymagany.');
        }
        if (isset($fields['role'])) {
            $roles = $before['kind'] === 'prefix' ? self::PREFIX_ROLES : self::ADDRESS_ROLES;
            if (! isset($roles[$fields['role']])) {
                throw new ApiException('Dozwolone role: ' . implode(', ', array_keys($roles)) . '.');
            }
            // Zmiana roli prefiksu podlega tym samym blokadom co dodanie.
            if ($before['kind'] === 'prefix' && $fields['role'] !== $before['role']) {
                if ($fields['role'] === 'used' && $index->knownNetwork($before['cidr'])) {
                    throw new ApiException("Sieć {$before['cidr']} jest w LibreNMS - nie może być ręczną siecią używaną.", 409);
                }
                if ($fields['role'] === 'reserved' && $index->knownInside($before['start'], $before['end']) !== []) {
                    throw new ApiException("W {$before['cidr']} są adresy z LibreNMS - nie można go zarezerwować.", 409);
                }
            }
        }

        $rows = $group && $before['batch_id'] ? $this->batchRows($before['batch_id']) : [$before];
        $changed = array_filter($fields, fn ($v, $k) => ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH);
        if ($changed === []) {
            return $before;
        }
        $batchSize = $before['batch_id'] ? count($this->batchRows($before['batch_id'])) : 1;
        $detach = ! $group && $batchSize > 1;

        $ids = array_map(fn ($r) => $r['id'], $rows);
        $ips = array_filter(array_map(fn ($r) => $r['kind'] === 'address' ? $r['start'] : null, $rows), fn ($v) => $v !== null);
        $label = $before['kind'] === 'prefix' ? $before['cidr'] : IpInput::compress($ips);
        $range = $before['kind'] === 'prefix' ? [$before['start'], $before['end']] : [min($ips), max($ips)];

        $this->db->transaction(function () use ($changed, $ids, $detach, $before, $rows, $label, $range, $comment) {
            $set = $changed + ['updated_at' => date('Y-m-d H:i:s'), 'updated_by' => Access::username()];
            if ($detach) {
                $set['batch_id'] = $before['id'];
                if ($before['batch_id'] === $before['id']) {
                    $rest = $this->db->table(self::TABLE)->where('batch_id', $before['id'])->where('id', '!=', $before['id'])
                        ->whereNull('deleted_at')->orderBy('id')->pluck('id')->all();
                    if ($rest !== []) {
                        $this->db->table(self::TABLE)->whereIn('id', $rest)->update(['batch_id' => min($rest)]);
                    }
                }
            }
            $this->db->table(self::TABLE)->whereIn('id', $ids)->update($set);

            $k = $before['kind'] === 'prefix' ? 'prefix' : 'ips';
            AuditLog::user(IpamModule::KEY, self::TYPE,
                count($rows) > 1 ? self::batchKey((int) $before['batch_id']) : self::key($before),
                AuditLog::UPDATE,
                [$k => $label] + array_intersect_key($before, $changed),
                [$k => $label] + $changed,
                trim(($comment ?? '') . ($detach ? ($comment ? '; ' : '') . 'odłączony od grupy' : '')) ?: null,
                $range);
        });

        return $this->find($id);
    }

    /** Usunięcie wpisu albo (przy $group) całej grupy. Jeden wpis w dzienniku. */
    public function delete(int $id, ?string $comment, bool $group): string
    {
        $before = $this->find($id);
        if ($before === null) {
            throw new ApiException('Nie ma takiego wpisu (mógł zostać już usunięty).', 404);
        }
        $rows = $group && $before['batch_id'] ? $this->batchRows($before['batch_id']) : [$before];
        $ips = array_filter(array_map(fn ($r) => $r['kind'] === 'address' ? $r['start'] : null, $rows), fn ($v) => $v !== null);
        $label = $before['kind'] === 'prefix' ? $before['cidr'] : IpInput::compress($ips);
        $range = $before['kind'] === 'prefix' ? [$before['start'], $before['end']] : [min($ips), max($ips)];

        $this->db->transaction(function () use ($rows, $before, $label, $range, $comment) {
            $this->db->table(self::TABLE)->whereIn('id', array_map(fn ($r) => $r['id'], $rows))->update([
                'deleted_at' => date('Y-m-d H:i:s'), 'deleted_by' => Access::username(),
            ]);
            AuditLog::user(IpamModule::KEY, self::TYPE,
                count($rows) > 1 ? self::batchKey((int) $before['batch_id']) : self::key($before),
                AuditLog::DELETE, self::groupView($before, $label, count($rows), $before['kind']), null, $comment, $range);
        });

        return $label;
    }

    /**
     * Wpisy, których adres albo sieć "używana" pojawiły się właśnie w LibreNMS.
     *
     * @param  array<int,IpamIndex>  $indexes  przestrzeń => indeks
     */
    public function adopt(array $indexes, \DateTimeImmutable $now): int
    {
        $ts = $now->format('Y-m-d H:i:s');
        $byGroup = [];
        foreach ($this->db->table(self::TABLE)->whereNull('deleted_at')->whereNull('adopted_at')->where('family', 4)->get() as $r) {
            $idx = $indexes[(int) $r->space_id] ?? null;
            if ($idx === null) {
                continue;
            }
            $hit = $r->kind === 'address'
                ? $idx->status((int) $r->start) === IpamIndex::LNMS
                : ($r->role === 'used' && $idx->activeNetwork($r->cidr));
            if ($hit) {
                $byGroup[(int) ($r->batch_id ?? $r->id)][] = self::present((array) $r);
            }
        }

        $audit = [];
        $count = 0;
        foreach ($byGroup as $batch => $rows) {
            $this->db->table(self::TABLE)->whereIn('id', array_map(fn ($r) => $r['id'], $rows))->update(['adopted_at' => $ts]);
            $count += count($rows);
            $first = $rows[0];
            $ips = array_filter(array_map(fn ($r) => $r['kind'] === 'address' ? $r['start'] : null, $rows), fn ($v) => $v !== null);
            $k = $first['kind'] === 'prefix' ? 'prefix' : 'ips';
            $label = $first['kind'] === 'prefix' ? $first['cidr'] : IpInput::compress($ips);
            $range = $first['kind'] === 'prefix' ? [$first['start'], $first['end']] : [min($ips), max($ips)];
            $audit[] = AuditLog::row(IpamModule::KEY, self::TYPE, count($rows) > 1 ? self::batchKey($batch) : self::key($first),
                AuditLog::ADOPTED, AuditLog::SOURCE_SYNC, [$k => $label, 'adopted_at' => null], [$k => $label, 'adopted_at' => $ts],
                'wpisany ręcznie ' . $first['created_at'] . ($first['created_by'] ? ' przez ' . $first['created_by'] : '')
                . ', pojawił się w LibreNMS; dane LibreNMS mają pierwszeństwo', null, null, null, $now, $range);
        }
        AuditLog::insertMany($audit);

        return $count;
    }
}
