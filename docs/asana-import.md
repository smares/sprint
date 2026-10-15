# Von Asana zu Sprint umziehen

Der Befehl `php artisan asana:import` holt Projekte über die Asana-API ab und legt sie in Sprint an: Aufgaben mit allen Ebenen von Unteraufgaben, Zuständigen, Beteiligten, Tags, eigenen Feldern, Abhängigkeiten, Kommentaren und Anhängen. Personen werden über die E-Mail-Adresse zugeordnet, Verweise auf andere Aufgaben zeigen danach auf die übernommenen Aufgaben.

Er liest nur aus Asana und verändert dort nichts. Er läuft auf dem Server von Sprint (oder lokal gegen dieselbe Datenbank) und ist wiederholbar: Was schon übernommen wurde, wird übersprungen, Neues kommt dazu. So lässt sich erst proben und kurz vor dem Umstieg noch einmal nachziehen.

> Der Import wurde mit nachgestellten Antworten getestet und gegen ein echtes Asana-Konto (kostenloser Tarif, ein Projekt mit rund 840 Aufgaben übernommen, alle Projekte als Probelauf gelesen). Fang trotzdem mit `--dry-run` und einem kleinen Projekt an, bevor du alles übernimmst.

## Vorbereitung

1. **Zugang in Asana:** unter *Profil → Einstellungen → Apps → Entwicklerkonsole* ein **persönliches Zugriffstoken** erzeugen. Es sieht, was die Person in Asana sieht. Am besten nimmt man ein Konto, das alle zu übernehmenden Projekte sehen darf.
2. **Token hinterlegen**, ohne dass es in der Shell-Historie landet: als `ASANA_TOKEN` in der `.env` (danach wieder entfernen) oder den Befehl interaktiv starten, dann fragt er danach. `--token=…` geht auch, ist aber die unsicherste Variante. Das Token gehört wie ein Passwort behandelt; es wird weder ausgegeben noch gespeichert.
3. **Personen in Sprint anlegen**, mit denselben E-Mail-Adressen wie in Asana (`php artisan user:create "Anna Beispiel" anna@firma.de`, siehe [Installation](installation.md)). Liefert Asana für eine Person gar keine Adresse, wird nach dem Namen gesucht, sofern er eindeutig ist; hat sie eine Adresse, die hier niemandem gehört, gilt sie als jemand ohne Konto (ein gleichnamiges Konto mit anderer Adresse ist eine andere Person). Wer fehlt, steht im Bericht; mit `--create-users` legt der Import die Konten selbst an, und zwar **deaktiviert** und **ohne Willkommens-Mail**. Die E-Mail-Adresse aus Asana bekommen sie trotzdem (ohne Adresse, oder wenn sie hier schon vergeben ist, entsteht kein Konto und der Bericht nennt die Person), das Passwort ist zufällig und unbekannt. Zuweisungen, Mitgliedschaften und Erwähnungen stimmen dann schon, die Personen werden mit „(deaktiviert)“ gekennzeichnet und bekommen keine Mails. Wer sich anmelden soll, wird von einer Administratorin unter *Personen* wieder aktiviert (oder per `php artisan user:deactivate name@firma.de --reactivate`) und fordert über „Passwort vergessen“ ein Passwort an, die Mail geht an die Adresse aus Asana. Die Willkommens-Mail kommt beim Aktivieren nicht von allein.
4. **Projekte finden:** `php artisan asana:import --list` zeigt Workspaces und Projekte samt Asana-ID (die Zahl in der Adresse des Projekts). Archivierte Projekte stehen dort nicht; die übernimmst du mit ihrer ID.

## Ablauf

```bash
# 1. Probelauf: liest alles, ändert nichts und berichtet
php artisan asana:import 1200000000000001 1200000000000002 --dry-run

# 2. Der eigentliche Import
php artisan asana:import 1200000000000001 1200000000000002

# Oder ohne IDs: der Befehl listet die Projekte, und du hakst die gewünschten an
php artisan asana:import
```

Ohne Projekt-IDs fragt der Befehl (im Terminal, nicht mit `--no-interaction`) nach den Projekten: Mit den Pfeiltasten wählen, mit der Leertaste anhaken, mit Enter bestätigen. **Die Reihenfolge des Ankreuzens zählt** wie die der IDs (siehe unten). Die Form `GID=ID` (in ein vorhandenes Sprint-Projekt importieren) gibt es nur über die Angabe der IDs.

### Im Docker-Setup

Der Befehl läuft im Container `sprint`, auf dem Server im Ordner mit der Compose-Datei (bei `compose.image.yaml` jeweils `-f compose.image.yaml` vor `exec`):

