# Bereitstellung mit Docker Compose

Vorher: die [Checkliste](deployment.md#checkliste-für-jeden-host). Das meiste davon übernimmt hier das Image.

Im Repository liegen ein `Dockerfile` und eine `compose.yaml`. Alle Dienste nutzen dasselbe Image ([FrankenPHP](https://frankenphp.dev): Caddy und PHP in einem Prozess, läuft ohne Root-Rechte):

| Dienst | Aufgabe |
|---|---|
| `app` | Webserver; migriert beim Start die Datenbank und baut die Caches (`php artisan optimize`) |
| `queue` | Queue-Worker für Mails, Posteingang und Live-Meldungen |
| `scheduler` | `php artisan schedule:work` (Tageszusammenfassung, Wiederholungen, Aufräumen) |
| `reverb` | nur mit `--profile realtime`: Live-Updates, der Browser verbindet sich über die Adresse der App |

Dauerhaft liegt alles im Volume `storage`: SQLite-Datenbank (`storage/database/database.sqlite`) und Anhänge (`storage/app/private`). Logs gehen nach `docker compose logs`. Die Zertifikate von Caddy liegen in `caddy_data`.

## Erster Start

1. **`.env`** aus `.env.example` anlegen und mindestens `APP_URL`, `APP_ENV=production`, `APP_DEBUG=false` und die Mail-Einstellungen eintragen (siehe [Checkliste](deployment.md#checkliste-für-jeden-host)). Die Datenbank musst du nicht eintragen, ohne Angabe nutzt Compose SQLite im Volume.
2. **Flux-Pro-Zugang** als `COMPOSER_AUTH` in dieselbe `.env` (eine Zeile, in einfachen Anführungszeichen). Er geht beim Bauen als Build-Secret hinein und landet nicht im Image:
   ```ini
   COMPOSER_AUTH='{"http-basic":{"composer.fluxui.dev":{"username":"<E-Mail der Lizenz>","password":"<Lizenzschlüssel>"}}}'
   ```
3. **Bauen und Schlüssel erzeugen**, die Ausgabe als `APP_KEY=…` in `.env` eintragen:
   ```bash
   docker compose build
   docker compose run --rm --no-deps app php artisan key:generate --show
   ```
4. **Starten** und den ersten Administrator anlegen:
   ```bash
   docker compose up -d
   docker compose exec app php artisan user:create "Anna Beispiel" anna@example.com --admin
   ```
5. **Prüfen:** `http://<server>:8000/health` liefert `{"status":"ok", …}`; `docker compose exec app php artisan sprint:health` zeigt Details.

## HTTPS

- **Caddy holt das Zertifikat selbst:** Domain in `SERVER_NAME` eintragen und die Standard-Ports freigeben, z. B. in `.env`:
  ```ini
  SERVER_NAME=sprint.example.com
  HTTP_PORT=80
  HTTPS_PORT=443
  APP_URL=https://sprint.example.com
  SESSION_SECURE_COOKIE=true
  ```
  Die Domain muss per DNS auf den Server zeigen. Ohne Angabe (`SERVER_NAME=:80`) liefert der Container reines HTTP auf Port `HTTP_PORT` (Standard 8000).
- **Hinter einem eigenen Proxy** (Traefik, nginx, Load Balancer), der TLS beendet: `SERVER_NAME` so lassen, den Proxy auf `HTTP_PORT` zeigen lassen (läuft er auf demselben Server, mit `HTTP_BIND=127.0.0.1` nur lokal erreichbar) und Laravel dem Proxy vertrauen lassen, damit Links, sichere Cookies und Passkeys `https` sehen:
  ```ini
  TRUSTED_PROXIES=*        # oder die Adressen des Proxys, kommagetrennt (CIDR erlaubt)
  ```
  `*` nur, wenn der App-Port ausschließlich für den Proxy erreichbar ist (`HTTP_BIND=127.0.0.1` oder Firewall). Sonst kann jeder, der den Port direkt anspricht, mit einem eigenen `X-Forwarded-For` eine fremde IP vortäuschen und so die Begrenzung der Anmeldeversuche umgehen; dann die Adresse des Proxys eintragen.

## Live-Updates

Mit `docker compose --profile realtime up -d` (oder dauerhaft `COMPOSE_PROFILES=realtime` in `.env`) läuft zusätzlich Reverb. Caddy leitet den WebSocket (`/app/…`) an den Reverb-Container weiter; der Browser braucht also keinen eigenen Port, und die App schickt ihre Meldungen intern direkt an Reverb (`REVERB_INTERNAL_*` setzt die `compose.yaml`). In `.env`:

```ini
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=sprint
REVERB_APP_KEY=<zufälliger Schlüssel>
REVERB_APP_SECRET=<zufälliges Geheimnis>
REVERB_HOST=              # leer: Host der Seite
REVERB_PORT=443           # Port, unter dem der Browser die App erreicht (lokal 8000)
REVERB_SCHEME=https       # lokal http
```

## Fertiges Image

Mit jedem Release baut GitHub das Image und legt es für `amd64` und `arm64` (Raspberry Pi, Apple-Silicon-Server, Graviton) unter `ghcr.io/smares/sprint` ab (Tags `0.1.0`, `0.1` und `latest`; `latest` zeigt auf die jüngste Version ohne Vorabkennung). Damit muss der Server nichts bauen und braucht keinen Flux-Pro-Zugang. Die Datei `compose.image.yaml` ist derselbe Stack wie `compose.yaml`, nur mit diesem Image:

```bash
docker login ghcr.io                                  # einmal; Benutzername und ein Token mit read:packages
cp .env.example .env                                  # APP_KEY, APP_URL, Mail … wie oben
SPRINT_VERSION=0.1.0 docker compose -f compose.image.yaml up -d
```

`SPRINT_VERSION` in die `.env` zu schreiben genügt; ohne Angabe gilt `latest`. Der Befehl für Live-Updates ist `--profile realtime`, wie beim Selbstbauen.

**Das Paket ist privat.** Es enthält den Quellcode von Flux UI Pro, dessen Lizenz die Weitergabe nicht erlaubt; deshalb darf es nicht öffentlich werden (auf GitHub: Profil → Packages → `sprint` → Package settings: Sichtbarkeit *Private*, den Haken *Inherit access from source repository* entfernen, sonst darf jede Person mit Zugriff auf das öffentliche Repository auch das Paket lesen, und unter *Manage Actions access* das Repository mit der Rolle *Write* eintragen, damit der Release-Workflow schreiben darf; beides ist nur einmal nach dem ersten Release nötig). Wer Zugriff haben soll, braucht dort Leserechte und für `docker login` ein *personal access token (classic)* mit `read:packages`. Der Release-Workflow bricht ab, wenn er das Paket ohne Anmeldung ziehen kann.

Hinter einem eigenen Traefik: [Bereitstellung hinter Traefik](deployment-traefik.md). Die anderen Wege bleiben: `compose.yaml` baut selbst (mit eigenem Flux-Zugang), siehe oben.

## Andere Datenbank

Das Image bringt die Treiber für MySQL/MariaDB und PostgreSQL mit. `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` und `DB_PASSWORD` in `.env` setzen; die Datenbank selbst läuft dann außerhalb oder als zusätzlicher Dienst in einer eigenen `compose.override.yaml`. Ohne FTS5 sucht Sprint automatisch per LIKE (siehe [Suche](configuration.md#suche)).

## Updates

```bash
git pull
docker compose build
docker compose up -d
```

Mit dem fertigen Image genügt `SPRINT_VERSION` zu ändern und `docker compose -f compose.image.yaml up -d` zu wiederholen (die Datei zieht das Image selbst nach).

Beim Start migriert der `app`-Container und baut die Caches neu; `queue`, `scheduler` und `reverb` starten erst, wenn `app` gesund ist. Wer Migrationen lieber selbst anstößt, setzt `MIGRATE_ON_START=false` und ruft `docker compose exec app php artisan migrate --force` auf.

## Backup

Der `scheduler`-Container schreibt jede Nacht ein Backup (Datenbank und Anhänge als ZIP) nach `storage/app/backups`, also ins selbe Volume. Damit es auch den Verlust des Servers übersteht, holst du es regelmäßig ab oder lässt es gleich in einen Bucket schreiben (`BACKUP_DISK`):

```bash
docker compose exec app php artisan sprint:backup       # sofort eines schreiben
docker compose cp app:/app/storage/app/backups ./sprint-backups
```

Einstellungen und Wiederherstellung unter [Backup und Wiederherstellung](maintenance.md#backup-und-wiederherstellung).

## Befehle

```bash
docker compose logs -f app                 # Logs (Laravel schreibt nach stderr)
docker compose exec app php artisan …      # Artisan im laufenden Container
docker compose restart queue               # Worker neu starten
```
