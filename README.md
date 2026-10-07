# Sprint

Sprint ist eine schlanke Aufgabenverwaltung für Teams: Projekte, Aufgaben und Subtasks, ein Kanban-Board, Kommentare mit Markdown und `@`-Erwähnungen.

Gebaut mit Laravel 13, Livewire 4 und [Flux UI Pro](https://fluxui.dev). Die Oberfläche ist deutsch.

## Funktionen

- **Rollen und Rechte:** Projekte sehen nur ihre Mitglieder. Pro Projekt gibt es die Rollen *Ansehen*, *Bearbeiten* und *Verwalten* (Mitglieder und Status); Administratoren der Anwendung haben überall Zugriff. Zuständige, Beteiligte, Erwähnungen und E-Mails gibt es nur für Personen, die das Projekt sehen dürfen
- **Projekte und Aufgaben** mit Titel, Beschreibung, zuständiger Person, Fälligkeit und Status
- **Liste und Kanban-Board** pro Projekt, mit Filtern (Status, Person, Tag) und Sortierung per Klick auf die Spaltenköpfe
- **Manuelle Reihenfolge** per Drag & Drop; Liste und Board teilen eine Reihenfolge
- **Eigene Status pro Projekt** mit Name, Farbe, Reihenfolge und „gilt als erledigt“-Markierung (unter *Status* im Projekt)
- **Subtasks in beliebiger Tiefe** mit Fortschritt, Zwischenüberschriften und Drag & Drop; der Fortschritt zeigt nur an und erledigt die Hauptaufgabe nicht automatisch
- **Tags pro Projekt** (derselbe Name darf in mehreren Projekten existieren)
- **Abhängigkeiten** („blockiert“ / „blockiert von“) nur als Markierung, ohne Sperre; Zyklen werden abgelehnt
- **Beteiligte** (mehrere Personen) zusätzlich zur zuständigen Person; *Meine Aufgaben* enthält auch Aufgaben, an denen man beteiligt ist
- **Aktivitätsverlauf** an jeder Aufgabe: wer wann Status, Zuständige, Fälligkeit, Titel, Beschreibung, Tags, Beteiligte oder Abhängigkeiten geändert hat, zusammen mit den Kommentaren in einer Zeitleiste
- **Kommentare** mit Markdown (GitHub-Variante: Tabellen, Durchgestrichen, Aufgabenlisten, automatische Links)
- **Markdown** auch in der Beschreibung, mit Vorschau
- **`@`-Erwähnungen** von Personen und Aufgaben: ein `@` tippen, aus dem Fenster wählen; angezeigt wird immer der aktuelle Name bzw. Titel
- **E-Mail-Benachrichtigungen** an zuständige und beteiligte Personen bei neuen Kommentaren und Statuswechseln sowie an erwähnte Personen (auch ohne Beteiligung, einmal pro neuer Erwähnung); pro Aufgabe abbestellbar (Schalter auf der Aufgabenseite oder signierter Link in der Mail, auch ohne Login)

Es gibt keine öffentliche Registrierung, Benutzer legt ein Administrator per Kommando an (siehe unten). Wer ein Projekt anlegt, verwaltet es und kann dort weitere Mitglieder hinzufügen.

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

Für lokale Demo-Daten (nur Entwicklung):

```bash
php artisan db:seed
```

Das legt drei Benutzer (`anna@`, `ben@`, `clara@example.com`, Passwort `password`; Anna ist Administratorin) sowie zwei Projekte mit Aufgaben an, in denen alle drei Mitglieder sind.

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
| `app/Models` | Eloquent-Modelle: `Project`, `Task`, `TaskStatus`, `Tag`, `Comment`, `User` |
| `app/Markdown.php` | Rendert Markdown samt `@`-Erwähnungen zu sicherem HTML |
| `resources/views/pages` | Seiten als Livewire-Komponenten (Projekte, Board, Status, Aufgaben, Login) |
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
- E-Mails brauchen einen Mailer: `MAIL_MAILER` und die übrigen `MAIL_*`-Variablen in `.env` (lokal reicht `log`, dann landen die Mails in `storage/logs`); `MAIL_FROM_ADDRESS` und `MAIL_FROM_NAME` bestimmen den Absender. Versendet wird über die Queue, ohne laufenden Worker kommt nichts an
- Sitzungen, Cache und Queue nutzen standardmäßig die Datenbank (`SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION`); für die Queue läuft im Betrieb ein Worker: `php artisan queue:work`
- Nach jedem Update: `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, `npm ci --ignore-scripts && npm run build`
- Vor Migrationen, die bestehende Daten umbauen, ein Datenbank-Backup ziehen
- Beim Einführen der Rollen werden alle bestehenden Benutzer Verwalter aller bestehenden Projekte (alles bleibt wie bisher erreichbar); danach per `php artisan user:admin <E-Mail>` Administratoren bestimmen und Mitgliedschaften in den Projekten anpassen

## Lizenz

Sprint ist proprietäre Software, alle Rechte vorbehalten (siehe [LICENSE](LICENSE)). Flux UI Pro ist ein kommerzielles Produkt und braucht eine eigene Lizenz.
