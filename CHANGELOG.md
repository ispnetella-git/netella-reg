# Changelog

## 0.6.1 - 2026-10-28

- Adresacja: rola wpisu ręcznego na tym samym prefiksie wygrywa z LibreNMS. Agregat na sieci
  z interfejsu (np. adresy puli NAT z maską /24 na MikroTiku) liczy się jako cały blok, więc zakres
  może objąć adres sieci i rozgłoszeniowy (pula NAT 45.10.136.0-255). Pula i sieć używana dalej
  chronią te adresy.
- Przycisk „Pobierz z RIPE” (było „Agregaty z RIPE”), pole numeru AS bez podpowiedzi.

## 0.6.0 - 2026-10-28

- Adresacja: „Agregaty z RIPE” - po podaniu numeru AS lista prefiksów z RIPEstat (ogłaszane w BGP)
  i bazy RIPE (obiekty route, przydziały organizacji AS) z przydziałem RIPE, stanem w rejestrze
  i liczbą sieci LibreNMS. Wybrane dodaje jako agregaty (każdy osobno w dzienniku). IPv6 tylko
  do podglądu.
- Adresacja: panel szczegółów czyści się przy rozwijaniu drzewa, dodawaniu, wyszukiwaniu i imporcie,
  a spóźniona odpowiedź wcześniejszego kliknięcia nie nadpisuje nowszej.

## 0.5.3 - 2026-10-28

- Jeden odcień fioletu (`#b57edc`) dla wpisów ręcznych wszędzie: mapa VLAN, plakietki i zakresy
  w drzewie adresacji, ramka wpisu ręcznego.
- Mapa VLAN: zaznaczanie zakresu myszą (przeciągnięcie), Ctrl + klik dokłada lub zdejmuje numery,
  Shift + klik zaznacza od ostatniego numeru, Esc czyści. Zaznaczenie trafia do pola „Zakres” i jest
  od razu sprawdzane (z przyciskiem dodania wolnych numerów jako wpis ręczny).

## 0.5.2 - 2026-10-28

- Mapa VLAN: znacznik „LibreNMS + wpis ręczny” to dolna połowa kwadratu w jaśniejszym fiolecie
  (wcześniej cienka kreska 3 px, słabo widoczna). Legenda używa tej samej klasy.

## 0.5.1 - 2026-10-27

- Drzewo adresacji zaczyna się od najszerszych prawdziwych prefiksów (sieć z LibreNMS, agregat),
  a nie od klas /8 i /16, których nie mamy. Poziomy grupowania tylko wewnątrz agregatów. Wolne
  miejsce widać tylko w znanej adresacji.
- Ustawienie „Adresacja wewnętrzna” (domyślnie 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16,
  100.64.0.0/10): te prefiksy są korzeniami jak agregat, gdy coś w nich leży.
- Adresy poza wszystkimi prefiksami są w korzeniu drzewa. „Wolne /N” odrzuca prefiks spoza
  znanej adresacji. Zakres może leżeć w adresacji wewnętrznej.

## 0.5.0 - 2026-10-27

- **Zakresy adresów (pule)** jako jeden wpis ręczny adresacji: `a-b` z rolą pula DHCP, pula PPPoE,
  NAT, adresy statyczne albo rezerwacja, bez limitu 1024 adresów. Zakres musi leżeć w jednej sieci
  albo prefiksie i nie może nachodzić na inny zakres. W drzewie jest wierszem między adresami
  i wolnym miejscem, liczy się jako zajęty i rozwija się do swoich adresów. Szczegóły zakresu,
  wyszukiwanie (adres pokazuje zakres, tekst znajduje zakresy), „Wolne /N” omija zakresy.
- Zamiana ciągłej grupy adresów na zakres („Na zakres”), jeden wpis w dzienniku.
- Uwagi: rezerwacje-zakresy z adresami z LibreNMS i zakresy przecięte przez sieć.
- Prefiks ręczny nie może przeciąć zakresu (objąć w całości może).
- Schemat `ipam` 3 (bez zmian kolumn; kopia z zakresami nie da się przywrócić w starszym kodzie).

## 0.4.0 - 2026-10-27

- **Termin ważności** wpisów ręcznych (pole „Ważne do” w formularzach VLAN i adresacji). Wpis po
  terminie nadal zajmuje numer albo adres, ale jest oznaczony: na mapie VLAN-ów kratka w paski
  (z licznikiem w legendzie), w drzewie adresacji plakietka „termin minął”, w listach wpisów
  ręcznych kolumna „Ważne do” (czerwona po terminie, pomarańczowa na 14 dni przed). Adresacja:
  w „Uwagach” lista wpisów po terminie i z bliskim terminem. Synchronizacja zapisuje jedno
  zdarzenie „termin minął” (`expired`) na grupę. Schematy: `vlan` 4, `ipam` 2 (kolumny
  `valid_until`, `expired_at`).
