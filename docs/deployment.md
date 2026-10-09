# Bereitstellung

Sprint ist eine normale Laravel-Anwendung. Egal wo sie läuft, braucht sie dieselben fünf Dinge: **PHP mit Webserver**, **eine Datenbank**, **einen dauerhaft laufenden Queue-Worker** (Mails, Posteingang), **den Scheduler** (jede Minute `php artisan schedule:run`, für die Tageszusammenfassung und die Gesundheitsprüfung) und **dauerhaften Speicher für Anhänge**.

Anleitungen für typische Hosts:

- [Laravel Forge](deployment-forge.md): eigener Server mit dauerhafter Platte
- [Laravel Cloud](deployment-laravel-cloud.md): flüchtiges Dateisystem, Datenbank und Bucket als Ressourcen
- [Traefik, selbst verwaltet](deployment-traefik.md): fertiges Image hinter einem eigenen Traefik, ohne veröffentlichte Ports
- [Docker Compose](deployment-docker.md): Image samt Worker und Scheduler, selbst gebaut oder als fertiges Release-Image von `ghcr.io`, Daten im Volume
- [Dokploy](deployment-dokploy.md): dasselbe Setup hinter Traefik, Domain und HTTPS über die Oberfläche

## Checkliste für jeden Host

1. **Code und Abhängigkeiten:** PHP ab 8.3 (Erweiterungen siehe [Voraussetzungen](installation.md#voraussetzungen)), `composer install --no-dev --optimize-autoloader` mit den [Flux-Pro-Zugangsdaten](installation.md#flux-pro-einrichten), `npm ci --ignore-scripts && npm run build`.
2. **`.env`** (oder Umgebungsvariablen des Hosts), mindestens:
   ```ini
   APP_ENV=production
   APP_DEBUG=false
   APP_KEY=                  # php artisan key:generate --show
   APP_URL=https://sprint.example.com
   APP_TIMEZONE=Europe/Berlin
   APP_LOCALE=de             # Standardsprache für neue Personen, sonst en

   DB_CONNECTION=mysql       # mysql, pgsql oder sqlite (nur mit dauerhafter Platte)
   DB_HOST=...  DB_PORT=...  DB_DATABASE=...  DB_USERNAME=...  DB_PASSWORD=...

   SESSION_DRIVER=database
   SESSION_SECURE_COOKIE=true  # Sitzungs-Cookie nur über HTTPS
   CACHE_STORE=database
   QUEUE_CONNECTION=database

   MAIL_MAILER=smtp          # smtp, postmark, resend, ses …
   MAIL_HOST=...  MAIL_PORT=587  MAIL_USERNAME=...  MAIL_PASSWORD=...
   MAIL_FROM_ADDRESS=sprint@example.com
   MAIL_FROM_NAME=Sprint

   # optional, für Push-Benachrichtigungen: php artisan webpush:vapid --show
   VAPID_PUBLIC_KEY=...  VAPID_PRIVATE_KEY=...
   ```
   Hinter einem Proxy oder Load Balancer, der TLS beendet, zusätzlich `TRUSTED_PROXIES` mit den Adressen des Proxys (kommagetrennt, CIDR erlaubt), sonst erzeugt Laravel `http`-Links. `*` nur, wenn die App ausschließlich über den Proxy erreichbar ist: Wer sie direkt erreicht, könnte sonst per `X-Forwarded-For` eine fremde IP vortäuschen und die Begrenzung der Anmeldeversuche umgehen.

   `APP_URL` muss exakt die Adresse sein, unter der die Leute Sprint öffnen (HTTPS): Links in Mails, signierte Abbestell-Links und **Passkeys** hängen daran.
3. **Datenbank anlegen und migrieren:** `php artisan migrate --force` bei jedem Deployment.
4. **Queue-Worker:** `php artisan queue:work --tries=3` als dauerhafter Prozess, nach jedem Deployment neu gestartet (`php artisan queue:restart`).
5. **Scheduler:** jede Minute `php artisan schedule:run`.
6. **Ersten Administrator anlegen:** `php artisan user:create "Anna Beispiel" anna@example.com --admin`.
7. **Prüfen:** `https://sprint.example.com/health` liefert `{"status":"ok", …}` (siehe [Gesundheitsprüfung](maintenance.md#gesundheitsprüfung)); `php artisan sprint:health` zeigt Details. Nach einer Mail-Probe (Kommentar mit Erwähnung) und einem Blick ins Profil (Sprache, Passkey) ist die Installation fertig.
8. **Backup prüfen:** Der Scheduler sichert jede Nacht Datenbank und Anhänge; Ziel (am besten ein Bucket) und Aufbewahrung stehen unter [Backup und Wiederherstellung](maintenance.md#backup-und-wiederherstellung), die `.env` sicherst du einmalig getrennt davon.

Anhänge landen auf der Standard-Disk von Laravel (`FILESYSTEM_DISK`): lokal in `storage/app/private`, mit `FILESYSTEM_DISK=s3` in einem S3-kompatiblen Bucket. Der S3-Adapter ist installiert; Zugangsdaten und Bucket stehen in den `AWS_*`-Variablen.
