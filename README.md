# Netella-Reg

Wtyczka LibreNMS (plugin v2, pakiet composera) do inwentaryzacji VLAN-ów i adresacji IP.
Dane bierze z bazy LibreNMS. Każdą zmianę, zarówno wykrytą w LibreNMS, jak i wprowadzoną
przez użytkownika, zapisuje w dzienniku. Jest zbudowana z modułów, a nowe moduły dokłada się
bez zmian w rdzeniu.

Stan na 0.3.0:
- **rdzeń**: własna baza, schemat wersjonowany per moduł, uprawnienia per rola, dziennik zmian
  (jedna akcja = jedna linia), migawki stanu LibreNMS, synchronizacja z crona, komendy `lnms`;
- **moduł VLAN**: mapa numerów 1–4094 w całej sieci, sprawdzanie zakresu, wpisy ręczne i rezerwacje
  (grupy), szukanie wolnych numerów, szczegóły i historia numeru;
- **moduł Adresacja IP**: drzewo IPv4 grupowane według masek, zajętość i wolne bloki, wyszukiwanie,
  szukanie wolnej podsieci, wpisy ręczne (adresy, zakresy, agregaty, pule, rezerwacje), przestrzenie
  adresowe, uwagi (duplikaty, adresy poza sieciami). IPv6 jest
  zapisywane, ale bez drzewa.

### Adresacja IP

Źródło: `ipv4_addresses`, `ipv6_addresses`, `ports`, `devices` w LibreNMS. Każdy adres na porcie
to jeden rekord (host, interfejs, opis portu, maska, VLAN z nazwy interfejsu).

- **Drzewo:** sieci z interfejsów (np. `45.10.136.0/24`) i prefiksy ręczne. Korzeniami są najszersze
  prawdziwe prefiksy: nad `45.10.136.0/24` nie ma `45.0.0.0/8`, bo reszta klasy nie jest nasza. Swój
  przydział publiczny dodaj jako agregat, a sieci ułożą się pod nim. Adresacja wewnętrzna (ustawienie,
  domyślnie RFC 1918 i CGNAT 100.64.0.0/10) jest korzeniem jak agregat, o ile coś w niej leży. Wewnątrz
  agregatów są poziomy grupowania (domyślnie /8 → /16 → /24, ustawienie „Poziomy grupowania”). Adresy
  poza wszystkimi prefiksami (np. publiczne loopbacki) i sieci drobniejsze niż /24 poza agregatami
  trafiają do grupy /24 („poza znaną adresacją”), więc w korzeniu nie ma masek dłuższych niż /24.
  Wolne miejsce jest liczone i pokazywane wszędzie, także w tych grupach. Pasek pokazuje zajętość.
  W sieciach liczą się hosty, bez adresu sieci i rozgłoszeniowego.
- **Szczegóły sieci:** interfejsy z LibreNMS, wpis ręczny, wolne bloki (w agregatach i grupach) albo
  wolne adresy hostów (w sieciach), historia. Kliknięcie wolnego bloku otwiera formularz dodania.
- **Szukaj:** adres (rozwija drzewo do niego), prefiks albo tekst (opisy, urządzenia, interfejsy).
- **Wolne /N w prefiksie:** pierwsze wolne, wyrównane bloki danej maski w dowolnym prefiksie.
- **Dodaj adresację** (prawo edycji): `10.0.0.0/24` (prefiks z rolą agregat, pula, rezerwacja albo
  sieć używana), `10.0.0.5`, `10.0.0.10-20`, `10.0.0.10-10.0.0.40` albo lista adresów (do 1024 naraz,
  jedna grupa i jeden wpis w dzienniku). Wymagana nazwa, opcjonalnie opis, właściciel, VLAN, urządzenia
  (pole podpowiada hosty z LibreNMS, można też wpisać własny tekst).
- **Zakresy (pule):** zakres `a-b` można zapisać jako jeden wpis zamiast grupy adresów (pole „jako”
  w formularzu; powyżej 1024 adresów tylko tak). Role: pula DHCP, pula PPPoE, NAT, adresy statyczne,
  rezerwacja. Granice nie muszą pasować do maski, ale zakres musi leżeć w całości w jednej sieci
  albo prefiksie (bez adresu sieci i rozgłoszeniowego) i nie może nachodzić na inny zakres. Agregat
  dodany na sieć z LibreNMS zmienia ją w cały blok: tak zapisuje się np. pulę NAT `45.10.136.0-255`,
  gdy router ma adresy z tej puli z maską /24. W drzewie
  zakres jest wierszem z fioletową kreską. Liczy się jako zajęty, a po rozwinięciu pokazuje adresy
  w środku i wolne miejsce. Zajętość zakresu to adresy z LibreNMS i wpisów ręcznych w nim. Ciągłą
  grupę adresów zamienia się na zakres przyciskiem „Na zakres” w liście wpisów ręcznych.