- **Kopie zapasowe** (jak w PppoeAsr): pełna kopia bazy wtyczki i ustawień raz na dobę
  z synchronizacji, ręcznie z ustawień albo `lnms netella-reg:backup`, pobieranie z ustawień,
  przywracanie `lnms netella-reg:restore` (podgląd bez `--force`, kopia bieżącego stanu przed
  przywróceniem). Kopia ustawień `storage/app/netella_reg_settings.json` i ich automatyczne
  przywrócenie, gdy znikną. Ustawienie `backup_keep` (domyślnie 14). Doctor pokazuje ostatnią kopię.
- Nowa trasa `/plugin/Netella-Reg/backup/<plik>`: po aktualizacji trzeba przebudować cache tras
  (`php artisan route:cache`).

## 0.3.2 - 2026-10-27

- Mapa VLAN-ów: większa czcionka numerów wierszy (12 px zamiast 10 px). Po najechaniu na kratkę
  od razu pokazuje się dymek z numerem VLAN i statusem (zamiast natywnego dymka z opóźnieniem).

## 0.3.1 - 2026-10-27

- Adresacja, poprawka: poziomy grupowania (/8, /16, /24) nie powstają już wewnątrz prawdziwej sieci.
  Wcześniej np. 172.19.0.1 z sieci 172.19.0.0/16 trafiał do sztucznego 172.19.0.0/24 i był pokazywany
  jako „adres poza siecią”.
- Drzewo: wolne miejsce między zajętymi pozycjami jest widoczne niebieską kursywą. W agregatach
  i grupach to wyrównane prefiksy CIDR, w sieciach przedziały wolnych hostów. Kliknięcie otwiera
  formularz dodania z wpisanym prefiksem lub zakresem (rola domyślnie „sieć używana”).
- Każdy prefiks da się rozwinąć, także pusty (pokazuje wtedy swoje wolne miejsce).
- Czcionka adresów i prefiksów w drzewie większa o 1 px. Sekcja „Adresy poza sieciami” nazywa się
  teraz „Adresy bez sieci” i ma dokładniejszy opis.

## 0.3.0 - 2026-10-27

- Nowy moduł **Adresacja IP** (`ipam`, schemat 1, tabele `reg_ipam_manual` i `reg_ipam_space`).
  - Synchronizacja adresów z LibreNMS (`ipv4_addresses`, `ipv6_addresses`) przez migawkę i dziennik.
    Zdarzenia mają zakres = adres jako liczba, więc historia sieci pokazuje zmiany jej adresów.
  - Drzewo IPv4: sieci z interfejsów, prefiksy ręczne i poziomy grupowania (ustawienie, domyślnie
    8,16,24). Zajętość, wolne bloki i wolne hosty, szczegóły sieci i adresu z historią.
  - Wyszukiwanie (adres, prefiks, tekst), szukanie wolnych bloków /N w prefiksie.
  - Wpisy ręczne: adresy, zakresy i listy (grupa, jeden wpis w dzienniku), prefiksy z rolą agregat,
    pula, rezerwacja albo sieć używana. Blokady dla danych, które LibreNMS już ma. Przejęcie przez LibreNMS.
  - Przestrzenie adresowe (Global + dodawane przez administratora, dopasowanie po VRF).
  - Uwagi: duplikaty adresów na aktywnych urządzeniach, maski krótsze niż /8, adresy poza sieciami,
    rezerwacje z adresami z LibreNMS.
  - Ustawienia: pomijane zakresy, pomijane interfejsy (domyślnie wewnętrzne Junipera), poziomy grupowania.
  - Panel adresów na stronie urządzenia.
- Rdzeń: `AuditLog::page()` przyjmuje listę prefiksów kluczy i filtr `overlaps`, a `Snapshot::apply()`
  przyjmuje funkcję zakresu rekordu.
- Dziennik rozpoznaje rekordy adresacji („IP 10.0.0.5 · urządzenie interfejs”, „10.0.0.0/24 (wpis)”).

## 0.2.3 - 2026-10-27

- Dziennik: jedna akcja użytkownika to jeden wpis. Dodanie zakresu (np. 1313-1399) zapisuje jeden
  wpis z listą numerów zamiast osobnego wpisu na każdy VLAN. To samo dotyczy edycji i usunięcia
  grupy oraz przejęcia przez LibreNMS kilku numerów naraz. Nowe kolumny `reg_audit.range_from/range_to`
  (schemat rdzenia 2): historia pojedynczego numeru znajduje także wpisy grupowe.
