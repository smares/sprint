# Wartung

Updates, Gesundheitsprüfung und Backups.

## Updates

- Nach jedem Update: `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, `npm ci --ignore-scripts && npm run build`
- Vor Migrationen, die bestehende Daten umbauen, ein Datenbank-Backup ziehen
- Beim Einführen der Rollen werden alle bestehenden Benutzer Verwalter aller bestehenden Projekte (alles bleibt wie bisher erreichbar); danach per `php artisan user:admin <E-Mail>` Administratoren bestimmen und Mitgliedschaften in den Projekten anpassen

## Gesundheitsprüfung

- `GET /health` ohne Login für Uptime-Monitore: Antwort `200` mit `{"status":"ok"|"degraded", "checks":{…}}`, solange Datenbank, Speicher (Standard-Disk) und Cache funktionieren, sonst `503` mit `"status":"down"`. `degraded` bedeutet nur Warnungen: der **Scheduler** hat sich länger als 5 Minuten nicht gemeldet (Cron-Eintrag fehlt, Tageszusammenfassung kommt nicht) oder die **Queue** hat einen Rückstand bzw. fehlgeschlagene Jobs (Worker läuft nicht, Mails und Posteingang kommen nicht an). Öffentlich werden nur die Statuswerte gezeigt, keine Details; die Route ist auf 60 Anfragen pro Minute und IP begrenzt
- `php artisan sprint:health` zeigt dieselben Prüfungen mit Details im Terminal (Exit-Code `1` nur bei `down`), praktisch nach einem Deployment
- `GET /up` (von Laravel) prüft nur, ob die Anwendung startet, und eignet sich als einfacher Lebenszeichen-Check für Load Balancer

## Backup und Wiederherstellung

Gesichert werden müssen drei Dinge, sonst nichts (Logs, Caches und der Suchindex lassen sich neu erzeugen):

1. **Die Datenbank**, am besten mit dem Werkzeug des Datenbanksystems statt per Dateikopie, damit der Stand konsistent ist:
   - SQLite: `sqlite3 database/database.sqlite ".backup '/backup/sprint-$(date +%F).sqlite'"` (nicht einfach `cp`, solange die Anwendung läuft)
   - MySQL/MariaDB: `mysqldump --single-transaction --routines sprint > /backup/sprint-$(date +%F).sql`
   - PostgreSQL: `pg_dump -Fc sprint > /backup/sprint-$(date +%F).dump`
2. **Die Anhänge** auf der Standard-Disk, lokal in `storage/app/private`: `tar czf /backup/sprint-files-$(date +%F).tar.gz -C storage/app private`
3. **Die Datei `.env`**, vor allem den `APP_KEY`: ohne ihn sind Zwei-Faktor-Geheimnisse, Wiederherstellungscodes und Sitzungen nicht mehr lesbar. Sie gehört an einen anderen, geschützten Ort als die Datenbank-Backups (Passwörter!)

Ein nächtlicher Cron-Eintrag mit den drei Befehlen genügt, dazu eine Aufbewahrung nach Bedarf (z. B. 14 Tage täglich) und die Kopie auf ein anderes System. Ein Backup zählt erst, wenn die Wiederherstellung einmal ausprobiert wurde.

**Wiederherstellen** auf einem frischen oder vorbereiteten System: Anwendung und Abhängigkeiten installieren (siehe [Installation](installation.md)), `.env` zurückspielen, Datenbank einspielen (`sqlite3`-Datei an ihren Platz kopieren bzw. `mysql sprint < sprint.sql` / `pg_restore -d sprint sprint.dump`), den Ordner `private` nach `storage/app/` entpacken, dann `php artisan migrate --force` (falls das Backup von einer älteren Version stammt), `php artisan search:rebuild`, `php artisan queue:restart` und zum Schluss `php artisan sprint:health`.
Liegen die Anhänge in einem Bucket, sichert man sie dort (Versionierung bzw. Replikation des Anbieters) statt per `tar`.