- **Pobierz z RIPE** (prawo edycji, agregaty według numeru AS): po podaniu numeru AS wtyczka pobiera prefiksy ogłaszane w BGP
  (RIPEstat), obiekty route z `origin:` tego AS i przydziały organizacji z obiektu aut-num (baza RIPE).
  Do każdego prefiksu IPv4 pokazuje przydział z bazy RIPE (netname, status, organizacja), stan
  w rejestrze i liczbę sieci LibreNMS w środku. Użytkownik zaznacza, które dodać jako agregaty; domyślnie
  zaznaczone są te, których nie ma w rejestrze i które nie leżą w agregacie ani w szerszym prefiksie
  z listy. IPv6 jest pokazywane, ale jeszcze nie dodawane. Serwer musi mieć dostęp do `stat.ripe.net`
  i `rest.db.ripe.net` (proxy z ustawień LibreNMS).
- **Adresacja spoza agregatów:** domyślnie drzewo pokazuje adresację wewnętrzną (ustawienie, np.
  10/8, 172.16/12, 192.168/16), w której coś leży, oraz prefiksy dodane ręcznie albo z RIPE. Sieci
  i adresy z LibreNMS poza nimi (np. połączenia BGP na adresacji peerów) są ukryte; pokazuje je
  przełącznik „Pokaż adresację spoza agregatów”. Wyszukany ukryty adres ma podpowiedź, jak go zobaczyć.
- **Wyłączone hosty:** adresy z hostów wyłączonych w LibreNMS są domyślnie ukryte (drzewo i wolne
  miejsce traktują je jak wolne). Pokazuje je przełącznik „Pokaż adresy z wyłączonych hostów” (wybór
  zapamiętany w przeglądarce); wtedy mają plakietkę „host wyłączony”. Dodanie takiego adresu ręcznie
  i tak jest blokowane. Przy ustawieniu „ignorowane” przełącznika nie ma.
- **Blokady:** adresu widocznego w LibreNMS nie da się dodać. Sieci z LibreNMS nie da się dodać jako
  „sieć używana” (można jako agregat, żeby ją opisać). Rezerwacji nie da się dodać, gdy LibreNMS ma
  w prefiksie adresy. Przejęcie przez LibreNMS działa jak w module VLAN.
- **Przestrzenie adresowe:** „Global” plus przestrzenie dodawane przez administratora (przycisk „+”).
  Adres z LibreNMS trafia do przestrzeni, której pole VRF równa się jego `context_name`.
- **Uwagi:** ten sam adres na kilku aktywnych urządzeniach, maski krótsze niż /8, adresy poza
  sieciami (np. aliasy /32), rezerwacje (prefiksy i zakresy), w których pojawiły się adresy z LibreNMS,
  i zakresy, które przecięła nowa sieć albo które straciły prefiks.
- Pomijane domyślnie: 127.0.0.0/8, 169.254.0.0/16, 0.0.0.0/8, interfejsy Junipera `emN.x` i `jsrv`,
  IPv6 link-local. Ustawienia „Pomijane zakresy” i „Pomijane interfejsy”.

Linki: `/plugin/Netella-Reg?ip=45.10.136.1#ipam`, `/plugin/Netella-Reg?prefix=45.10.136.0/24#ipam`.

Plan kolejnych wersji jest w `../PROPOZYCJA.md` (rozdział 11).

### Adresacja IPv6 (moduł `ipam6`)

Osobna zakładka „Adresacja IPv6”. Wymaga rozszerzenia PHP `gmp` (LibreNMS ma je w pakietach
instalacyjnych; bez niego zakładka pokazuje komunikat, a reszta wtyczki działa). Adresy IPv6 z LibreNMS
zbiera moduł „Adresacja IP”, więc ten moduł nie ma własnej synchronizacji.

