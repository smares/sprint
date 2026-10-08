# Sprint

Sprint ist eine schlanke Aufgabenverwaltung für Teams: Projekte, Aufgaben und Subtasks, ein Kanban-Board, Kommentare mit Markdown und `@`-Erwähnungen.

Gebaut mit Laravel 13, Livewire 4 und [Flux UI Pro](https://fluxui.dev). Die Oberfläche ist deutsch.

## Funktionen

- **Rollen und Rechte:** Projekte sehen nur ihre Mitglieder. Pro Projekt gibt es die Rollen *Ansehen*, *Bearbeiten* und *Verwalten* (Mitglieder und Status); **Teams** geben einer ganzen Gruppe auf einmal Zugriff (die höchste Rolle aus direkter Mitgliedschaft und Teams gilt); Administratoren der Anwendung haben überall Zugriff und legen unter *Benutzer* neue Personen an, verwalten Administratoren und unter *Teams* die Gruppen. Zuständige, Beteiligte, Erwähnungen und E-Mails gibt es nur für Personen, die das Projekt sehen dürfen
- **Projekte und Aufgaben** mit Titel, Beschreibung, zuständiger Person, Fälligkeit und Status
- **Projekt-Einstellungen** (Zahnrad-Menü, für Projekt-Admins): Name und Beschreibung ändern, Projekt **archivieren** (verschwindet aus der Liste, nur noch lesbar, jederzeit wiederherstellbar; archivierte Projekte stehen unter der Projektliste) oder nach Eingabe des Namens endgültig **löschen** (inklusive Aufgaben, Kommentare und Anhangsdateien)
- **Startdatum und Fälligkeit** an jeder Aufgabe (beides optional); dazu ein **Kalender** (Monatsansicht) und eine **Zeitleiste** (Balken über sechs Wochen) pro Projekt, Aufgaben ohne Startdatum erscheinen am Fälligkeitstag
- **Wiederkehrende Aufgaben**: täglich, wöchentlich, monatlich oder jährlich (alle n Einheiten), wahlweise nach Plan oder nach Erledigung, optional mit Enddatum; beim Erledigen entsteht automatisch die nächste Aufgabe samt Unteraufgaben, Tags, Beteiligten und Feldwerten
- **Aufgaben duplizieren**: Kopie mit Unteraufgaben, Tags, Beteiligten, Feldwerten und Terminen, direkt hinter dem Original; Kommentare, Anhänge, Abhängigkeiten und Wiederholungsregel werden nicht kopiert
- **Anhänge**: beliebig viele Dateien pro Aufgabe (per Drag & Drop oder Klick mit Fortschrittsanzeige) (bis 20 MB je Datei), nur für Projektmitglieder abrufbar; Bilder erscheinen als Vorschau, alles andere wird als Download ausgeliefert
- **Profil**: Name, E-Mail-Adresse (mit Passwortbestätigung) und Passwort ändert jede Person selbst im Menü am Avatar
- **Tageszusammenfassung per E-Mail**: Werktags am Morgen eine Mail mit überfälligen, heute und in den nächsten drei Tagen fälligen Aufgaben (nur wenn man zuständig oder beteiligt ist, ohne stummgeschaltete Aufgaben und archivierte Projekte, und nur wenn es etwas zu berichten gibt); im Profil abstellbar
- **Befehlspalette** (Strg/Cmd+K oder Suchfeld in der Kopfzeile): springt zu Projekten, Seiten und Aufgaben (ab zwei Zeichen, öffnet die Aufgabe im Seitenpanel), schaltet die Darstellung um und führt mit „Alle Ergebnisse“ zur vollständigen Suche; die Flux-Texte (Datumsfeld, Farbwähler, Palette) sind über `lang/en.json` eingedeutscht
- **Globale Suche** (Suchfeld in der Kopfzeile): durchsucht Titel, Beschreibung, Kommentare und Anhangsnamen aller Projekte, die man sehen darf; alle Wörter müssen vorkommen, Wortanfänge genügen; Filter nach Projekt, offen/erledigt und „nur meine". Auf SQLite mit FTS5 gibt es einen Volltextindex (nach Relevanz sortiert, Umlaute egal), sonst eine LIKE-Suche
- **Liste und Kanban-Board** pro Projekt, mit Filtern (Status, Person, Felder, Tag; Filter-Knopf mit Fenster, aktive Filter als Chips zum Entfernen) und Sortierung per Klick auf die Spaltenköpfe
- **Termine per Ziehen ändern**: Im Kalender zieht man eine Aufgabe auf einen anderen Tag (die ganze Aufgabe verschiebt sich um dieselbe Anzahl Tage), in der Zeitleiste verschiebt man Balken oder zieht an ihrem linken bzw. rechten Rand, um Beginn oder Fälligkeit zu ändern; nur mit Bearbeitungsrecht, Änderungen stehen im Aktivitätsverlauf
- **Gespeicherte Ansichten**: Filter und Sortierung der Liste lassen sich unter einem Namen speichern („Ansichten“ neben dem Filter-Knopf), privat oder – für Projekt-Admins – für alle im Projekt; ein Klick stellt sie wieder her, nicht mehr vorhandene Tags, Status oder Personen werden dabei übersprungen
- **Seitenpanel wie bei Asana**: Ein Klick auf eine Aufgabe in Liste oder Board öffnet sie rechts neben der Liste (auf dem Handy als Vollbild), man kann sie dort bearbeiten, kommentieren und Unteraufgaben wechseln, während die Liste sichtbar bleibt und sich mitaktualisiert. Die Adresse enthält `?task=…` und lässt sich teilen; Strg/Cmd-Klick öffnet weiterhin die Vollseite
- **Viele Aufgaben**: Liste, „Meine Aufgaben" und Suche zeigen zunächst 50 Einträge und laden beim Weiterscrollen (oder per „Mehr laden") die nächsten nach, das Board 30 Karten je Spalte mit „Mehr laden" pro Spalte und dem echten Gesamtstand im Spaltenkopf; Filter, Sortierung und neue Suchbegriffe beginnen wieder bei der ersten Seite; Umsortieren per Ziehen funktioniert auch in einer teilweise geladenen Liste
- **Manuelle Reihenfolge** per Drag & Drop; Liste und Board teilen eine Reihenfolge
- **Eigene Status pro Projekt** mit Name, frei wählbarer Farbe (Farbwähler mit Farbfeldern und Hex-Eingabe), Reihenfolge und „gilt als erledigt“-Markierung (Fenster *Status* an Liste und Board, per Ziehen sortierbar)
- **Subtasks in beliebiger Tiefe** mit Fortschritt, Zwischenüberschriften und Drag & Drop; der Fortschritt zeigt nur an und erledigt die Hauptaufgabe nicht automatisch
- **Eigene Felder pro Projekt** (Auswahl, Text, Zahl, Datum), jedes Projekt startet mit einem Auswahlfeld *Priorität* (Niedrig, Mittel, Hoch, Dringend); Felder, Optionen mit Farbe und Reihenfolge verwalten Projekt-Verwalter unter *Felder*, Werte trägst du an der Aufgabe ein und siehst sie im Verlauf; in der Liste erscheinen die Felder als Spalten (sortierbar, Auswahlfelder auch als Filter) und auf den Board-Karten als Badges
- **Tags pro Projekt** (derselbe Name darf in mehreren Projekten existieren); Projekt-Admins verwalten sie in einem Fenster direkt an der Projektliste: umbenennen, per Farbwähler umfärben, löschen oder beim Löschen auf ein anderes Tag zusammenführen
- **Abhängigkeiten** („blockiert“ / „blockiert von“) nur als Markierung, ohne Sperre; Zyklen werden abgelehnt
- **Beteiligte** (mehrere Personen) zusätzlich zur zuständigen Person; *Meine Aufgaben* enthält auch Aufgaben, an denen man beteiligt ist
- **Kommentare bearbeiten und löschen**: Wer einen Kommentar geschrieben hat, kann ihn nachträglich ändern (mit Hinweis „bearbeitet“; neue @-Erwähnungen benachrichtigen, alte nicht noch einmal) oder löschen; Projekt-Admins dürfen fremde Kommentare löschen, aber nicht ändern
- **Aktivitätsverlauf** an jeder Aufgabe: wer wann Status, Zuständige, Fälligkeit, Titel, Beschreibung, Tags, Beteiligte oder Abhängigkeiten geändert hat, zusammen mit den Kommentaren in einer Zeitleiste
- **Kommentare** mit Markdown (GitHub-Variante: Tabellen, Durchgestrichen, Aufgabenlisten, automatische Links)
- **Markdown** auch in der Beschreibung, mit Vorschau
- **`@`-Erwähnungen** von Personen und Aufgaben: ein `@` tippen, aus dem Fenster wählen; angezeigt wird immer der aktuelle Name bzw. Titel
- **E-Mail-Benachrichtigungen** an zuständige und beteiligte Personen bei neuen Kommentaren und Statuswechseln sowie an erwähnte Personen (auch ohne Beteiligung, einmal pro neuer Erwähnung); pro Aufgabe abbestellbar (Schalter auf der Aufgabenseite oder signierter Link in der Mail, auch ohne Login)
- **Posteingang** (Glocke in der Kopfzeile mit Zähler): dieselben Benachrichtigungen auch in der Anwendung, mit gelesen/ungelesen, „alle gelesen" und Entfernen; es wird kein Kommentartext gespeichert, und Einträge zu Aufgaben, die man nicht mehr sehen darf, bleiben ohne Link. Gilt ab Einführung, ältere Mails erscheinen nicht nachträglich
- **Dunkelmodus und Mobilansicht**: Hell, Dunkel oder System lassen sich im Menü am Avatar umschalten (wird im Browser gemerkt); auf dem Handy wird die Navigation zum ausklappbaren Menü, Werkzeugleisten brechen um, Tabellen blenden Nebenspalten aus und scrollen bei Bedarf
- **MCP-Server für KI-Agenten** (`/mcp`): Agenten wie Claude Code können Projekte und Aufgaben lesen, suchen, anlegen, ändern (auch Unteraufgaben, Status, Tags, Beteiligte, Felder) und kommentieren – mit genau den Rechten und unter dem Namen der Person, der der Token gehört; Löschen ist bewusst nicht möglich. Tokens erstellt man im *Profil* (Ablauf wählbar, optional nur lesend, jederzeit widerrufbar, werden einmalig angezeigt)
- **Zwei-Faktor-Anmeldung und Passkeys** (im *Profil*, nach Passwortbestätigung): Authenticator-App (TOTP) mit QR-Code und Wiederherstellungscodes; Passkeys (Fingerabdruck, Gesicht, Sicherheitsschlüssel) zum Anmelden ohne Passwort, jederzeit hinzufüg- und entfernbar. Administratoren können beides für ausgesperrte Personen zurücksetzen (*Benutzer*)
- **CSV-Export und -Import** (Menü „…“ in der Liste): Export des ganzen Projekts mit Unteraufgaben, Tags, Beteiligten und Feldern (Komma oder Semikolon für Excel; Formeln in Zellen werden entschärft). Import legt neue Aufgaben an, erkennt Komma, Semikolon und Tabulator, UTF-8 und Windows-Kodierung sowie die Spaltennamen unseres Exports und anderer Tools wie Asana (`Name`, `Notes`, `Assignee Email`, `Due Date`, `Tags`, `Parent task`, `Completed At`); vorab gibt es eine Vorschau mit Hinweisen, Zeilen mit Fehlern werden übersprungen (bis 2 MB und 2000 Zeilen)
- **Zeilennummern** in der Liste: eine blasse Zahl vor jedem Titel zählt die angezeigten Zeilen (passend zu Filter und Sortierung), damit man sich gegenseitig auf „Zeile 12“ verweisen kann; sie ist nur eine Orientierung, keine feste Kennung
- **Mehrsprachig (Deutsch und Englisch)**: jede Person wählt ihre Sprache im Profil (Besucher:innen auf der Anmeldeseite oder über den Browser); sie gilt für die ganze Oberfläche, Meldungen, Datumsformate und E-Mails. Weitere Sprachen lassen sich über Übersetzungsdateien ergänzen (siehe *Betrieb*)
- **Mehrfachauswahl in der Liste** (*Auswählen*): Aufgaben ankreuzen (auch alle sichtbaren oder alle, die zu den Filtern passen, bis 500) und gemeinsam erledigen, löschen oder ändern: Status, Zuständige, Fälligkeit, Tags hinzufügen und entfernen; geändert wird nur, was ausgefüllt ist. Wer bei mehreren der Aufgaben zuständig oder beteiligt ist, bekommt für die Statuswechsel eine gebündelte Mail (und einen Posteingang-Eintrag) statt einer pro Aufgabe

