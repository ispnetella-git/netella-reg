# Netella-Reg

Wtyczka LibreNMS (plugin v2, pakiet composera) do inwentaryzacji VLAN-ów i adresacji IP.
Dane bierze z bazy LibreNMS. Każdą zmianę, zarówno wykrytą w LibreNMS, jak i wprowadzoną
przez użytkownika, zapisuje w dzienniku. Jest zbudowana z modułów, a nowe moduły dokłada się
bez zmian w rdzeniu.

Stan na 0.1.0:
- **rdzeń**: własna baza, schemat wersjonowany per moduł, uprawnienia per rola, dziennik zmian,
  migawki stanu LibreNMS, synchronizacja z crona, komendy `lnms`;
- **moduł VLAN** (tylko odczyt): mapa numerów 1–4094 w całej sieci, sprawdzanie zakresu,
  szukanie wolnych numerów, szczegóły i historia numeru, panel na stronie urządzenia.

Plan kolejnych wersji jest w `../PROPOZYCJA.md` (rozdział 11).

## Instalacja

Repozytorium jest prywatne, więc composer potrzebuje tokenu i adresu repo. Wszystko jako
użytkownik `librenms`:

```bash
cd /opt/librenms
sudo -u librenms php scripts/composer_wrapper.php config -g github-oauth.github.com ghp_TOKEN
sudo -u librenms php scripts/composer_wrapper.php config repositories.netella-reg vcs https://github.com/ispnetella-git/Netella-Reg
sudo -u librenms php lnms plugin:add ispnetella/librenms-netella-reg dev-main
```

`lnms plugin:add` dopisuje pakiet do `composer.plugins.json`. Bez tego wpisu wtyczka zniknie przy
najbliższym `daily.sh`.

### Baza danych

Wtyczka trzyma dane w osobnej bazie `librenms_netella_reg`, bo LibreNMS przy discovery usuwa ze
swoich tabel wiersze, których nie wykrył. Użytkownik bazodanowy LibreNMS zwykle nie ma prawa
`CREATE DATABASE`, więc bazę zakłada się raz, ręcznie:

```bash
sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`librenms_netella_reg\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`librenms_netella_reg\`.* TO 'librenms'@'localhost'; FLUSH PRIVILEGES;"
```

### Pierwsze uruchomienie i cron

```bash
cd /opt/librenms
sudo -u librenms php lnms netella-reg:install
echo '*/5 * * * * librenms /usr/bin/env php /opt/librenms/lnms netella-reg:sync -q >> /opt/librenms/logs/netella-reg.log 2>&1' \
  | sudo tee /etc/cron.d/netella-reg
```

`lnms plugin:add` zwykle od razu włącza wtyczkę. Jeśli nie, włącz ją w
**Overview → Plugins → Plugin Admin** albo przez `lnms plugin:enable Netella-Reg`.
Stan sprawdza `lnms netella-reg:doctor`.

**Cache tras.** LibreNMS trzyma cache tras w `bootstrap/cache/routes-v7.php`. Jeśli
powstał przed instalacją wtyczki, API (`/plugin/Netella-Reg/api/...`) odpowiada 404, a strona
pokazuje pustą mapę. Pomaga przebudowanie cache:

```bash
sudo -u librenms php artisan route:cache
```

### Instalacja bez GitHuba (repozytorium ścieżkowe)

Na czas, gdy repo nie ma jeszcze zdalnej kopii, pakiet instaluje się z katalogu na serwerze.
`vendor/ispnetella/librenms-netella-reg` jest wtedy symlinkiem, więc zmiany w katalogu działają od razu:

```bash
sudo -u librenms git clone -b main <bundle albo repo> /opt/netella-plugins/librenms-netella-reg
cd /opt/librenms
sudo -u librenms php scripts/composer_wrapper.php config -g repositories.netella-reg \
  '{"type":"path","url":"/opt/netella-plugins/librenms-netella-reg","options":{"symlink":true}}'
sudo -u librenms php lnms plugin:add ispnetella/librenms-netella-reg dev-main
```

Przejście na GitHuba: podmień wpis `repositories.netella-reg` na
`vcs https://github.com/ispnetella-git/Netella-Reg` i uruchom ponownie `lnms plugin:add`.

## Użycie

Strona `/plugin/Netella-Reg`:
- zakładka **VLAN-y**: mapa 1–4094 (kolor = status). Kliknięcie numeru pokazuje urządzenia, porty
  tagowane i nietagowane, interfejsy L3, subinterfejsy, daty widzenia i historię zmian. Pole
  „Zakres” sprawdza np. `1400-1450` (także `1400 to 1450` i listy po przecinku). „Znajdź N
  kolejnych wolnych” szuka pierwszego wolnego ciągu numerów;
- zakładka **Dziennik zmian**: wszystkie zdarzenia z filtrami po module, źródle, zdarzeniu i tekście.

Linki bezpośrednie: `/plugin/Netella-Reg?vlan=1400#vlan`, `/plugin/Netella-Reg?range=1400-1450#vlan`.

### Statusy VLAN-u

