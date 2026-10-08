# Bereitstellung mit Laravel Cloud

Vorher: die [Checkliste](deployment.md#checkliste-für-jeden-host).

Laravel Cloud baut aus deinem GitHub-Repository ein Image und startet es ohne Ausfallzeit. Das Dateisystem ist dabei **flüchtig** (jedes Deployment setzt es zurück, jedes Replikat hat eine eigene Platte). Daraus folgt für Sprint:

* **Keine SQLite-Datenbank.** Hänge eine *Laravel MySQL*- oder *Serverless-Postgres*-Datenbank an (gleiche Region wie die App); Cloud setzt `DB_*` selbst. Die Volltextsuche läuft dort automatisch über LIKE.
* **Anhänge gehören in einen Bucket.** Hänge einen privaten *Object Storage*-Bucket als Standard-Disk an; Cloud setzt `FILESYSTEM_DISK` samt Zugangsdaten, und Sprint legt Anhänge auf der Standard-Disk ab. Der nötige S3-Adapter (`league/flysystem-aws-s3-v3`) gehört zu den Abhängigkeiten von Sprint, es ist nichts nachzuinstallieren.
* **Sitzungen, Cache und Queue in der Datenbank:** `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database` (die Voreinstellung). Die *Managed Queues* von Cloud setzen `QUEUE_CONNECTION=cloud` und das Paket `aws/aws-sdk-php` voraus und sind für Sprint nicht nötig.

So gehst du vor:

1. **Anwendung anlegen:** in Laravel Cloud *New application*, GitHub-Repository wählen, Region, Umgebung *production*, PHP 8.4 oder neuer.
2. **Build-Befehle** der Umgebung (*Deployments*): die Flux-Zugangsdaten gehören **vor** `composer install` hinein, genau wie in der Cloud-Dokumentation für private Pakete beschrieben. Behandle die Build-Befehle deshalb vertraulich:
   ```bash
   composer config http-basic.composer.fluxui.dev "<E-Mail der Lizenz>" "<Lizenzschlüssel>"
   composer install --no-dev
   npm ci --ignore-scripts
   npm run build
   php artisan optimize
   ```
3. **Deploy-Befehl:** `php artisan migrate --force`. Nicht hinzufügen: `queue:restart`, `optimize:clear`, `storage:link` (Cloud übernimmt Neustarts, das Dateisystem bleibt nicht erhalten).
4. **Datenbank und Bucket** anhängen (siehe oben).
5. **Umgebungsvariablen** setzen: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` (zuerst die `…laravel.cloud`-Adresse, später die eigene Domain), `APP_TIMEZONE`, `APP_LOCALE`, die `MAIL_*`-Werte deines Mail-Anbieters (Cloud bringt keinen eigenen Mailversand mit) sowie `SESSION_DRIVER`, `CACHE_STORE` und `QUEUE_CONNECTION` auf `database`. `APP_KEY` erzeugst du lokal mit `php artisan key:generate --show` und trägst ihn ein; sensible Werte gehören in den *Secrets Manager* von Cloud.
6. **Queue-Worker:** am App-Cluster (für kleine Installationen) oder an einem eigenen Worker-Cluster unter *Background processes* einen *Queue worker* mit `queue:work` und einem Prozess anlegen. Soll Mail auch dann rausgehen, wenn die Umgebung sonst schlafen würde, den Worker-Cluster nicht mit der App einschlafen lassen (*Scale to zero* vermeiden).
7. **Scheduler:** am App-Cluster (oder Worker-Cluster) den Schalter **Scheduler** einschalten. Bei mehreren Replikaten läuft die Tageszusammenfassung dank `onOneServer` nur einmal.
8. **Deployen**, dann den ersten Administrator anlegen: im Reiter *Commands* der Umgebung `php artisan user:create "Anna Beispiel" anna@example.com --admin` ausführen.
9. **Eigene Domain:** in Cloud hinzufügen und verifizieren, danach `APP_URL` auf die neue Adresse ändern und neu deployen. **Bestehende Passkeys gelten nur für die alte Adresse**, Passwort und 2FA funktionieren weiter.
10. **Prüfen:** `https://<deine-domain>/health`; die Gesundheitsprüfung zeigt auch, ob Scheduler und Queue-Worker laufen. Backups für Datenbank und Bucket stellst du bei den jeweiligen Cloud-Ressourcen ein; die Befehle in [Backup und Wiederherstellung](maintenance.md#backup-und-wiederherstellung) gelten für eigene Server.

Die Cloud-CLI (`composer global require laravel/cloud-cli`, dann `cloud ship` bzw. `cloud deploy`) kann dieselben Schritte aus dem Terminal erledigen; die Befehle und Optionen zeigt `cloud -h`.
