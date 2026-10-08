# Entwicklung

```bash
composer dev      # Server, Queue, Logs und Vite
composer test     # Testsuite (PHPUnit)
vendor/bin/pint   # Code-Stil
composer analyse  # Statische Analyse (Larastan)
composer refactor:check  # Rector prüfen (ohne Änderungen); composer refactor wendet sie an
```

Änderungen an Views, CSS oder JavaScript erscheinen im Browser erst nach `npm run dev` (oder `npm run build`).

## Statische Analyse und Rector

- **Larastan** (`phpstan.neon`) prüft `app`, `config`, `database` und `routes` auf Stufe 5. Die Funde, die sich nicht sinnvoll beheben lassen (Larastan-Fehlmeldungen bei `first()` mit Closure und bei Pivot-Zugriffen), stehen in `phpstan-baseline.neon`; die Meldung „Trait wird nicht benutzt“ für `app/Concerns` ist in `phpstan.neon` abgeschaltet, weil nur die Livewire-Komponenten diese Traits nutzen; neue Fehler dürfen nicht dazukommen. Wer alte Fehler behebt, erzeugt die Baseline neu: `vendor/bin/phpstan analyse --generate-baseline`. Die PHP-Blöcke in den Livewire-Blade-Dateien prüft das Werkzeug nicht
- **Rector** (`rector.php`) enthält die PHP-8.3-Regeln, Totcode, Code-Qualität, Typdeklarationen, frühe Rückgaben und die Laravel-Regeln. Ausgeschaltet sind Regeln, die Konventionen des Projekts ändern würden (`strict_types`, `resolve()` statt `app()`, Typen an jeder Closure, `Date` statt `Carbon`, `#[Scope]` statt `scopeName()`). Nach `composer refactor` immer `vendor/bin/pint` und die Tests laufen lassen
- Beides läuft in der CI vor den Tests

## Aufbau

| Pfad | Inhalt |
| --- | --- |
| `app/Enums` | Aufzählungen: `ProjectRole`, `CustomFieldType`, `RepeatUnit`, `RepeatMode`, `ActivityType` (Einträge im Verlauf samt Satz), `AutomationTrigger`, `AutomationAction` |
| `app/Models` | Eloquent-Modelle: `Project`, `Task`, `TaskStatus`, `TaskActivity`, `Tag`, `Comment`, `Attachment`, `CustomField`, `CustomFieldOption`, `CustomFieldValue`, `SavedFilter`, `Automation` (Regel eines Projekts), `Reaction` (Emoji auf Aufgabe oder Kommentar, eine je Person), `Team`, `User`, `UserAvatar` (Profilbild, eigene Tabelle) |
| `app/Policies` | Berechtigungen: `ProjectPolicy` (sehen, bearbeiten, verwalten), `CommentPolicy`, `SavedFilterPolicy` |
| `app/Services` | Dienste (Klassen mit Endung `Service`): `MarkdownService` (Markdown samt Erwähnungen und Bildern zu sicherem HTML), `TaskSearchService`, `TaskCsvService`, `DailyDigestService`, `HealthCheckService`, `InboxTextService`, `LocaleService` (Sprachen, ISO-Datumsformate), `RealtimeService` (Reverb), `DateService`, `BackupService`, `AutomationService` (führt Regeln nach einer Änderung aus) |
| `resources/views/pages` | Seiten als Livewire-Komponenten (Projekte, Board, Status, Mitglieder, Aufgaben, Administration, Login) |
| `app/Concerns` | Traits: `ShowsProject` (gemeinsamer Teil von Liste, Board, Kalender, Zeitleiste), `ListensForRealtime`, `OpensTaskPanel`, `EditsTasksInBulk`, `ConfirmsPassword`, `HasPosition` (manuelle Reihenfolge der Modelle), `HasReactions` (Reactions auf Aufgabe und Kommentar) |
| `resources/views/components` | Blade-Komponenten (u. a. `x-project-header`, `x-task-title-link`, `x-markdown`, `x-markdown-editor`, `x-task-subtree`) und kleine Livewire-Komponenten mit ⚡ (`task-create`, Projekt-Dialoge, Glocke, Befehlspalette) |
| `resources/js/app.js` | Alpine-Komponenten (`@`-Auswahl, Zeitleistenbalken, Anwesenheit, Profilbild), Bildvorschau, Tastenkürzel; Passkeys werden erst bei Bedarf geladen |
| `resources/js/realtime.js` | Echo/Reverb-Verbindung; das Layout lädt die Datei nur, wenn Live-Updates eingeschaltet sind |
| `routes/web.php` | Routen; alles hinter dem Login außer `/login`, Passkey-Anmeldung, Sprachwahl (`/locale`), `/health` und dem signierten Abmeldelink aus Benachrichtigungs-Mails |
| `app/Mcp` | MCP-Server für KI-Agenten (Tools unter `app/Mcp/Tools`) |
| `tests/Feature` | Feature-Tests je Funktionsbereich |

