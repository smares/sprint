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

## Anhänge

- Anhänge liegen privat auf der Standard-Disk von Laravel (`FILESYSTEM_DISK`): lokal `storage/app/private`, mit `FILESYSTEM_DISK=s3` in einem Bucket (Zugangsdaten über `AWS_*`; der S3-Adapter ist installiert). Sie müssen mit gesichert werden. Für große Dateien müssen `upload_max_filesize` und `post_max_size` in der PHP-Konfiguration (und ggf. das Limit des Webservers) mindestens 20 MB erlauben

## MCP-Server und Passkeys

- Der **MCP-Server** läuft unter `https://<host>/mcp` (Tools: `list-projects`, `list-tasks`, `get-task`, `create-task`, `update-task`, `add-comment`) und braucht kein weiteres Setup außer `php artisan migrate`. Anbinden z. B. mit `claude mcp add --transport http sprint https://<host>/mcp --header "Authorization: Bearer <token>"`; lokal testen mit `php artisan mcp:inspector mcp`. Es gelten nur API-Tokens (keine Browser-Sitzung), 120 Anfragen pro Minute und Token; beim Deaktivieren einer Person werden ihre Tokens gelöscht. Im Betrieb nur über HTTPS erreichbar machen, da der Token im Header übertragen wird
- **Passkeys** brauchen HTTPS (lokal reicht `localhost`) und eine korrekte `APP_URL` in `.env`: Host und Schema der Adresse, unter der die App im Browser aufgerufen wird, bestimmen, wo ein Passkey gilt. Wer die Adresse später ändert, kann bestehende Passkeys nicht mehr nutzen (Passwort und 2FA funktionieren weiter). Wer sich aussperrt, wird von einem Administrator zurückgesetzt (*Benutzer*); API-Tokens für den MCP-Server sind davon unabhängig

## Suche

- Der Suchindex (SQLite/FTS5) wird von der Anwendung gepflegt und von der Migration aufgebaut. Nach einem Restore oder bei Unstimmigkeiten: `php artisan search:rebuild`. Ohne FTS5 (z. B. MySQL/PostgreSQL) läuft die Suche automatisch über LIKE und braucht keinen Index

## Sprachen

- jede Person hat in ihrem Profil eine Sprache (Spalte `users.locale`, zweistelliges Kürzel wie `de`, `en`); sie bestimmt Oberfläche, E-Mails, Meldungen und Datumsformate. Besucher:innen bekommen die Sprache ihres Browsers oder wählen sie auf der Anmeldeseite. `APP_LOCALE` (Standard `en`, in `.env.example` gesetzt) ist die Voreinstellung für neue Personen und für Browser mit einer anderen Sprache. Die Konsolenbefehle (`php artisan user:create` usw.) sprechen Englisch. Mails liegen pro Sprache als eigene Vorlagen unter `resources/views/mail/<kürzel>/` (Inhalt) und `…/subjects/` (Betreff); fehlt eine Sprache, gilt Englisch. Die Oberfläche nutzt englische Ausgangstexte als Schlüssel (`__('New task')`), die Übersetzungen stehen in `lang/<kürzel>.json` (für Deutsch `lang/de.json`; `lang/en.json` bleibt leer). Standard-Status und das Feld „Priorität“ neuer Projekte entstehen in der Sprache der Person, die das Projekt anlegt; bereits gespeicherte Daten (Projekt-, Status-, Tag- und Feldnamen, Aufgabentexte) werden nicht übersetzt. Eine neue Sprache braucht `lang/<kürzel>.json`, den Ordner `lang/<kürzel>/` (Laravel-Texte für Validierung, Anmeldung …), die Mail-Vorlagen und einen Eintrag in `config/sprint.php`