```bash
# Projekte finden (fragt nach dem Token, die Eingabe bleibt unsichtbar)
docker compose exec sprint php artisan asana:import --list

# Ohne IDs wählst du die Projekte aus einer Liste an (das Token wird abgefragt)
docker compose exec sprint php artisan asana:import --dry-run

# Probelauf und Import mit IDs
docker compose exec sprint php artisan asana:import 1200000000000001 1200000000000002 --dry-run
docker compose exec sprint php artisan asana:import 1200000000000001 1200000000000002 --create-users
```

- **Token:** Am einfachsten lässt man den Befehl danach fragen. Wer es mitgeben will, ohne dass es in der Shell-Historie steht: `read -rs ASANA_TOKEN && export ASANA_TOKEN`, dann `docker compose exec -e ASANA_TOKEN sprint php artisan asana:import …` (ohne `=Wert`, der Wert kommt aus der Umgebung der Shell). Ein Eintrag in der `.env` wirkt erst, wenn der Container neu erstellt wird (`docker compose up -d`; ein `docker compose restart` liest die `.env` nicht neu), weil er die Konfiguration beim Start zwischenspeichert.
- **Daten:** Aufgaben landen in der Datenbank im Volume `storage`, Anhänge unter `storage/app/private`; beides bleibt über Updates erhalten. Die Tabelle für die Zuordnung (`asana_imports`) legt die Migration an, die der Container beim Start ausführt.
- **Lange Läufe:** Ein Projekt mit Hunderten Aufgaben braucht eine Weile (Asana begrenzt die Anfragen). Bei einer SSH-Verbindung also in `tmux` oder `screen` starten. Bricht ein Lauf ab, einfach neu starten: Was fertig ist, wird übersprungen. Konnten Dateien nicht geladen werden, meldet der Befehl am Ende einen Fehler (Exit-Code 1), damit ein Skript es merkt; ein zweiter Lauf holt genau diese Dateien nach. Das Lesen aus Asana beginnt nach einem Abbruch von vorn, nur das Schreiben macht dort weiter, wo es war.

Die Reihenfolge der Projekte zählt: **Eine Aufgabe, die in mehreren Asana-Projekten liegt, wird nur im ersten angegeben Projekt angelegt** (Sprint-Aufgaben gehören zu genau einem Projekt). In den anderen Projekten fehlt sie, der Bericht nennt, wie viele es waren.

**Fortschritt:** Der Import arbeitet in zwei Phasen. Zuerst **liest** er alles aus Asana (ein Zähler zeigt, wie viele Aufgaben gelesen sind; wie viele es werden, steht erst danach fest) und schreibt dabei nichts nach Sprint. Danach **legt er an** und zeigt einen Balken mit Aufgaben, Dauer und geschätzter Gesamtzeit. Muss er auf Asana warten (Anfragegrenze oder ein vorübergehender Fehler), steht das mit der Wartezeit dabei, er hängt also nicht. Ohne Terminal (Logs, Pipes) meldet er stattdessen alle 25 Aufgaben eine Zeile. Das Anlegen läuft pro Aufgabe in einer eigenen Transaktion; bricht ein Lauf ab, macht ein neuer Start dort weiter.

Der Probelauf stellt alle Anfragen an Asana, schreibt aber nichts. Er zeigt, wie viele Aufgaben, Kommentare, Anhänge (mit Größe) und Abhängigkeiten kommen, und welche Personen in Sprint fehlen. Ohne Probelauf bricht der Import in dem Fall ab, solange du nicht entscheidest: Konten anlegen (`--create-users`), sie vorher selbst anlegen oder die Zuordnungen dieser Personen weglassen (`--ignore-missing-users`).

### Optionen

| Option | Wirkung |
| --- | --- |
| `--dry-run` | nur lesen und berichten |
| `--list` | Workspaces und Projekte des Tokens anzeigen |
| `--sections=status` (Standard) | Asana-Abschnitte werden **Status** des Projekts (Board-Spalten) |
| `--sections=headings` | Abschnitte werden **Überschriften** in der Liste, die Status bleiben Standard. Die Überschriften stehen in der Liste ohne Filter und Sortierung; Board und Zählungen übergehen sie. Eine neue Aufgabe in einem weiteren Lauf kommt ans Ende ihres Abschnitts (ohne Abschnitt: vor die erste Überschrift) |
| `--create-users` | Personen ohne Konto deaktiviert und ohne Willkommens-Mail anlegen (mit ihrer Adresse aus Asana); später aktivierbar |
| `--ignore-missing-users` | trotz fehlender Konten weitermachen, deren Zuständigkeiten entfallen |
| `--skip-followers` | Follower nicht als Beteiligte übernehmen |
| `--as=anna@firma.de` | wer als importierende Person eingetragen wird (Standard: der erste Administrator); in einem neuen Projekt bekommt sie die Rolle *Verwalten*, in ein bestehendes (`GID=ID`) kommt sie ohne Rolle mit *Bearbeiten* hinein, eine vorhandene Rolle bleibt |
| `GID=ID` statt `GID` | in das bestehende Sprint-Projekt mit dieser ID importieren, statt ein neues anzulegen |

