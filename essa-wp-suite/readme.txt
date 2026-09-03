=== ESSA WP Suite ===
Contributors: essaseo
Tags: security, maintenance mode, email encoder, disable comments, hardening
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.2
Stable tag: 2.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sześć darmowych narzędzi administracyjnych w jednej wtyczce (Email Encoder, Disable Comments, XML-RPC, Hardening, Admin Notices, Maintenance) plus siedem modułów Pro po aktywacji klucza.

== Description ==

ESSA WP Suite zastępuje kilkanaście małych wtyczek jednym panelem. Każdy moduł włącza się osobno jednym przełącznikiem na dashboardzie i ma własną zakładkę ustawień.

**Moduły Free**

* **Email Encoder** — koduje adresy email do encji HTML (decimal/hex/mixed), więc boty spamowe ich nie wyciągną ze źródła strony. Działa bez JavaScriptu. Shortcode `[encode]`.
* **Disable Comments** — wyłącza komentarze globalnie lub per typ wpisu, czyści UI admina, blokuje komentarze przez REST API i XML-RPC, usuwa istniejące komentarze z bazy.
* **Disable XML-RPC** — całkowite wyłączenie XML-RPC, blokada endpointu (403), usunięcie nagłówka X-Pingback i linków RSD/wlwmanifest.
* **Maintenance Mode** — strona serwisowa z kodem 503 i Retry-After, własny tytuł, treść, logo, kolory, CSS; whitelist IP; rola omijająca; podgląd dla admina.
* **WP Hardening** — usuwa `?ver=` z adresów assetów, meta generator, skrypty emoji, wp-embed, linki RSD/shortlink; dodaje nagłówki bezpieczeństwa.
* **Admin Notices** — chowa powiadomienia wtyczek do panelu pod ikoną w pasku admina; błędy i whitelistowane klasy zostają widoczne.

**Moduły Pro** (osobna wtyczka, klucz licencji ze sklepu essaseo.pl, instalacja jednym kliknięciem z zakładki „Licencja Pro”)

* **Login Security** — własny adres logowania, ukrycie `/wp-login.php`, limit prób z blokadą IP, whitelist IP (CIDR), powiadomienia.
* **SMTP Mailer** — `wp_mail()` przez SMTP, presety, test wysyłki, hasło w `wp-config.php`.
* **DB Cleaner** — rewizje, auto-drafty, kosz, spam, wygasłe transjenty, osierocone meta; cotygodniowy cron.
* **Admin Cleaner** — ukrywanie menu i widżetów kokpitu, Pomoc, Opcje ekranu, pasek admina, własna stopka.
* **Auto Updates** — core minor/major, wtyczki i motywy (wymuś / zablokuj / jak w WP), tłumaczenia, maile.
* **White Label** — logo i kolory logowania, własna nazwa wtyczki w menu, ukrycie brandingu WordPress.
* **Activity Log** — logowania, wpisy, wtyczki, opcje, użytkownicy; eksport CSV; retencja.

**Języki**

Interfejs wtyczki jest po angielsku i po polsku. Język idzie za ustawieniem WordPressa (Ustawienia → Ogólne → Język witryny) — na polskiej instalacji panel wtyczki jest polski, na angielskiej angielski. Tłumaczenie polskie jest w paczce (`languages/essa-wp-suite-pl_PL.mo`), nic nie trzeba dogrywać.

**Stałe w wp-config.php**

* `EWPS_LICENSE_KEY` — klucz licencji Pro (zamiast wpisywania w panelu).
* `EWPS_LIC_URL` — inny serwer licencji niż `https://lic.essaseo.pl`.
* `EWPS_DISABLE_LOGIN_SECURITY` (Pro) — `true` wyłącza Login Security, gdy zablokujesz sobie dostęp.
* `EWPS_SMTP_PASS` (Pro) — hasło SMTP poza bazą danych.
* `EWPS_GITHUB_TOKEN` — token, gdy repozytorium z wydaniami jest prywatne.
* `EWPS_GITHUB_REPO` — inne repozytorium niż `Essaseo/essa-wp-suite`.

**Filtry i akcje dla deweloperów**

* `ewps_security_headers` (array) — lista nagłówków bezpieczeństwa.
* `ewps_maintenance_show` (bool) — pozwala pominąć stronę serwisową dla żądania.
* `ewps_log_skip_options` (array) — prefiksy opcji pomijane w logu aktywności.
* `ewps_ip_locked_out` (action: $ip, $username, $until) — po zablokowaniu IP.
* `ewps_db_cleanup_done` (action: $results) — po automatycznym czyszczeniu bazy.

== Installation ==

1. Wgraj katalog `essa-wp-suite` do `/wp-content/plugins/` albo zainstaluj z pliku ZIP.
2. Aktywuj wtyczkę w menu Wtyczki.
3. Wejdź w **ESSA WP Suite** w lewym menu i włącz potrzebne moduły.

== Frequently Asked Questions ==

