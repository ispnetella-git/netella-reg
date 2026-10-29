<?php

// Tłumaczenia: moduł Adresy IPv4 (src/Modules/Ipam). Klucz: tekst polski.
return [
    // Nazwa zakładki i dziennik synchronizacji
    'Adresy IPv4' => 'IPv4 addresses',
    '{n} adresów w LibreNMS (po pominięciu ignorowanych)' => '{n} addresses in LibreNMS (after skipping ignored ones)',

    // Etykiety stanów adresu (IpamIndex::LABELS)
    'LibreNMS' => 'LibreNMS',
    'host wyłączony w LibreNMS' => 'host disabled in LibreNMS',
    'wpis ręczny' => 'manual entry',
    'rezerwacja' => 'reservation',
    'zniknął z LibreNMS (karencja)' => 'gone from LibreNMS (grace period)',
    'wolny' => 'free',

    // Role (IpamManual::PREFIX_ROLES, ADDRESS_ROLES, RANGE_ROLES)
    'agregat' => 'aggregate',
    'pula' => 'pool',
    'sieć używana' => 'network in use',
    'używany' => 'used',
    'pula DHCP' => 'DHCP pool',
    'pula PPPoE' => 'PPPoE pool',
    'NAT' => 'NAT',
    'adresy statyczne' => 'static addresses',

    // Źródła RIPE (RipeLookup::SOURCES)
    'ogłaszany w BGP' => 'announced in BGP',
    'obiekt route w RIPE' => 'route object in RIPE',
    'przydział organizacji AS' => 'AS organisation allocation',

    // Etykiety węzłów drzewa i listy wpisów
    'adresacja prywatna (RFC 1918)' => 'private addressing (RFC 1918)',
    'adresacja wewnętrzna' => 'internal addressing',
    'poza znaną adresacją' => 'outside known addressing',
    'sieć jest w LibreNMS' => 'network is in LibreNMS',
    'konflikt z siecią (zobacz Uwagi)' => 'conflict with a network (see Notices)',

    // API
    'Wymagany POST.' => 'POST required.',
    'Nie ma takiej przestrzeni adresowej.' => 'No such address space.',
    'Nie ma takiego zakresu w tej przestrzeni (mógł zostać usunięty).' => 'No such range in this space (it may have been deleted).',
    'Nie ma węzła {cidr}.' => 'No node {cidr}.',
    'Wpisz adres, prefiks albo tekst.' => 'Enter an address, prefix or text.',
    'Maska musi być z zakresu /8-/32.' => 'Mask must be in the range /8-/32.',
    'Blok /{len} jest większy niż {cidr}.' => 'Block /{len} is larger than {cidr}.',
    'Zaznacz co najmniej jeden prefiks.' => 'Select at least one prefix.',
    'Najwięcej {n} prefiksów naraz.' => 'At most {n} prefixes at once.',
    'IPv6 nie jest jeszcze obsługiwane.' => 'IPv6 is not supported yet.',

    // Wpisy ręczne
    'Pole „{f}” może mieć najwyżej {max} znaków.' => 'Field "{f}" can have at most {max} characters.',
    'VLAN musi być liczbą 1-4094.' => 'VLAN must be a number 1-4094.',
    'Nazwa jest wymagana.' => 'Name is required.',
    'Zakres nachodzi na zakres {cidr} ({name}).' => 'The range overlaps range {cidr} ({name}).',
    'Zakres nachodzi na zakres {cidr}.' => 'The range overlaps range {cidr}.',
    'Nie można zarezerwować {cidr}, bo LibreNMS ma w nim adresy: {ips}.' => 'Cannot reserve {cidr} because LibreNMS has addresses in it: {ips}.',
    'Dozwolone role: {roles}.' => 'Allowed roles: {roles}.',
    'Prefiks {cidr} ma już wpis ręczny w tej przestrzeni (edytuj go).' => 'Prefix {cidr} already has a manual entry in this space (edit it).',
    'Sieci {cidr} nie można dodać ręcznie jako używanej, bo jest już w LibreNMS. Jeśli chcesz ją opisać albo zgrupować, dodaj ją jako agregat.'
        => 'Network {cidr} cannot be added manually as a network in use because it is already in LibreNMS. To describe or group it, add it as an aggregate.',
    'Prefiks {cidr} przecina zakres {range}. Zakres musi leżeć w całości w jednym prefiksie.'
        => 'Prefix {cidr} cuts through range {range}. A range must lie entirely within one prefix.',
    'Te adresy mają już wpis ręczny (edytuj go): {ips}.' => 'These addresses already have a manual entry (edit it): {ips}.',
    'Tych adresów nie można dodać ręcznie, bo są już w LibreNMS: {ips}.' => 'These addresses cannot be added manually because they are already in LibreNMS: {ips}.',
    'Na wyłączonych hostach: {ips} (włącz „Pokaż adresy z wyłączonych hostów”, żeby je zobaczyć).'
        => 'On disabled hosts: {ips} (turn on "Show addresses from disabled hosts" to see them).',
    'Bez nich zostaje: {ips}.' => 'Without them: {ips}.',
    'Nie ma takiego wpisu (mógł zostać usunięty).' => 'No such entry (it may have been deleted).',
    'Nie ma takiego wpisu (mógł zostać już usunięty).' => 'No such entry (it may have already been deleted).',
    'W {cidr} są adresy z LibreNMS - nie można go zarezerwować.' => '{cidr} contains addresses from LibreNMS - it cannot be reserved.',
    'Sieć {cidr} jest w LibreNMS - nie może być ręczną siecią używaną.' => 'Network {cidr} is in LibreNMS - it cannot be a manual network in use.',
    'Na zakres można zamienić tylko grupę adresów.' => 'Only a group of addresses can be converted to a range.',
    'Grupa ma jeden adres - zakres musi mieć co najmniej dwa.' => 'The group has one address - a range must have at least two.',
    'Część adresów grupy przejął LibreNMS - takiej grupy nie zamieniamy na zakres.'
        => 'Some addresses of the group were adopted by LibreNMS - such a group cannot be converted to a range.',
    'Adresy grupy ({ips}) nie tworzą ciągłego zakresu.' => 'The group addresses ({ips}) do not form a contiguous range.',

    // Zakresy (IpamIndex::rangeContainer)
    'Zakres {range} przecina albo obejmuje prefiks {cidr}. Zakres musi leżeć w całości w jednej sieci albo prefiksie.'
        => 'Range {range} cuts through or contains prefix {cidr}. A range must lie entirely within one network or prefix.',
    'Zakres {range} nie leży w żadnej sieci z LibreNMS ani w prefiksie ręcznym. Dodaj najpierw prefiks (np. agregat albo sieć).'
        => 'Range {range} is not in any LibreNMS network or manual prefix. Add a prefix first (e.g. an aggregate or a network).',
    'Zakres {range} obejmuje adres sieci albo rozgłoszeniowy sieci {cidr}.' => 'Range {range} includes the network or broadcast address of network {cidr}.',

    // Przestrzenie
    'Nazwa przestrzeni jest wymagana (do 64 znaków).' => 'Space name is required (up to 64 characters).',
    'Przestrzeń „{name}” już istnieje.' => 'Space "{name}" already exists.',

    // Parsowanie wpisanych adresów (IpInput, IpCalc)
    'Podaj adres, zakres albo prefiks, np. 10.0.0.0/24 lub 10.0.0.10-20.' => 'Enter an address, range or prefix, e.g. 10.0.0.0/24 or 10.0.0.10-20.',
    'Prefiks „{p}” dodaj osobno - nie łączy się go z adresami w jednym wpisie.' => 'Add prefix "{p}" separately - it cannot be combined with addresses in one entry.',
    'Nie rozumiem zakresu „{p}”.' => 'Cannot parse range "{p}".',
    'Najwięcej {n} adresów naraz. Większy blok dodaj jako prefiks.' => 'At most {n} addresses at once. Add a larger block as a prefix.',
    '„{p}” to nie jest adres IPv4.' => '"{p}" is not an IPv4 address.',
    'Zakres podaj jako początek-koniec, np. 10.0.0.10-10.0.0.200 albo 10.0.0.10-200.' => 'Enter the range as start-end, e.g. 10.0.0.10-10.0.0.200 or 10.0.0.10-200.',
    'Zakres musi mieć co najmniej dwa adresy. Pojedynczy adres dodaj jako adres.' => 'A range must have at least two addresses. Add a single address as an address.',
    '„{text}” to nie jest adres ani prefiks IPv4.' => '"{text}" is not an IPv4 address or prefix.',
    '„{text}” to nie jest poprawny adres ani prefiks IPv4.' => '"{text}" is not a valid IPv4 address or prefix.',
    '„{text}” ma ustawione bity hosta. Chodziło o {cidr}?' => '"{text}" has host bits set. Did you mean {cidr}?',

    // RIPE
    'Podaj numer AS, np. AS61154 albo 61154.' => 'Enter an AS number, e.g. AS61154 or 61154.',
    'AS{n} to nie jest poprawny publiczny numer AS.' => 'AS{n} is not a valid public AS number.',
    'AS{n} to numer prywatny albo zarezerwowany - RIPE nie ma dla niego prefiksów.' => 'AS{n} is a private or reserved number - RIPE has no prefixes for it.',
    'RIPEstat (opis AS) nie odpowiedział.' => 'RIPEstat (AS overview) did not respond.',
    'RIPEstat (prefiksy ogłaszane w BGP) nie odpowiedział.' => 'RIPEstat (prefixes announced in BGP) did not respond.',
    'Baza RIPE (obiekty route) nie odpowiedziała.' => 'RIPE database (route objects) did not respond.',
    'Nie udało się pobrać danych z RIPE (RIPEstat i baza RIPE nie odpowiadają). Spróbuj później.'
        => 'Could not fetch data from RIPE (RIPEstat and the RIPE database are not responding). Try again later.',
    'Baza RIPE odpowiada wolno: bez danych o przydziale zostało {n} prefiksów.' => 'The RIPE database is slow: {n} prefix(es) left without allocation data.',
];