- **Drzewo** jak w IPv4: domyślnie adresacja wewnętrzna (ustawienie, domyślnie ULA `fc00::/7`), w której
  coś leży, i prefiksy dodane ręcznie albo z RIPE. Reszta za przełącznikami „Pokaż adresację spoza
  agregatów” i „Pokaż adresy z wyłączonych hostów”. W korzeniu nie ma masek dłuższych niż /48. Wewnątrz
  agregatów poziomy /32 → /48 → /56 → /64 (ustawienie).
- **Zajętość** w prefiksach /64 i szerszych liczy się w blokach /64 (adres albo /127 zajmuje całą swoją
  /64); w węższych - w adresach. Wolne miejsce to bloki prefiksów, kliknięcie dodaje wpis.
- **Prefiksy ręczne:** agregat, sieć używana, rezerwacja (te same blokady co w IPv4), nazwa wymagana.
- **Pobierz z RIPE:** jak w IPv4, dodaje prefiksy IPv6 jako agregaty.
- Na razie bez pojedynczych adresów ręcznych, zakresów i „Wolne /N” (kolejny etap).

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
  „Zakres” sprawdza np. `1400-1450` (także `1400 to 1450` i listy po przecinku). Zakres można też
  zaznaczyć na mapie: przeciągnięcie myszą, Ctrl + klik (dokłada albo zdejmuje numer), Shift + klik
  (od ostatniego numeru), Esc czyści; zaznaczenie od razu jest sprawdzane. „Znajdź N
  kolejnych wolnych” szuka pierwszego wolnego ciągu numerów;
- **Dodaj VLAN** (prawo edycji): wpis ręczny dla numeru lub zakresu (do 500 numerów naraz).
  Wymagany jest opis. Opcjonalnie: nazwa, właściciel, status (używany albo rezerwacja), urządzenia
  i komentarz do dziennika. Wpis można dodać też z wyniku sprawdzania zakresu i ze szczegółów
  numeru. Wszystkie wpisy są na liście „Wpisy ręczne” pod mapą. Edycja wymaga prawa edycji,
  a usunięcie prawa usuwania. **Blokada:** nie da się dodać numeru, który LibreNMS widzi teraz
  na urządzeniu aktywnym albo wyłączonym (statusy „LibreNMS” i „nieaktualny”). Jeden taki numer
  odrzuca całe dodawanie, a komunikat podaje zablokowane numery i resztę zakresu. Numer, który
  zniknął z LibreNMS i jest w karencji, można dodać;
- **termin ważności** (pole „Ważne do” we wpisach VLAN i adresacji, opcjonalne): wpis po terminie
  nie zwalnia się sam. Nadal zajmuje numer albo adres, ale jest oznaczony jako „termin minął”: na mapie
  VLAN-ów kratka w paski, w drzewie adresacji czerwona plakietka, a w liście „Uwagi” adresacji jest
  lista wpisów po terminie i z terminem w ciągu 14 dni. Synchronizacja zapisuje w dzienniku jedno
  zdarzenie „termin minął” na grupę. Decyzja (przedłużyć albo usunąć) należy do człowieka. Termin
  nie może być w przeszłości, a wpisy przejęte przez LibreNMS go nie sprawdzają;
- zakładka **Dziennik zmian**: wszystkie zdarzenia z filtrami po module, źródle, zdarzeniu i tekście.

Linki bezpośrednie: `/plugin/Netella-Reg?vlan=1400#vlan`, `/plugin/Netella-Reg?range=1400-1450#vlan`.

### Statusy VLAN-u

| Status | Znaczenie |
|---|---|
| LibreNMS | numer jest na co najmniej jednym aktywnym urządzeniu |
| nieaktualny | numer jest tylko na urządzeniach wyłączonych w LibreNMS (ustawienie „Urządzenia wyłączone”) |
| wpis ręczny | LibreNMS nie widzi numeru, ale jest wpis ręczny ze statusem „używany” |
| rezerwacja | LibreNMS nie widzi numeru, ale jest wpis ręczny ze statusem „rezerwacja” |
| zniknął | numer zniknął z LibreNMS i trwa karencja (domyślnie 30 dni) |
| wolny | nic z powyższych |

Kolejność w tabeli to priorytet: **dane LibreNMS mają pierwszeństwo** przed wpisem ręcznym.
Wpis ręczny może powstać tylko dla numeru, którego LibreNMS nie widzi. Gdy taki numer pojawi się
później w LibreNMS, synchronizacja oznacza wpis jako „przejęty przez LibreNMS”, z datą przejęcia.
Kratka ma wtedy kolor LibreNMS z fioletową dolną połową, a w szczegółach numeru widać, kto i kiedy
dodał go wcześniej ręcznie. Jeśli numer potem zniknie z LibreNMS, wraca status wpisu ręcznego.

