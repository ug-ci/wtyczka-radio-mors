=== Lista Przebojów Radia MORS ===
Contributors: radiomors
Tags: radio, chart, voting, music, playlist
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lista przebojów radiowych z głosowaniem słuchaczy (1–15 głosów na 24h) oraz panelem redakcji do zarządzania notowaniem.

== Description ==

Wtyczka udostępnia publiczną listę przebojów (notowanie + poczekalnia) z głosowaniem słuchaczy oraz panel redakcyjny do zarządzania utworami, zamrażania i publikacji kolejnych wydań notowania.

Funkcje:

* Publiczna lista przebojów i poczekalnia (shortcode `[lista_przebojow_mors]`).
* Głosowanie słuchaczy: 1–15 utworów na głosującego, limit jednego głosu na 24h (identyfikacja po hashu IP/fingerprint, bez logowania).
* Panel redakcji pod menu „Radio MORS” w kokpicie WordPressa (wymaga capability `mors_edit_music`) — dodawanie utworów (cover + audio przez Bibliotekę mediów), edycja, usuwanie, zamrażanie notowania i publikacja nowego wydania.
* Zarządzanie redaktorami (capability `mors_manage_editors`).
* Log audytowy działań redakcyjnych.

== Installation ==

1. Wgraj katalog `radio-mors` do `wp-content/plugins`.
2. Aktywuj wtyczkę w Kokpicie → Wtyczki — aktywacja utworzy wymagane tabele bazy danych oraz pierwsze wydanie notowania.
3. Wstaw shortcode `[lista_przebojow_mors]` na wybranej stronie, aby wyświetlić publiczną listę przebojów.
4. Panel redakcji jest dostępny w menu „Radio MORS” w Kokpicie — widoczny dla użytkowników z uprawnieniem `mors_edit_music` (domyślnie nadanym roli Administrator przy aktywacji).

== Frequently Asked Questions ==

= Czy dezaktywacja wtyczki usuwa moje dane? =

Nie. Dezaktywacja jedynie odbiera capability wtyczki rolom — tabele, utwory, głosy i notowania pozostają nietknięte.

= Czy odinstalowanie (Delete) wtyczki usuwa dane? =

Domyślnie nie. Usunięcie tabel, opcji i uprawnień przy odinstalowaniu jest opt-in: trzeba jawnie ustawić opcję `mors_delete_data_on_uninstall` (np. `update_option('mors_delete_data_on_uninstall', 1)`) przed usunięciem wtyczki z ekranu Wtyczki. Bez tej opcji dane zostają w bazie na wypadek ponownej instalacji.

== Changelog ==

= 1.0.0 =
* Pierwsze wydanie: publiczna lista przebojów, głosowanie, panel redakcji, uninstall opt-in.
