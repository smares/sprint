# Konfiguration

Wichtige Einstellungen und Eigenheiten im laufenden Betrieb. Alle Werte stehen in `.env` (Vorlage: `.env.example`).

## Allgemein

- Die Datenbank wird über die Variablen `DB_*` gewählt (SQLite, MySQL, PostgreSQL)
- Sitzungen, Cache und Queue nutzen standardmäßig die Datenbank (`SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION`); für die Queue läuft im Betrieb ein Worker: `php artisan queue:work`
- Der Scheduler schreibt jede Minute einen Herzschlag, den die [Gesundheitsprüfung](maintenance.md#gesundheitsprüfung) auswertet

## E-Mail und Tageszusammenfassung

- Der Posteingang liegt in der Tabelle `notifications` und wird über dieselbe Queue befüllt wie die Mails (Worker nötig)
- E-Mails brauchen einen Mailer: `MAIL_MAILER` und die übrigen `MAIL_*`-Variablen in `.env` (lokal reicht `log`, dann landen die Mails in `storage/logs`); `MAIL_FROM_ADDRESS` und `MAIL_FROM_NAME` bestimmen den Absender. Versendet wird über die Queue, ohne laufenden Worker kommt nichts an
- Die **Tageszusammenfassung** (werktags 07:30, einstellbar mit `SPRINT_DIGEST_TIME`) braucht den Scheduler: ein Cron-Eintrag, der jede Minute `php artisan schedule:run` startet, z. B. `* * * * * cd /pfad/zu/sprint && php artisan schedule:run >> /dev/null 2>&1`. Die Uhrzeit gilt in der Zeitzone der Anwendung (`APP_TIMEZONE`, in `.env.example` `Europe/Berlin`); sie bestimmt auch, was „heute“ und „überfällig“ heißt. Zum Ausprobieren: `php artisan digest:send --user=anna@example.com`

## Live-Updates (Reverb)

Mit [Laravel Reverb](https://laravel.com/docs/reverb) aktualisieren sich die Seiten ohne Neuladen, wenn jemand anderes etwas ändert. Ohne Reverb läuft Sprint wie bisher; es wird dann nichts gesendet und der Browser öffnet keine Verbindung.

Was live ist:

- **Liste, Board, Kalender und Zeitleiste** eines Projekts laden neu, sobald eine Aufgabe, ein Kommentar oder ein Anhang im Projekt geändert wird (bei Massenaktionen und CSV-Import eine einzige Meldung statt einer je Aufgabe)
- **Aufgabenseite:** neue Kommentare, Anhänge und Unteraufgaben erscheinen sofort; speichert jemand anderes die Aufgabe, erscheint ein Hinweis mit „Neu laden“, ohne dass deine ungespeicherten Eingaben überschrieben werden
- **Wer ist gerade da:** Avatare im Projekt-Kopf und ein Hinweis an der Aufgabe („Anna sieht sich diese Aufgabe auch gerade an“)
- **Posteingang:** die Glocke zählt sofort hoch und zeigt eine Meldung

Über den Socket gehen nur Kennungen (Projekt, Aufgabe, Art der Änderung), nie Inhalte; die Seiten holen sich die Daten selbst, mit den Rechten der jeweiligen Person. Wer ein Projekt nicht sehen darf, kann seinen Kanal nicht abonnieren.

Einrichten:

1. In `.env` eintragen (Beispielwerte stehen in `.env.example`):
   ```ini
   BROADCAST_CONNECTION=reverb
   REVERB_APP_ID=sprint
   REVERB_APP_KEY=<zufälliger Schlüssel>
   REVERB_APP_SECRET=<zufälliges Geheimnis>
   REVERB_HOST=sprint.example.com   # Adresse, unter der der Browser den Socket erreicht
   REVERB_PORT=443                  # 443 hinter einem Proxy mit TLS, lokal 8080
   REVERB_SCHEME=https              # lokal http
   REVERB_SERVER_HOST=0.0.0.0       # wo der Reverb-Prozess lauscht
   REVERB_SERVER_PORT=8080
   ```
2. Den Reverb-Server dauerhaft laufen lassen: `php artisan reverb:start` (Forge: Daemon, sonst Supervisor oder systemd; nach jedem Deployment mit `php artisan reverb:restart` neu starten)
3. Den Queue-Worker laufen lassen: die Meldungen gehen über die Queue (`queue:work`), ohne Worker kommt nichts an
4. Nach `.env`-Änderungen `php artisan config:clear` (bzw. `optimize`) und die Assets müssen nicht neu gebaut werden, die Verbindungsdaten liefert der Server zur Laufzeit

Hinter Nginx leitest du den Pfad `/app` (WebSocket) und `/apps` (Meldungen) an den Reverb-Port weiter und terminierst TLS am Proxy; Laravel Forge und Laravel Cloud (eigener *WebSocket-Cluster*) richten das ein. Mehrere Anwendungsserver brauchen `REVERB_SCALING_ENABLED=true` und Redis.

Wenn der Reverb-Server nicht erreichbar ist, laufen die Seiten normal weiter, sie aktualisieren sich nur nicht von selbst; fehlgeschlagene Meldungen landen in `failed_jobs`.

## Anhänge

- Anhänge liegen privat auf der Standard-Disk von Laravel (`FILESYSTEM_DISK`): lokal `storage/app/private`, mit `FILESYSTEM_DISK=s3` in einem Bucket (Zugangsdaten über `AWS_*`; der S3-Adapter ist installiert). Sie müssen mit gesichert werden. Für große Dateien müssen `upload_max_filesize` und `post_max_size` in der PHP-Konfiguration (und ggf. das Limit des Webservers) mindestens 20 MB erlauben

## MCP-Server und Passkeys

- Der **MCP-Server** läuft unter `https://<host>/mcp` (Tools: `list-projects`, `list-tasks`, `get-task`, `create-task`, `update-task`, `add-comment`) und braucht kein weiteres Setup außer `php artisan migrate`. Anbinden z. B. mit `claude mcp add --transport http sprint https://<host>/mcp --header "Authorization: Bearer <token>"`; lokal testen mit `php artisan mcp:inspector mcp`. Es gelten nur API-Tokens (keine Browser-Sitzung), 120 Anfragen pro Minute und Token; beim Deaktivieren einer Person werden ihre Tokens gelöscht. Im Betrieb nur über HTTPS erreichbar machen, da der Token im Header übertragen wird
- **Passkeys** brauchen HTTPS (lokal reicht `localhost`) und eine korrekte `APP_URL` in `.env`: Host und Schema der Adresse, unter der die App im Browser aufgerufen wird, bestimmen, wo ein Passkey gilt. Wer die Adresse später ändert, kann bestehende Passkeys nicht mehr nutzen (Passwort und 2FA funktionieren weiter). Wer sich aussperrt, wird von einem Administrator zurückgesetzt (*Benutzer*); API-Tokens für den MCP-Server sind davon unabhängig

## Suche

- Der Suchindex (SQLite/FTS5) wird von der Anwendung gepflegt und von der Migration aufgebaut. Nach einem Restore oder bei Unstimmigkeiten: `php artisan search:rebuild`. Ohne FTS5 (z. B. MySQL/PostgreSQL) läuft die Suche automatisch über LIKE und braucht keinen Index

## Sprachen

- jede Person hat in ihrem Profil eine Sprache (Spalte `users.locale`, zweistelliges Kürzel wie `de`, `en`); sie bestimmt Oberfläche, E-Mails, Meldungen und Datumsformate. Besucher:innen bekommen die Sprache ihres Browsers oder wählen sie auf der Anmeldeseite. `APP_LOCALE` (Standard `en`, in `.env.example` gesetzt) ist die Voreinstellung für neue Personen und für Browser mit einer anderen Sprache. Die Konsolenbefehle (`php artisan user:create` usw.) sprechen Englisch. Mails liegen pro Sprache als eigene Vorlagen unter `resources/views/mail/<kürzel>/` (Inhalt) und `…/subjects/` (Betreff); fehlt eine Sprache, gilt Englisch. Die Oberfläche nutzt englische Ausgangstexte als Schlüssel (`__('New task')`), die Übersetzungen stehen in `lang/<kürzel>.json` (für Deutsch `lang/de.json`; `lang/en.json` bleibt leer). Standard-Status und das Feld „Priorität“ neuer Projekte entstehen in der Sprache der Person, die das Projekt anlegt; bereits gespeicherte Daten (Projekt-, Status-, Tag- und Feldnamen, Aufgabentexte) werden nicht übersetzt. Eine neue Sprache braucht `lang/<kürzel>.json`, den Ordner `lang/<kürzel>/` (Laravel-Texte für Validierung, Anmeldung …), die Mail-Vorlagen und einen Eintrag in `config/sprint.php`
