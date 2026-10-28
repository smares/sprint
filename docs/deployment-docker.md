# Bereitstellung mit Docker Compose

Vorher: die [Checkliste](deployment.md#checkliste-für-jeden-host). Das meiste davon übernimmt hier das Image.

Im Repository liegen ein `Dockerfile` und eine `compose.yaml`. Alle Dienste nutzen dasselbe Image ([FrankenPHP](https://frankenphp.dev): Caddy und PHP in einem Prozess, läuft ohne Root-Rechte). Die Basis-Images von Docker Hub (FrankenPHP, Composer, Node) lädt der Build über Googles Spiegel `mirror.gcr.io`, damit er nicht an den Abruflimits oder Ausfällen von Docker Hub scheitert:

| Dienst | Aufgabe |
|---|---|
| `sprint` | Webserver; migriert beim Start die Datenbank und baut die Caches (`php artisan optimize`) |
| `queue` | Queue-Worker für Mails, Posteingang und Live-Meldungen |
| `scheduler` | `php artisan schedule:work` (Tageszusammenfassung, Erinnerungen, Backup, Aufräumen) |
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
   docker compose run --rm --no-deps sprint php artisan key:generate --show
   ```
   Für Push-Benachrichtigungen (optional) ebenso die VAPID-Schlüssel erzeugen und beide Zeilen in `.env` übernehmen: `docker compose run --rm --no-deps sprint php artisan webpush:vapid --show` (siehe [Konfiguration](configuration.md#push-benachrichtigungen)).
4. **Starten** und den ersten Administrator anlegen:
   ```bash
   docker compose up -d
   docker compose exec sprint php artisan user:create "Anna Beispiel" anna@example.com --admin
   ```
5. **Prüfen:** `http://<server>:8000/health` liefert `{"status":"ok", …}`; `docker compose exec sprint php artisan sprint:health` zeigt Details.

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
SPRINT_VERSION=x.y.z docker compose -f compose.image.yaml up -d   # x.y.z durch die aktuelle Version ersetzen (siehe Releases)
```

`SPRINT_VERSION` in die `.env` zu schreiben genügt; ohne Angabe gilt `latest`. Der Befehl für Live-Updates ist `--profile realtime`, wie beim Selbstbauen.

**Das Paket ist privat.** Es enthält den Quellcode von Flux UI Pro, dessen Lizenz jede Installation an eine eigene Flux-Pro-Lizenz bindet; deshalb darf das Paket nicht öffentlich werden, und wer ein Image betreibt, braucht eine eigene Lizenz (auf GitHub: Profil → Packages → `sprint` → Package settings: Sichtbarkeit *Private*, den Haken *Inherit access from source repository* entfernen, sonst darf jede Person mit Zugriff auf das öffentliche Repository auch das Paket lesen, und unter *Manage Actions access* das Repository mit der Rolle *Write* eintragen, damit der Release-Workflow schreiben darf; beides ist nur einmal nach dem ersten Release nötig). Wer Zugriff haben soll, braucht dort Leserechte und für `docker login` ein *personal access token (classic)* mit `read:packages`. Der Release-Workflow bricht ab, wenn er das Paket ohne Anmeldung ziehen kann.

Hinter einem eigenen Traefik: [Bereitstellung hinter Traefik](deployment-traefik.md). Die anderen Wege bleiben: `compose.yaml` baut selbst (mit eigenem Flux-Zugang), siehe oben.

## Andere Datenbank

Das Image bringt die Treiber für MySQL/MariaDB und PostgreSQL mit. `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` und `DB_PASSWORD` in `.env` setzen; die Datenbank selbst läuft dann außerhalb oder als zusätzlicher Dienst in einer eigenen `compose.override.yaml`. Ohne FTS5 sucht Sprint automatisch per LIKE (siehe [Suche](configuration.md#suche)).

## Updates

```bash
git pull
docker compose build
docker compose up -d
```

**Einmalig beim Update auf eine Version nach 0.2.0:** Der Webdienst heißt jetzt `sprint` statt `app`. Damit der alte Container den Port bzw. den Namen nicht blockiert, einmal `docker compose down` und danach wie gewohnt `docker compose up -d` (die Daten im Volume bleiben erhalten).

Mit dem fertigen Image genügt `SPRINT_VERSION` zu ändern und `docker compose -f compose.image.yaml up -d` zu wiederholen (die Datei zieht das Image selbst nach).

Beim Start migriert der `sprint`-Container und baut die Caches neu; `queue`, `scheduler` und `reverb` starten erst, wenn `sprint` gesund ist. Wer Migrationen lieber selbst anstößt, setzt `MIGRATE_ON_START=false` und ruft `docker compose exec sprint php artisan migrate --force` auf.

## Backup

Der `scheduler`-Container schreibt jede Nacht ein Backup (Datenbank und Anhänge als ZIP) nach `storage/app/backups`, also ins selbe Volume. Damit es auch den Verlust des Servers übersteht, holst du es regelmäßig ab oder lässt es gleich in einen Bucket schreiben (`BACKUP_DISK`):

```bash
docker compose exec sprint php artisan sprint:backup       # sofort eines schreiben
docker compose cp sprint:/app/storage/app/backups ./sprint-backups
```

Einstellungen und Wiederherstellung unter [Backup und Wiederherstellung](maintenance.md#backup-und-wiederherstellung).

### Letztes Backup auf den Host holen und per rsync sichern

Solange `BACKUP_DISK=backups` gilt (Standard), liegen die Backups nur im Docker-Volume auf dem Server. Ein Skript auf dem Host holt das neueste heraus, prüft es und kopiert es per rsync auf einen anderen Rechner (NAS, zweiter Server, Storage-Box). Es bricht lieber laut ab, als etwas Falsches zu sichern:

| Fall | Verhalten |
| --- | --- |
| Der Container `sprint` läuft nicht | Abbruch mit Fehler |
| `BACKUP_DISK` zeigt auf einen Bucket (z. B. `s3`) | Abbruch mit Hinweis, **nichts** wird synchronisiert: das Volume ist dann leer, und ein rsync des leeren Ordners würde nichts sichern (siehe [Backups in einem Bucket](#backups-in-einem-bucket)) |
| Im Volume liegt kein Backup | Abbruch mit Fehler |
| Das neueste Backup ist älter als `MAX_AGE_HOURS` (Standard 30) | Abbruch: der nächtliche Lauf ist ausgefallen, ein altes Backup soll nicht als frisches gelten |
| Kopie unvollständig, keine gültige ZIP oder ohne `database.sqlite` | Abbruch, die Teildatei wird gelöscht und nie weitergegeben |
| rsync scheitert (Ziel nicht erreichbar, Platte voll) | Abbruch, der lokale Zwischenspeicher bleibt unangetastet |
| Zwei Läufe überschneiden sich | der zweite beendet sich ohne Fehler (`flock`) |

rsync überträgt nur diese eine Datei und **nie mit `--delete`**: Ein leeres oder kaputtes Quellverzeichnis kann so keine guten Backups am Ziel löschen. Das Aufräumen alter Dateien dort liegt beim Ziel (siehe unten). Lokal bleiben die letzten `KEEP_LOCAL` Backups (Standard 7) im Zwischenspeicher; sie werden erst nach einem erfolgreichen rsync aufgeräumt.

Auf dem Host werden `docker` (mit Compose), `rsync`, `unzip` und `flock` gebraucht, zum Beispiel als `/usr/local/bin/sprint-backup-pull`:

```bash
#!/usr/bin/env bash
# Holt das neueste Sprint-Backup aus dem Docker-Volume auf den Host, prüft es und sichert es per rsync.
set -euo pipefail

COMPOSE_DIR=${COMPOSE_DIR:-/opt/sprint}            # Ordner mit der compose.yaml
STAGING=${STAGING:-/var/backups/sprint}            # lokaler Zwischenspeicher auf dem Host
DEST=${DEST:?DEST fehlt, z. B. DEST=backup@nas.example.com:/srv/backups/sprint/}
MAX_AGE_HOURS=${MAX_AGE_HOURS:-30}                 # älter gilt als "nächtlicher Lauf ausgefallen"
KEEP_LOCAL=${KEEP_LOCAL:-7}                        # so viele Backups bleiben im Zwischenspeicher
REQUIRE_DATABASE=${REQUIRE_DATABASE:-1}            # 0 bei MySQL/PostgreSQL: die ZIP enthält dann keine database.sqlite
RSYNC_SSH=${RSYNC_SSH:-ssh -o BatchMode=yes -o ConnectTimeout=20}
BACKUP_DIR=/app/storage/app/backups                # Pfad im Container

fail() {
    echo "sprint-backup-pull: $*" >&2
    exit 1
}

for tool in docker rsync unzip flock; do
    command -v "$tool" >/dev/null || fail "'$tool' fehlt auf dem Host"
done

mkdir -p "$STAGING"
exec 9>"$STAGING/.lock"
flock -n 9 || { echo "sprint-backup-pull: ein anderer Lauf ist noch aktiv" >&2; exit 0; }

cd "$COMPOSE_DIR" || fail "Ordner $COMPOSE_DIR nicht gefunden"
sprint() { docker compose exec -T sprint "$@"; }

docker compose ps --status running --services | grep -qx sprint || fail "der Container 'sprint' läuft nicht"

# Liegen die Backups in einem Bucket, ist das Volume leer: dann nichts synchronisieren, sondern laut scheitern
disk=$(sprint php artisan --no-ansi config:show sprint.backup.disk | awk 'NF { last = $NF } END { print last }')
[ "$disk" = backups ] || fail "BACKUP_DISK ist '$disk': die Backups liegen nicht im Volume, sondern dort. Sichere sie mit dem Werkzeug des Anbieters (siehe docs/deployment-docker.md)"

latest=$(sprint ls -1 "$BACKUP_DIR" | tr -d '\r' | grep -E '^sprint-[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{6}\.zip$' | sort | tail -n 1 || true)
[ -n "$latest" ] || fail "im Volume liegt kein Backup ($BACKUP_DIR); lief 'php artisan sprint:backup' schon, und ist BACKUP_ENABLED an?"

mtime=$(sprint stat -c %Y "$BACKUP_DIR/$latest" | tr -d '\r')
age_hours=$(( ($(date +%s) - mtime) / 3600 ))
[ "$age_hours" -le "$MAX_AGE_HOURS" ] || fail "das neueste Backup ($latest) ist $age_hours Stunden alt, erlaubt sind $MAX_AGE_HOURS: der nächtliche Lauf ist ausgefallen (php artisan sprint:health prüfen)"

# Erst als versteckte Teildatei laden und prüfen, damit nie eine halbe Datei als Backup herumliegt oder weitergegeben wird
partial="$STAGING/.$latest.partial"
trap 'rm -f "$partial"' EXIT
sprint cat "$BACKUP_DIR/$latest" > "$partial"

expected=$(sprint stat -c %s "$BACKUP_DIR/$latest" | tr -d '\r')
actual=$(stat -c %s "$partial")
[ "$expected" = "$actual" ] || fail "$latest wurde unvollständig kopiert ($actual von $expected Bytes)"
unzip -tqq "$partial" >/dev/null || fail "$latest ist keine gültige ZIP-Datei"
if [ "$REQUIRE_DATABASE" = 1 ]; then
    unzip -Z1 "$partial" | grep -qx database.sqlite || fail "$latest enthält keine database.sqlite"
fi

chmod 600 "$partial"
mv "$partial" "$STAGING/$latest"

# Nur diese eine Datei und ohne --delete: ein leeres oder kaputtes Ziel kann nie ein gutes Backup löschen
rsync -t --partial --chmod=F600 -e "$RSYNC_SSH" "$STAGING/$latest" "$DEST" || fail "rsync nach $DEST ist fehlgeschlagen"

# Aufräumen erst nach erfolgreichem rsync
printf '%s\n' "$STAGING"/sprint-*.zip | sort -r | tail -n +$((KEEP_LOCAL + 1)) | xargs -r rm -f --

echo "sprint-backup-pull: $latest ($(du -h "$STAGING/$latest" | cut -f1)) gesichert nach $DEST"
```

Aufruf von Hand und per Cron, eine Stunde nach dem Backup (03:30 ist der Standard, siehe `BACKUP_TIME`). Achtung, zwei Uhren: `BACKUP_TIME` gilt in `APP_TIMEZONE` der Anwendung, die Cron-Zeit in der Zeitzone des Hosts (oder von `CRON_TZ=`, wenn dein Cron es kennt). Rechne um, sonst läuft das Skript vor dem Backup und holt, weil es noch durch die Altersprüfung kommt, das der Vornacht:

```bash
sudo chmod +x /usr/local/bin/sprint-backup-pull
sudo DEST=backup@nas.example.com:/srv/backups/sprint/ /usr/local/bin/sprint-backup-pull

# /etc/cron.d/sprint-backup-pull
30 4 * * * root DEST=backup@nas.example.com:/srv/backups/sprint/ /usr/local/bin/sprint-backup-pull 2>&1 | logger -t sprint-backup-pull
```

Hinweise:

- **SSH-Zugang:** Der Benutzer, unter dem das Skript läuft (oben `root`), braucht einen SSH-Schlüssel ohne Passphrase, dessen öffentlicher Teil am Ziel hinterlegt ist. Am Ziel lässt sich der Schlüssel in `~/.ssh/authorized_keys` mit `command="rrsync /srv/backups/sprint",restrict` auf rsync in genau diesen Ordner beschränken (`rrsync` liegt dem rsync-Paket bei); dann ist `DEST` einfach `backup@nas.example.com:` und der Schlüssel kann auf dem Ziel nichts anderes tun.
- **Alte Backups am Ziel:** Dort selbst aufräumen, zum Beispiel täglich mit `find /srv/backups/sprint -name 'sprint-*.zip' -mtime +30 -delete`.
- **Alarm:** Cron meldet Fehler nur, wenn Mail eingerichtet ist. Zuverlässiger ist ein Dead-Man's-Switch wie Healthchecks.io: `… sprint-backup-pull && curl -fsS -m 10 --retry 3 https://hc-ping.com/<uuid> >/dev/null`. Bleibt der Ping aus, meldet der Dienst es, auch wenn der ganze Host steht.
- **Andere Compose-Datei, anderer Ordner:** `COMPOSE_DIR` (Standard `/opt/sprint`) ist der Ordner mit der Compose-Datei. Wer `compose.image.yaml` nutzt, setzt zusätzlich `COMPOSE_FILE=compose.image.yaml`.
- **MySQL/PostgreSQL:** Dann enthält die ZIP keine `database.sqlite` (nur die Anhänge); mit `REQUIRE_DATABASE=0` prüft das Skript das nicht, und den Dump sicherst du separat (siehe [Wartung](maintenance.md#backup-und-wiederherstellung)).
- **Die `.env` (vor allem `APP_KEY`) steckt nicht im Backup** und gehört einmalig an einen eigenen, geschützten Ort.
- Das Skript ist gegen ein nachgebautes `docker` getestet (alle Fälle der Tabelle), nicht gegen deinen Server: Probiere es einmal von Hand aus und spiele ein Backup wirklich ein, denn erst eine geprobte Wiederherstellung zählt (siehe [Wiederherstellen](maintenance.md#wiederherstellen)).

#### Backups in einem Bucket

Mit `BACKUP_DISK=s3` (oder einer anderen Cloud-Disk) liegen die Backups schon nicht mehr auf dem Server, und das Skript oben verweigert die Arbeit, weil das Volume leer ist. Die Kopie an einen weiteren Ort holst du dann mit dem Werkzeug des Anbieters, etwa mit der AWS-CLI (oder `rclone`). Bucket-Name und Zugang sind deine; geprüft und weitergegeben wird mit denselben Schritten wie oben:

```bash
latest=$(aws s3 ls s3://mein-bucket/ | awk '{print $4}' | grep -E '^sprint-[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{6}\.zip$' | sort | tail -n 1)
[ -n "$latest" ] || { echo "kein Backup im Bucket" >&2; exit 1; }
aws s3 cp "s3://mein-bucket/$latest" "/var/backups/sprint/.$latest.partial"
unzip -tqq "/var/backups/sprint/.$latest.partial" && mv "/var/backups/sprint/.$latest.partial" "/var/backups/sprint/$latest"
rsync -t --partial "/var/backups/sprint/$latest" backup@nas.example.com:/srv/backups/sprint/
```

Außerdem empfiehlt sich im Bucket eine Versionierung oder eine Aufbewahrungsregel (Lifecycle), damit gelöschte oder überschriebene Backups wiederherstellbar bleiben. Sprint löscht dort selbst nur Dateien nach dem Muster `sprint-JJJJ-MM-TT-HHMMSS.zip`, die älter als `BACKUP_KEEP_DAYS` sind.

## Befehle

```bash
docker compose logs -f sprint              # Logs (Laravel schreibt nach stderr)
docker compose exec sprint php artisan …      # Artisan im laufenden Container
docker compose restart queue               # Worker neu starten
```
