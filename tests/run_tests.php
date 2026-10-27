#!/usr/bin/env php
<?php

/*
 * run_tests.php - testy logiki niezależnej od LibreNMS i Laravela.
 *
 *   php tests/run_tests.php
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

$src = __DIR__ . '/../src';
require $src . '/Core/SnapshotDiff.php';
require $src . '/Core/Settings.php';
require $src . '/Core/Access.php';
require $src . '/Core/ApiException.php';
require $src . '/Core/Expiry.php';
require $src . '/Core/Backup.php';
require $src . '/Modules/Vlan/VlanName.php';
require $src . '/Modules/Vlan/VlanRange.php';
require $src . '/Modules/Vlan/VlanIndex.php';
require $src . '/Modules/Vlan/VlanManual.php';
require $src . '/Modules/Ipam/IpCalc.php';
require $src . '/Modules/Ipam/IpInput.php';
require $src . '/Modules/Ipam/IpTree.php';
require $src . '/Modules/Ipam/IpamIndex.php';

use Ispnetella\NetellaReg\Core\Access;
use Ispnetella\NetellaReg\Core\ApiException;
use Ispnetella\NetellaReg\Core\Backup;
use Ispnetella\NetellaReg\Core\Expiry;
use Ispnetella\NetellaReg\Modules\Ipam\IpCalc;
use Ispnetella\NetellaReg\Modules\Ipam\IpamIndex;
use Ispnetella\NetellaReg\Modules\Ipam\IpInput;
use Ispnetella\NetellaReg\Modules\Ipam\IpTree;
use Ispnetella\NetellaReg\Core\SnapshotDiff;
use Ispnetella\NetellaReg\Modules\Vlan\VlanIndex;
use Ispnetella\NetellaReg\Modules\Vlan\VlanManual;
use Ispnetella\NetellaReg\Modules\Vlan\VlanName;
use Ispnetella\NetellaReg\Modules\Vlan\VlanRange;

$passed = 0;
$failed = 0;

function check(string $name, mixed $got, mixed $expected): void
{
    global $passed, $failed;

    if ($got === $expected) {
        $passed++;

        return;
    }

    $failed++;
    echo "  BŁĄD: $name\n    oczekiwano: " . var_export($expected, true) . "\n    otrzymano:  " . var_export($got, true) . "\n";
}

function throws(string $name, callable $fn): void
{
    global $passed, $failed;

    try {
        $fn();
        $failed++;
        echo "  BŁĄD: $name - brak wyjątku\n";
    } catch (\InvalidArgumentException) {
        $passed++;
    }
}

// --- VlanName: nazwy interfejsów z noc-spidernet ---
$cases = [
    'Vlanif1400' => ['vlan' => 1400, 'kind' => 'l3'],
    'Vlanif2' => ['vlan' => 2, 'kind' => 'l3'],
    'Vlan1600' => ['vlan' => 1600, 'kind' => 'l3'],
    'vlan1600' => ['vlan' => 1600, 'kind' => 'l3'],
    'vlan2220' => ['vlan' => 2220, 'kind' => 'l3'],
    'vlan100-ether2' => ['vlan' => 100, 'kind' => 'l3'],
    'Vlan-interface300' => ['vlan' => 300, 'kind' => 'l3'],
    'irb.1400' => ['vlan' => 1400, 'kind' => 'l3'],
    'Po1.1600' => ['vlan' => 1600, 'kind' => 'subif'],
    'Po2.100' => ['vlan' => 100, 'kind' => 'subif'],
    'Gi0/0/0.1400' => ['vlan' => 1400, 'kind' => 'subif'],
    'ae0.2501' => ['vlan' => 2501, 'kind' => 'subif'],
    'xe-0/1/0.3001' => ['vlan' => 3001, 'kind' => 'subif'],
    'Eth-Trunk4.100' => ['vlan' => 100, 'kind' => 'subif'],
    'vlan-voip' => null,
    'vlankamery' => null,
    'Vlanif0' => null,
    'Vlanif4095' => null,
    'lo0.0' => null,
    'lo0.16384' => null,
    'em0.0' => null,
    'lt-0/0/0.100' => null,
    'InLoopBack0' => null,
    'GigabitEthernet0/0/1' => null,
    'ae0.32767' => null,
    'MEth0/0/1.5' => null,
    '' => null,
];
foreach ($cases as $if => $want) {
    check("VlanName::fromIfName('$if')", VlanName::fromIfName((string) $if), $want);
}

// --- VlanRange ---
check('parse zakres', VlanRange::parse('1400-1403'), [1400, 1401, 1402, 1403]);
check('parse mieszany', VlanRange::parse('5, 3 1-2;10'), [1, 2, 3, 5, 10]);
check('parse huawei "to"', VlanRange::parse('100 to 102 200'), [100, 101, 102, 200]);
check('parse odwrócony', VlanRange::parse('12-10'), [10, 11, 12]);
check('parse duplikaty', VlanRange::parse('7,7,6-7'), [6, 7]);
throws('parse 0', fn () => VlanRange::parse('0-5'));
throws('parse 4095', fn () => VlanRange::parse('4090-4095'));
throws('parse śmieci', fn () => VlanRange::parse('abc'));
throws('parse pusty', fn () => VlanRange::parse('  '));
check('compress', VlanRange::compress([1500, 1400, 1401, 1402, 7]), '7,1400-1402,1500');
check('compress pusty', VlanRange::compress([]), '');
check('compress pojedynczy', VlanRange::compress([5]), '5');

$busy = [3 => true, 4 => true, 8 => true];
$isFree = fn (int $v) => ! isset($busy[$v]);
check('findFreeRun 2', VlanRange::findFreeRun($isFree, 2, 1, 20), [1, 2]);
check('findFreeRun 3', VlanRange::findFreeRun($isFree, 3, 1, 20), [5, 6, 7]);
check('findFreeRun od 4', VlanRange::findFreeRun($isFree, 1, 4, 20), [5]);
check('findFreeRun brak', VlanRange::findFreeRun($isFree, 5, 1, 10), null);
check('findFreeRun 0', VlanRange::findFreeRun($isFree, 0), null);
check('findFreeRun do 4094', VlanRange::findFreeRun(fn ($v) => $v > 4090, 4, 1, 9999), [4091, 4092, 4093, 4094]);

// --- VlanIndex: statusy i priorytety ---
$now = new DateTimeImmutable('2026-10-27 12:00:00');
$row = fn (int $vlan, int $dev, bool $disabled = false, ?string $gone = null) => [
    'key' => "v$vlan:d$dev",
    'data' => ['vlan' => $vlan, 'device_id' => $dev, 'device' => "dev$dev", 'disabled' => $disabled, 'name' => null],
    'first_seen' => '2026-10-01 00:00:00',
    'last_seen' => '2026-10-27 12:00:00',
    'gone_at' => $gone,
];
$rows = [
    $row(100, 1),
    $row(100, 2, true),                          // aktywny wygrywa z wyłączonym
    $row(200, 2, true),                          // tylko wyłączone
    $row(300, 1, false, '2026-10-20 00:00:00'),  // zniknął 7 dni temu, karencja 30
    $row(400, 1, false, '2026-08-01 00:00:00'),  // zniknął dawno - wolny
    $row(500, 1, false, '2026-10-20 00:00:00'),
    $row(500, 2, true),                          // wyłączony wygrywa ze zniknięciem
];
$idx = new VlanIndex($rows, $now, 30, 'stale');
check('index 100 aktywny', $idx->status(100), VlanIndex::LNMS);
check('index 200 nieaktualny', $idx->status(200), VlanIndex::STALE);
check('index 300 zniknął', $idx->status(300), VlanIndex::GONE);
check('index 400 po karencji', $idx->status(400), VlanIndex::FREE);
check('index 500 stale > gone', $idx->status(500), VlanIndex::STALE);
check('index 1 wolny', $idx->status(1), VlanIndex::FREE);
check('index wystąpienia 100', count($idx->occurrences(100)), 2);
check('index długość mapy', strlen($idx->statusString()), 4094);
check('index znak 100', $idx->statusString()[99], 'l');
check('index liczniki', $idx->counts(), ['f' => 4090, 'l' => 1, 's' => 2, 'm' => 0, 'r' => 0, 'g' => 1]);

$idx2 = new VlanIndex($rows, $now, 30, 'ignore');
check('ignore: 200 wolny', $idx2->status(200), VlanIndex::FREE);
check('ignore: 500 zniknął', $idx2->status(500), VlanIndex::GONE);
check('ignore: 100 aktywny', $idx2->status(100), VlanIndex::LNMS);

$idx3 = new VlanIndex($rows, $now, 0, 'stale');
check('karencja 0: 300 wolny', $idx3->status(300), VlanIndex::FREE);

// --- VlanIndex: wpisy ręczne ---
$manual = [
    100 => ['status' => 'used'],      // LibreNMS ma pierwszeństwo
    200 => ['status' => 'reserved'],  // nieaktualny (wyłączone) wygrywa z rezerwacją
    300 => ['status' => 'reserved'],  // rezerwacja wygrywa ze zniknięciem
    1400 => ['status' => 'used'],
    1401 => ['status' => 'reserved'],
    5000 => ['status' => 'used'],     // poza zakresem - pomijany
];
$im = new VlanIndex($rows, $now, 30, 'stale', $manual);
check('manual: 100 nadal LibreNMS', $im->status(100), VlanIndex::LNMS);
check('manual: 100 ma adnotację', $im->manual(100), ['status' => 'used']);
check('manual: 200 stale > reserved', $im->status(200), VlanIndex::STALE);
check('manual: 300 reserved > gone', $im->status(300), VlanIndex::RESERVED);
check('manual: 1400 ręczny', $im->status(1400), VlanIndex::MANUAL);
check('manual: 1401 rezerwacja', $im->status(1401), VlanIndex::RESERVED);
check('manual: 1401 zajęty', $im->isFree(1401), false);
check('manual: 1402 wolny', $im->isFree(1402), true);
check('manual: inLibreNms 100', $im->inLibreNms(100), true);
check('manual: inLibreNms 1400', $im->inLibreNms(1400), false);
check('manual: maska', [strlen($im->manualMask()), substr_count($im->manualMask(), '1'), $im->manualMask()[1399]], [4094, 5, '1']);
check('manual: wolny ciąg omija wpisy', VlanRange::findFreeRun(fn ($v) => $im->isFree($v), 3, 1400), [1402, 1403, 1404]);
$im2 = new VlanIndex($rows, $now, 30, 'ignore', $manual);
check('manual+ignore: 200 rezerwacja', $im2->status(200), VlanIndex::RESERVED);

// --- blokada dodawania numerów widocznych w LibreNMS ---
$plain = new VlanIndex($rows, $now, 30, 'stale');
check('known: 100 aktywny', $plain->knownToLibreNms(100), true);
check('known: 200 tylko wyłączone', $plain->knownToLibreNms(200), true);
check('known: 300 zniknął (karencja)', $plain->knownToLibreNms(300), false);
check('known: 1 wolny', $plain->knownToLibreNms(1), false);
check('known: 200 przy ignore', (new VlanIndex($rows, $now, 30, 'ignore'))->knownToLibreNms(200), false);
check('blocked z zakresu', VlanManual::blocked([99, 100, 200, 300, 301], fn ($v) => $plain->knownToLibreNms($v)), [100, 200]);
check('blocked pusty', VlanManual::blocked([1, 2], fn ($v) => $plain->knownToLibreNms($v)), []);

// --- SnapshotDiff ---
check('hash niezależny od kolejności kluczy', SnapshotDiff::hash(['a' => 1, 'b' => [2, 3]]), SnapshotDiff::hash(['b' => [2, 3], 'a' => 1]));
check('hash zależny od kolejności listy', SnapshotDiff::hash(['b' => [2, 3]]) === SnapshotDiff::hash(['b' => [3, 2]]), false);

$a = ['vlan' => 1, 'name' => 'A'];
$prev = [
    'k1' => ['hash' => SnapshotDiff::hash($a), 'gone' => false, 'data' => $a],
    'k2' => ['hash' => SnapshotDiff::hash($a), 'gone' => false, 'data' => $a],
    'k3' => ['hash' => SnapshotDiff::hash($a), 'gone' => true, 'data' => $a],
    'k4' => ['hash' => SnapshotDiff::hash($a), 'gone' => false, 'data' => $a],
    'k5' => ['hash' => SnapshotDiff::hash($a), 'gone' => true, 'data' => $a],
];
$cur = [
    'k1' => $a,                              // bez zmian
    'k2' => ['vlan' => 1, 'name' => 'B'],    // zmiana
    'k3' => $a,                              // wrócił
    'k6' => $a,                              // nowy
    // k4 zniknął, k5 nadal zniknięty (bez zdarzenia)
];
$d = SnapshotDiff::compute($prev, $cur);
check('diff unchanged', $d['unchanged'], ['k1']);
check('diff changed', array_keys($d['changed']), ['k2']);
check('diff returned', array_keys($d['returned']), ['k3']);
check('diff appeared', array_keys($d['appeared']), ['k6']);
check('diff gone', array_keys($d['gone']), ['k4']);
check('changedFields', SnapshotDiff::changedFields(['a' => 1, 'b' => 2, 'c' => [1]], ['a' => 1, 'b' => 3, 'd' => 4, 'c' => [1]]),
    [['b' => 2, 'd' => null], ['b' => 3, 'd' => 4]]);

// --- IpCalc ---
$ip = fn (string $s) => IpCalc::ip2int($s);
check('ip2int', $ip('10.0.0.1'), 167772161);
check('ip2int 255.255.255.255', $ip('255.255.255.255'), 4294967295);
check('ip2int zły', $ip('10.0.0.256'), null);
check('int2ip', IpCalc::int2ip(3232235777), '192.168.1.1');
check('mask /24', IpCalc::mask(24), 0xFFFFFF00);
check('mask /0', IpCalc::mask(0), 0);
check('network', IpCalc::int2ip(IpCalc::network($ip('45.10.136.77'), 24)), '45.10.136.0');
check('parseV4 prefiks', IpCalc::parseV4('45.10.136.0/24')['cidr'], '45.10.136.0/24');
check('parseV4 adres', [IpCalc::parseV4('10.0.0.5')['len'], IpCalc::parseV4('10.0.0.5')['cidr']], [32, '10.0.0.5/32']);
check('parseV4 normalizuje', IpCalc::parseV4('10.0.0.5/24')['cidr'], '10.0.0.0/24');
throws('parseV4 strict bity hosta', fn () => IpCalc::parseV4('10.0.0.5/24', true));
throws('parseV4 śmieci', fn () => IpCalc::parseV4('10.0.0/24'));
throws('parseV4 /33', fn () => IpCalc::parseV4('10.0.0.0/33'));
check('normalizeV6', IpCalc::normalizeV6('2001:0db8:0000::0001'), '2001:db8::1');
check('normalizeV6 zły', IpCalc::normalizeV6('10.0.0.1'), null);
$c = fn ($a, $b) => array_map(fn ($x) => IpCalc::cidr($x['start'], $x['len']), IpCalc::rangeToCidrs($ip($a), $ip($b)));
check('rangeToCidrs .20-.63', $c('10.0.0.20', '10.0.0.63'), ['10.0.0.20/30', '10.0.0.24/29', '10.0.0.32/27']);
check('rangeToCidrs cała /24', $c('10.0.0.0', '10.0.0.255'), ['10.0.0.0/24']);
check('rangeToCidrs 1 adres', $c('10.0.0.7', '10.0.0.7'), ['10.0.0.7/32']);
check('rangeToCidrs 0.0.0.0-255.255.255.255', $c('0.0.0.0', '255.255.255.255'), ['0.0.0.0/0']);
check('freeIntervals', IpCalc::freeIntervals(0, 99, [[10, 19], [15, 30], [50, 50], [120, 130]]), [[0, 9], [31, 49], [51, 99]]);
check('freeIntervals pełny', IpCalc::freeIntervals(0, 9, [[0, 20]]), []);
check('merge', IpCalc::merge([[5, 6], [0, 2], [3, 3], [10, 12]]), [[0, 3], [5, 6], [10, 12]]);
check('coveredCount', IpCalc::coveredCount([[0, 9], [5, 14], [20, 20]]), 16);
check('findFreeBlocks /29', array_map(fn ($s) => IpCalc::int2ip($s),
    IpCalc::findFreeBlocks($ip('10.0.0.0'), $ip('10.0.0.255'), [[$ip('10.0.0.0'), $ip('10.0.0.9')]], 29, 3)),
    ['10.0.0.16', '10.0.0.24', '10.0.0.32']);
check('usable /24', IpCalc::usable(24), 254);
check('usable /31', IpCalc::usable(31), 2);

// --- IpInput ---
check('input prefiks', IpInput::parse('10.0.0.0/24')['prefix']['cidr'], '10.0.0.0/24');
throws('input prefiks z bitami hosta', fn () => IpInput::parse('10.0.0.1/24'));
check('input adres', IpInput::parse('10.0.0.5')['addresses'], [$ip('10.0.0.5')]);
check('input adres /32', IpInput::parse('10.0.0.5/32')['addresses'], [$ip('10.0.0.5')]);
check('input zakres skrót', count(IpInput::parse('10.0.0.10-20')['addresses']), 11);
check('input zakres pełny', IpInput::compress(IpInput::parse('10.0.0.250-10.0.1.2')['addresses']), '10.0.0.250-10.0.1.2');
check('input lista', IpInput::compress(IpInput::parse('10.0.0.7, 10.0.0.1 10.0.0.2')['addresses']), '10.0.0.1-10.0.0.2, 10.0.0.7');
throws('input prefiks w liście', fn () => IpInput::parse('10.0.0.1, 10.0.0.0/24'));
throws('input za dużo', fn () => IpInput::parse('10.0.0.0-10.0.8.0'));
throws('input pusty', fn () => IpInput::parse(' '));
throws('input śmieci', fn () => IpInput::parse('abc'));

// --- IpTree ---
$pfx = fn (string $cidr, string $src = 'lnms', bool $subnet = true) => ['start' => IpCalc::parseV4($cidr)['start'], 'len' => IpCalc::parseV4($cidr)['len'], 'src' => $src, 'subnet' => $subnet];
// 45.10.136.0/22 to agregat (nasz przydział), 10.0.0.0/8 to adresacja wewnętrzna,
// 193.0.0.1 to publiczny loopback bez prefiksu.
$tree = new IpTree(
    [$pfx('45.10.136.0/24'), $pfx('45.10.136.0/22', 'manual', false), $pfx('10.218.8.0/30'), $pfx('10.218.8.4/30'),
        $pfx('10.0.0.0/8', 'implicit', false), $pfx('91.238.142.0/24')],
    [['ip' => $ip('45.10.136.1')], ['ip' => $ip('45.10.136.2')], ['ip' => $ip('10.218.8.1')], ['ip' => $ip('10.218.8.5')], ['ip' => $ip('10.218.0.3')],
        ['ip' => $ip('193.0.0.1')]],
    [8, 16, 24]
);
check('tree korzenie: najszersze prawdziwe prefiksy, bez klas nad nimi', $tree->roots(), ['10.0.0.0/8', '45.10.136.0/22', '91.238.142.0/24']);
check('tree brak 45.0.0.0/8 i 45.10.0.0/16', [$tree->node('45.0.0.0/8'), $tree->node('45.10.0.0/16')], [null, null]);
check('tree rodzic /24', $tree->node('45.10.136.0/24')['parent'], '45.10.136.0/22');
check('tree /22 bez rodzica', $tree->node('45.10.136.0/22')['parent'], null);
check('tree /22 nie ma wirtualnego /24 nad sobą', $tree->node('45.10.136.0/24')['sources'], ['lnms' => true]);
check('tree adres w /24', $tree->deepest($ip('45.10.136.2')), '45.10.136.0/24');
check('tree adres poza siecią w adresacji wewnętrznej -> wirtualny /24', $tree->deepest($ip('10.218.0.3')), '10.218.0.0/24');
check('tree /30 pod wirtualnym /24', $tree->node('10.218.8.0/30')['parent'], '10.218.8.0/24');
check('tree wirtualny /16 w adresacji wewnętrznej', IpTree::isVirtual($tree->node('10.218.0.0/16')), true);
check('tree publiczny loopback w korzeniu', [$tree->deepest($ip('193.0.0.1')), $tree->node('193.0.0.0/24')], [null, null]);
check('tree adresy w korzeniu', array_map(fn ($i) => IpCalc::int2ip($tree->address($i)['ip']), $tree->rootAddresses()), ['193.0.0.1']);
check('tree inNetwork', [$tree->inNetwork($ip('10.218.8.1')), $tree->inNetwork($ip('10.218.0.3')), $tree->inNetwork($ip('193.0.0.1'))], [true, false, false]);
check('tree realContainer', [$tree->realContainer($ip('45.10.138.0'), $ip('45.10.139.255')), $tree->realContainer($ip('45.10.0.0'), $ip('45.10.255.255')),
    $tree->realContainer($ip('10.5.0.0'), $ip('10.5.0.255'))], ['45.10.136.0/22', null, '10.0.0.0/8']);
$s24 = $tree->node('45.10.136.0/24')['stats'];
check('tree /24 sieć: hosty', [$s24['capacity'], $s24['used'], $s24['free'], $s24['addresses']], [254, 2, 252, 2]);
$s22 = $tree->node('45.10.136.0/22')['stats'];
check('tree /22 agregat: zajęta jedna /24', [$s22['capacity'], $s22['used'], $s22['util']], [1024, 256, 25.0]);
$s8 = $tree->node('10.0.0.0/8')['stats'];
check('tree adresacja wewnętrzna /8 (2x /30 + 1 adres)', $s8['used'], 9);
check('tree wolne bloki /22', array_column($tree->freeBlocks('45.10.136.0/22', 5), 'cidr'), ['45.10.138.0/23', '45.10.137.0/24']);
check('tree wolne /30 sieci', $tree->node('10.218.8.0/30')['stats']['free'], 1);
check('tree ścieżka', $tree->path('45.10.136.0/24'), ['45.10.136.0/22', '45.10.136.0/24']);
$empty = new IpTree([], [], [8, 16, 24]);
check('tree pusty', $empty->roots(), []);

// Adres w sieci /16 nie trafia do wirtualnego /24 wewnątrz niej (błąd z 172.19.0.1/16).
$t16 = new IpTree([$pfx('172.19.0.0/16')], [['ip' => $ip('172.19.0.1')]], [8, 16, 24]);
check('tree: adres w sieci /16', $t16->deepest($ip('172.19.0.1')), '172.19.0.0/16');
check('tree: brak wirtualnego /24 w sieci', $t16->node('172.19.0.0/24'), null);
check('tree: brak wirtualnego /8 nad siecią', [$t16->node('172.19.0.0/16')['parent'], $t16->roots()], [null, ['172.19.0.0/16']]);
// W agregacie (nie sieci) poziomy grupowania zostają.
$tAgg = new IpTree([$pfx('10.0.0.0/16', 'manual', false), $pfx('10.0.5.0/30')], [], [8, 16, 24]);
check('tree: wirtualny /24 w agregacie', IpTree::isVirtual($tAgg->node('10.0.5.0/24')), true);

// Wolne miejsce do drzewa: dziecko wirtualne liczy się w całości.
check('gaps agregatu /16', array_map(fn ($g) => [IpCalc::int2ip($g[0]), IpCalc::int2ip($g[1])], $tAgg->gaps('10.0.0.0/16')),
    [['10.0.0.0', '10.0.4.255'], ['10.0.6.0', '10.0.255.255']]);
check('gaps sieci /30 bez adresu sieci i rozgłoszeniowego', array_map(fn ($g) => [IpCalc::int2ip($g[0]), IpCalc::int2ip($g[1])], $tAgg->gaps('10.0.5.0/30')),
    [['10.0.5.1', '10.0.5.2']]);
check('gaps wolne prefiksy agregatu', array_map(fn ($b) => IpCalc::cidr($b['start'], $b['len']), IpCalc::rangeToCidrs($ip('10.0.6.0'), $ip('10.0.255.255'))),
    ['10.0.6.0/23', '10.0.8.0/21', '10.0.16.0/20', '10.0.32.0/19', '10.0.64.0/18', '10.0.128.0/17']);

// --- Zakresy (pule a-b) ---
check('range parse pełny', IpInput::parseRange('10.0.0.10-10.0.0.200'), [$ip('10.0.0.10'), $ip('10.0.0.200')]);
check('range parse skrót i spacje', IpInput::parseRange(' 10.0.0.200 – 10 '), [$ip('10.0.0.10'), $ip('10.0.0.200')]);
check('range parse duży (bez limitu 1024)', IpInput::parseRange('100.64.0.0-100.64.63.255'), [$ip('100.64.0.0'), $ip('100.64.63.255')]);
throws('range parse jeden adres', fn () => IpInput::parseRange('10.0.0.5-5'));
throws('range parse lista', fn () => IpInput::parseRange('10.0.0.1, 10.0.0.5'));
throws('range parse prefiks', fn () => IpInput::parseRange('10.0.0.0/24'));
check('range etykieta', IpInput::rangeLabel($ip('10.0.0.10'), $ip('10.0.0.20')), '10.0.0.10-10.0.0.20');

// Sieć /24 z pulą .100-.199; adres .150 w puli, .20 poza nią.
$tR = new IpTree([$pfx('10.1.1.0/24')], [['ip' => $ip('10.1.1.1')], ['ip' => $ip('10.1.1.150')]], [8, 16, 24],
    [['id' => 7, 'start' => $ip('10.1.1.100'), 'end' => $ip('10.1.1.199')]]);
check('tree zakres w sieci', $tR->range(7)['node'], '10.1.1.0/24');
check('tree zakres w węźle', $tR->node('10.1.1.0/24')['ranges'], [7]);
check('tree zakres zajmuje całość (1 + 100 adresów)', $tR->node('10.1.1.0/24')['stats']['used'], 101);
check('tree gaps omija zakres', array_map(fn ($g) => [IpCalc::int2ip($g[0]), IpCalc::int2ip($g[1])], $tR->gaps('10.1.1.0/24')),
    [['10.1.1.2', '10.1.1.99'], ['10.1.1.200', '10.1.1.254']]);
// Zakres w agregacie bez sieci: kotwica poziomu /24, gdy się w nim mieści.
$tRA = new IpTree([$pfx('10.2.0.0/16', 'manual', false)], [], [8, 16, 24],
    [['id' => 1, 'start' => $ip('10.2.5.10'), 'end' => $ip('10.2.5.20')], ['id' => 2, 'start' => $ip('10.2.6.200'), 'end' => $ip('10.2.7.10')]]);
check('tree zakres tworzy wirtualny /24', $tRA->range(1)['node'], '10.2.5.0/24');
check('tree zakres przez granicę /24 należy do agregatu', $tRA->range(2)['node'], '10.2.0.0/16');
check('tree agregat liczy oba zakresy', $tRA->node('10.2.0.0/16')['stats']['used'], 11 + 67);
check('tree zakres bez prefiksu w korzeniu', (new IpTree([], [], [8, 16, 24], [['id' => 3, 'start' => $ip('192.0.2.1'), 'end' => $ip('192.0.2.9')]]))->range(3)['node'], null);

// IpamIndex::rangeContainer na wpisach ręcznych (bez migawki).
$mp = function (int $id, string $cidr, string $role) {
    $c = IpCalc::parseV4($cidr);

    return ['id' => $id, 'family' => 4, 'kind' => 'prefix', 'cidr' => $cidr, 'start' => $c['start'], 'end' => $c['end'], 'len' => $c['len'], 'role' => $role];
};
$idxR = new IpamIndex([], [$mp(1, '10.3.0.0/16', 'aggregate'), $mp(2, '10.3.1.0/24', 'used')], 1, [], new DateTimeImmutable('2026-10-27'), 30, 'stale');
check('kontener: zakres w sieci', $idxR->rangeContainer($ip('10.3.1.10'), $ip('10.3.1.20')), ['10.3.1.0/24', null]);
check('kontener: zakres w agregacie obok sieci', $idxR->rangeContainer($ip('10.3.2.10'), $ip('10.3.2.20')), ['10.3.0.0/16', null]);
check('kontener: przecina sieć', $idxR->rangeContainer($ip('10.3.0.250'), $ip('10.3.1.5'))[0], null);
check('kontener: adres sieci', $idxR->rangeContainer($ip('10.3.1.0'), $ip('10.3.1.5'))[0], null);
check('kontener: rozgłoszeniowy', $idxR->rangeContainer($ip('10.3.1.250'), $ip('10.3.1.255'))[0], null);
check('kontener: poza prefiksami', $idxR->rangeContainer($ip('10.9.0.1'), $ip('10.9.0.5'))[0], null);
$idxP = new IpamIndex([], [$mp(1, '10.3.1.0/24', 'used')], 1, [], new DateTimeImmutable('2026-10-27'), 30, 'stale', [8, 16, 24], ['10.0.0.0/8', '192.168.0.0/16']);
check('kontener: adresacja wewnętrzna', $idxP->rangeContainer($ip('10.9.0.1'), $ip('10.9.0.5')), ['10.0.0.0/8', null]);
check('wewnętrzna tylko używana', array_keys($idxP->usedImplicit()), ['10.0.0.0/8']);
check('wewnętrzna w drzewie', $idxP->tree()->roots(), ['10.0.0.0/8']);

// --- Expiry: termin ważności wpisów ręcznych ---
$today = '2026-10-27';
check('expiry pusto', Expiry::parse('', $today), null);
check('expiry data', Expiry::parse('2026-12-31', $today), '2026-12-31');
check('expiry dziś dozwolone', Expiry::parse('2026-10-27', $today), '2026-10-27');
check('expiry przeszłość przy allowPast', Expiry::parse('2026-01-01', $today, true), '2026-01-01');
$apiThrows = function (string $name, callable $fn) {
    global $passed, $failed;
    try {
        $fn();
        $failed++;
        echo "  BŁĄD: $name - brak wyjątku\n";
    } catch (ApiException) {
        $passed++;
    }
};
$apiThrows('expiry przeszłość', fn () => Expiry::parse('2026-10-26', $today));
$apiThrows('expiry zły format', fn () => Expiry::parse('27.10.2026', $today));
$apiThrows('expiry nieistniejąca data', fn () => Expiry::parse('2026-02-30', $today));
check('expiry wygasł wczoraj', Expiry::isExpired('2026-10-26', $today), true);
check('expiry ważny do końca dnia', Expiry::isExpired('2026-10-27', $today), false);
check('expiry bez terminu', Expiry::isExpired(null, $today), false);
check('expiry dni', Expiry::daysLeft('2026-11-10', $today), 14);
check('expiry dni ujemne', Expiry::daysLeft('2026-10-20', $today), -7);
check('expiry stan soon (14 dni)', Expiry::state('2026-11-10', $today), 'soon');
check('expiry stan daleko', Expiry::state('2026-11-11', $today), null);
check('expiry stan dziś', Expiry::state('2026-10-27', $today), 'soon');
check('expiry stan minął', Expiry::state('2026-10-26', $today), 'expired');
check('expiry present z datetime', Expiry::present(['valid_until' => '2026-10-01 00:00:00'], $today)['valid_until'], '2026-10-01');

// Mapa VLAN: wygasły wpis ręczny oznaczony, numer z LibreNMS nie.
$exIdx = new VlanIndex([
    ['key' => 'v20:d1', 'data' => ['vlan' => 20], 'first_seen' => '2026-01-01 00:00:00', 'last_seen' => '2026-10-27 00:00:00', 'gone_at' => null],
], new DateTimeImmutable('2026-10-27 12:00:00'), 30, 'stale', [
    10 => ['status' => 'reserved', 'expiry' => 'expired'],
    11 => ['status' => 'used', 'expiry' => 'soon'],
    20 => ['status' => 'used', 'expiry' => 'expired'],
]);
$em = $exIdx->expiredMask();
check('expiredMask rezerwacja po terminie', $em[9], '1');
check('expiredMask wkrótce mija', $em[10], '0');
check('expiredMask numer z LibreNMS', $em[19], '0');
check('expiredMask status nadal rezerwacja', $exIdx->status(10), 'r');

// --- Backup ---
check('backup schemat zgodny', Backup::problems(['core' => 2, 'vlan' => 4], ['core' => 2, 'vlan' => 4, 'ipam' => 2]), []);
check('backup schemat starszy', Backup::problems(['core' => 1, 'vlan' => 3], ['core' => 2, 'vlan' => 4]), []);
check('backup schemat nowszy', count(Backup::problems(['vlan' => 5], ['vlan' => 4])), 1);
check('backup nieznany moduł', Backup::problems(['zte' => 9], ['vlan' => 4]), []);
check('backup ustawienia z formularza', Backup::settingsLookConfigured(['grace_days' => '30']), true);
check('backup ustawienia puste', Backup::settingsLookConfigured([]), false);
check('backup nazwa pliku', (bool) preg_match(Backup::FILE_PATTERN, 'netella-reg-20261027-031500-auto.json.gz'), true);
check('backup nazwa ze ścieżką', (bool) preg_match(Backup::FILE_PATTERN, '../netella-reg-20261027-031500-auto.json.gz'), false);
check('backup nazwa inny rodzaj', (bool) preg_match(Backup::FILE_PATTERN, 'netella-reg-20261027-031500-x.json.gz'), false);

// --- Access ---
check('matches pusta lista view', Access::matches(['user'], [], true), true);
check('matches pusta lista edit', Access::matches(['user'], [], false), false);
check('matches trafienie', Access::matches(['user', 'noc'], ['noc'], false), true);
check('matches pudło', Access::matches(['user'], ['noc'], true), false);
check('level 10', Access::roleFromLevel(10), 'admin');
check('level 5', Access::roleFromLevel(5), 'global-read');
check('level 1', Access::roleFromLevel(1), 'user');
check('level 0', Access::roleFromLevel(0), null);

echo "\n" . ($failed === 0 ? 'OK' : 'BŁĘDY') . ": $passed zaliczonych, $failed niezaliczonych\n";
exit($failed === 0 ? 0 : 1);
