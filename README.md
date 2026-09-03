# ESSA WP Suite (Free)

Darmowa wtyczka WordPress z 6 modułami administracyjnymi (Email Encoder, Disable Comments, Disable XML-RPC, WP Hardening, Admin Notices, Maintenance Mode) i zakładką licencji, która odblokowuje 7 modułów **Pro** z osobnej, prywatnej wtyczki (`Essaseo/essa-wp-suite-pro`). Kod wtyczki leży w `essa-wp-suite/`, gotowa paczka w `dist/`.

Model: Free publicznie (to repo, docelowo wordpress.org), Pro sprzedawane w sklepie essaseo.pl, klucz aktywowany na serwerze licencji `lic.essaseo.pl`.

## Struktura

```
essa-wp-suite/          ← to trafia do wp-content/plugins/
  essa-wp-suite.php     bootstrap, kontrola wymagań, dashboard, Email Encoder
  includes/
    class-utils.php     IP klienta, listy IP/CIDR, ścieżka żądania, URL zakładek
    class-updater.php   aktualizacje z GitHub Releases
    class-license.php   klucz Pro: aktywacja, stan, instalacja Pro jednym kliknięciem
    class-*.php         po jednym module
  admin/                admin.css, admin.js
  languages/            .pot + polskie tłumaczenie (.po/.mo)
  readme.txt            opis w formacie wordpress.org
  uninstall.php
tests/
  test-encoder.php      testy rdzenia Email Encodera (bez WP)
  smoke.php             ładuje wtyczkę na atrapach WP, każdy moduł włączony
narzedzia/
  build.sh              lint + testy + zip do dist/
  make-pot.php          generuje languages/essa-wp-suite.pot
```

## Wymagania

PHP 7.2+, WordPress 5.6+. Na starszych wersjach wtyczka nie ładuje modułów i pokazuje komunikat w adminie zamiast białego ekranu.

## Testy i budowanie

```
php tests/test-encoder.php
php tests/smoke.php
bash narzedzia/build.sh      # lint, testy, dist/essa-wp-suite-<wersja>.zip
```

## Języki

Teksty źródłowe w kodzie są **po angielsku**; polski wchodzi jako tłumaczenie z `languages/essa-wp-suite-pl_PL.mo`. WordPress wybiera język po ustawieniu witryny.

```
python narzedzia/i18n.py check essa-wp-suite                  # czy coś zostało po polsku
python narzedzia/i18n.py apply mapa.json essa-wp-suite        # podmiana tekstów w kodzie
python narzedzia/i18n.py po mapa.json essa-wp-suite pl_PL essa-wp-suite   # .po + .mo
```

Mapa to `{"tekst polski": "English text"}`. Nowy tekst dopisujesz po angielsku w kodzie, a polskie tłumaczenie dokładasz do mapy i przebudowujesz `.mo`. Wtyczka Pro ma osobną domenę `essa-wp-suite-pro` i własny plik `.mo` — jedna domena dla dwóch wtyczek nie działa, bo WordPress ładuje ją tylko z jednego katalogu.

## Wydanie nowej wersji

1. Podbij wersję w DWÓCH miejscach `essa-wp-suite/essa-wp-suite.php` (nagłówek `Version:` i `EWPS_VERSION`) oraz `Stable tag` w `readme.txt`.
2. Dopisz sekcję `## [X.Y.Z]` w `CHANGELOG.md` — trafi do notatek wydania.
3. `bash narzedzia/build.sh` (lint + testy), commit, `git tag vX.Y.Z`, `git push && git push --tags`.
4. GitHub Actions buduje ZIP i publikuje wydanie. Strony z wtyczką zobaczą aktualizację w Kokpicie w ciągu 6 h, natychmiast po „Sprawdź aktualizacje" na liście wtyczek.

Wtyczka pyta `https://api.github.com/repos/Essaseo/essa-wp-suite/releases/latest`. Repo prywatne wymaga `EWPS_GITHUB_TOKEN` na każdej stronie klienta.

## Bezpieczniki w wp-config.php

| Stała | Działanie |
|---|---|
| `EWPS_LICENSE_KEY` | klucz Pro zamiast pola w panelu |
| `EWPS_LIC_URL` | inny serwer licencji niż lic.essaseo.pl |
| `EWPS_GITHUB_TOKEN` | token do prywatnego repo z wydaniami |
| `EWPS_GITHUB_REPO` | inne repo niż `Essaseo/essa-wp-suite` |

## Konwencje

- Każdy moduł to singleton z `$defaults`, `$opts`, `opt()`, `register_settings()`, `sanitize()`. Włączony moduł podpina hooki w `init()`, wyłączony nie robi nic.
- `current_user_can()`, `is_user_logged_in()` i inne funkcje z `pluggable.php` wolno wołać dopiero od hooka `init` / `admin_init`. Przy ładowaniu wtyczki jeszcze nie istnieją.
- Opcje modułu Disable Comments mają prefiks `eee_` (spadek po starej wtyczce). Zmiana nazwy wymagałaby migracji na stronach klientów, więc zostaje.
- Adres IP zawsze przez `ESSA_Suite_Utils::client_ip()`. Nagłówki proxy tylko z jawną opcją „za proxy”.
- Nowy moduł Free: klasa w `includes/`, wpis w `free_modules()` (`group`/`page`/`sidebar` albo `render`), opcja w `uninstall.php`, hook w `tests/smoke.php`.
- Nowy moduł Pro: opis w `pro_catalog()` tutaj (żeby Free pokazywał zablokowaną kartę), kod i rejestracja przez filtr `ewps_pro_modules` w repo Pro.
