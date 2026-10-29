<?php

/*
 * Lang.php - język interfejsu (angielski domyślnie, polski do wyboru).
 *
 * Kluczem tłumaczenia jest polski tekst źródłowy: Lang::t("Zapisz") daje
 * "Save" po angielsku i "Zapisz" po polsku. Brak tłumaczenia = tekst polski.
 * Słowniki: resources/lang/en/*.php (tablice 'polski' => 'english'), łączone.
 * Wstawki {nazwa} wypełnia druga tablica: Lang::t("Nie ma węzła {cidr}.", ['cidr' => $c]).
 *
 * Język wybiera przełącznik na stronie wtyczki; zapisuje go w cookie (czytanym
 * wprost z $_COOKIE, bo Laravel szyfruje swoje cookies i odrzuciłby to z JS)
 * i w localStorage. Bez cookie (np. cron, CLI) - angielski.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Core;

final class Lang
{
    public const COOKIE = 'netella_reg_lang';

    public const DEFAULT = 'en';

    /** Kod => nazwa w przełączniku. */
    public const LANGS = ['en' => 'English', 'pl' => 'Polski'];

    private static ?string $forced = null;

    /** @var array<string,string>|null */
    private static ?array $dict = null;

    public static function current(): string
    {
        if (self::$forced !== null) {
            return self::$forced;
        }
        $c = $_COOKIE[self::COOKIE] ?? null;

        return is_string($c) && isset(self::LANGS[$c]) ? $c : self::DEFAULT;
    }

    /** Wymusza język (testy, CLI). null = znowu z cookie. */
    public static function force(?string $lang): void
    {
        self::$forced = $lang !== null && isset(self::LANGS[$lang]) ? $lang : null;
    }

    public static function t(string $pl, array $vars = []): string
    {
        $s = self::current() === 'pl' ? $pl : (self::dict()[$pl] ?? $pl);
        if ($vars !== []) {
            $s = preg_replace_callback('/\{(\w+)\}/', fn ($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $s);
        }

        return $s;
    }

    /** Słownik dla JS: pusty po polsku (klucz jest tekstem). */
    public static function jsDict(): array
    {
        return self::current() === 'pl' ? [] : self::dict();
    }

    /** @return array<string,string> polski => angielski */
    public static function dict(): array
    {
        if (self::$dict === null) {
            self::$dict = [];
            foreach (glob(dirname(__DIR__, 2) . '/resources/lang/en/*.php') ?: [] as $f) {
                $part = require $f;
                if (is_array($part)) {
                    self::$dict = $part + self::$dict;
                }
            }
        }

        return self::$dict;
    }
}
