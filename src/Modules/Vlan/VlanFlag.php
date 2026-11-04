<?php

/*
 * VlanFlag.php - oznaczenia numerów widocznych w LibreNMS (tabela reg_vlan_flag).
 *
 * Numer z LibreNMS nie może mieć wpisu ręcznego, a QinQ (zewnętrzny tag,
 * S-VLAN) trzeba oznaczyć ręcznie, bo LibreNMS tego nie rozróżnia. Dlatego
 * osobna, lekka tabela: jeden wiersz na oznaczony numer. Oznaczenie nie
 * zajmuje numeru i nie zmienia jego statusu.
 *
 * Oznaczenie trzyma się numeru, dopóki LibreNMS go widzi albo trwa karencja
 * po zniknięciu. Gdy numer jest wolny (minęła karencja) albo dostał wpis
 * ręczny, sync() zdejmuje oznaczenie i zapisuje to w dzienniku.
 *
 * Numery z wpisem ręcznym mają własne pole qinq (VlanManual).
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Vlan;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Ispnetella\NetellaReg\Core\Access;
use Ispnetella\NetellaReg\Core\ApiException;
use Ispnetella\NetellaReg\Core\AuditLog;
use Ispnetella\NetellaReg\Core\Lang;

final class VlanFlag
{
    public const TABLE = 'reg_vlan_flag';

    public const TYPE = 'flag';

    public function __construct(private readonly Connection $db)
    {
    }

    public static function migrate(Connection $db, int $from): void
    {
        $schema = $db->getSchemaBuilder();

        if ($from < 5 && ! $schema->hasTable(self::TABLE)) {
            $schema->create(self::TABLE, function (Blueprint $t) {
                $t->unsignedSmallInteger('vlan')->primary();
                $t->boolean('qinq')->default(false)->comment('zewnętrzny tag QinQ (S-VLAN)');
                $t->dateTime('updated_at');
                $t->string('updated_by', 191)->nullable();
            });
        }
    }

    public static function key(int $vlan): string
    {
        return "v{$vlan}:flag";
    }

    /** @return array<int,array{qinq:bool,updated_at:string,updated_by:?string}> numer => oznaczenie */
    public function all(): array
    {
        $out = [];
        foreach ($this->db->table(self::TABLE)->orderBy('vlan')->get() as $r) {
            $out[(int) $r->vlan] = ['qinq' => ! empty($r->qinq), 'updated_at' => (string) $r->updated_at, 'updated_by' => $r->updated_by];
        }

        return $out;
    }

    /**
     * Ustawia albo zdejmuje oznaczenie QinQ numeru z LibreNMS. Zdjęcie jest
     * zawsze możliwe, ustawienie tylko dla numeru, który LibreNMS widzi
     * (także w karencji) i który nie ma wpisu ręcznego.
     */
    public function setQinq(int $vlan, bool $qinq, ?string $comment, VlanIndex $index): bool
    {
        if ($vlan < VlanName::MIN || $vlan > VlanName::MAX) {
            throw new ApiException(Lang::t('Numer VLAN poza zakresem 1-4094.'));
        }

        $before = (bool) $this->db->table(self::TABLE)->where('vlan', $vlan)->value('qinq');
        if ($before === $qinq) {
            return $qinq;
        }

        if ($qinq) {
            if ($index->manual($vlan) !== null) {
                throw new ApiException(Lang::t('Ten numer ma wpis ręczny. Zaznacz QinQ w edycji wpisu.'), 409);
            }
            if (! $index->flaggable($vlan)) {
                throw new ApiException(Lang::t('LibreNMS nie widzi tego numeru. Dodaj wpis ręczny i zaznacz w nim QinQ.'), 409);
            }
        }

        $this->db->transaction(function () use ($vlan, $qinq, $before, $comment) {
            if ($qinq) {
                $this->db->table(self::TABLE)->updateOrInsert(['vlan' => $vlan],
                    ['qinq' => true, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => Access::username()]);
            } else {
                $this->db->table(self::TABLE)->where('vlan', $vlan)->delete();
            }
            AuditLog::user(VlanModule::KEY, self::TYPE, self::key($vlan), AuditLog::UPDATE,
                ['vlans' => (string) $vlan, 'qinq' => $before], ['vlans' => (string) $vlan, 'qinq' => $qinq], $comment, [$vlan, $vlan]);
        });

        return $qinq;
    }

    /**
     * Zdejmuje oznaczenia numerów, które przestały być w LibreNMS (minęła
     * karencja) albo dostały wpis ręczny. Wołane z sync().
     *
     * @param  list<int>  $vlans  VlanIndex::staleFlags()
     * @return int liczba zdjętych oznaczeń
     */
    public function prune(array $vlans, \DateTimeImmutable $now): int
    {
        if ($vlans === []) {
            return 0;
        }

        $audit = [];
        foreach ($vlans as $v) {
            $audit[] = AuditLog::row(VlanModule::KEY, self::TYPE, self::key($v), AuditLog::UPDATE, AuditLog::SOURCE_SYNC,
                ['vlans' => (string) $v, 'qinq' => true], ['vlans' => (string) $v, 'qinq' => false],
                'numer nie jest już w LibreNMS (minęła karencja) albo ma wpis ręczny; oznaczenie QinQ zdjęte',
                null, null, null, $now, [$v, $v]);
        }

        $this->db->transaction(function () use ($vlans, $audit) {
            $this->db->table(self::TABLE)->whereIn('vlan', $vlans)->delete();
            AuditLog::insertMany($audit);
        });

        return count($vlans);
    }
}
