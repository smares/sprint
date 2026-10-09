# Bereitstellung hinter Traefik (selbst verwaltet)

Für einen eigenen Server, auf dem schon ein Traefik läuft und jeder Dienst seine eigene `compose.yaml` mit Traefik-Labels hat. Sprint kommt als fertiges Image von `ghcr.io` (siehe [Fertiges Image](deployment-docker.md#fertiges-image)); `compose.traefik.yaml` im Repository ist dafür vorbereitet:

```
Browser ──HTTPS──▶ Traefik ──HTTP──▶ sprint (Caddy + PHP) ──▶ /app/* WebSocket ──▶ sprint-reverb
                                          │
                       sprint-queue, sprint-scheduler, Volume "storage" (SQLite, Anhänge, Backups)
```

Traefik beendet TLS und holt das Zertifikat; Caddy im Container spricht nur HTTP. Sprint veröffentlicht **keine Ports**, erreichbar ist es nur über Traefik. SQLite im Volume ist hier die richtige Wahl, damit die Volltextsuche (FTS5) funktioniert.

## Voraussetzungen

- Ein Traefik, das über das Docker-Netz `reverse-proxy` mit den Diensten spricht (extern angelegt: `docker network create reverse-proxy`), mit dem Entrypoint `web-secure`, dem Zertifikats-Resolver `letsencrypt` und der Middleware `default@file`. Heißt bei dir etwas anders, passt du die Labels in der Datei an.
- Eine DNS-Adresse (A-Record) für Sprint auf den Server.
- Zugang zum privaten Image: einmal auf dem Server `docker login ghcr.io` (Benutzername und ein *personal access token (classic)* mit `read:packages`; der Benutzer braucht Leserechte auf das Paket).

## Einrichten

```bash
mkdir sprint && cd sprint
curl -fsSLO https://raw.githubusercontent.com/smares/sprint/main/compose.traefik.yaml
mv compose.traefik.yaml compose.yaml
```

Dazu eine `.env` im selben Ordner:

```ini
SPRINT_HOST=sprint.example.com       # Adresse, unter der Sprint erreichbar sein soll
SPRINT_VERSION=0.1.0                  # oder latest

APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:…                      # echo "base64:$(openssl rand -base64 32)"
APP_URL=https://sprint.example.com
APP_LOCALE=de
APP_TIMEZONE=Europe/Berlin

# Traefik beendet TLS und spricht per HTTP mit dem Container
TRUSTED_PROXIES=*
SESSION_SECURE_COOKIE=true

MAIL_MAILER=smtp
MAIL_HOST=…
MAIL_PORT=587
MAIL_USERNAME=…
MAIL_PASSWORD=…
MAIL_FROM_ADDRESS=sprint@example.com
MAIL_FROM_NAME=Sprint

# Live-Updates (Reverb); ohne diese Zeilen läuft Sprint ohne
COMPOSE_PROFILES=realtime
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=sprint
REVERB_APP_KEY=<zufälliger Schlüssel>
REVERB_APP_SECRET=<zufälliges Geheimnis>
REVERB_HOST=                          # leer: Host der Seite
REVERB_PORT=443
REVERB_SCHEME=https
```

`APP_KEY` hebst du getrennt von den Backups auf: Ohne ihn sind Zwei-Faktor-Geheimnisse und Sitzungen nach einem Restore nicht mehr lesbar. Datenbank-Variablen brauchst du nicht: Ohne Angabe nimmt das Image SQLite im Volume `storage`.

Starten und den ersten Administrator anlegen:

```bash
docker compose up -d
docker compose exec app php artisan user:create "Anna Beispiel" anna@example.com --admin
curl -fsS https://sprint.example.com/health        # {"status":"ok", …}
```

Beim ersten Start migriert der Container `sprint` die Datenbank; `sprint-queue`, `sprint-scheduler` und `sprint-reverb` starten, sobald er gesund ist.

## Update

`SPRINT_VERSION` in der `.env` ändern (bei `latest` entfällt das), dann:

```bash
docker compose pull
docker compose up -d
```

Migrationen laufen beim Start automatisch.

## Daten und Backup

Alles Dauerhafte liegt im Volume `storage` (Compose nennt es `<Ordnername>_storage`): SQLite-Datenbank, Anhänge, Profilbilder (in der Datenbank) und die nächtlichen Backups unter `storage/app/backups` (jede Nacht um 02:30, siehe [Backup und Wiederherstellung](maintenance.md#backup-und-wiederherstellung)). Damit sie auch den Verlust des Servers überstehen, holst du das Backup-ZIP regelmäßig ab, nicht die laufende `database.sqlite`:

```bash
docker compose exec app php artisan sprint:backup      # sofort eines schreiben
docker compose cp app:/app/storage/app/backups ./sprint-backups
```

Willst du statt eines Volumes ein Verzeichnis (`./data:/app/storage`), muss es dem Benutzer des Containers gehören: `sudo chown -R 1000:1000 data`.

## Worauf du achten solltest

- **Die Labels nennen `traefik.docker.network: reverse-proxy`:** Sprint hängt an zwei Netzen, einem eigenen (zwischen den vier Containern, damit `app` und `reverb` nicht mit gleichnamigen Diensten anderer Stacks im Netz `reverse-proxy` kollidieren) und dem des Proxys. Ohne das Label könnte Traefik die Adresse im falschen Netz nehmen und einen Fehler 504 liefern.
- **`TRUSTED_PROXIES=*`** ist hier vertretbar, weil Sprint keine Ports veröffentlicht. Andere Container im Netz `reverse-proxy` könnten den Dienst aber direkt ansprechen und `X-Forwarded-*` setzen; wer das ausschließen will, trägt statt `*` das Subnetz des Netzes ein (`docker network inspect reverse-proxy`).
- **Die Middleware `default@file`** gilt zusätzlich zu den Sicherheits-Headern, die Sprint selbst setzt (CSP, `X-Frame-Options` …). Setzt sie dieselben Header, gewinnt je nach Traefik-Version einer von beiden; eine eigene, strengere CSP in der Middleware kann Sprint ausbremsen (Alpine und Livewire brauchen `'unsafe-eval'`).
- **Links mit `http://` oder Passkeys gehen nicht:** `TRUSTED_PROXIES` fehlt, oder `APP_URL` stimmt nicht exakt mit `SPRINT_HOST` überein.
- **Live-Updates fehlen:** `COMPOSE_PROFILES=realtime` und die `REVERB_*`-Werte in der `.env`; der WebSocket läuft als `wss://<Adresse>/app/…` über Traefik und Caddy zum Container `sprint-reverb`, eine zweite Adresse ist nicht nötig.
