# Changelog

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
