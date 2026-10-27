# Changelog

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
