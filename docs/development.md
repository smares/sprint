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

- **Larastan** (`phpstan.neon`) prüft `app`, `config`, `database` und `routes` auf Stufe 5. Die 13 Funde, die sich nicht sinnvoll beheben lassen (Larastan-Fehlmeldungen bei `first()` mit Closure und bei Pivot-Zugriffen, Traits, die nur Livewire-Komponenten nutzen), stehen in `phpstan-baseline.neon`; neue Fehler dürfen nicht dazukommen. Wer alte Fehler behebt, erzeugt die Baseline neu: `vendor/bin/phpstan analyse --generate-baseline`. Die PHP-Blöcke in den Livewire-Blade-Dateien prüft das Werkzeug nicht
- **Rector** (`rector.php`) enthält die PHP-8.3-Regeln, Totcode, Code-Qualität, Typdeklarationen, frühe Rückgaben und die Laravel-Regeln. Ausgeschaltet sind Regeln, die Konventionen des Projekts ändern würden (`strict_types`, `resolve()` statt `app()`, Typen an jeder Closure, `Date` statt `Carbon`, `#[Scope]` statt `scopeName()`). Nach `composer refactor` immer `vendor/bin/pint` und die Tests laufen lassen
- Beides läuft in der CI vor den Tests

## Aufbau

| Pfad | Inhalt |
| --- | --- |
| `app/Enums` | Aufzählungen: `ProjectRole`, `CustomFieldType`, `RepeatUnit`, `RepeatMode` |
| `app/Models` | Eloquent-Modelle: `Project`, `Task`, `TaskStatus`, `Tag`, `Comment`, `Team`, `User` |
| `app/Services` | Dienste (Klassen mit Endung `Service`): `MarkdownService` (Markdown samt Erwähnungen und Bildern zu sicherem HTML), `TaskSearchService`, `TaskCsvService`, `DailyDigestService`, `HealthCheckService`, `InboxTextService`, `LocaleService` |
| `resources/views/pages` | Seiten als Livewire-Komponenten (Projekte, Board, Status, Mitglieder, Aufgaben, Administration, Login) |
| `resources/views/components` | Blade-Komponenten (`x-markdown`, `x-markdown-editor`, `x-task-subtree`) |
| `resources/js/app.js` | Alpine-Komponente für das `@`-Auswahlfenster |
| `routes/web.php` | Routen (alles hinter dem Login, außer `/login`) |
| `tests/Feature` | Feature-Tests je Funktionsbereich |

## Tests und CI

GitHub Actions führt die Tests auf PHP 8.3, 8.4 und 8.5 aus (`.github/workflows/tests.yml`). Der Workflow braucht die Repository-Secrets `FLUX_USERNAME` und `FLUX_LICENSE_KEY`, damit Composer `livewire/flux-pro` installieren kann.

## Arbeitsweise

- Branches tragen ein Präfix: `feat/…`, `fix/…`, `chore/…`, `test/…`, `docs/…`
- Änderungen laufen über Pull Requests nach `main`; gemergt wird per Merge-Commit, damit aufeinander aufbauende PRs nicht in Konflikte laufen
- Vor dem Commit: `vendor/bin/pint --dirty` und `composer test`
