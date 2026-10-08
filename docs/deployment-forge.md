# Bereitstellung mit Laravel Forge

Vorher: die [Checkliste](deployment.md#checkliste-für-jeden-host).

Auf einem Forge-Server liegen Datenbank und Dateien dauerhaft auf der Platte, es ist also nichts Besonderes nötig.

1. **Server** anlegen (PHP 8.4 oder neuer, MySQL oder PostgreSQL; SQLite geht auch, dann `DB_DATABASE` auf einen Pfad **außerhalb** des Projektordners setzen, z. B. `/home/forge/sprint.sqlite`, damit ein Deployment sie nie überschreibt).
2. **Site** mit der Domain anlegen, Repository `smares/sprint` (dein Fork), Branch `main`; **SSL** per Let's Encrypt aktivieren.
3. **Flux-Pro-Zugang** einmal auf dem Server hinterlegen (per SSH als Benutzer `forge`), damit `composer install` das private Paket laden kann:
   ```bash
   composer config --global http-basic.composer.fluxui.dev "<E-Mail der Lizenz>" "<Lizenzschlüssel>"
   ```
4. **Umgebung** (*Environment*) mit den Werten aus der [Checkliste](deployment.md#checkliste-für-jeden-host) füllen und `APP_KEY` erzeugen (`php artisan key:generate --show` auf dem Server).
5. **Deployment-Skript** der Site:
   ```bash
   cd $FORGE_SITE_PATH
   git pull origin $FORGE_SITE_BRANCH

   $FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

   npm ci --ignore-scripts
   npm run build

   ( flock -w 10 9 || exit 1
       echo 'Restarting FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock

   $FORGE_PHP artisan migrate --force
   $FORGE_PHP artisan optimize
   $FORGE_PHP artisan queue:restart
   ```
6. **Queue-Worker:** in der Site unter *Queue* einen Worker anlegen (Connection `database`, Queue `default`, 1 Prozess); Forge hält ihn am Leben.
7. **Scheduler:** unter *Scheduler* einen Job mit Befehl `php8.4 /home/forge/sprint.example.com/artisan schedule:run` und Frequenz *jede Minute* anlegen (Pfad und PHP-Version anpassen).
8. **Ersten Administrator** anlegen (Forge-Terminal oder SSH): `php artisan user:create "Anna Beispiel" anna@example.com --admin`.
9. **Backups:** Datenbank-Backups in Forge einrichten und `storage/app/private` (Anhänge) sowie die `.env` zusätzlich sichern, siehe [Backup und Wiederherstellung](maintenance.md#backup-und-wiederherstellung). Die Adresse `/health` kannst du in einen externen Uptime-Dienst eintragen.

Aktualisieren: Code auf `main` pushen und in Forge *Deploy now* (oder Quick Deploy aktivieren).
