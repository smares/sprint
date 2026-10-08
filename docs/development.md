# Entwicklung

```bash
composer dev      # Server, Queue, Logs und Vite
composer test     # Testsuite (PHPUnit)
vendor/bin/pint   # Code-Stil
```

Änderungen an Views, CSS oder JavaScript erscheinen im Browser erst nach `npm run dev` (oder `npm run build`).

## Aufbau

| Pfad | Inhalt |
| --- | --- |
| `app/Models` | Eloquent-Modelle: `Project`, `Task`, `TaskStatus`, `Tag`, `Comment`, `Team`, `User` |
| `app/Markdown.php` | Rendert Markdown samt `@`-Erwähnungen zu sicherem HTML |
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
