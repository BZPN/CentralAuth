# MultiCentralAuth - Dokumentacja Rozszerzenia MediaWiki

**MultiCentralAuth** to zaawansowane rozszerzenie dla MediaWiki (wymagane MediaWiki ≥ 1.45.1), które umożliwia zarządzanie centralną autentykacją kont użytkowników na wielu farmach wiki (np. Wikimedia, Miraheze oraz własne/customowe farmy) oraz na lokalnych instancjach wiki.

Rozszerzenie zapewnia pełną kontrolę nad łączeniem kont, zarządzaniem podłączonymi serwisami, składaniem i rozpatrywaniem wniosków o połączenie kont, nakładaniem i zdejmowaniem blokad CentralAuth, konfigurowaniem farm wiki oraz wysyłaniem powiadomień w systemie Echo.

---

## Spis Treści
1. [Czym jest MultiCentralAuth?](#czym-jest-multicentralauth)
2. [Jak działa rozszerzenie?](#jak-działa-rozszerzenie)
3. [Główne Funkcje](#główne-funkcje)
4. [Strony Specjalne](#strony-specjalne)
5. [Uprawnienia i Grupy Użytkowników](#uprawnienia-i-grupy-użytkowników)
6. [Struktura Bazy Danych](#struktura-bazy-danych)
7. [System Blokad CentralAuth](#system-blokad-centralauth)
8. [System Wniosków o Połączenie (CAMergeRequest)](#system-wniosków-o-połączenie-camergerequest)
9. [Integracja z Powiadomieniami Echo](#integracja-z-powiadomieniami-echo)
10. [Rejestry Zdarzeń (Logi)](#rejestry-zdarzeń-logi)
11. [Struktura Plików Projektu](#struktura-plików-projektu)

---

## Czym jest MultiCentralAuth?

W tradycyjnym MediaWiki rozszerzenie `CentralAuth` obsługuje pojedynczą sieć wiki (np. Wikimedia). **MultiCentralAuth** rozszerza tę koncepcję na **wiele niezależnych farm wiki** jednocześnie.

Pozwala ono połączyć jedno lokalne konto użytkownika w MediaWiki z jego kontami zewnętrznymi na różnych farmach (np. Wikimedia Meta/Wikipedia, Miraheze Meta/wikis oraz niestandardowych farmach skonfigurowanych przez administratorów).

---

## Jak działa rozszerzenie?

1. **Dynamiczne Farmy i Pobieranie Danych (ExternalCAProvider)**:
   - Rozszerzenie odczytuje skonfigurowane farmy z bazy danych (`mca_farms`, `mca_farm_wikis`) oraz posiada domyślne wpisy dla Wikimedii (`wm`) i Miraheze (`mh`).
   - Przy użyciu `HttpRequestFactory` komunikuje się z zewnętrznymi API MediaWiki (moduł `meta=globaluserinfo` dla farm CentralAuth oraz `list=users` dla metadanych poszczególnych wiki).
2. **Mechanizm Blokady Logowania (MCAPreAuthenticationProvider)**:
   - Integracja z systemem autentykacji MediaWiki (`AuthManagerAutoConfig` / `AbstractPreAuthenticationProvider`).
   - Podczas próby logowania użytkownika sprawdzana jest tabela `mca_locks`. Jeśli użytkownik posiada aktywną blokadę CentralAuth (terminową lub bezterminową), proces logowania zostaje odrzucony ze stosownym komunikatem błędu.
3. **Prezentacja i Powiadomienia**:
   - Rozszerzenie generuje czytelne tabele z listą połączonych wiki, metodą połączenia, liczbą edycji, datą rejestracji, grupami i statusem zablokowania.
   - W przypadku aktywnych blokad CentralAuth, na stronie wkładu użytkownika (`Special:Contributions`) wyświetlany jest boks ostrzegawczy.
   - Akcje łączenia kont, rozłączania, przesyłania wniosków i ich rozpatrywania wyzwalają zdarzenia powiadomień powiązane z rozszerzeniem Echo.

---

## Główne Funkcje

* **Obsługa wielu farm wiki**: Domyślne wsparcie dla Wikimedii i Miraheze oraz możliwość dodawania dowolnych własnych farm MediaWiki za pomocą panelu administracyjnego.
* **Łączenie i rozłączanie kont (Merge / Unmerge)**: Przypisywanie zewnętrznych kont globalnych do konta lokalnego i ich odpinanie.
* **Zarządzanie poszczególnymi wiki (ManageCA)**: Ręczne dodawanie pojedynczych wiki do profilu użytkownika lub ich usuwanie/ukrywanie (suppression).
* **System wniosków o połączenie kont (Merge Request System)**: Zwykli użytkownicy mogą składać wnioski o połączenie kont podając zewnętrzną nazwę oraz link do edycji potwierdzającej (diff-of-proof).
* **Blokady kont CentralAuth (Lock / Unlock)**: Możliwość zablokowania konta CentralAuth na określony czas lub bezterminowo, co natychmiastowo uniemożliwia logowanie na wiki.
* **Powiadomienia Echo**: Zintegrowane powiadomienia w czasie rzeczywistym o zmianach statusu konta i wniosków.
* **Dedykowane rejestry zdarzeń (Audit Logs)**: Pełny audit log dla operacji na kontach (`mca-log`) oraz operacji blokowania (`mca-lock-log`).

---

## Strony Specjalne

Rozszerzenie dostarcza 10 stron specjalnych:

| Strona Specjalna | Klasa PHP | Wymagane Uprawnienie | Opis |
| :--- | :--- | :--- | :--- |
| `Special:CentralAuth` | `SpecialCentralAuth` | brak (publiczna) | Wyświetla szczegółowe informacje o globalnym koncie użytkownika, liczbie edycji, połączonych wiki na poszczególnych farmach, blokadach i grupach. |
| `Special:CentralAuthMerge` | `SpecialCentralAuthMerge` | `ca-merge` | Służy administratorom do ręcznego połączenia konta lokalnego z kontami na farmach (np. Wikimedia, Miraheze). |
| `Special:CentralAuthUnmerge` | `SpecialCentralAuthUnmerge` | `ca-merge` | Służy do rozłączania konta lokalnego od wybranych farm lub ze wszystkich farm naraz. |
| `Special:ManageCA` | `SpecialManageCA` | `ca-manage` | Umożliwia dodawanie pojedynczych wiki do profilu użytkownika oraz usuwanie/ukrywanie zaznaczonych wiki z listy. |
| `Special:CAMergeRequest` | `SpecialCAMergeRequest` | `ca-request` | Strona dla użytkowników do wysyłania wniosków o połączenie kont wraz z potwierdzeniem tożsamości (diff-of-proof). |
| `Special:CAMergeRequestQueue` | `SpecialCAMergeRequestQueue` | `ca-request-view` | Kolejka wniosków o połączenie kont. Zwykły użytkownik widzi swoje wnioski, a administrator z `ca-merge` widzi i rozpatruje wszystkie wnioski. |
| `Special:LockCAAccount` | `SpecialLockCAAccount` | `ca-lock` | Pozwala na zablokowanie konta CentralAuth użytkownika (wybór okresu blokady, powodu z listy lub własnego, oraz opcjonalne rozłączenie wszystkich kont). |
| `Special:UnlockCAAccount` | `SpecialUnlockCAAccount` | `ca-lock` | Zdejmowanie blokady CentralAuth z konta użytkownika z podaniem uzasadnienia. |
| `Special:CALockList` | `SpecialCALockList` | brak (publiczna) | Przeglądanie rejestru nałożonych blokad CentralAuth z możliwością filtrowania po nazwie użytkownika, sortowania i podglądu szczegółów (`Special:CALockList/view/<id>`). |
| `Special:CentralAuthSettings` | `SpecialCentralAuthSettings` | `ca-admin` | Panel zarządzania konfiguracją dynamicznych farm wiki (dodawanie, edycja, usuwanie farm i ich adresów API oraz listy należących do nich wiki). |

---

## Uprawnienia i Grupy Użytkowników

W pliku `extension.json` zdefiniowane są następujące uprawnienia (rights) i przypisania do domyślnych grup:

| Uprawnienie | Opis | Domyślna grupa |
| :--- | :--- | :--- |
| `ca-request` | Składanie wniosków o połączenie kont na `Special:CAMergeRequest` | `user` |
| `ca-manage` | Zarządzanie podłączonymi wiki na `Special:ManageCA` | `user`, `sysop` |
| `ca-request-view` | Przeglądanie wniosków o połączenie w kolejce `Special:CAMergeRequestQueue` | `user`, `sysop` |
| `ca-merge` | Ręczne łączenie (`Special:CentralAuthMerge`), rozłączenie (`Special:CentralAuthUnmerge`) oraz akceptowanie/odrzucanie wniosków | `sysop` |
| `ca-lock` | Nakładanie i zdejmowanie blokad CentralAuth (`Special:LockCAAccount`, `Special:UnlockCAAccount`) | `sysop` |
| `ca-admin` | Zarządzanie ustawieniami farm wiki na `Special:CentralAuthSettings` | `sysop` |

---

## Struktura Bazy Danych

Rozszerzenie wykorzystuje 8 tabel w bazie danych MediaWiki:

1. **`mca_external_userids`**:
   - `meu_user_id` (INT UNSIGNED, PK) - ID lokalnego użytkownika.
   - `meu_farm_id` (VARBINARY(32), PK) - ID farmy (np. `wm`, `mh`).
   - `meu_external_username` (VARBINARY(255)) - Zewnętrzna nazwa użytkownika na danej farmie.
2. **`mca_external_ids`** *(Tabela legacy/fallback)*:
   - `mei_user_id` (INT UNSIGNED, PK) - ID użytkownika.
   - `mei_wm_username`, `mei_mh_username` (VARBINARY(255)) - Zewnętrzne nazwy użytkownika.
3. **`mca_local_attachments`**:
   - `mla_user_id` (INT UNSIGNED, PK) - ID użytkownika.
   - `mla_wiki_id` (VARBINARY(255), PK) - Identyfikator/domena ręcznie podłączonej wiki.
4. **`mca_suppressed_wikis`**:
   - `msw_user_id` (INT UNSIGNED, PK) - ID użytkownika.
   - `msw_wiki_id` (VARBINARY(255), PK) - Identyfikator/domena ukrytej/usuniętej wiki z połączonej farmy.
5. **`mca_merge_requests`**:
   - `mmr_id` (INT UNSIGNED, PK) - Losowy 8-cyfrowy identyfikator wniosku.
   - `mmr_user_id` (INT UNSIGNED) - ID wnioskodawcy.
   - `mmr_farm` (VARBINARY(32)) - Wybrana farma (`wm`, `mh`, `both`).
   - `mmr_external_data` (BLOB) - Dane zewnętrznego konta i link do diffa potwierdzającego w formacie JSON.
   - `mmr_comment` (BLOB) - Komentarz wnioskodawcy.
   - `mmr_status` (VARBINARY(16)) - Status wniosku (`open`, `done`, `rejected`).
   - `mmr_timestamp` (BINARY(14)) - Data złożenia wniosku.
   - `mmr_closed_by`, `mmr_closed_comment`, `mmr_closed_timestamp` - Dane dotyczące zamknięcia wniosku.
6. **`mca_locks`**:
   - `mcl_id` (INT UNSIGNED, PK, AUTO_INCREMENT) - ID blokady.
   - `mcl_user_id` (INT UNSIGNED) - ID zablokowanego użytkownika.
   - `mcl_reason` (VARBINARY(255)) - Powód zablokowania.
   - `mcl_expiry` (VARBINARY(14)) - Czas wygaśnięcia lub `'infinity'`.
   - `mcl_by` (INT UNSIGNED) - ID administratora nakładającego blokadę.
   - `mcl_timestamp` (VARBINARY(14)) - Data nałożenia blokady.
7. **`mca_farms`**:
   - `mf_id` (VARBINARY(32), PK) - Identyfikator farmy.
   - `mf_display_name` (VARBINARY(255)) - Wyświetlana nazwa farmy.
   - `mf_api_url` (VARBINARY(255)) - Adres API wiersza MediaWiki.
   - `mf_is_centralauth` (TINYINT(1)) - Czy farma korzysta z CentralAuth.
   - `mf_header_msg` (VARBINARY(255)) - Klucz wiadomości i18n dla nagłówka tabeli.
8. **`mca_farm_wikis`**:
   - `mfw_farm_id` (VARBINARY(32), PK) - ID farmy.
   - `mfw_wiki_id` (VARBINARY(255), PK) - Domena/wiki przypisana do farmy.

---

## System Blokad CentralAuth

1. **Nakładanie blokady (`Special:LockCAAccount`)**:
   - Administrator z uprawnieniem `ca-lock` określa cel, okres trwania (od 1 godziny do "infinity"), powód (z listy rozwijanej lub własny tekst) oraz opcjonalną flagę unmerge (usuwającą wpisy w `mca_external_userids`).
2. **Egzekwowanie blokady (`MCAPreAuthenticationProvider`)**:
   - Podczas próby logowania rozszerzenie odczytuje rekord z `mca_locks`. Jeśli blokada jest aktywna, logowanie zostaje zablokowane i zwracany jest komunikat błędu z powodem i czasem trwania.
3. **Powiadomienia na stronach wkładu (`Hooks::onSpecialContributionsBeforeMainOutput`)**:
   - Gdy jakikolwiek użytkownik przegląda `Special:Contributions/NazwaUżytkownika` dla zablokowanego konta CentralAuth, wyświetlany jest boks ostrzegawczy wraz ze szczegółami ostatniego wpisu w logu blokad.

---

## System Wniosków o Połączenie (CAMergeRequest)

1. **Złożenie wniosku (`Special:CAMergeRequest`)**:
   - Użytkownik wybiera farmę (Wikimedia, Miraheze lub obie), podaje nazwę użytkownika na danej farmie oraz link do diffa wkładu potwierdzającego własność konta.
   - System generuje unikalny, 8-cyfrowy ID wniosku i zapisuje go w stanie `open`.
   - Wyzwalany jest hook `MCAMergeRequestSubmitted`, wysyłający powiadomienia Echo do użytkowników z uprawnieniem `ca-merge`.
2. **Obsługa kolejki (`Special:CAMergeRequestQueue`)**:
   - Administratorzy przeglądają listę wniosków. Po otwarciu szczegółów wniosku (`view/<id>`) mogą go zaakceptować (`done`) lub odrzucić (`rejected`) z podaniem komentarza.
   - Zatwierdzenie wniosku przekierowuje bezpośrednio do `Special:CentralAuthMerge` z automatycznie uzupełnionymi parametrami.
   - Wyzwalany jest hook `MCAMergeRequestResolved`, informujący wnioskodawcę o rozpatrzeniu wniosku.

---

## Integracja z Powiadomieniami Echo

Rozszerzenie rejestruje w Echo 4 typy powiadomień:

* **`mca-merge-request-submitted`**: Informuje administratorów o złożeniu nowego wniosku o połączenie kont.
* **`mca-merge-request-resolved`**: Informuje użytkownika o zaakceptowaniu lub odrzuceniu jego wniosku.
* **`mca-account-merged`**: Informuje użytkownika o ręcznym połączeniu jego konta CentralAuth przez administratora.
* **`mca-account-unmerged`**: Informuje użytkownika o rozłączeniu jego konta CentralAuth.

Powiadomienia posiadają dynamiczne ikony (`mca-icon-new`, `mca-icon-approved`, `mca-icon-rejected`, `mca-icon-merged`, `mca-icon-unmerged`) oraz modele prezentacji wspierające obsługę form gramatycznych i płci (`GENDER`).

---

## Rejestry Zdarzeń (Logi)

Rozszerzenie definiuje dwa typy rejestrów logów MediaWiki:

1. **`mca-log`** (CentralAuth Merge Log):
   - Akcje: `merge` (połączenie konta), `unmerge` (rozłączenie konta), `manage-add` (ręczne dodanie wiki), `manage-remove` (usunięcie wiki).
2. **`mca-lock-log`** (CentralAuth Lock Log):
   - Akcje: `lock` (zablokowanie konta CentralAuth), `unlock` (odblokowanie konta CentralAuth).

Wszystkie wpisy w logach korzystają z `MCALogFormatter`, który automatycznie formatuje cele użytkowników i odnośniki do `Special:CentralAuth`.

---

## Struktura Plików Projektu

```text
extensions/MultiCentralAuth/
├── extension.json                         # Główny plik konfiguracyjny rozszerzenia
├── i18n/                                  # Tłumaczenia i wiadomości
│   ├── en.json                            # Język angielski
│   └── pl.json                            # Język polski
├── includes/                              # Kod źródłowy PHP (Namespace: MediaWiki\Extension\MultiCentralAuth)
│   ├── ExternalCAProvider.php             # Pobieranie danych z zewnętrznych API i bazy
│   ├── Hooks.php                          # Obsługa hooków MediaWiki i Echo
│   ├── MCALogFormatter.php                # Formatowanie wpisów w rejestrach logów
│   ├── MCAAccountStatusPresentationModel.php # Prezentacja powiadomień statusu konta w Echo
│   ├── MCAMergeRequestPresentationModel.php  # Prezentacja powiadomień wniosków w Echo
│   ├── MCAPreAuthenticationProvider.php   # Dostawca blokady logowania w AuthManagerze
│   ├── ResilientPresentationModel.php     # Klasa bazowa modeli prezentacji Echo
│   ├── ServiceWiring.php                  # Definicje usług w kontenerze MediaWiki Services
│   └── Special/                           # Strony specjalne
│       ├── SpecialCALockList.php
│       ├── SpecialCAMergeRequest.php
│       ├── SpecialCAMergeRequestQueue.php
│       ├── SpecialCentralAuth.php
│       ├── SpecialCentralAuthMerge.php
│       ├── SpecialCentralAuthSettings.php
│       ├── SpecialCentralAuthUnmerge.php
│       ├── SpecialLockCAAccount.php
│       ├── SpecialManageCA.php
│       └── SpecialUnlockCAAccount.php
├── resources/                             # Zasoby podręczne (JS, Less, ikony)
│   ├── ext.multicentralauth.js            # Skrypty klienckie (interfejs OOUI)
│   ├── main.less                          # Style CSS/Less
│   └── icons/                             # Ikony metod połączeń i powiadomień
└── sql/                                   # Schematy tabel bazy danych
    ├── mca_external_ids.sql
    ├── mca_external_userids.sql
    ├── mca_farms.sql
    ├── mca_locks.sql
    ├── mca_merge_requests.sql
    ├── mca_suppressed_wikis.sql
    └── tables.sql
```
