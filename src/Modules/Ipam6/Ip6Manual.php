<?php

/*
 * Ip6Manual.php - wpisy ręczne IPv6 (na razie prefiksy: agregat, sieć używana,
 * rezerwacja) w przestrzeniach adresowych wspólnych z IPv4 (IpamSpaces).
 *
 * Początek i koniec prefiksu są zapisane jako 32 znaki szesnastkowe (Ip6Calc::hex):
 * porównanie tekstów daje kolejność adresów, a kopia zapasowa (JSON) zostaje czytelna.
 *
 * Blokady jak w IPv4: sieci z LibreNMS nie da się dodać jako "używanej" (można
 * jako agregat), a rezerwacji - gdy LibreNMS ma w prefiksie adresy.
 * Każda zmiana to jeden wpis w dzienniku (moduł ipam6, klucz "n:<cidr>:m<id>").
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Ipam6;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Ispnetella\NetellaReg\Core\Access;
use Ispnetella\NetellaReg\Core\ApiException;
use Ispnetella\NetellaReg\Core\AuditLog;
use Ispnetella\NetellaReg\Core\Expiry;
use Ispnetella\NetellaReg\Modules\Ipam\IpamManual;
use Ispnetella\NetellaReg\Modules\Ipam\IpamSpaces;

final class Ip6Manual
{
    public const TABLE = 'reg_ipam6_manual';

    public const TYPE = 'manual';

    public const PREFIX_ROLES = [
        'aggregate' => 'agregat',
        'used' => 'sieć używana',
        'reserved' => 'rezerwacja',
    ];

    private const DATA_FIELDS = ['role', 'name', 'description', 'owner', 'vlan', 'devices', Expiry::FIELD];

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
            $t->unsignedInteger('space_id')->default(IpamSpaces::GLOBAL_ID);
            $t->string('kind', 8)->default('prefix');
            $t->string('cidr', 64)->comment('postać kanoniczna (inet_ntop)');
            $t->char('start_hex', 32);
            $t->char('end_hex', 32);
            $t->unsignedTinyInteger('len');
            $t->string('role', 16);
            $t->string('name', 64)->nullable();
            $t->text('description');
            $t->string('owner', 128)->nullable();
            $t->unsignedSmallInteger('vlan')->nullable();
            $t->text('devices')->nullable();
            $t->date(Expiry::FIELD)->nullable();
            $t->dateTime('created_at');
            $t->string('created_by', 191)->nullable();
            $t->dateTime('updated_at');
            $t->string('updated_by', 191)->nullable();
            $t->dateTime('deleted_at')->nullable();
            $t->string('deleted_by', 191)->nullable();
            $t->index(['space_id', 'deleted_at']);
            $t->index(['space_id', 'start_hex', 'end_hex']);
        });
    }

    public static function key(array $r): string
    {
        return "n:{$r['cidr']}:m{$r['id']}";
    }

    private static function present(array $r): array
    {
        foreach (['id', 'space_id', 'len'] as $f) {
            $r[$f] = (int) $r[$f];
        }
        $r['vlan'] = $r['vlan'] === null ? null : (int) $r['vlan'];
        $r['role_label'] = self::PREFIX_ROLES[$r['role']] ?? $r['role'];

        return Expiry::present($r, Expiry::today());
    }

    /** @return list<array> */
    public function active(int $space): array
    {
        return $this->db->table(self::TABLE)->where('space_id', $space)->whereNull('deleted_at')
            ->orderBy('start_hex')->orderBy('len')->get()->map(fn ($r) => self::present((array) $r))->all();
    }

    public function find(int $id): ?array
    {
        $r = $this->db->table(self::TABLE)->where('id', $id)->whereNull('deleted_at')->first();

        return $r === null ? null : self::present((array) $r);
    }

    private static function view(array $r): array
    {
        $out = ['prefix' => $r['cidr']];
        foreach (self::DATA_FIELDS as $f) {
            if (($r[$f] ?? null) !== null && $r[$f] !== '') {
                $out[$f] = $r[$f];
            }
        }

        return $out;
    }

    /**
     * @param  callable(string):bool  $knownNetwork  czy sieć jest w LibreNMS
     * @param  callable(\GMP,\GMP):int  $knownInside  ile adresów LibreNMS leży w przedziale
     */
    public function create(int $space, string $text, array $input, ?string $comment, callable $knownNetwork, callable $knownInside): array
    {
        try {
            $p = Ip6Calc::parse($text, true);
        } catch (\InvalidArgumentException $e) {
            throw new ApiException($e->getMessage());
        }
        if ($p['len'] === 128) {
            throw new ApiException('Na razie dodaje się tylko prefiksy IPv6 (krótsze niż /128).');
        }
        $fields = IpamManual::validate($input);
        $fields['description'] ??= '';
        $fields['role'] ??= 'aggregate';
        if (! isset(self::PREFIX_ROLES[$fields['role']])) {
            throw new ApiException('Dozwolone role: ' . implode(', ', array_keys(self::PREFIX_ROLES)) . '.');
        }
        if (($fields[Expiry::FIELD] ?? null) !== null && $fields[Expiry::FIELD] < Expiry::today()) {
            throw new ApiException('Termin ważności nie może być w przeszłości.');
        }
        if ($this->db->table(self::TABLE)->where('space_id', $space)->whereNull('deleted_at')->where('cidr', $p['cidr'])->exists()) {
            throw new ApiException("Prefiks {$p['cidr']} ma już wpis ręczny w tej przestrzeni (edytuj go).");
        }
        $this->checkRole($fields['role'], $p, $knownNetwork, $knownInside);

        $now = date('Y-m-d H:i:s');
        $user = Access::username();
        $row = ['space_id' => $space, 'kind' => 'prefix', 'cidr' => $p['cidr'], 'start_hex' => $p['start_hex'], 'end_hex' => $p['end_hex'],
            'len' => $p['len'], 'created_at' => $now, 'created_by' => $user, 'updated_at' => $now, 'updated_by' => $user]
            + array_intersect_key($fields, array_flip(self::DATA_FIELDS));
        $id = 0;
        $this->db->transaction(function () use ($row, $comment, $space, &$id) {
            $id = (int) $this->db->table(self::TABLE)->insertGetId($row);
            AuditLog::user(Ipam6Module::KEY, self::TYPE, self::key($row + ['id' => $id]), AuditLog::CREATE, null,
                self::view($row) + ['space' => $space], $comment);
        });

        return ['entry' => $this->find($id), 'label' => $p['cidr']];
    }

    public function update(int $id, array $input, ?string $comment, callable $knownNetwork, callable $knownInside): array
    {
        $before = $this->find($id);
        if ($before === null) {
            throw new ApiException('Nie ma takiego wpisu (mógł zostać usunięty).', 404);
        }
        $fields = IpamManual::validate($input, false);
        if (array_key_exists('name', $fields) && $fields['name'] === null) {
            throw new ApiException('Nazwa jest wymagana.');
        }
        if (isset($fields['role'])) {
            if (! isset(self::PREFIX_ROLES[$fields['role']])) {
                throw new ApiException('Dozwolone role: ' . implode(', ', array_keys(self::PREFIX_ROLES)) . '.');
            }
            if ($fields['role'] !== $before['role']) {
                $this->checkRole($fields['role'], Ip6Calc::parse($before['cidr']), $knownNetwork, $knownInside);
            }
        }
        $vu = $fields[Expiry::FIELD] ?? null;
        if ($vu !== null && $vu !== $before[Expiry::FIELD] && $vu < Expiry::today()) {
            throw new ApiException('Termin ważności nie może być w przeszłości.');
        }
        $changed = [];
        foreach (array_intersect_key($fields, array_flip(self::DATA_FIELDS)) as $f => $v) {
            if (($before[$f] ?? null) != $v) {
                $changed[$f] = $v;
            }
        }
        if ($changed === []) {
            return $before;
        }
        $this->db->transaction(function () use ($id, $changed, $before, $comment) {
            $this->db->table(self::TABLE)->where('id', $id)->update($changed + ['updated_at' => date('Y-m-d H:i:s'), 'updated_by' => Access::username()]);
            AuditLog::user(Ipam6Module::KEY, self::TYPE, self::key($before), AuditLog::UPDATE,
                array_intersect_key(self::view($before), $changed) + ['prefix' => $before['cidr']], ['prefix' => $before['cidr']] + $changed, $comment);
        });

        return $this->find($id);
    }

    public function delete(int $id, ?string $comment): string
    {
        $before = $this->find($id);
        if ($before === null) {
            throw new ApiException('Nie ma takiego wpisu (mógł zostać usunięty).', 404);
        }
        $this->db->transaction(function () use ($id, $before, $comment) {
            $this->db->table(self::TABLE)->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s'), 'deleted_by' => Access::username()]);
            AuditLog::user(Ipam6Module::KEY, self::TYPE, self::key($before), AuditLog::DELETE, self::view($before), null, $comment);
        });

        return $before['cidr'];
    }

    private function checkRole(string $role, array $p, callable $knownNetwork, callable $knownInside): void
    {
        if ($role === 'used' && $knownNetwork($p['cidr'])) {
            throw new ApiException("Sieci {$p['cidr']} nie można dodać ręcznie jako używanej, bo jest już w LibreNMS. "
                . 'Jeśli chcesz ją opisać albo zgrupować, dodaj ją jako agregat.', 409);
        }
        if ($role === 'reserved' && ($n = $knownInside($p['start'], $p['end'])) > 0) {
            throw new ApiException("Nie można zarezerwować {$p['cidr']}, bo LibreNMS ma w nim adresy ({$n}).", 409);
        }
    }
}