### Automatisierungen

Eine Regel (`Automation`) gehört zu einem Projekt: **Auslöser** (Status wechselt zu X, Zuständige wechseln, Tag wird hinzugefügt), optionale **Bedingungen** (Status, Zuständige, Tag; geprüft nach der Änderung) und eine Liste von **Aktionen** (Zuständige oder Status setzen, Tag hinzufügen, Fälligkeit verschieben, kommentieren, jemanden im Posteingang benachrichtigen).

- Aufgehängt ist alles in `Task::logActivity()`: Die Einträge, die einen Auslöser darstellen, merkt sich `AutomationService`, und `Task` ruft `flush()` auf, sobald das Speichern ganz abgeschlossen ist. So arbeitet die Regel auf der fertigen Aufgabe, und ein verschachteltes Speichern stört das laufende nicht.
- **Keine Ketten:** Was eine Regel ändert, löst keine weitere aus.
- **Akteur ist die Regel:** Der Verlauf bekommt `user_id = null`, `automation_id` und in `data` den Namen der Regel (`automation`) sowie die auslösende Person (`by`); Kommentare haben `user_id = null` und `automation_name`. Benachrichtigungen nennen die Regel als Urheber und gehen nicht an die Person, die sie ausgelöst hat.
- **Rechte:** Die Regel handelt mit den Rechten ihres Erstellers (`created_by`). Darf er das Projekt nicht mehr bearbeiten, schaltet sie sich ab, statt zu laufen. Zuständige und Benachrichtigte müssen das Projekt sehen dürfen; Status, Tags und Personen aus anderen Projekten werden ignoriert. Eine fehlerhafte Aktion bricht weder das Speichern noch die folgenden Aktionen ab.

## Release

Ein Release entsteht über den Workflow *Release* (Actions → Release → *Run workflow*, Zweig `main`) mit einer Version wie `0.1.0` (Vorabversionen wie `1.0.0-rc.1` sind erlaubt). Er baut das Docker-Image mit dem Flux-Pro-Zugang aus den Secrets, je Architektur (`amd64`, `arm64`) auf einem Runner der jeweiligen Architektur ohne Emulation, schiebt es nach `ghcr.io/smares/sprint` (Tags `0.1.0`, `0.1`, bei einer Hauptversion auch `latest`), legt den Tag `v0.1.0` samt GitHub-Release mit automatisch erzeugten Hinweisen an und prüft zuletzt, dass das Paket privat ist. Mit *dry run* baut er nur (beide Architekturen), auf jedem Zweig, und veröffentlicht nichts. Das Image ist privat zu halten, weil es Flux Pro enthält; siehe [Fertiges Image](deployment-docker.md#fertiges-image).

## Tests und CI

GitHub Actions führt die Tests auf PHP 8.3, 8.4 und 8.5 aus (`.github/workflows/tests.yml`). Ändern sich `Dockerfile`, `compose.yaml`, `docker/` oder die Lockfiles, baut `.github/workflows/docker.yml` zusätzlich das Image und startet den ganzen Stack samt Reverb. Die Workflows brauchen die Repository-Secrets `FLUX_USERNAME` und `FLUX_LICENSE_KEY`, damit Composer `livewire/flux-pro` installieren kann.

**Abhängigkeiten:** `.github/workflows/audit.yml` prüft Composer- und npm-Pakete auf bekannte Sicherheitslücken, bei Änderungen an den Lockfiles, auf `main` und täglich (aufgegebene Pakete werden nur gemeldet). Dependabot (`.github/dependabot.yml`) öffnet wöchentlich Update-PRs für Composer, npm, GitHub Actions und das Docker-Basis-Image, kleinere Updates gebündelt. Dieselben zwei Werte braucht es zusätzlich als **Dependabot-Secrets** (*Settings → Secrets and variables → Dependabot*): Dependabot selbst braucht sie, um `livewire/flux-pro` aufzulösen, und Workflows auf Dependabot-PRs sehen nur diese Secrets, nicht die Actions-Secrets. Fehlen sie, bricht jeder Workflow gleich zu Beginn mit einem Hinweis darauf ab.

## Arbeitsweise

- Branches tragen ein Präfix: `feat/…`, `fix/…`, `refactor/…`, `perf/…`, `chore/…`, `test/…`, `docs/…`
- Änderungen laufen über Pull Requests nach `main`; gemergt wird per Merge-Commit, damit aufeinander aufbauende PRs nicht in Konflikte laufen
- Vor dem Commit: `vendor/bin/pint --dirty` und `composer test`
