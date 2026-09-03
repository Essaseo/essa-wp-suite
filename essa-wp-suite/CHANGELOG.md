# ESSA WP Suite — Changelog

Wszystkie istotne zmiany w tym projekcie są dokumentowane w tym pliku.
Format: [Semantic Versioning](https://semver.org/) — MAJOR.MINOR.PATCH

---

## [2.1.0] — 2026-09-03

### Dodano
- **Promocja usług ESSA SEO** w panelu (`includes/class-promo.php`): sekcja na dashboardzie z kartami usług (pozycjonowanie, wizytówka Google, Google Ads i Meta Ads, strony WWW) i kartą „Moduł na życzenie”, karta w sidebarze zakładek, jednorazowe powiadomienie po aktywacji z przyciskiem „Nie pokazuj więcej”. Treść z serwera licencji (`GET /v1/promo`, plik `promo.json` na VPS — zmiana bez nowej wersji), cache 12 h, wbudowana wersja zapasowa, linki z UTM. White Label (Pro) chowa całość.

---

## [2.0.0] — 2026-09-03

### Zmieniono — podział Free / Pro
- **Free** (ta wtyczka): Email Encoder, Disable Comments, Disable XML-RPC, WP Hardening, Admin Notices, Maintenance Mode.
- **Pro** (osobna wtyczka `essa-wp-suite-pro`, klucz ze sklepu essaseo.pl): Login Security, SMTP Mailer, DB Cleaner, Admin Cleaner, Auto Updates, White Label, Activity Log. Klasy modułów przeniesione 1:1, opcje w bazie bez zmian — po instalacji Pro ustawienia z 1.x wracają.
- Rejestr modułów: `get_modules()` = moduły Free + `apply_filters('ewps_pro_modules')` + katalog Pro jako zablokowane karty. Moduł deklaruje `group`/`page`/`sidebar` albo własny `render`.
- White Label steruje Free przez filtry `ewps_menu_label`, `ewps_menu_capability`, `ewps_hide_branding`; Pro rejestruje ustawienia w akcji `ewps_admin_init`.

### Dodano
- `includes/class-license.php` — klucz i stan licencji w opcji `ewps_license`, aktywacja/dezaktywacja/sprawdzenie na serwerze licencji (`EWPS_LIC_URL`, domyślnie lic.essaseo.pl), ponowna aktywacja po zmianie domeny, grace 14 dni bez serwera, instalacja Pro jednym kliknięciem (`Plugin_Upgrader`), `EWPS_LICENSE_KEY` w wp-config.
- Zakładka „Licencja Pro”, karty zablokowane z linkiem do sklepu, badge FREE/PRO w nagłówku.

### Usunięto z Free
- 7 klas modułów Pro, ich AJAX, crony i wpisy w uninstall.php (należą teraz do Pro).

---

## [1.5.0] — 2026-09-03

### Dodano
- **Aktualizacje z GitHub Releases** (`includes/class-updater.php`) — WordPress widzi nową wersję w Kokpicie jak każdą inną wtyczkę; paczka z assetu wydania, w ostateczności zipball repo z przemianowaniem katalogu; okno „Zobacz szczegóły wersji" z changelogiem wydania; link „Sprawdź aktualizacje" na liście wtyczek; cache 6 h; obsługa repo prywatnego przez `EWPS_GITHUB_TOKEN`, innego repo przez `EWPS_GITHUB_REPO`
- `Update URI` wskazuje na `github.com/Essaseo/essa-wp-suite`
- GitHub Actions (`.github/workflows/release.yml`): tag `vX.Y.Z` → kontrola wersji, lint, testy, ZIP, wydanie z paczką i notatkami z CHANGELOG

---

## [1.4.0] — 2026-09-03

Przegląd całego kodu i naprawa błędów, które blokowały działanie połowy modułów.

### Naprawiono — krytyczne
- **Login Security** nie wczytywał ustawień z bazy i nie sprawdzał `ls_enabled` — własny adres logowania nigdy nie działał, a limit prób działał zawsze na domyślnych wartościach
- **Admin Cleaner** wołał `current_user_can()` przy ładowaniu wtyczki, przed `pluggable.php` — włączenie modułu wywalało stronę fatal errorem
- **admin.js**: presety SMTP, test wysyłki i DB Cleaner używały `$` poza wrapperem jQuery — w adminie WP `$` nie istnieje, przyciski nie działały
- **Podmenu** przekierowywało przez `wp_redirect()` po wysłaniu nagłówków — pusta strona z ostrzeżeniem; teraz pozycje są zwykłymi linkami do zakładek, podświetlane przez `submenu_file`
- **Własny adres logowania** wykrywany przez `strpos` na całym URI — `/blog/login-tips/` ładował formularz logowania; teraz porównanie pełnej ścieżki
- **Admin Notices** przechwytywał output buforem i nigdy go nie wypisywał — błędy krytyczne i whitelist znikały razem z resztą; przepisane na CSS + JS bez buforowania

### Naprawiono — funkcjonalne
- Trzy przekierowania po akcjach prowadziły na `options-general.php?page=eee-settings` (stary slug)
- DB Cleaner: przycisk „Kosz" wysyłał `trashed`, metoda nazywała się `clean_trash`; „wygasłe transjenty" kasowało wszystkie, także aktywne; auto-drafty kasowane bez postmeta
- Email Encoder kodował bajtami — polskie znaki w `[encode]` rozpadały się; kodował też w `<script>`, psując JSON-LD; nie kodował w feedach
- `[encode link="javascript:..."]` przepuszczał XSS — link idzie teraz przez `esc_url`
- Auto Updates z wyłączonym przełącznikiem wymuszał `false`, kasując auto-update ustawione per wtyczka — teraz tri-state (jak w WP / wymuś / zablokuj) z migracją
- `settings_errors()` dostawało nazwę grupy zamiast opcji — komunikat „Zapisano" nigdy się nie pokazywał
- Filtr `site_url` pomijał admina — link wylogowania przy ukrytym wp-login.php prowadził na 404
- Hasło SMTP przechodziło przez `sanitize_text_field` (psuło znaki specjalne) i wracało do HTML formularza
- Nagłówki proxy (X-Forwarded-For) zawsze zaufane — blokadę IP dało się ominąć jednym nagłówkiem; teraz opcja „za proxy" domyślnie wyłączona
- Tabela logów: `CREATE TABLE IF NOT EXISTS` psuło `dbDelta`, `DEFAULT CURRENT_TIMESTAMP` wywalało stary MySQL
- `wp_count_comments` bez `awaiting_moderation`, podwójna rejestracja AJAX, martwy `templates/maintenance.php`, brak `.ewps-checkboxes` w CSS, cron nie znikał przy dezaktywacji, uninstall nie czyścił wersji
- White Label: `manage_network` na pojedynczej stronie chowało menu dla wszystkich — walidacja z fallbackiem; podmiana „Cześć" niezależna od języka
- Log aktywności zalewany przez `ewps_login_attempts`, `rewrite_rules`, locki updatera — lista pomijanych opcji + filtr

### Dodano
- Nagłówek `Update URI` — bez niego obca wtyczka o slugu `essa-wp-suite` na wordpress.org nadpisałaby tę przez auto-update
- Kontrola wymagań PHP 7.2 / WP 5.6 przed załadowaniem modułów (komunikat zamiast białego ekranu)
- Bezpieczniki: `EWPS_DISABLE_LOGIN_SECURITY`, `EWPS_SMTP_PASS`
- Aktualizacja bez reaktywacji (`ewps_version` → `maybe_upgrade()`): tabela logów, crony
- Whitelist IP z CIDR, wspólna klasa `ESSA_Suite_Utils`
- Login Security: walidacja slugu (zarezerwowane, kolizja ze stroną), konfigurowalny cel przekierowania, `plugins_loaded` zamiast `init` (wp-login.php przetwarza POST przed init)
- Maintenance: podgląd działa przy wyłączonym module, ostrzeżenie w pasku admina, `noindex`, `lang` z ustawień, filtr `ewps_maintenance_show`
- SMTP: `SMTPAutoTLS=false` dla „brak", `setFrom` w `phpmailer_init`, komunikat błędu z `wp_mail_failed`, preset home.pl
- DB Cleaner: `wp_revisions_to_keep` zamiast `define(WP_POST_REVISIONS)`, wykrywanie object cache, `sync_cron` przy każdej zmianie opcji, orphan commentmeta
- Activity Log: BOM + separator `;` (Excel), ochrona przed CSV injection, `paginate_links`, tabela dociągana gdy brak
- Hardening: `ewps_security_headers`, nagłówki także na stronie logowania; usunięty `X-XSS-Protection` (porzucony przez przeglądarki)
- Link „Ustawienia" na liście wtyczek, `index.php` w katalogach, `readme.txt`, `.pot`, testy (`tests/`), `narzedzia/build.sh`

---

## [1.3.0] — 2026-03-31

### Dodano — 6 nowych modułów

- **📧 SMTP Mailer** — konfiguracja wp_mail() przez prawdziwy serwer SMTP (TLS/SSL), własny nadawca, szybkie presety (Gmail, OVH, Outlook, Brevo), test wysyłki z panelu
- **🗑️ DB Cleaner** — czyszczenie rewizji, auto-draftów, kosza, spamu, tranzjentów i osieroconych postmeta; limit rewizji na post; auto-cleanup cotygodniowy przez WP-Cron
- **🧹 Admin Cleaner** — ukrywanie pozycji menu i widżetów dashboardu, własna stopka admina, ukrycie zakładki Pomoc i Opcji ekranu, ukrycie admin bara na froncie
- **🔄 Auto Updates Manager** — niezależna kontrola auto-update dla core major/minor, wtyczek, motywów i tłumaczeń; opcja wyłączenia emaili powiadomień
- **🏷️ White Label** — logo i kolory strony logowania, ukrycie brandingu WP, własna stopka admina, zamiana "Cześć/Howdy", własny CSS logowania, konfigurowalny tytuł okna
- **📋 Activity Log** — pełny log akcji użytkowników (logowania, edycje, wtyczki, ustawienia, role), tabela z paginacją i filtrowaniem, eksport CSV, automatyczne czyszczenie według retencji

### Techniczne
- Tabela `{prefix}ewps_activity_log` tworzona przy aktywacji (`dbDelta`)
- `uninstall.php` — DROP TABLE activity log + `wp_clear_scheduled_hook`
- Podmenu rozszerzone do 15 pozycji
- Dashboard grid z 13 kafelkami modułów
- Nowe AJAX endpoints: `ewps_smtp_test`, `ewps_db_clean`, `ewps_db_stats`

---



### Dodano
- **Lewe menu WordPress** — wtyczka ma teraz własną pozycję w sidebarze (nie jest już schowana pod Ustawienia)
- Podmenu z 8 pozycjami: Dashboard + każdy moduł osobno
- `render_tab_redirect()` — każda pozycja podmenu przekierowuje do głównej strony z aktywnym tabem
- Podświetlanie aktywnej pozycji podmenu przez JS (bazując na `?tab=`)
- SVG ikona tarczy w lewym menu

### Moduł Admin Notices
- Przechwytywanie notices przez `ob_start()` z priorytetem -100 / 99999
- Panel w admin barze z licznikiem ukrytych powiadomień (ikona 🔔)
- Oddzielne przełączniki dla: info (niebieski), success (zielony), warning (żółty)
- Błędy krytyczne zawsze widoczne (opcja `keepErrors`)
- CSS blokada popularnych overlayów: Elementor, WooCommerce, Yoast, RankMath, AIOSEO
- Whitelist klas CSS — wybrane notices zawsze widoczne

### Naprawiono
- `admin_assets` — poprawiony hook z `settings_page_` na `toplevel_page_` dla top-level menu

---

## [1.1.0] — 2026-03-31

### Dodano
- **Dashboard** — domyślna strona z 7 kartami modułów
- Toggle switch na każdej karcie — włącz/wyłącz moduł przez AJAX bez przeładowania
- Licznik aktywnych modułów w headerze (aktualizowany live)
- Banner ostrzeżenia gdy Maintenance Mode jest aktywny
- Moduł **Admin Notices** (`class-admin-notices.php`)

### Zmieniono
- Domyślny tab zmieniony z `encoder` na `dashboard`
- `get_modules_meta()` — centralna konfiguracja wszystkich modułów
- `ajax_toggle_module()` — AJAX endpoint do włączania/wyłączania modułów

---

## [1.0.0] — 2026-03-31

### Pierwsza wersja

#### Moduły
- **Email Encoder** — kodowanie emaili do HTML entities (decimal/hex/mixed), shortcode `[encode]`, live tester AJAX
- **Disable Comments** — wyłączanie komentarzy globalnie lub per typ postu, blokada REST API i XML-RPC, narzędzie usuwania z bazy
- **Login Security** — własny URL logowania, ukrycie `/wp-login.php` → 404, limit prób z blokadą IP, whitelist IP, powiadomienie email, panel odblokowywania
- **Disable XML-RPC** — `xmlrpc_enabled → false`, blokada endpointu 403, usunięcie X-Pingback / RSD / wlwmanifest
- **Maintenance Mode** — strona 503 z własnym tytułem i treścią HTML, ESSA-brandowany template, whitelist IP, bypass po roli WordPress
- **WP Hardening** — usunięcie `?ver=`, `<meta generator>`, emoji scripts, wp-embed; Security Headers (X-Frame-Options, nosniff, XSS-Protection, Referrer-Policy, Permissions-Policy)

#### Techniczne
- PHP 7.2+ (zero arrow functions, zero named arguments)
- Testy jednostkowe (56 testów, zero WP dependency)
- `protected $opts / $defaults` — testowalność bez mocków
- `uninstall.php` — czyste usuwanie opcji z bazy
- Strona ustawień z tabami (7 → 8 w v1.1.0)
