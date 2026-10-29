<?php

/*
 * lang_check.inc.php - testy języka, dołączane z run_tests.php (check() i Lang są już dostępne).
 *
 * Sprawdza, że każdy tekst w Lang::t(), nreg_t(), N.t(), NReg.t() i t() (wspólny JS
 * w page.blade.php) ma tłumaczenie w resources/lang/en/*.php, a tłumaczenie ma
 * te same wstawki {nazwa} co tekst polski. Klucz musi być literałem w apostrofach.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

use Ispnetella\NetellaReg\Core\Lang;

Lang::force('en');
check('lang: tłumaczenie', Lang::t('Język interfejsu'), 'Interface language');
check('lang: wstawka', Lang::t('wersja {v}', ['v' => '1.2']), 'version 1.2');
check('lang: brak tłumaczenia = tekst polski', Lang::t('zzz tekst bez tłumaczenia'), 'zzz tekst bez tłumaczenia');
Lang::force('pl');
check('lang: po polsku tekst źródłowy', Lang::t('wersja {v}', ['v' => '1.2']), 'wersja 1.2');
Lang::force(null);
check('lang: domyślnie angielski', Lang::current(), 'en');

$root = dirname(__DIR__);
$files = [];
foreach (['/src', '/resources/views'] as $dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (str_ends_with($f->getFilename(), '.php')) {
            $files[] = $f->getPathname();
        }
    }
}
$dict = Lang::dict();
$missing = $badVars = [];
$keys = 0;
$re = '/(?:Lang::t|nreg_t|\bN\.t|NReg\.t|(?<![\w.$])t)\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/u';
foreach ($files as $f) {
    preg_match_all($re, file_get_contents($f), $m);
    foreach ($m[1] as $k) {
        $k = strtr($k, ["\\'" => "'", '\\\\' => '\\']);
        $keys++;
        if (! isset($dict[$k])) {
            $missing[] = basename($f) . ': ' . $k;
            continue;
        }
        preg_match_all('/\{(\w+)\}/', $k, $a);
        preg_match_all('/\{(\w+)\}/', $dict[$k], $b);
        $va = array_values(array_unique($a[1]));
        $vb = array_values(array_unique($b[1]));
        sort($va);
        sort($vb);
        if ($va !== $vb) {
            $badVars[] = basename($f) . ': ' . $k;
        }
    }
}
check('lang: teksty do tłumaczenia znalezione', $keys > 0, true);
check('lang: wszystkie teksty mają tłumaczenie', array_values(array_unique($missing)), []);
check('lang: tłumaczenia mają te same wstawki', array_values(array_unique($badVars)), []);
Lang::force('pl');
