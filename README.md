<img src="public/favicon.svg" alt="" width="64" height="64">

# Sprint

Sprint ist eine schlanke Aufgabenverwaltung für Teams: Projekte, Aufgaben und Subtasks, Liste, Kanban-Board, Kalender und Zeitleiste, Kommentare mit Markdown und `@`-Erwähnungen, Benachrichtigungen per Mail, im Posteingang und als Push auf Browser und Handy, ein MCP-Server für KI-Agenten. Die Oberfläche gibt es auf Deutsch und Englisch.

Gebaut mit Laravel 13, Livewire 4 und [Flux UI Pro](https://fluxui.dev) (kommerzielle Lizenz nötig).

## Schnellstart

```bash
git clone https://github.com/smares/sprint.git
cd sprint
composer setup
php artisan user:create "Anna Beispiel" anna@example.com --admin
composer dev
```

Vorher die Zugangsdaten für `livewire/flux-pro` hinterlegen, siehe [Installation](docs/installation.md).

## Dokumentation

Alles Weitere steht in [`docs/`](docs/README.md):

- [Funktionen](docs/features.md)
- [Installation](docs/installation.md)
- [Bereitstellung](docs/deployment.md) (Laravel Forge, Laravel Cloud, Docker Compose, Dokploy)
- [Konfiguration](docs/configuration.md)
- [Wartung](docs/maintenance.md) (Updates, Gesundheitsprüfung, Backup)
- [Entwicklung](docs/development.md)

## Lizenz

Sprint ist proprietäre Software, alle Rechte vorbehalten (siehe [LICENSE](LICENSE)). Flux UI Pro ist ein kommerzielles Produkt und braucht eine eigene Lizenz.
