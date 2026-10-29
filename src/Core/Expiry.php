<?php

/*
 * Expiry.php - termin ważności wpisów ręcznych (pole valid_until, data).
 *
 * Wpis po terminie NIE zwalnia się sam: dalej zajmuje numer albo adres,
 * a w UI jest oznaczony jako "termin minął", żeby ktoś zdecydował, czy go
 * przedłużyć, czy usunąć. Synchronizacja zapisuje w dzienniku jedno
 * zdarzenie "termin minął" na grupę (kolumna expired_at pilnuje, żeby
 * nie powtarzać go co przebieg). Zmiana terminu na przyszły kasuje expired_at.
 *
 * Czyste, bez Laravela (poza ApiException).
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Core;

final class Expiry
{
    public const FIELD = 'valid_until';

    public const EXPIRED = 'expired';
    public const SOON = 'soon';

    /** Ile dni przed terminem wpis jest oznaczany jako "wkrótce mija". */
    public const WARN_DAYS = 14;

    /**
     * Data z formularza: "RRRR-MM-DD" albo pusto (bez terminu).
     *
     * $allowPast = false: nie można ustawić terminu, który już minął.
     */
    public static function parse(mixed $value, string $today, bool $allowPast = false): ?string
    {
        $v = trim((string) ($value ?? ''));
        if ($v === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            throw new ApiException(Lang::t('Termin ważności musi być datą w postaci RRRR-MM-DD.'));
        }
        if (! $allowPast && $v < $today) {
            throw new ApiException(Lang::t('Termin ważności nie może być w przeszłości.'));
        }

        return $v;
    }

    /** Czy termin minął: wpis jest ważny do końca dnia valid_until. */
    public static function isExpired(?string $validUntil, string $today): bool
    {
        return $validUntil !== null && $validUntil !== '' && substr($validUntil, 0, 10) < $today;
    }

    /** Dni do terminu (0 = mija dziś, ujemne = minął) albo null bez terminu. */
    public static function daysLeft(?string $validUntil, string $today): ?int
    {
        if ($validUntil === null || $validUntil === '') {
            return null;
        }
        $a = new \DateTimeImmutable($today);
        $b = new \DateTimeImmutable(substr($validUntil, 0, 10));

        return (int) $a->diff($b)->format('%r%a');
    }

    /** "expired", "soon" albo null. */
    public static function state(?string $validUntil, string $today): ?string
    {
        $left = self::daysLeft($validUntil, $today);
        if ($left === null) {
            return null;
        }
        if ($left < 0) {
            return self::EXPIRED;
        }

        return $left <= self::WARN_DAYS ? self::SOON : null;
    }

    /** Pola wyliczane do JSON-a wpisu. */
    public static function present(array $r, string $today): array
    {
        $vu = $r[self::FIELD] ?? null;
        $r[self::FIELD] = $vu === null || $vu === '' ? null : substr((string) $vu, 0, 10);
        $r['expiry'] = self::state($r[self::FIELD], $today);
        $r['days_left'] = self::daysLeft($r[self::FIELD], $today);

        return $r;
    }

    public static function today(?\DateTimeInterface $now = null): string
    {
        return ($now ?? new \DateTimeImmutable())->format('Y-m-d');
    }
}
