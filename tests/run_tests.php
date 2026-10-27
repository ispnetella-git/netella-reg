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
require $src . '/Modules/Vlan/VlanName.php';
require $src . '/Modules/Vlan/VlanRange.php';
require $src . '/Modules/Vlan/VlanIndex.php';
require $src . '/Modules/Vlan/VlanManual.php';

use Ispnetella\NetellaReg\Core\Access;
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
