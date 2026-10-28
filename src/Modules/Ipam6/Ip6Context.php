<?php

/*
 * Ip6Context.php - stan LibreNMS i prefiksów potrzebny do blokad wpisów ręcznych
 * IPv6: znane adresy i sieci, prawdziwe prefiksy (sieci z LibreNMS, prefiksy
 * ręczne, adresacja wewnętrzna). Zawsze z pełnych danych - bez filtrów widoku.
 *
 * Czyste, bez Laravela.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Ipam6;

final class Ip6Context
{
    /**
     * @param  array<string,array{ip:\GMP,state:string}>  $addresses  hex => adres z LibreNMS (state l albo s)
     * @param  list<string>  $networks  sieci z LibreNMS (cidr)
     * @param  array<string,array{start:\GMP,end:\GMP,len:int}>  $prefixes  cidr => prawdziwy prefiks
     */
    public function __construct(
        private readonly array $addresses,
        private readonly array $networks,
        private readonly array $prefixes,
    ) {
    }

    public function knownAddress(string $hex): bool
    {
        return isset($this->addresses[$hex]);
    }

    public function staleAddress(string $hex): bool
    {
        return ($this->addresses[$hex]['state'] ?? null) === 's';
    }

    public function knownNetwork(string $cidr): bool
    {
        return in_array($cidr, $this->networks, true);
    }

    /** Ile adresów LibreNMS leży w przedziale. */
    public function knownInside(\GMP $start, \GMP $end): int
    {
        return count(array_filter($this->addresses, fn ($a) => gmp_cmp($a['ip'], $start) >= 0 && gmp_cmp($a['ip'], $end) <= 0));
    }

    /**
     * Prefiks, w którym leży zakres, i ewentualny problem: zakres musi leżeć w całości
     * w jednym prawdziwym prefiksie i nie może przecinać ani obejmować innego.
     *
     * @return array{0:?string,1:?string}
     */
    public function rangeContainer(\GMP $start, \GMP $end): array
    {
        $label = Ip6Calc::rangeLabel($start, $end);
        $best = null;
        foreach ($this->prefixes as $cidr => $p) {
            if (gmp_cmp($p['end'], $start) < 0 || gmp_cmp($p['start'], $end) > 0) {
                continue;
            }
            if (Ip6Calc::within($p['start'], $p['end'], $start, $end)) {
                if ($best === null || $p['len'] > $best[1]) {
                    $best = [$cidr, $p['len']];
                }
            } else {
                return [null, "Zakres {$label} przecina albo obejmuje prefiks {$cidr}. Zakres musi leżeć w całości w jednym prefiksie."];
            }
        }
        if ($best === null) {
            return [null, "Zakres {$label} nie leży w żadnej sieci z LibreNMS ani w prefiksie ręcznym. Dodaj najpierw prefiks (np. agregat)."];
        }

        return [$best[0], null];
    }
}