= Zablokowałem sobie dostęp własnym adresem logowania. Co teraz? =

Dopisz do `wp-config.php` linię `define( 'EWPS_DISABLE_LOGIN_SECURITY', true );` — moduł się wyłączy i `/wp-login.php` znów działa. Po zalogowaniu popraw ustawienia i usuń linię.

= Czy blokada IP działa za Cloudflare? =

Tak, ale trzeba włączyć „Strona jest za proxy / Cloudflare” w Login Security. Bez tego wtyczka celowo ignoruje nagłówki proxy, bo bez proxy każdy może je podrobić.

= Dlaczego DB Cleaner nie usuwa wszystkich transjentów? =

Usuwa tylko wygasłe. Aktywne transjenty to cache, którego skasowanie spowolniłoby stronę na kilka minut bez żadnej korzyści.

= Czy wtyczka działa na multisite? =

Tak, per strona. Odinstalowanie czyści dane na wszystkich stronach sieci.

== Changelog ==

= 2.2.0 =
* Wtyczka mówi po angielsku i po polsku. Teksty źródłowe przepisane na angielski, polskie tłumaczenie dołączone jako plik .mo — panel dobiera język do ustawień WordPressa.
* Narzędzie `narzedzia/i18n.py` do utrzymania tłumaczeń bez gettexta w systemie.

= 2.1.0 =
* Dodano: sekcja usług ESSA SEO i „Moduł na życzenie” na dashboardzie, karta w sidebarze, jednorazowe powiadomienie powitalne (do wyłączenia jednym kliknięciem).

= 2.0.0 =
* Podział na Free i Pro. Free: Email Encoder, Disable Comments, Disable XML-RPC, WP Hardening, Admin Notices, Maintenance Mode. Pro (osobna wtyczka, klucz ze sklepu): Login Security, SMTP, DB Cleaner, Admin Cleaner, Auto Updates, White Label, Activity Log.
* Zakładka „Licencja Pro”: aktywacja klucza, stan, instalacja Pro jednym kliknięciem.
* Rejestr modułów z filtrem `ewps_pro_modules`; zablokowane karty Pro na dashboardzie.
* Ustawienia modułów Pro zapisane w wersji 1.x zostają i wracają po instalacji Pro.

= 1.5.0 =
* Dodano: aktualizacje z GitHub Releases (Kokpit → Aktualizacje), link „Sprawdź aktualizacje", workflow wydań.

= 1.4.0 =
* Naprawiono: Login Security nie wczytywał zapisanych ustawień — własny adres logowania nigdy nie działał.
* Naprawiono: fatal error przy włączonym Admin Cleaner (current_user_can przed załadowaniem pluggable.php).
* Naprawiono: przyciski SMTP i DB Cleaner nie działały (JS poza wrapperem jQuery).
* Naprawiono: podmenu przekierowywało po wysłaniu nagłówków — pusta strona.
* Naprawiono: własny adres logowania łapał każdy URL zawierający slug.
* Naprawiono: Admin Notices gubił błędy krytyczne i whitelistę.
* Naprawiono: kodowanie emaili psuło polskie znaki i JSON-LD w `<script>`.
* Naprawiono: XSS przez `[encode link="javascript:..."]`.
* Naprawiono: DB Cleaner kasował aktywne transjenty, przycisk „Kosz” nie działał.
* Naprawiono: Auto Updates wymuszał wyłączenie auto-update ustawionego per wtyczka.
* Naprawiono: komunikat „Zapisano” nie pokazywał się po zapisie.
* Naprawiono: link wylogowania z panelu przy ukrytym wp-login.php prowadził na 404.
* Naprawiono: hasło SMTP psute przez sanitize_text_field i zwracane do HTML.
* Naprawiono: nagłówki proxy zawsze zaufane — blokadę IP dało się ominąć.
* Naprawiono: schemat tabeli logów niezgodny z dbDelta i starym MySQL.
* Dodano: nagłówek Update URI (ochrona przed podmianą przez wtyczkę o tym samym slugu).
* Dodano: kontrola wymagań PHP/WP przed załadowaniem modułów.
* Dodano: bezpiecznik EWPS_DISABLE_LOGIN_SECURITY, EWPS_SMTP_PASS.
* Dodano: whitelist IP z CIDR, opcja „za proxy”, ostrzeżenie w pasku admina przy trybie serwisowym.
* Dodano: tri-state auto-update wtyczek/motywów, migracja starych ustawień.
* Dodano: ochrona CSV injection w eksporcie logów, BOM dla Excela.
* Dodano: filtry i akcje dla deweloperów, readme.txt, szablon tłumaczeń, testy.

= 1.3.2 =
* Poprawki wersji 1.3.

= 1.3.0 =
* Nowe moduły: SMTP Mailer, DB Cleaner, Admin Cleaner, Auto Updates, White Label, Activity Log.

= 1.0.0 =
* Pierwsza wersja: Email Encoder, Disable Comments, Login Security, Disable XML-RPC, Maintenance Mode, WP Hardening.
