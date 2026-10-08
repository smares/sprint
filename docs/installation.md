# Installation

Diese Anleitung richtet Sprint für die Entwicklung oder einen einfachen Server ein. Für den Produktivbetrieb gibt es die [Bereitstellung](deployment.md).

## Voraussetzungen

- PHP 8.4 oder neuer mit den Erweiterungen `dom`, `curl`, `libxml`, `mbstring`, `zip`, `pdo` und `pdo_sqlite` (bzw. dem Treiber deiner Datenbank)
- [Composer](https://getcomposer.org) 2
- Node.js ab Version 22 und npm
- Eine Lizenz für **Flux UI Pro** (siehe unten)

## Flux Pro einrichten

Sprint nutzt Komponenten aus `livewire/flux-pro` (Kanban, Datumsauswahl, Pillbox). Das Paket kommt aus dem privaten Composer-Repository `composer.fluxui.dev`, das in `composer.json` eingetragen ist. Vor dem ersten `composer install` hinterlegst du deine Zugangsdaten:

```bash
composer config --global http-basic.composer.fluxui.dev "<E-Mail der Lizenz>" "<Lizenzschlüssel>"
```

Die Zugangsdaten gehören **nicht** ins Repository (`auth.json` ist in `.gitignore`).

## Installieren

```bash
git clone https://github.com/smares/sprint.git
cd sprint
composer setup
```

`composer setup` installiert die Abhängigkeiten, legt `.env` aus `.env.example` an, erzeugt den App-Key, führt die Migrationen aus und baut die Assets. Standardmäßig läuft Sprint mit SQLite (`database/database.sqlite`, wird bei Bedarf angelegt).

## Benutzer verwalten

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

## Demo-Daten

Für lokale Demo-Daten (nur Entwicklung):

```bash
php artisan db:seed
```

Das legt drei Benutzer (`anna@`, `ben@`, `clara@example.com`, Passwort `password`; Anna ist Administratorin) sowie zwei Projekte mit Aufgaben an, in denen alle drei Mitglieder sind.