Numer jest zajęty, jeśli występuje na **którymkolwiek** urządzeniu w sieci. Źródła w LibreNMS:
tabela `vlans` (definicje), `ports_vlans` (porty) i nazwy interfejsów: `Vlanif1400`, `Vlan1400`,
`Vlan-interface1400`, `irb.1400`, `vlan1600-xyz` (L3) oraz `Po2.100`, `Gi0/0/0.1400`,
`ae0.1400` (subinterfejsy). Dla routerów (ASR, C8500, MX204) tabela `vlans` jest pusta, więc
subinterfejsy są jedynym źródłem. Trunk z `allowed vlan 1-4094` nie zajmuje numerów.

## Ustawienia

`/plugin/settings/Netella-Reg`: włączanie modułów, uprawnienia per rola, karencja, sposób
traktowania urządzeń wyłączonych i nazwa bazy. Zmiana ustawień też trafia do dziennika.

### Kopie zapasowe

Wzorowane na wtyczce PppoeAsr:
- **pełna kopia** bazy wtyczki (wszystkie tabele `reg_*`: wpisy ręczne, przestrzenie, dziennik,
  migawki, stan) i ustawień w pliku `storage/app/netella-reg/netella-reg-RRRRMMDD-GGMMSS-<rodzaj>.json.gz`.
  Synchronizacja robi jedną na dobę (`auto`). Ręcznie: przycisk „Utwórz kopię teraz” w ustawieniach
  albo `lnms netella-reg:backup` (`manual`). Trzymamy `backup_keep` kopii (domyślnie 14). Kopie
  można pobrać z ustawień (tylko administrator wtyczki). Baza wtyczki jest osobna, więc kopie
  LibreNMS jej zwykle nie obejmują;
- **kopia ustawień** `storage/app/netella_reg_settings.json`, zapisywana przy zmianie. Gdy ustawienia
  wtyczki znikną (np. `daily.sh` przeinstaluje pakiet), synchronizacja przywróci je z tej kopii
  i zapisze to w dzienniku;
- **przywracanie** tylko z konsoli: `lnms netella-reg:restore <plik>` pokazuje podgląd (wiersze
  w kopii i teraz), `--force` przywraca, a `--with-settings` przywraca także ustawienia. Przed
  przywróceniem bieżący stan trafia do kopii `pre-restore` (zostają 3 ostatnie). Kopii z nowszym
  schematem niż kod nie da się przywrócić. Starszą migracja podnosi do bieżącej wersji.

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
| `src/Modules/Vlan/` | moduł VLAN: `VlanCollector` (odczyt LibreNMS), `VlanName` (VLAN z nazwy interfejsu), `VlanRange`, `VlanIndex` (statusy), `VlanManual` (wpisy ręczne, grupy) |
| `src/Modules/Ipam/` | moduł adresacji: `IpCalc` (obliczenia IPv4), `IpInput` (wpisywane adresy/zakresy), `IpTree` (drzewo, zajętość, wolne bloki), `IpamCollector`, `IpamIndex` (statusy), `IpamManual`, `IpamSpaces` |
| `resources/views/` | strona, ustawienia, zakładki modułów, link na karcie urządzenia |
| `tests/run_tests.php` | testy logiki bez Laravela |

### Tabele rdzenia

| Tabela | Zawartość |
|---|---|
| `reg_schema` | wersja schematu per moduł (`core`, `vlan`, …) |
| `reg_audit` | dziennik zmian, tylko dopisywany: kto, kiedy, co, stan przed i po (JSON) |
| `reg_snapshot` | ostatni stan LibreNMS per moduł i rekord: hash, dane, `first_seen`, `last_seen`, `gone_at` |
| `reg_state` | klucz-wartość: wyniki synchronizacji, ostatnio widziane ustawienia |
| `reg_vlan_manual` | moduł VLAN: wpisy ręczne (jeden aktywny na numer, `batch_id` = grupa, usuwanie miękkie, `adopted_at` = przejęcie przez LibreNMS) |
| `reg_ipam_manual` | adresacja: wpisy ręczne (adres albo prefiks z rolą, przestrzeń, `batch_id`, usuwanie miękkie, `adopted_at`) |
| `reg_ipam_space` | adresacja: przestrzenie adresowe (Global = 1, pole `vrf` = `context_name` z LibreNMS) |

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
