# Wartung

Updates, Gesundheitsprüfung und Backups.

## Updates

- Nach jedem Update: `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, `npm ci --ignore-scripts && npm run build`
- Vor Migrationen, die bestehende Daten umbauen, ein Datenbank-Backup ziehen
- Beim Einführen der Rollen werden alle bestehenden Benutzer Verwalter aller bestehenden Projekte (alles bleibt wie bisher erreichbar); danach per `php artisan user:admin <E-Mail>` Administratoren bestimmen und Mitgliedschaften in den Projekten anpassen

## Gesundheitsprüfung

- `GET /health` ohne Login für Uptime-Monitore: Antwort `200` mit `{"status":"ok"|"degraded", "checks":{…}}`, solange Datenbank, Speicher (Standard-Disk) und Cache funktionieren, sonst `503` mit `"status":"down"`. `degraded` bedeutet nur Warnungen: der **Scheduler** hat sich länger als 5 Minuten nicht gemeldet (Cron-Eintrag fehlt, Tageszusammenfassung kommt nicht) oder die **Queue** hat einen Rückstand bzw. fehlgeschlagene Jobs (Worker läuft nicht, Mails und Posteingang kommen nicht an) oder das letzte **Backup** ist fehlgeschlagen bzw. älter als zwei Tage. Öffentlich werden nur die Statuswerte gezeigt, keine Details; die Route ist auf 60 Anfragen pro Minute und IP begrenzt
- `php artisan sprint:health` zeigt dieselben Prüfungen mit Details im Terminal (Exit-Code `1` nur bei `down`), praktisch nach einem Deployment
- `GET /up` (von Laravel) prüft nur, ob die Anwendung startet, und eignet sich als einfacher Lebenszeichen-Check für Load Balancer

## Backup und Wiederherstellung

Gesichert werden müssen drei Dinge, sonst nichts (Logs, Caches und der Suchindex lassen sich neu erzeugen): **die Datenbank**, **die Anhänge** und **die Datei `.env`**.

### Automatisch (Standard)

Jede Nacht um 02:30 schreibt der Scheduler mit `php artisan sprint:backup` eine ZIP-Datei `sprint-JJJJ-MM-TT-HHMMSS.zip` mit

- `database.sqlite`: einer konsistenten Kopie der SQLite-Datenbank (per `VACUUM INTO`, also auch im laufenden Betrieb und im WAL-Modus korrekt; Profilbilder stecken mit drin),
- `attachments/…`: allen Anhängen der Standard-Disk, wenn sie lokal liegt.

Backups, die älter als `BACKUP_KEEP_DAYS` Tage sind, löscht derselbe Lauf. Die Gesundheitsprüfung (`/health`, `php artisan sprint:health`) warnt, wenn das letzte Backup fehlgeschlagen oder älter als zwei Tage ist. Einstellungen in `.env`:

```ini
BACKUP_ENABLED=true      # false schaltet die nächtlichen Backups ab
BACKUP_DISK=backups      # "backups" = storage/app/backups; besser ein eigener Bucket (s3), damit die Kopie nicht auf demselben Server liegt
BACKUP_TIME=02:30
BACKUP_KEEP_DAYS=14
```

Liegen die Backups auf dem Server selbst (`BACKUP_DISK=backups`), schützen sie vor versehentlichem Löschen und kaputten Updates, aber nicht vor dem Verlust des Servers; dann sollte ein externer Job den Ordner `storage/app/backups` regelmäßig woanders hin kopieren. Für einen Bucket legst du in `config/filesystems.php` eine eigene Disk an (oder nutzt `s3` mit einem eigenen `AWS_BUCKET`) und trägst ihren Namen in `BACKUP_DISK` ein.

Nicht enthalten sind:

- **MySQL/PostgreSQL:** Der Befehl sichert dann nur die Anhänge und weist darauf hin; die Datenbank sicherst du mit ihrem eigenen Werkzeug, z. B. `mysqldump --single-transaction --routines sprint > /backup/sprint-$(date +%F).sql` bzw. `pg_dump -Fc sprint > /backup/sprint-$(date +%F).dump`.
- **Anhänge in einem Bucket** (`FILESYSTEM_DISK=s3`): Die sichert der Anbieter per Versionierung bzw. Replikation.
- **`.env`**, vor allem der `APP_KEY`: Ohne ihn sind Zwei-Faktor-Geheimnisse, Wiederherstellungscodes und Sitzungen nicht mehr lesbar. Sie gehört einmalig an einen anderen, geschützten Ort als die Backups (Passwörter!).

Ein Backup zählt erst, wenn die Wiederherstellung einmal ausprobiert wurde.

### Wiederherstellen

Auf einem frischen oder vorbereiteten System Anwendung und Abhängigkeiten installieren (siehe [Installation](installation.md)) und `.env` zurückspielen. Dann bei angehaltenem Queue-Worker:

```bash
unzip sprint-2026-10-08-023000.zip -d /tmp/restore
cp /tmp/restore/database.sqlite database/database.sqlite      # bzw. an den Pfad aus DB_DATABASE
cp -r /tmp/restore/attachments/. storage/app/private/
php artisan migrate --force        # falls das Backup von einer älteren Version stammt
php artisan search:rebuild
php artisan queue:restart
php artisan sprint:health
```

Bei MySQL/PostgreSQL spielst du stattdessen den Dump ein (`mysql sprint < sprint.sql` bzw. `pg_restore -d sprint sprint.dump`).
