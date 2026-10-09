# Dokumentation

Anleitungen für Sprint, nach Aufgabe sortiert.

## Inhalt

- [Funktionen](features.md): was Sprint kann
- [Installation](installation.md): Voraussetzungen, Flux Pro, Setup, Benutzer und Demo-Daten
- [Bereitstellung](deployment.md): Checkliste für jeden Host
  - [Laravel Forge](deployment-forge.md): eigener Server
  - [Laravel Cloud](deployment-laravel-cloud.md): flüchtiges Dateisystem, Bucket und Datenbank als Ressourcen
  - [Docker Compose](deployment-docker.md): ein Image für App, Worker, Scheduler und Reverb (selbst gebaut oder als fertiges Release-Image), Daten im Volume
  - [Traefik, selbst verwaltet](deployment-traefik.md): das fertige Image hinter einem eigenen Traefik, ohne veröffentlichte Ports
  - [Dokploy](deployment-dokploy.md): das Docker-Setup hinter Traefik, mit Domain, HTTPS und Volume-Backups aus der Oberfläche
- [Konfiguration](configuration.md): E-Mail, Tageszusammenfassung, Anhänge, MCP, Passkeys, Suche, Sprachen
- [Wartung](maintenance.md): Updates, Gesundheitsprüfung, Backup und Wiederherstellung
- [Entwicklung](development.md): Befehle, Aufbau, Tests und Arbeitsweise
