<?php

/*
 * IpamSpaces.php - przestrzenie adresowe (tabela reg_ipam_space).
 *
 * Przestrzeń rozdziela nakładające się sieci prywatne (VRF, klient BSA).
 * "Global" (id 1) istnieje zawsze. Adres z LibreNMS trafia do przestrzeni,
 * której pole vrf równa się jego context_name, a w pozostałych przypadkach
 * do Global. Na Spidernecie context_name jest puste we wszystkich adresach.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Ipam;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Ispnetella\NetellaReg\Core\Access;
use Ispnetella\NetellaReg\Core\ApiException;
use Ispnetella\NetellaReg\Core\AuditLog;
use Ispnetella\NetellaReg\Core\Lang;

final class IpamSpaces
{
    public const TABLE = 'reg_ipam_space';

    public const GLOBAL_ID = 1;

    public function __construct(private readonly Connection $db)
    {
    }

    public static function migrate(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();
        if (! $schema->hasTable(self::TABLE)) {
            $schema->create(self::TABLE, function (Blueprint $t) {
                $t->increments('id');
                $t->string('name', 64)->unique();
                $t->text('description')->nullable();
                $t->string('vrf', 128)->nullable()->comment('context_name z LibreNMS przypisywany do tej przestrzeni');
                $t->dateTime('created_at');
                $t->string('created_by', 191)->nullable();
            });
        }
        if (! $db->table(self::TABLE)->where('id', self::GLOBAL_ID)->exists()) {
            $db->table(self::TABLE)->insert([
                'id' => self::GLOBAL_ID, 'name' => 'Global', 'description' => 'Domyślna przestrzeń adresowa',
                'vrf' => null, 'created_at' => date('Y-m-d H:i:s'), 'created_by' => null,
            ]);
        }
    }

    /** @return array<int,array> id => przestrzeń */
    public function all(): array
    {
        $out = [];
        foreach ($this->db->table(self::TABLE)->orderBy('id')->get() as $r) {
            $out[(int) $r->id] = ['id' => (int) $r->id, 'name' => $r->name, 'description' => $r->description, 'vrf' => $r->vrf];
        }

        return $out;
    }

    /** Przestrzeń dla context_name z LibreNMS. */
    public static function resolve(array $spaces, ?string $context): int
    {
        $context = trim((string) $context);
        if ($context !== '') {
            foreach ($spaces as $s) {
                if ($s['vrf'] !== null && strcasecmp($s['vrf'], $context) === 0) {
                    return $s['id'];
                }
            }
        }

        return self::GLOBAL_ID;
    }

    public function create(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 64) {
            throw new ApiException(Lang::t('Nazwa przestrzeni jest wymagana (do 64 znaków).'));
        }
        if ($this->db->table(self::TABLE)->where('name', $name)->exists()) {
            throw new ApiException(Lang::t('Przestrzeń „{name}” już istnieje.', ['name' => $name]));
        }
        $row = [
            'name' => $name,
            'description' => trim((string) ($input['description'] ?? '')) ?: null,
            'vrf' => trim((string) ($input['vrf'] ?? '')) ?: null,
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => Access::username(),
        ];
        $id = (int) $this->db->table(self::TABLE)->insertGetId($row);
        AuditLog::user(IpamModule::KEY, 'space', "s{$id}", AuditLog::CREATE, null,
            array_intersect_key($row, array_flip(['name', 'description', 'vrf'])));

        return ['id' => $id] + $row;
    }
}