- Wpisy ręczne dodane razem tworzą grupę (`reg_vlan_manual.batch_id`, schemat `vlan` 3). Lista
  „Wpisy ręczne” pokazuje jedną pozycję na grupę, z przyciskami „Edytuj grupę” i „Usuń grupę”.
  Edycja pojedynczego numeru odłącza go od grupy.
- Migracja scala istniejące wpisy: numery dodane jednym kliknięciem (ten sam czas, autor i opis)
  dostają wspólną grupę, a ich wpisy „dodano” w dzienniku zamieniają się w jeden wpis z komentarzem
  „scalono N wpisów”.
- Dziennik w jednej linii: zmiany jako „pole: przed → po · …”, czytelne nazwy rekordów
  („VLAN 1313-1399 (87)”), komentarz w tej samej linii. Kliknięcie rozwija pełną treść.

## 0.2.2 - 2026-10-27

- Blokada: nie można dodać ręcznie numeru VLAN, który LibreNMS widzi teraz (na urządzeniu aktywnym albo
  wyłączonym). Jeden zablokowany numer odrzuca całe dodawanie (HTTP 409). Komunikat podaje zablokowane
  numery i resztę zakresu. Numer w karencji po zniknięciu z LibreNMS można dodać.
- W szczegółach numeru widocznego w LibreNMS nie ma przycisku „Dodaj wpis ręczny”.

## 0.2.1 - 2026-10-27

- API `vlan/manual_update`: edycja samych wybranych pól (np. statusu) bez przesyłania opisu. Wcześniej
  kończyła się błędem „Opis jest wymagany”. Formularz w UI nie był dotknięty, bo wysyła wszystkie pola.

## 0.2.0 - 2026-10-27

- Moduł VLAN: dodawanie VLAN-ów ręcznie (przycisk „Dodaj VLAN”, także prosto z wyniku
  sprawdzania zakresu i ze szczegółów numeru). Wymagane są numer lub zakres (do 500 numerów
  naraz) oraz opis. Opcjonalnie: nazwa, właściciel, status (używany albo rezerwacja),
  urządzenia i komentarz do dziennika.
- Edycja (prawo „edycja”) i usuwanie (prawo „usuwanie”) wpisów. Usunięcie jest miękkie, a każda
  zmiana trafia do dziennika ze stanem przed i po.
- Nowe statusy na mapie: wpis ręczny i rezerwacja. Wpis przy numerze widocznym w LibreNMS jest
  adnotacją (pasek u dołu kratki), a status pochodzi z LibreNMS.
- Przejęcie przez LibreNMS: gdy numer wpisany ręcznie pojawi się na aktywnym urządzeniu,
  synchronizacja ustawia `adopted_at` i zapisuje zdarzenie „przejęty przez LibreNMS”. Dane
  LibreNMS mają wtedy pierwszeństwo, a wpis pokazuje, kto i kiedy dodał go wcześniej.
- Lista „Wpisy ręczne” w zakładce VLAN-y. Szukanie wolnych numerów pomija wpisy ręczne.
- Schemat modułu `vlan` w wersji 2: tabela `reg_vlan_manual`.

## 0.1.0 - 2026-10-27

Pierwsza wersja.

- Rdzeń: własna baza `librenms_netella_reg`, schemat wersjonowany per moduł (`reg_schema`),
  dziennik zmian (`reg_audit`), migawki stanu LibreNMS (`reg_snapshot`), magazyn stanu (`reg_state`).
- Kontrakt modułu `RegModule` i `ModuleRegistry`. Moduły z innych pakietów rejestrują się bez zmian w rdzeniu.
- Uprawnienia per rola: przeglądanie, edycja, usuwanie, administracja, z fallbackiem na `users.level`.
- Synchronizacja `lnms netella-reg:sync` z blokadą przed równoległymi przebiegami. Zmiany w LibreNMS
  trafiają do dziennika (pojawił się, zmienił, zniknął, wrócił). Zmiany ustawień też są logowane.
- Komendy `netella-reg:install` i `netella-reg:doctor`.
- Moduł VLAN (tylko odczyt): mapa 1–4094 w całej sieci, sprawdzanie zakresu, szukanie wolnych
  numerów, szczegóły i historia numeru, panel na stronie urządzenia. Źródła: `vlans`, `ports_vlans`
  i nazwy interfejsów (Vlanif/Vlan/irb oraz subinterfejsy, czyli VLAN-y routerów ASR, C8500 i MX204).
- Statusy: LibreNMS, nieaktualny (tylko urządzenia wyłączone), zniknął (karencja 30 dni), wolny.
