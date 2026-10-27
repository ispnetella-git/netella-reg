<?php

/*
 * Access.php - uprawnienia per rola LibreNMS: view, edit, delete, admin.
 *
 * Wzór z Netella-Map (Support\Access), z tymi samymi zabezpieczeniami:
 *   - authorize() hooków nie dostaje zalogowanego użytkownika, tylko pusty
 *     model User z kontenera, więc zawsze bierzemy auth()->user() i
 *     odrzucamy obiekty z exists === false;
 *   - role spatie bywają nieprzypisane (po aktualizacji, SSO), więc jest
 *     fallback na users.level: 10 = admin, 5 = global-read, 1 = user.
 *
 * Admin ma zawsze wszystkie prawa, żeby błędna konfiguracja nie mogła
 * go zablokować. Prawa są kumulatywne: admin > delete > edit > view.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Core;

use Illuminate\Support\Facades\Gate;

final class Access
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
    public const DELETE = 'delete';
    public const ADMIN = 'admin';

    /** Kolejność od najsłabszego. Wyższe prawo obejmuje niższe. */
    private const ORDER = [self::VIEW, self::EDIT, self::DELETE, self::ADMIN];

    public static function canView(?object $user = null): bool
    {
        return self::can(self::VIEW, $user);
    }

    public static function canEdit(?object $user = null): bool
    {
        return self::can(self::EDIT, $user);
    }

    public static function canDelete(?object $user = null): bool
    {
        return self::can(self::DELETE, $user);
    }

    public static function canAdmin(?object $user = null): bool
    {
        return self::can(self::ADMIN, $user);
    }

    public static function can(string $level, ?object $user = null): bool
    {
        $user ??= self::currentUser();

        if ($user === null || ! ($user->exists ?? true)) {
            return false;
        }

        if (self::isAdmin($user)) {
            return true;
        }

        $roles = self::rolesFor($user);
        $index = array_search($level, self::ORDER, true);

        if ($index === false) {
            return false;
        }

        // Prawo wyższe obejmuje niższe: rola z listy "edit" może też przeglądać.
        foreach (array_slice(self::ORDER, $index) as $l) {
            if (self::matches($roles, Settings::roles('perm_' . $l . '_roles'), $l === self::VIEW)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Czysta reguła (testowalna bez frameworka): czy któraś rola użytkownika
     * jest na liście. Pusta lista daje prawo wszystkim tylko dla view.
     *
     * @param  list<string>  $userRoles
     * @param  list<string>  $allowed
     */
    public static function matches(array $userRoles, array $allowed, bool $emptyMeansAll): bool
    {
        if ($allowed === []) {
            return $emptyMeansAll;
        }

        return array_intersect($userRoles, $allowed) !== [];
    }

    public static function currentUser(): ?object
    {
        try {
            return auth()->user();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Nazwa użytkownika do dziennika zmian. */
    public static function username(?object $user = null): ?string
    {
        $user ??= self::currentUser();

        return $user === null ? null : (string) ($user->username ?? $user->name ?? $user->user_id ?? '');
    }

    public static function userId(?object $user = null): ?int
    {
        $user ??= self::currentUser();
        $id = $user === null ? null : ($user->user_id ?? $user->id ?? null);

        return $id === null ? null : (int) $id;
    }

    private static function isAdmin(object $user): bool
    {
        try {
            if (Gate::forUser($user)->allows('admin')) {
                return true;
            }
        } catch (\Throwable) {
            // brak Gate albo nietypowy model użytkownika
        }

        if (method_exists($user, 'hasRole')) {
            try {
                if ($user->hasRole('admin')) {
                    return true;
                }
            } catch (\Throwable) {
                // brak spatie
            }
        }

        return self::levelOf($user) >= 10;
    }

    /** @return list<string> */
    public static function rolesFor(object $user): array
    {
        $names = [];

        try {
            if (method_exists($user, 'getRoleNames')) {
                $names = array_values(array_filter($user->getRoleNames()->all(), 'is_string'));
            }
        } catch (\Throwable) {
            $names = [];
        }

        if ($names !== []) {
            return $names;
        }

        $role = self::roleFromLevel(self::levelOf($user));

        return $role === null ? [] : [$role];
    }

    public static function roleFromLevel(int $level): ?string
    {
        return match (true) {
            $level >= 10 => 'admin',
            $level >= 5 => 'global-read',
            $level >= 1 => 'user',
            default => null,
        };
    }

    private static function levelOf(object $user): int
    {
        try {
            return (int) ($user->level ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Role dostępne w LibreNMS - do formularza ustawień. Admina pomijamy, bo ma wszystko. */
    public static function availableRoles(): array
    {
        $roles = [];

        try {
            if (class_exists(\Spatie\Permission\Models\Role::class)) {
                $roles = \Spatie\Permission\Models\Role::query()->orderBy('name')->pluck('name')->all();
            }
        } catch (\Throwable) {
            $roles = [];
        }

        if ($roles === []) {
            $roles = ['global-read', 'user'];
        }

        return array_values(array_filter($roles, static fn ($r) => $r !== 'admin'));
    }
}