Es gibt keine öffentliche Registrierung: Benutzer legt ein Administrator in der App an (*Benutzer*) oder per Kommando (siehe unten). Wer ein Projekt anlegt, verwaltet es und kann dort weitere Mitglieder hinzufügen.

## Voraussetzungen

- PHP 8.3 oder neuer mit den Erweiterungen `dom`, `curl`, `libxml`, `mbstring`, `zip`, `pdo` und `pdo_sqlite` (bzw. dem Treiber deiner Datenbank)
- [Composer](https://getcomposer.org) 2
- Node.js ab Version 22 und npm
- Eine Lizenz für **Flux UI Pro** (siehe nächster Abschnitt)

## Flux Pro einrichten

Sprint nutzt Komponenten aus `livewire/flux-pro` (Kanban, Datumsauswahl, Pillbox). Das Paket kommt aus dem privaten Composer-Repository `composer.fluxui.dev`, das in `composer.json` eingetragen ist. Vor dem ersten `composer install` hinterlegst du deine Zugangsdaten:

```bash
composer config --global http-basic.composer.fluxui.dev "<E-Mail der Lizenz>" "<Lizenzschlüssel>"
```

Die Zugangsdaten gehören **nicht** ins Repository (`auth.json` ist in `.gitignore`).

## Installation

```bash
git clone https://github.com/smares/sprint.git
cd sprint
composer setup
```

`composer setup` installiert die Abhängigkeiten, legt `.env` aus `.env.example` an, erzeugt den App-Key, führt die Migrationen aus und baut die Assets. Standardmäßig läuft Sprint mit SQLite (`database/database.sqlite`, wird bei Bedarf angelegt).

Den ersten Benutzer legst du so an:

```bash
php artisan user:create "Anna Beispiel" anna@example.com --password=geheim
```

Ohne `--password` erzeugt das Kommando ein Passwort und gibt es aus. Mit `--admin` wird die Person Administrator der ganzen Anwendung; bestehende Benutzer machst du so dazu (oder mit `--revoke` wieder zum normalen Benutzer):

```bash
php artisan user:admin anna@example.com
```

Wer das Team verlässt, wird **deaktiviert** statt gelöscht (unter *Benutzer* oder per Kommando; mit `--reactivate` geht es zurück): kein Login mehr, laufende Sitzungen enden sofort, keine neuen Zuweisungen, Erwähnungen, Mails oder Posteingangs-Einträge; Aufgaben, Kommentare und Verlauf bleiben erhalten, bestehende Zuweisungen werden mit „(deaktiviert)“ gekennzeichnet. Sich selbst und den letzten aktiven Administrator kann man nicht deaktivieren.

```bash
php artisan user:deactivate anna@example.com
```

Für lokale Demo-Daten (nur Entwicklung):

```bash
php artisan db:seed
```

Das legt drei Benutzer (`anna@`, `ben@`, `clara@example.com`, Passwort `password`; Anna ist Administratorin) sowie zwei Projekte mit Aufgaben an, in denen alle drei Mitglieder sind.

## Bereitstellung

Sprint ist eine normale Laravel-Anwendung. Egal wo sie läuft, braucht sie dieselben fünf Dinge: **PHP mit Webserver**, **eine Datenbank**, **einen dauerhaft laufenden Queue-Worker** (Mails, Posteingang), **den Scheduler** (jede Minute `php artisan schedule:run`, für die Tageszusammenfassung und die Gesundheitsprüfung) und **dauerhaften Speicher für Anhänge**.

### Checkliste für jeden Host

1. **Code und Abhängigkeiten:** PHP ab 8.3 (Erweiterungen siehe *Voraussetzungen*), `composer install --no-dev --optimize-autoloader` mit den [Flux-Pro-Zugangsdaten](#flux-pro-einrichten), `npm ci --ignore-scripts && npm run build`.
2. **`.env`** (oder Umgebungsvariablen des Hosts), mindestens:
   ```ini
   APP_ENV=production
   APP_DEBUG=false
   APP_KEY=                  # php artisan key:generate --show
   APP_URL=https://sprint.example.com
   APP_TIMEZONE=Europe/Berlin
   APP_LOCALE=de             # Standardsprache für neue Personen, sonst en

   DB_CONNECTION=mysql       # mysql, pgsql oder sqlite (nur mit dauerhafter Platte)
   DB_HOST=...  DB_PORT=...  DB_DATABASE=...  DB_USERNAME=...  DB_PASSWORD=...

   SESSION_DRIVER=database
   CACHE_STORE=database
   QUEUE_CONNECTION=database

   MAIL_MAILER=smtp          # smtp, postmark, resend, ses …
   MAIL_HOST=...  MAIL_PORT=587  MAIL_USERNAME=...  MAIL_PASSWORD=...
   MAIL_FROM_ADDRESS=sprint@example.com
   MAIL_FROM_NAME=Sprint
   ```
   `APP_URL` muss exakt die Adresse sein, unter der die Leute Sprint öffnen (HTTPS): Links in Mails, signierte Abbestell-Links und **Passkeys** hängen daran.
3. **Datenbank anlegen und migrieren:** `php artisan migrate --force` bei jedem Deployment.
4. **Queue-Worker:** `php artisan queue:work --tries=3` als dauerhafter Prozess, nach jedem Deployment neu gestartet (`php artisan queue:restart`).
5. **Scheduler:** jede Minute `php artisan schedule:run`.
6. **Ersten Administrator anlegen:** `php artisan user:create "Anna Beispiel" anna@example.com --admin`.
7. **Prüfen:** `https://sprint.example.com/health` liefert `{"status":"ok", …}` (siehe *Gesundheitsprüfung*); `php artisan sprint:health` zeigt Details. Nach einer Mail-Probe (Kommentar mit Erwähnung) und einem Blick ins Profil (Sprache, Passkey) ist die Installation fertig.
8. **Backup einrichten** (siehe *Backup und Wiederherstellung*).

### Beispiel: Laravel Forge (eigener Server)

Auf einem Forge-Server liegen Datenbank und Dateien dauerhaft auf der Platte, es ist also nichts Besonderes nötig.

1. **Server** anlegen (PHP 8.3 oder neuer, MySQL oder PostgreSQL; SQLite geht auch, dann `DB_DATABASE` auf einen Pfad **außerhalb** des Projektordners setzen, z. B. `/home/forge/sprint.sqlite`, damit ein Deployment sie nie überschreibt).
2. **Site** mit der Domain anlegen, Repository `smares/sprint` (dein Fork), Branch `main`; **SSL** per Let's Encrypt aktivieren.
3. **Flux-Pro-Zugang** einmal auf dem Server hinterlegen (per SSH als Benutzer `forge`), damit `composer install` das private Paket laden kann:
   ```bash
   composer config --global http-basic.composer.fluxui.dev "<E-Mail der Lizenz>" "<Lizenzschlüssel>"
   ```
4. **Umgebung** (*Environment*) mit den Werten aus der Checkliste füllen und `APP_KEY` erzeugen (`php artisan key:generate --show` auf dem Server).
5. **Deployment-Skript** der Site:
   ```bash
   cd $FORGE_SITE_PATH
   git pull origin $FORGE_SITE_BRANCH

   $FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

   npm ci --ignore-scripts
   npm run build

   ( flock -w 10 9 || exit 1
       echo 'Restarting FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock

   $FORGE_PHP artisan migrate --force
   $FORGE_PHP artisan optimize
   $FORGE_PHP artisan queue:restart
   ```
6. **Queue-Worker:** in der Site unter *Queue* einen Worker anlegen (Connection `database`, Queue `default`, 1 Prozess); Forge hält ihn am Leben.
7. **Scheduler:** unter *Scheduler* einen Job mit Befehl `php8.3 /home/forge/sprint.example.com/artisan schedule:run` und Frequenz *jede Minute* anlegen (Pfad und PHP-Version anpassen).
8. **Ersten Administrator** anlegen (Forge-Terminal oder SSH): `php artisan user:create "Anna Beispiel" anna@example.com --admin`.
9. **Backups:** Datenbank-Backups in Forge einrichten und `storage/app/private` (Anhänge) sowie die `.env` zusätzlich sichern, siehe *Backup und Wiederherstellung*. Die Adresse `/health` kannst du in einen externen Uptime-Dienst eintragen.

Aktualisieren: Code auf `main` pushen und in Forge *Deploy now* (oder Quick Deploy aktivieren).

### Beispiel: Laravel Cloud

Laravel Cloud baut aus deinem GitHub-Repository ein Image und startet es ohne Ausfallzeit. Das Dateisystem ist dabei **flüchtig** (jedes Deployment setzt es zurück, jedes Replikat hat eine eigene Platte). Daraus folgt für Sprint:

* **Keine SQLite-Datenbank.** Hänge eine *Laravel MySQL*- oder *Serverless-Postgres*-Datenbank an (gleiche Region wie die App); Cloud setzt `DB_*` selbst. Die Volltextsuche läuft dort automatisch über LIKE.
* **Anhänge gehören in einen Bucket.** Hänge einen privaten *Object Storage*-Bucket als Standard-Disk an; Cloud setzt `FILESYSTEM_DISK` samt Zugangsdaten, und Sprint legt Anhänge auf der Standard-Disk ab (`ATTACHMENTS_DISK` leer lassen). Dafür braucht die Anwendung den S3-Adapter, der **nicht** zu den Abhängigkeiten von Sprint gehört: `composer require league/flysystem-aws-s3-v3 "^3.0" --with-all-dependencies` (einmalig, danach committen).
* **Sitzungen, Cache und Queue in der Datenbank:** `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database` (die Voreinstellung). Die *Managed Queues* von Cloud setzen `QUEUE_CONNECTION=cloud` und das Paket `aws/aws-sdk-php` voraus und sind für Sprint nicht nötig.

So gehst du vor:

1. **Anwendung anlegen:** in Laravel Cloud *New application*, GitHub-Repository wählen, Region, Umgebung *production*, PHP 8.3 oder neuer.
2. **Build-Befehle** der Umgebung (*Deployments*): die Flux-Zugangsdaten gehören **vor** `composer install` hinein, genau wie in der Cloud-Dokumentation für private Pakete beschrieben. Behandle die Build-Befehle deshalb vertraulich:
   ```bash
   composer config http-basic.composer.fluxui.dev "<E-Mail der Lizenz>" "<Lizenzschlüssel>"
   composer install --no-dev
   npm ci --ignore-scripts
   npm run build
   php artisan optimize
   ```
3. **Deploy-Befehl:** `php artisan migrate --force`. Nicht hinzufügen: `queue:restart`, `optimize:clear`, `storage:link` (Cloud übernimmt Neustarts, das Dateisystem bleibt nicht erhalten).
4. **Datenbank und Bucket** anhängen (siehe oben).
5. **Umgebungsvariablen** setzen: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` (zuerst die `…laravel.cloud`-Adresse, später die eigene Domain), `APP_TIMEZONE`, `APP_LOCALE`, die `MAIL_*`-Werte deines Mail-Anbieters (Cloud bringt keinen eigenen Mailversand mit) sowie `SESSION_DRIVER`, `CACHE_STORE` und `QUEUE_CONNECTION` auf `database`. `APP_KEY` erzeugst du lokal mit `php artisan key:generate --show` und trägst ihn ein; sensible Werte gehören in den *Secrets Manager* von Cloud.
6. **Queue-Worker:** am App-Cluster (für kleine Installationen) oder an einem eigenen Worker-Cluster unter *Background processes* einen *Queue worker* mit `queue:work` und einem Prozess anlegen. Soll Mail auch dann rausgehen, wenn die Umgebung sonst schlafen würde, den Worker-Cluster nicht mit der App einschlafen lassen (*Scale to zero* vermeiden).
7. **Scheduler:** am App-Cluster (oder Worker-Cluster) den Schalter **Scheduler** einschalten. Bei mehreren Replikaten läuft die Tageszusammenfassung dank `onOneServer` nur einmal.
8. **Deployen**, dann den ersten Administrator anlegen: im Reiter *Commands* der Umgebung `php artisan user:create "Anna Beispiel" anna@example.com --admin` ausführen.
9. **Eigene Domain:** in Cloud hinzufügen und verifizieren, danach `APP_URL` auf die neue Adresse ändern und neu deployen. **Bestehende Passkeys gelten nur für die alte Adresse**, Passwort und 2FA funktionieren weiter.
10. **Prüfen:** `https://<deine-domain>/health`; die Gesundheitsprüfung zeigt auch, ob Scheduler und Queue-Worker laufen. Backups für Datenbank und Bucket stellst du bei den jeweiligen Cloud-Ressourcen ein; die Befehle im Abschnitt *Backup und Wiederherstellung* gelten für eigene Server.

Die Cloud-CLI (`composer global require laravel/cloud-cli`, dann `cloud ship` bzw. `cloud deploy`) kann dieselben Schritte aus dem Terminal erledigen; die Befehle und Optionen zeigt `cloud -h`.

## Entwicklung

```bash
composer dev      # Server, Queue, Logs und Vite
composer test     # Testsuite (PHPUnit)
vendor/bin/pint   # Code-Stil
```

Änderungen an Views, CSS oder JavaScript erscheinen im Browser erst nach `npm run dev` (oder `npm run build`).

### Aufbau

| Pfad | Inhalt |
| --- | --- |
| `app/Models` | Eloquent-Modelle: `Project`, `Task`, `TaskStatus`, `Tag`, `Comment`, `Team`, `User` |
| `app/Markdown.php` | Rendert Markdown samt `@`-Erwähnungen zu sicherem HTML |
| `resources/views/pages` | Seiten als Livewire-Komponenten (Projekte, Board, Status, Mitglieder, Aufgaben, Administration, Login) |
| `resources/views/components` | Blade-Komponenten (`x-markdown`, `x-markdown-editor`, `x-task-subtree`) |
| `resources/js/app.js` | Alpine-Komponente für das `@`-Auswahlfenster |
| `routes/web.php` | Routen (alles hinter dem Login, außer `/login`) |
| `tests/Feature` | Feature-Tests je Funktionsbereich |

### Tests und CI

GitHub Actions führt die Tests auf PHP 8.3, 8.4 und 8.5 aus (`.github/workflows/tests.yml`). Der Workflow braucht die Repository-Secrets `FLUX_USERNAME` und `FLUX_LICENSE_KEY`, damit Composer `livewire/flux-pro` installieren kann.

### Arbeitsweise

- Branches tragen ein Präfix: `feat/…`, `fix/…`, `chore/…`, `test/…`, `docs/…`
- Änderungen laufen über Pull Requests nach `main`; gemergt wird per Merge-Commit, damit aufeinander aufbauende PRs nicht in Konflikte laufen
- Vor dem Commit: `vendor/bin/pint --dirty` und `composer test`

## Betrieb

- Die Datenbank wird über die Variablen `DB_*` in `.env` gewählt (SQLite, MySQL, PostgreSQL)
- Der Posteingang liegt in der Tabelle `notifications` und wird über dieselbe Queue befüllt wie die Mails (Worker nötig)
- E-Mails brauchen einen Mailer: `MAIL_MAILER` und die übrigen `MAIL_*`-Variablen in `.env` (lokal reicht `log`, dann landen die Mails in `storage/logs`); `MAIL_FROM_ADDRESS` und `MAIL_FROM_NAME` bestimmen den Absender. Versendet wird über die Queue, ohne laufenden Worker kommt nichts an
- Die **Tageszusammenfassung** (werktags 07:30, einstellbar mit `SPRINT_DIGEST_TIME`) braucht den Scheduler: ein Cron-Eintrag, der jede Minute `php artisan schedule:run` startet, z. B. `* * * * * cd /pfad/zu/sprint && php artisan schedule:run >> /dev/null 2>&1`. Die Uhrzeit gilt in der Zeitzone der Anwendung (`APP_TIMEZONE`, in `.env.example` `Europe/Berlin`); sie bestimmt auch, was „heute“ und „überfällig“ heißt. Zum Ausprobieren: `php artisan digest:send --user=anna@example.com`
- Sitzungen, Cache und Queue nutzen standardmäßig die Datenbank (`SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION`); für die Queue läuft im Betrieb ein Worker: `php artisan queue:work`
- Der **MCP-Server** läuft unter `https://<host>/mcp` (Tools: `list-projects`, `list-tasks`, `get-task`, `create-task`, `update-task`, `add-comment`) und braucht kein weiteres Setup außer `php artisan migrate`. Anbinden z. B. mit `claude mcp add --transport http sprint https://<host>/mcp --header "Authorization: Bearer <token>"`; lokal testen mit `php artisan mcp:inspector mcp`. Es gelten nur API-Tokens (keine Browser-Sitzung), 120 Anfragen pro Minute und Token; beim Deaktivieren einer Person werden ihre Tokens gelöscht. Im Betrieb nur über HTTPS erreichbar machen, da der Token im Header übertragen wird
- **Passkeys** brauchen HTTPS (lokal reicht `localhost`) und eine korrekte `APP_URL` in `.env`: Host und Schema der Adresse, unter der die App im Browser aufgerufen wird, bestimmen, wo ein Passkey gilt. Wer die Adresse später ändert, kann bestehende Passkeys nicht mehr nutzen (Passwort und 2FA funktionieren weiter). Wer sich aussperrt, wird von einem Administrator zurückgesetzt (*Benutzer*); API-Tokens für den MCP-Server sind davon unabhängig
- Anhänge liegen privat auf der Standard-Disk (`FILESYSTEM_DISK`, lokal `storage/app/private`; mit `ATTACHMENTS_DISK` lässt sich eine andere Disk wählen, z. B. ein Bucket) und müssen mit gesichert werden; für große Dateien müssen `upload_max_filesize` und `post_max_size` in der PHP-Konfiguration (und ggf. das Limit des Webservers) mindestens 20 MB erlauben
- Der Suchindex (SQLite/FTS5) wird von der Anwendung gepflegt und von der Migration aufgebaut. Nach einem Restore oder bei Unstimmigkeiten: `php artisan search:rebuild`. Ohne FTS5 (z. B. MySQL/PostgreSQL) läuft die Suche automatisch über LIKE und braucht keinen Index
- **Sprachen:** jede Person hat in ihrem Profil eine Sprache (Spalte `users.locale`, zweistelliges Kürzel wie `de`, `en`); sie bestimmt Oberfläche, E-Mails, Meldungen und Datumsformate. Besucher:innen bekommen die Sprache ihres Browsers oder wählen sie auf der Anmeldeseite. `APP_LOCALE` (Standard `en`, in `.env.example` gesetzt) ist die Voreinstellung für neue Personen und für Browser mit einer anderen Sprache. Die Konsolenbefehle (`php artisan user:create` usw.) sprechen Englisch. Mails liegen pro Sprache als eigene Vorlagen unter `resources/views/mail/<kürzel>/` (Inhalt) und `…/subjects/` (Betreff); fehlt eine Sprache, gilt Englisch. Die Oberfläche nutzt englische Ausgangstexte als Schlüssel (`__('New task')`), die Übersetzungen stehen in `lang/<kürzel>.json` (für Deutsch `lang/de.json`; `lang/en.json` bleibt leer). Standard-Status und das Feld „Priorität“ neuer Projekte entstehen in der Sprache der Person, die das Projekt anlegt; bereits gespeicherte Daten (Projekt-, Status-, Tag- und Feldnamen, Aufgabentexte) werden nicht übersetzt. Eine neue Sprache braucht `lang/<kürzel>.json`, den Ordner `lang/<kürzel>/` (Laravel-Texte für Validierung, Anmeldung …), die Mail-Vorlagen und einen Eintrag in `config/sprint.php`
- Der Scheduler schreibt jede Minute einen Herzschlag, den die Gesundheitsprüfung auswertet (siehe unten)
- Nach jedem Update: `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, `npm ci --ignore-scripts && npm run build`
- Vor Migrationen, die bestehende Daten umbauen, ein Datenbank-Backup ziehen
- Beim Einführen der Rollen werden alle bestehenden Benutzer Verwalter aller bestehenden Projekte (alles bleibt wie bisher erreichbar); danach per `php artisan user:admin <E-Mail>` Administratoren bestimmen und Mitgliedschaften in den Projekten anpassen

### Gesundheitsprüfung

- `GET /health` ohne Login für Uptime-Monitore: Antwort `200` mit `{"status":"ok"|"degraded", "checks":{…}}`, solange Datenbank, Speicher (`storage/app/private`) und Cache funktionieren, sonst `503` mit `"status":"down"`. `degraded` bedeutet nur Warnungen: der **Scheduler** hat sich länger als 5 Minuten nicht gemeldet (Cron-Eintrag fehlt, Tageszusammenfassung kommt nicht) oder die **Queue** hat einen Rückstand bzw. fehlgeschlagene Jobs (Worker läuft nicht, Mails und Posteingang kommen nicht an). Öffentlich werden nur die Statuswerte gezeigt, keine Details; die Route ist auf 60 Anfragen pro Minute und IP begrenzt
- `php artisan sprint:health` zeigt dieselben Prüfungen mit Details im Terminal (Exit-Code `1` nur bei `down`), praktisch nach einem Deployment
- `GET /up` (von Laravel) prüft nur, ob die Anwendung startet, und eignet sich als einfacher Lebenszeichen-Check für Load Balancer

### Backup und Wiederherstellung

Gesichert werden müssen drei Dinge, sonst nichts (Logs, Caches und der Suchindex lassen sich neu erzeugen):

1. **Die Datenbank**, am besten mit dem Werkzeug des Datenbanksystems statt per Dateikopie, damit der Stand konsistent ist:
   - SQLite: `sqlite3 database/database.sqlite ".backup '/backup/sprint-$(date +%F).sqlite'"` (nicht einfach `cp`, solange die Anwendung läuft)
   - MySQL/MariaDB: `mysqldump --single-transaction --routines sprint > /backup/sprint-$(date +%F).sql`
   - PostgreSQL: `pg_dump -Fc sprint > /backup/sprint-$(date +%F).dump`
2. **Die Anhänge** in `storage/app/private`: `tar czf /backup/sprint-files-$(date +%F).tar.gz -C storage/app private`
3. **Die Datei `.env`**, vor allem den `APP_KEY`: ohne ihn sind Zwei-Faktor-Geheimnisse, Wiederherstellungscodes und Sitzungen nicht mehr lesbar. Sie gehört an einen anderen, geschützten Ort als die Datenbank-Backups (Passwörter!)

Ein nächtlicher Cron-Eintrag mit den drei Befehlen genügt, dazu eine Aufbewahrung nach Bedarf (z. B. 14 Tage täglich) und die Kopie auf ein anderes System. Ein Backup zählt erst, wenn die Wiederherstellung einmal ausprobiert wurde.

**Wiederherstellen** auf einem frischen oder vorbereiteten System: Anwendung und Abhängigkeiten installieren (siehe *Installation*), `.env` zurückspielen, Datenbank einspielen (`sqlite3`-Datei an ihren Platz kopieren bzw. `mysql sprint < sprint.sql` / `pg_restore -d sprint sprint.dump`), den Ordner `private` nach `storage/app/` entpacken, dann `php artisan migrate --force` (falls das Backup von einer älteren Version stammt), `php artisan search:rebuild`, `php artisan queue:restart` und zum Schluss `php artisan sprint:health`.

## Lizenz

Sprint ist proprietäre Software, alle Rechte vorbehalten (siehe [LICENSE](LICENSE)). Flux UI Pro ist ein kommerzielles Produkt und braucht eine eigene Lizenz.