| Status | Znaczenie |
|---|---|
| LibreNMS | numer jest na co najmniej jednym aktywnym urządzeniu |
| nieaktualny | numer jest tylko na urządzeniach wyłączonych w LibreNMS (ustawienie „Urządzenia wyłączone”) |
| zniknął | numer zniknął z LibreNMS i trwa karencja (domyślnie 30 dni) |
| wolny | nic z powyższych |

Numer jest zajęty, jeśli występuje na **którymkolwiek** urządzeniu w sieci. Źródła w LibreNMS:
tabela `vlans` (definicje), `ports_vlans` (porty) i nazwy interfejsów: `Vlanif1400`, `Vlan1400`,
`Vlan-interface1400`, `irb.1400`, `vlan1600-xyz` (L3) oraz `Po2.100`, `Gi0/0/0.1400`,
`ae0.1400` (subinterfejsy). Dla routerów (ASR, C8500, MX204) tabela `vlans` jest pusta, więc
subinterfejsy są jedynym źródłem. Trunk z `allowed vlan 1-4094` nie zajmuje numerów.

## Ustawienia

`/plugin/settings/Netella-Reg`: włączanie modułów, uprawnienia per rola, karencja, sposób
traktowania urządzeń wyłączonych i nazwa bazy. Zmiana ustawień też trafia do dziennika.

Uprawnienia: przeglądanie, edycja, usuwanie i administracja. Wyższe prawo obejmuje niższe.
Administrator LibreNMS ma zawsze wszystkie prawa. Pusta lista przy przeglądaniu oznacza każdego
zalogowanego, a przy pozostałych tylko administratora. Ręczną synchronizację z UI może uruchomić
tylko osoba z prawem administracji.

## Architektura

| Ścieżka | Zawartość |
|---|---|
| `src/NetellaRegProvider.php` | rejestracja modułów, hooków, komend, tras i widoków |
| `src/Plugin.php` | nazwa, `VERSION`, domyślna baza |
| `src/Contracts/RegModule.php` | kontrakt modułu |
| `src/Core/` | `Db` (połączenie, schemat), `Settings`, `Access`, `AuditLog`, `Snapshot` + `SnapshotDiff`, `SyncRunner`, `ModuleRegistry`, `SettingsWatcher` |
| `src/Hooks/` | `Page`, `MenuEntry`, `Settings`, `DeviceOverview` |
| `src/Http/ApiController.php` | API `GET/POST /plugin/Netella-Reg/api/<moduł>/<akcja>` |
| `src/Console/` | `netella-reg:install`, `:sync`, `:doctor` |
| `src/Modules/Vlan/` | moduł VLAN: `VlanCollector` (odczyt LibreNMS), `VlanName` (VLAN z nazwy interfejsu), `VlanRange`, `VlanIndex` (statusy) |
| `resources/views/` | strona, ustawienia, zakładki modułów, panel urządzenia |
| `tests/run_tests.php` | testy logiki bez Laravela |

### Tabele rdzenia

| Tabela | Zawartość |
|---|---|
| `reg_schema` | wersja schematu per moduł (`core`, `vlan`, …) |
| `reg_audit` | dziennik zmian, tylko dopisywany: kto, kiedy, co, stan przed i po (JSON) |
| `reg_snapshot` | ostatni stan LibreNMS per moduł i rekord: hash, dane, `first_seen`, `last_seen`, `gone_at` |
| `reg_state` | klucz-wartość: wyniki synchronizacji, ostatnio widziane ustawienia |

### Synchronizacja i dziennik

`netella-reg:sync` woła `sync()` każdego włączonego modułu. Moduł zwraca bieżący stan jako
`klucz => dane`, a `Core\Snapshot` porównuje go z migawką i zapisuje zdarzenia:
`lnms_appeared`, `lnms_changed` (tylko zmienione pola), `lnms_gone`. Rekord, który zniknął,
zostaje w migawce z `gone_at`. Pierwszy import daje jeden wpis `initial_import`.

### Nowy moduł

1. Klasa implementująca `Contracts\RegModule` (najprościej: dziedzicząca po
   `Modules\AbstractModule`), z unikalnym kluczem `[a-z][a-z0-9_]`.
2. Własne tabele z prefiksem `reg_<klucz>_` w `migrate()`, wersja w `schemaVersion()`.
3. `sync()` zwraca podsumowanie. Do wykrywania zmian w LibreNMS: `$ctx->snapshot->apply(...)`.
4. `api()` obsługuje `/api/<klucz>/<akcja>`, a `apiPermission()` mówi, jakie prawo jest
   wymagane (domyślnie GET = przeglądanie, inne = edycja). Zmiany użytkownika zapisuje
   `AuditLog::user(...)`.
5. Rejestracja: w `NetellaRegProvider::register()` albo z innego pakietu:
   `$this->app->afterResolving(ModuleRegistry::class, fn ($r) => $r->register(new MojModul()));`

## Rozwój

```bash
php tests/run_tests.php
for f in $(find src routes tests -name '*.php'); do php -l "$f"; done
```

Przy każdej zmianie funkcjonalnej podbij `Plugin::VERSION` i dopisz wpis w `CHANGELOG.md`.

## Licencja

GPL-3.0-or-later.
