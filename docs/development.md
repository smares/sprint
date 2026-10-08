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
| `app/Enums` | Aufzählungen: `ProjectRole`, `CustomFieldType`, `RepeatUnit`, `RepeatMode`, `ActivityType` (Einträge im Verlauf samt Satz) |
| `app/Models` | Eloquent-Modelle: `Project`, `Task`, `TaskStatus`, `TaskActivity`, `Tag`, `Comment`, `Attachment`, `CustomField`, `CustomFieldOption`, `CustomFieldValue`, `SavedFilter`, `Team`, `User`, `UserAvatar` (Profilbild, eigene Tabelle) |
| `app/Policies` | Berechtigungen: `ProjectPolicy` (sehen, bearbeiten, verwalten), `CommentPolicy`, `SavedFilterPolicy` |
| `app/Services` | Dienste (Klassen mit Endung `Service`): `MarkdownService` (Markdown samt Erwähnungen und Bildern zu sicherem HTML), `TaskSearchService`, `TaskCsvService`, `DailyDigestService`, `HealthCheckService`, `InboxTextService`, `LocaleService` (Sprachen, ISO-Datumsformate), `RealtimeService` (Reverb), `DateService`, `BackupService` |
| `resources/views/pages` | Seiten als Livewire-Komponenten (Projekte, Board, Status, Mitglieder, Aufgaben, Administration, Login) |
| `app/Concerns` | Traits: `ShowsProject` (gemeinsamer Teil von Liste, Board, Kalender, Zeitleiste), `ListensForRealtime`, `OpensTaskPanel`, `EditsTasksInBulk`, `ConfirmsPassword`, `HasPosition` (manuelle Reihenfolge der Modelle) |
| `resources/views/components` | Blade-Komponenten (u. a. `x-project-header`, `x-task-title-link`, `x-markdown`, `x-markdown-editor`, `x-task-subtree`) und kleine Livewire-Komponenten mit ⚡ (`task-create`, Projekt-Dialoge, Glocke, Befehlspalette) |
| `resources/js/app.js` | Alpine-Komponenten (`@`-Auswahl, Zeitleistenbalken, Anwesenheit), Bildvorschau; Passkeys werden erst bei Bedarf geladen |
| `resources/js/realtime.js` | Echo/Reverb-Verbindung; das Layout lädt die Datei nur, wenn Live-Updates eingeschaltet sind |
| `routes/web.php` | Routen; alles hinter dem Login außer `/login`, Passkey-Anmeldung, Sprachwahl (`/locale`), `/health` und dem signierten Abmeldelink aus Benachrichtigungs-Mails |
| `app/Mcp` | MCP-Server für KI-Agenten (Tools unter `app/Mcp/Tools`) |
| `tests/Feature` | Feature-Tests je Funktionsbereich |

## Tests und CI

GitHub Actions führt die Tests auf PHP 8.3, 8.4 und 8.5 aus (`.github/workflows/tests.yml`). Ändern sich `Dockerfile`, `compose.yaml`, `docker/` oder die Lockfiles, baut `.github/workflows/docker.yml` zusätzlich das Image und startet den ganzen Stack samt Reverb. Die Workflows brauchen die Repository-Secrets `FLUX_USERNAME` und `FLUX_LICENSE_KEY`, damit Composer `livewire/flux-pro` installieren kann.

**Abhängigkeiten:** `.github/workflows/audit.yml` prüft Composer- und npm-Pakete auf bekannte Sicherheitslücken, bei Änderungen an den Lockfiles, auf `main` und täglich (aufgegebene Pakete werden nur gemeldet). Dependabot (`.github/dependabot.yml`) öffnet wöchentlich Update-PRs für Composer, npm, GitHub Actions und das Docker-Basis-Image, kleinere Updates gebündelt. Damit Dependabot `livewire/flux-pro` auflösen kann, braucht es dieselben zwei Werte zusätzlich als **Dependabot-Secrets** (*Settings → Secrets and variables → Dependabot*); die Actions-Secrets sieht Dependabot nicht.

## Arbeitsweise

- Branches tragen ein Präfix: `feat/…`, `fix/…`, `refactor/…`, `perf/…`, `chore/…`, `test/…`, `docs/…`
- Änderungen laufen über Pull Requests nach `main`; gemergt wird per Merge-Commit, damit aufeinander aufbauende PRs nicht in Konflikte laufen
- Vor dem Commit: `vendor/bin/pint --dirty` und `composer test`
