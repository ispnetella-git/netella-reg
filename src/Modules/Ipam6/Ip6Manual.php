<?php

/*
 * Ip6Manual.php - wpisy ręczne IPv6 w przestrzeniach adresowych wspólnych z IPv4
 * (IpamSpaces). Rodzaje:
 *   prefix   agregat, pula (np. delegowane prefiksy dla klientów), sieć używana, rezerwacja
 *   address  pojedynczy adres: używany albo rezerwacja
 *   range    zakres a-b (np. pula DHCPv6): pula DHCPv6, adresy statyczne, rezerwacja
 *
 * Początek i koniec są zapisane jako 32 znaki szesnastkowe (Ip6Calc::hex): porównanie
 * tekstów daje kolejność adresów, a kopia zapasowa (JSON) zostaje czytelna.
 *
 * Blokady jak w IPv4:
 *   - adresu, który LibreNMS widzi, nie da się dodać; sieci z LibreNMS nie da się
 *     dodać jako "używanej" (można jako agregat); rezerwacji (prefiksu, zakresu)
 *     nie da się dodać, gdy LibreNMS ma w niej adresy;
 *   - zakres musi leżeć w całości w jednym prawdziwym prefiksie (sieć z LibreNMS,
 *     prefiks ręczny, adresacja wewnętrzna), nie może przecinać innego prefiksu
 *     ani nachodzić na inny zakres; prefiks nie może przeciąć zakresu.
 * Każda zmiana to jeden wpis w dzienniku (moduł ipam6).
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
use Ispnetella\NetellaReg\Core\Lang;
use Ispnetella\NetellaReg\Modules\Ipam\IpamManual;
use Ispnetella\NetellaReg\Modules\Ipam\IpamSpaces;

final class Ip6Manual
{
    public const TABLE = 'reg_ipam6_manual';

    public const TYPE = 'manual';

    public const PREFIX_ROLES = [
        'aggregate' => 'agregat',
        'pool' => 'pula (np. prefiksy dla klientów)',
        'used' => 'sieć używana',
        'reserved' => 'rezerwacja',
    ];

    public const ADDRESS_ROLES = [
        'used' => 'używany',
        'reserved' => 'rezerwacja',
    ];

    public const RANGE_ROLES = [
        'dhcp' => 'pula DHCPv6',
        'static' => 'adresy statyczne',
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
            $t->string('cidr', 100)->comment('prefiks, adres albo zakres a-b w postaci kanonicznej');
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

    /** Schemat 2: dłuższe pole cidr na zakresy "a-b" (dwa pełne adresy IPv6). */
    public static function migrateWiderCidr(Connection $db): void
    {
        $db->statement('ALTER TABLE `' . self::TABLE . '` MODIFY `cidr` VARCHAR(100) NOT NULL');
    }

    public static function rolesFor(string $kind): array
    {
        return match ($kind) {
            'prefix' => self::PREFIX_ROLES,
            'range' => self::RANGE_ROLES,
            default => self::ADDRESS_ROLES,
        };
    }

    public static function key(array $r): string
    {
        return match ($r['kind']) {
            'address' => "a:{$r['cidr']}:m{$r['id']}",
            'range' => "r:{$r['cidr']}:m{$r['id']}",
            default => "n:{$r['cidr']}:m{$r['id']}",
        };
    }

    private static function present(array $r): array
    {
        foreach (['id', 'space_id', 'len'] as $f) {
            $r[$f] = (int) $r[$f];
        }
        $r['vlan'] = $r['vlan'] === null ? null : (int) $r['vlan'];
        $roles = self::rolesFor($r['kind']);
        $r['role_label'] = isset($roles[$r['role']]) ? Lang::t($roles[$r['role']]) : $r['role'];
        if ($r['kind'] === 'range') {
            [$r['first'], $r['last']] = explode('-', $r['cidr'], 2);
            $r['size'] = gmp_strval(gmp_add(gmp_sub(Ip6Calc::fromHex($r['end_hex']), Ip6Calc::fromHex($r['start_hex'])), 1));
        }

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
        $out = [match ($r['kind']) { 'address' => 'ip', 'range' => 'range', default => 'prefix' } => $r['cidr']];
        foreach (self::DATA_FIELDS as $f) {
            if (($r[$f] ?? null) !== null && $r[$f] !== '') {
                $out[$f] = $r[$f];
            }
        }

        return $out;
    }

    /**
     * Co wpisał użytkownik: zakres (z myślnikiem), prefiks (maska < 128) albo adres.
     *
     * @return array{kind:string,cidr:string,start:\GMP,end:\GMP,len:int,start_hex:string,end_hex:string}
     */
    public static function parseTarget(string $text): array
    {
        $text = trim($text);
        try {
            if (preg_match('/[-–]/u', $text)) {
                [$a, $b] = Ip6Calc::parseRange($text);

                return ['kind' => 'range', 'cidr' => Ip6Calc::rangeLabel($a, $b), 'start' => $a, 'end' => $b, 'len' => 128,
                    'start_hex' => Ip6Calc::hex($a), 'end_hex' => Ip6Calc::hex($b)];
            }
            $p = Ip6Calc::parse($text, true);
        } catch (\InvalidArgumentException $e) {
            throw new ApiException($e->getMessage());
        }
        if ($p['len'] === 128) {
            return ['kind' => 'address', 'cidr' => Ip6Calc::gmp2ip($p['start'])] + $p;
        }

        return ['kind' => 'prefix'] + $p;
    }

    /**
     * @param  Ip6Context  $ctx  stan LibreNMS i prefiksów (do blokad)
     */
    public function create(int $space, string $text, array $input, ?string $comment, Ip6Context $ctx): array
    {
        $p = self::parseTarget($text);
        $fields = IpamManual::validate($input);
        $fields['description'] ??= '';
        $roles = self::rolesFor($p['kind']);
        $fields['role'] ??= array_key_first($roles);
        if (! isset($roles[$fields['role']])) {
            throw new ApiException(Lang::t('Dozwolone role: {roles}.', ['roles' => implode(', ', array_keys($roles))]));
        }
        if (($fields[Expiry::FIELD] ?? null) !== null && $fields[Expiry::FIELD] < Expiry::today()) {
            throw new ApiException(Lang::t('Termin ważności nie może być w przeszłości.'));
        }
        if ($this->db->table(self::TABLE)->where('space_id', $space)->whereNull('deleted_at')->where('kind', $p['kind'])
            ->where('cidr', $p['cidr'])->exists()) {
            throw new ApiException(Lang::t('{cidr} ma już wpis ręczny w tej przestrzeni (edytuj go).', ['cidr' => $p['cidr']]));
        }
        $this->checkEntry($space, $p, $fields['role'], $ctx, null);

        $now = date('Y-m-d H:i:s');
        $user = Access::username();
        $row = ['space_id' => $space, 'kind' => $p['kind'], 'cidr' => $p['cidr'], 'start_hex' => $p['start_hex'], 'end_hex' => $p['end_hex'],
            'len' => $p['len'], 'created_at' => $now, 'created_by' => $user, 'updated_at' => $now, 'updated_by' => $user]
            + array_intersect_key($fields, array_flip(self::DATA_FIELDS));
        $id = 0;
        $this->db->transaction(function () use ($row, $comment, $space, &$id) {
            $id = (int) $this->db->table(self::TABLE)->insertGetId($row);
            AuditLog::user(Ipam6Module::KEY, self::TYPE, self::key($row + ['id' => $id]), AuditLog::CREATE, null,
                self::view($row) + ['space' => $space], $comment);
        });

        return ['entry' => $this->find($id), 'label' => $p['cidr'], 'kind' => $p['kind']];
    }

    public function update(int $id, array $input, ?string $comment, Ip6Context $ctx): array
    {
        $before = $this->find($id) ?? throw new ApiException(Lang::t('Nie ma takiego wpisu (mógł zostać usunięty).'), 404);
        $fields = IpamManual::validate($input, false);
        if (array_key_exists('name', $fields) && $fields['name'] === null) {
            throw new ApiException(Lang::t('Nazwa jest wymagana.'));
        }
        if (isset($fields['role'])) {
            $roles = self::rolesFor($before['kind']);
            if (! isset($roles[$fields['role']])) {
                throw new ApiException(Lang::t('Dozwolone role: {roles}.', ['roles' => implode(', ', array_keys($roles))]));
            }
            if ($fields['role'] !== $before['role']) {
                $this->checkRole(self::parseTarget($before['cidr']), $fields['role'], $ctx);
            }
        }
        $vu = $fields[Expiry::FIELD] ?? null;
        if ($vu !== null && $vu !== $before[Expiry::FIELD] && $vu < Expiry::today()) {
            throw new ApiException(Lang::t('Termin ważności nie może być w przeszłości.'));
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
        $label = self::view($before);
        $label = array_slice($label, 0, 1, true);
        $this->db->transaction(function () use ($id, $changed, $before, $comment, $label) {
            $this->db->table(self::TABLE)->where('id', $id)->update($changed + ['updated_at' => date('Y-m-d H:i:s'), 'updated_by' => Access::username()]);
            AuditLog::user(Ipam6Module::KEY, self::TYPE, self::key($before), AuditLog::UPDATE,
                array_intersect_key(self::view($before), $changed) + $label, $label + $changed, $comment);
        });

        return $this->find($id);
    }

    public function delete(int $id, ?string $comment): string
    {
        $before = $this->find($id) ?? throw new ApiException(Lang::t('Nie ma takiego wpisu (mógł zostać usunięty).'), 404);
        $this->db->transaction(function () use ($id, $before, $comment) {
            $this->db->table(self::TABLE)->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s'), 'deleted_by' => Access::username()]);
            AuditLog::user(Ipam6Module::KEY, self::TYPE, self::key($before), AuditLog::DELETE, self::view($before), null, $comment);
        });

        return $before['cidr'];
    }

    /** Blokady przy dodawaniu (rola + położenie). */
    private function checkEntry(int $space, array $p, string $role, Ip6Context $ctx, ?int $exceptId): void
    {
        $this->checkRole($p, $role, $ctx);
        $ranges = $this->db->table(self::TABLE)->where('space_id', $space)->whereNull('deleted_at')->where('kind', 'range')
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->where('start_hex', '<=', $p['end_hex'])->where('end_hex', '>=', $p['start_hex'])->get();

        if ($p['kind'] === 'address') {
            if ($ctx->knownAddress($p['start_hex'])) {
                throw new ApiException($ctx->staleAddress($p['start_hex'])
                    ? Lang::t('Adresu {cidr} nie można dodać ręcznie, bo jest już w LibreNMS (na wyłączonym hoście - włącz „Pokaż adresy z wyłączonych hostów”, żeby go zobaczyć).', ['cidr' => $p['cidr']])
                    : Lang::t('Adresu {cidr} nie można dodać ręcznie, bo jest już w LibreNMS.', ['cidr' => $p['cidr']]), 409);
            }
        } elseif ($p['kind'] === 'prefix') {
            // Prefiks może objąć cały zakres, ale nie może go przeciąć.
            foreach ($ranges as $r) {
                if (strcmp($r->start_hex, $p['start_hex']) < 0 || strcmp($r->end_hex, $p['end_hex']) > 0) {
                    throw new ApiException(Lang::t('Prefiks {cidr} przecina zakres {range}. Zakres musi leżeć w całości w jednym prefiksie.', ['cidr' => $p['cidr'], 'range' => $r->cidr]), 409);
                }
            }
        } else {
            if ($ranges->isNotEmpty()) {
                throw new ApiException(Lang::t('Zakres {cidr} nachodzi na zakres {other}.', ['cidr' => $p['cidr'], 'other' => $ranges->first()->cidr]), 409);
            }
            $problem = $ctx->rangeContainer($p['start'], $p['end'])[1];
            if ($problem !== null) {
                throw new ApiException($problem, 409);
            }
        }
    }

    private function checkRole(array $p, string $role, Ip6Context $ctx): void
    {
        if ($p['kind'] === 'prefix' && $role === 'used' && $ctx->knownNetwork($p['cidr'])) {
            throw new ApiException(Lang::t('Sieci {cidr} nie można dodać ręcznie jako używanej, bo jest już w LibreNMS. Jeśli chcesz ją opisać albo zgrupować, dodaj ją jako agregat.', ['cidr' => $p['cidr']]), 409);
        }
        if ($p['kind'] !== 'address' && $role === 'reserved' && ($n = $ctx->knownInside($p['start'], $p['end'])) > 0) {
            throw new ApiException(Lang::t('Nie można zarezerwować {cidr}, bo LibreNMS ma w nim adresy ({n}).', ['cidr' => $p['cidr'], 'n' => $n]), 409);
        }
    }
}
