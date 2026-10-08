# Bereitstellung mit Dokploy

Vorher: die [Checkliste](deployment.md#checkliste-für-jeden-host). Grundlage ist das Docker-Setup aus dem Repository (siehe [Docker Compose](deployment-docker.md)); Dokploy baut es auf deinem Server, Traefik davor kümmert sich um Domain und HTTPS; Caddy im Container spricht nur HTTP und holt kein eigenes Zertifikat.

So sieht es danach aus:

```
Browser ──HTTPS──▶ Traefik (Dokploy) ──HTTP──▶ app (Caddy + PHP) ──▶ /app/* WebSocket ──▶ reverb
                                                    │
                                     queue, scheduler, Volume "storage" (SQLite, Anhänge, Backups)
```

**SQLite ist hier die richtige Wahl:** Die Datenbank liegt im benannten Volume `storage`, das Deployments überdauert, und die Volltextsuche (FTS5) funktioniert damit direkt; mit MySQL/PostgreSQL fiele die Suche auf LIKE zurück.

## Anwendung anlegen

1. In Dokploy ein Projekt öffnen, **Create Service → Compose** wählen. Wichtig: Typ **Docker Compose**, nicht *Stack*; im Stack-Modus (Swarm) kann Dokploy nicht bauen.
2. **Provider:** das GitHub-Repository (dein Fork von `smares/sprint`), Branch `main`, **Compose Path** `./compose.yaml`.
3. **Environment** (Tab *Environment*): Dokploy schreibt den Inhalt als `.env` neben die `compose.yaml`; von dort lesen ihn alle Container. Mindestens:

   ```ini
   APP_ENV=production
   APP_DEBUG=false
   APP_KEY=base64:…                 # siehe unten
   APP_URL=https://sprint.example.com
   APP_LOCALE=de
   APP_TIMEZONE=Europe/Berlin

   # Traefik beendet TLS und spricht per HTTP mit dem Container
   TRUSTED_PROXIES=*
   SESSION_SECURE_COOKIE=true
   # Die Ports des Containers nicht öffentlich machen, nur Traefik soll ihn erreichen
   HTTP_BIND=127.0.0.1

   # Flux Pro für den Build (eine Zeile, einfache Anführungszeichen)
   COMPOSER_AUTH='{"http-basic":{"composer.fluxui.dev":{"username":"<E-Mail der Lizenz>","password":"<Lizenzschlüssel>"}}}'

   MAIL_MAILER=smtp
   MAIL_HOST=…
   MAIL_PORT=587
   MAIL_USERNAME=…
   MAIL_PASSWORD=…
   MAIL_FROM_ADDRESS=sprint@example.com
   MAIL_FROM_NAME=Sprint
   ```

   Datenbank-Variablen trägst du nicht ein: ohne Angabe nimmt das Image SQLite im Volume (`/app/storage/database/database.sqlite`).

   **`APP_KEY`** erzeugst du einmal, z. B. lokal mit `php artisan key:generate --show` oder mit `echo "base64:$(openssl rand -base64 32)"`, und hebst ihn getrennt von den Backups gut auf: Ohne ihn sind Zwei-Faktor-Geheimnisse und Sitzungen nach einem Restore nicht mehr lesbar.

4. **Domain** (Tab *Domains*; *Preview Compose* zeigt vorher die fertige Datei mit den erzeugten Labels): **Service** `app`, **Port** `80`, Host `sprint.example.com`, **HTTPS** an mit Let's Encrypt als Zertifikat. Dokploy hängt `app` dafür ans Netz `dokploy-network` und setzt die Traefik-Labels selbst; eigene Labels in der `compose.yaml` sind nicht nötig. Die Domain muss per DNS (A-Record) auf den Server zeigen. Änderungen an Domains wirken erst nach dem nächsten **Deploy**.
5. **Deploy**. Beim ersten Start migriert der `app`-Container die Datenbank; `queue` und `scheduler` starten, sobald `app` gesund ist (Healthcheck `php artisan sprint:health`).
6. **Ersten Administrator anlegen** im Terminal des Containers `app` (in Dokploy über *Docker Terminal*):

   ```bash
   php artisan user:create "Anna Beispiel" anna@example.com --admin
   ```

7. **Prüfen:** `https://sprint.example.com/health` liefert `{"status":"ok", …}`, im Terminal zeigt `php artisan sprint:health` Details.

Mit **Auto Deploy** (Webhook von GitHub) baut Dokploy nach jedem Push auf `main` neu; Migrationen laufen beim Start automatisch.

## Live-Updates (Reverb)

Der Dienst `reverb` steckt im Compose-Profil `realtime`. Docker Compose liest das gewünschte Profil aus der `.env`, ein Eintrag im *Environment* genügt also (der Startbefehl unter *Advanced* bleibt, wie er ist):

```ini
COMPOSE_PROFILES=realtime
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=sprint
REVERB_APP_KEY=<zufälliger Schlüssel>
REVERB_APP_SECRET=<zufälliges Geheimnis>
REVERB_HOST=                     # leer: Host der Seite
REVERB_PORT=443
REVERB_SCHEME=https
```

Eine zweite Domain braucht Reverb nicht: Der Browser verbindet sich mit `wss://sprint.example.com/app/…`, Traefik reicht das an `app` weiter, und Caddy dort leitet `/app/*` an den Reverb-Container. Die App selbst schickt ihre Meldungen intern direkt an `reverb:8080` (`REVERB_INTERNAL_*` setzt die `compose.yaml`).

## Daten und Backups

- Alles Dauerhafte liegt im benannten Volume **`storage`**: SQLite-Datenbank, Anhänge, Profilbilder (in der Datenbank) und die nächtlichen Backups unter `storage/app/backups`. Es überdauert Deployments; bitte keine Bind-Mounts auf Pfade im Repository anlegen, die leert Dokploy beim nächsten Klonen.
- Sprint schreibt jede Nacht um 02:30 ein konsistentes Backup (siehe [Backup und Wiederherstellung](maintenance.md#backup-und-wiederherstellung)). Damit es auch den Verlust des Servers übersteht:
  - **Dokploy Volume Backups** (Tab *Volume Backups*) für das Volume `storage` auf ein S3-Ziel einrichten, zeitlich **nach** 02:30, z. B. 03:30. Für eine Wiederherstellung nimmst du dann die ZIP-Datei aus `storage/app/backups` und nicht die Live-Datei `database.sqlite`, die während des Kopierens beschrieben werden kann.
  - oder Sprint die Backups direkt in einen Bucket schreiben lassen (`BACKUP_DISK`, siehe Backup-Doku).
- `APP_KEY` und die übrigen Werte aus dem *Environment*-Tab sicherst du einmalig getrennt davon (Passwort-Manager).

## Updates und Wartung

- Neue Version: Push auf `main` (mit Auto Deploy) oder **Deploy** in Dokploy. Der `app`-Container migriert und baut die Caches beim Start neu.
- Artisan-Befehle im *Docker Terminal* des Containers `app`, z. B. `php artisan sprint:backup` für ein sofortiges Backup oder `php artisan search:rebuild` nach einem Restore.
- Logs pro Dienst im Tab *Logs* (Laravel schreibt nach stderr).

## Häufige Stolpersteine

- **Links mit `http://` oder Passkeys gehen nicht:** `TRUSTED_PROXIES=*` fehlt, oder `APP_URL` stimmt nicht exakt mit der Domain überein.
- **Build bricht bei `livewire/flux-pro` ab:** `COMPOSER_AUTH` fehlt im *Environment* oder ist nicht in einfachen Anführungszeichen.
- **Seite über `http://<server-ip>:8000` erreichbar:** `HTTP_BIND=127.0.0.1` fehlt; ohne ihn veröffentlicht der Container seine Ports am Server vorbei an Traefik.
- **Kein `SERVER_NAME` setzen:** Das Image lässt Caddy mit `SERVER_NAME=:80` nur HTTP sprechen, Zertifikate holt allein Traefik. Steht dort eine Domain, will Caddy selbst eines bei Let's Encrypt holen; das scheitert, weil Port 80 Traefik gehört, und Caddy leitet dann auf HTTPS um, was in einer Umleitungsschleife endet.
- **Kein `container_name`** in der `compose.yaml` ergänzen: Dokploy braucht seine eigenen Namen für Logs und Monitoring.