## Was wie übernommen wird

| Asana | Sprint |
| --- | --- |
| Projekt (Name, Notizen, Farbe, archiviert) | Projekt; die importierende Person bekommt die Rolle *Verwalten*. Archivierte Projekte werden archiviert angelegt |
| Mitglieder des Projekts | Mitglieder mit der Rolle *Bearbeiten*, der Besitzer mit *Verwalten*; wer eine Aufgabe zugewiesen bekommt, kommt mit *Bearbeiten* dazu |
| Abschnitt | Status (oder Überschrift, siehe oben). „Untitled section“ bleibt der erste Standard-Status. Abschnitte, die so heißen wie ein vorhandener Status, nutzen diesen (auch „Done“ oder „Erledigt“: Eine offene Aufgabe in einer solchen Spalte ist in Sprint danach erledigt). Neue Status stehen vor den erledigten |
| Aufgabe | Aufgabe in der Reihenfolge von Asana, auch Unteraufgaben in beliebiger Tiefe; Titel über 255 Zeichen werden gekürzt |
| Erledigt | der erledigte Status samt Erledigt-Datum (die Spalte, in der die Aufgabe stand, geht dabei verloren) |
| Status einer Unteraufgabe | Asana kennt für Unteraufgaben nur offen oder erledigt, Spalten haben sie nicht (sie stehen nur bei ihrer Aufgabe). Eine offene Unteraufgabe bekommt in Sprint den Status ihrer Aufgabe, außer der ist ein erledigter Status: Dann bleibt sie im ersten Status, damit offene Arbeit nicht als fertig zählt. Eine erledigte kommt in den erledigten Status |
| Beschreibung, Kommentare | Markdown: Fett, Kursiv, Durchgestrichen, Code, Listen, Überschriften, Zitate und Links bleiben erhalten; Bilder in der Beschreibung nicht (sie kommen, soweit Asana sie als Anhang führt, als Anhang). Was in Asana als Text getippt wurde, bleibt Text: `*Hinweis*`, `# kein Titel` oder `<div>` werden nicht zu Formatierung bzw. verschluckt. Texte über 65.000 Zeichen (die Grenze der Eingabefelder) werden gekürzt, der Bericht nennt, wie viele |
| Erstellt (Verlauf) | Die Aufgabe trägt das Erstellungsdatum aus Asana, und der Verlauf beginnt mit „Name hat die Aufgabe angelegt“ zu diesem Zeitpunkt (nicht am Tag des Imports), sodass er vor den Kommentaren steht. Wer die Aufgabe in Asana angelegt hat, steht dort (ohne Konto: die importierende Person) |
| Zuständige, Ersteller | Zuständige und Ersteller, per E-Mail zugeordnet; ohne Konto bleibt die Aufgabe ohne Zuständige, in der Beschreibung steht dann „Zuständig in Asana: Name“, und als Ersteller steht die importierende Person |
| Follower | Beteiligte, aber nur, wenn die Person Mitglied des Projekts ist (sonst gäbe es keine Rechte dafür) |
| Start und Fällig | Start- und Fälligkeitsdatum; ein Zeitpunkt wird in der Zeitzone der Anwendung zum Datum |
| Tags | Tags des Projekts, nach Namen; ein Meilenstein bekommt zusätzlich den Tag „Meilenstein“ |
| Eigene Felder | Auswahl → Auswahlfeld (mit seinen Optionen), Zahl, Datum, Text; Mehrfachauswahl und Personen werden Textfelder mit den Werten durch Komma getrennt; Formeln und Verweise entfallen. Ein Feld, das in Sprint schon so heißt und denselben Typ hat (z. B. *Priorität*), wird benutzt; hat das gleichnamige einen anderen Typ, entsteht „Name (Asana)“, und ein weiterer Lauf benutzt dieses wieder; Textwerte werden auf 500 Zeichen gekürzt (die Grenze der Textfelder) |
| Abhängigkeiten („blockiert von“) | Abhängigkeiten zwischen den übernommenen Aufgaben, auch über Projekte hinweg; auf nicht übernommene Aufgaben verweisende entfallen mit einem Hinweis. Sie werden einmal je Aufgabe gesetzt (ein weiterer Lauf ergänzt nur Abhängigkeiten auf Aufgaben, die er neu anlegt): Was jemand in Sprint entfernt hat, kommt nicht zurück |
| Links auf andere Aufgaben in Texten | Erwähnungen der übernommenen Aufgaben (`@[Titel](task:…)`); @-Erwähnungen von Personen werden Erwähnungen, ohne Konto bleibt der Name |
| Kommentare | Kommentare mit Verfasser:in und Zeitpunkt. Stammt ein Kommentar von jemandem ohne Konto, steht „Von Name in Asana“ am Anfang und die importierende Person ist die Verfasser:in |
| Kommentare ohne Text | werden nicht übernommen (etwa ein Kommentar, der in Asana nur aus einem Anhang oder Emoji besteht). Der Befehl nennt sie mit Aufgabe und Asana-ID in den Hinweisen, auch im Probelauf, und zählt sie in der Übersicht |
| Anhänge | Dateien bis 20 MB werden heruntergeladen und angehängt (die Endung folgt dem erkannten Dateityp; Weiterleitungen nur über https); Dateien anderer Dienste (Dropbox, Google Drive …) und zu große Dateien werden als Links an die Beschreibung angehängt; kommen sie erst in einem weiteren Lauf dazu, werden die Links an die Beschreibung der vorhandenen Aufgabe angefügt (je Datei einmal) |
| Zeitpunkt der Anlage und Änderung | bleibt bei Aufgaben, Kommentaren und Anhängen erhalten |

