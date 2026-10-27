# Changelog

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