**Benachrichtigungen:** Der Import verschickt nichts, weder E-Mails noch Push noch Einträge im Posteingang, auch nicht an erwähnte, zuständige oder beteiligte Personen.

## Was nicht übernommen wird

- **Wiederholungen**, Reaktionen („Gefällt mir“), Genehmigungsaufgaben, Teilnahme an Portfolios, Ziele, Formulare, Automatisierungsregeln und Sichtweisen (Boards, Zeitleisten): Sprint hat eigene.
- **Die Aktivitätsliste** einer Aufgabe (Zuweisungen, Datumsänderungen). Der Verlauf in Sprint beginnt mit dem Import.
- **Unteraufgaben, die schon übernommen wurden:** Ein zweiter Lauf ändert keine vorhandene Aufgabe, also auch nicht ihren Status.
- **Importe vor Version 1.5.9:** Dort steht im Verlauf die Person, die den Import gestartet hat, und der Tag des Imports als Anlegedatum. `php artisan asana:fix-created-history` setzt Datum und Ersteller dieser Einträge nach den Angaben der Aufgabe (ohne Verbindung zu Asana; `--dry-run` zeigt nur, was sich ändert).
- **Änderungen nach dem Import:** Ein zweiter Lauf übernimmt neue Aufgaben, Unteraufgaben, Kommentare und Anhänge, ändert aber keine bereits übernommene Aufgabe (Titel, Status, Datum …). Links auf Asana-Aufgaben in früher übernommenen Texten werden in jedem Lauf nachgezogen, sobald die Aufgabe in Sprint existiert (auch nach einem Abbruch oder wenn sie erst jetzt dazukommt).
- **Aufgaben aus anderen Projekten** (siehe oben), solange sie nicht im ersten angegebenen Projekt liegen.

## Dauer und Grenzen von Asana

Pro Aufgabe sind mindestens zwei Anfragen nötig (Kommentare, Anhänge), dazu je eine für Unteraufgaben. Asana begrenzt die Anfragen pro Minute (je nach Tarif etwa 150 bis 1500). Der Befehl wartet bei Überschreitung selbst und versucht es erneut, ein Projekt mit tausend Aufgaben dauert deshalb im kostenlosen Tarif eine Weile. Bricht der Lauf ab, startet man ihn einfach neu: Was fertig ist, bleibt, der Rest folgt.

Der Import liest erst alle Aufgaben der angegebenen Projekte (nur diese, ohne Kommentare und Anhänge) und schreibt dann Aufgabe für Aufgabe; Kommentare und Dateien holt er erst, wenn er die Aufgabe schreibt. Der Speicherbedarf wächst deshalb nur mit der Zahl der Aufgaben, nicht mit der der Kommentare: Der Docker-Container erlaubt 256 MB (`docker/php.ini`). Reicht das bei sehr vielen Aufgaben nicht, ruf den Befehl mit mehr auf (`php -d memory_limit=1G artisan asana:import …`) oder importiere die Projekte einzeln. Ein Fehler beim Lesen von Kommentaren stoppt den Lauf deshalb mitten im Schreiben; ein weiterer Lauf macht dort weiter. Der Probelauf (`--dry-run`) liest alles, um zu zählen, und behält nichts.

Was der Import sich gemerkt hat, steht in der Tabelle `asana_imports` (Asana-ID → Sprint-ID). Löscht man eine übernommene Aufgabe oder ein übernommenes Projekt in Sprint, legt ein weiterer Lauf es wieder an, mit seinen Kommentaren, Anhängen und (bei `--sections=headings`) Überschriften. Auch die Status und Felder, die der Import angelegt hat, merkt er sich: Benennst du einen Status um, entsteht er nicht noch einmal.
