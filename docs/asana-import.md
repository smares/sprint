# Von Asana zu Sprint umziehen

Der Befehl `php artisan asana:import` holt Projekte über die Asana-API ab und legt sie in Sprint an: Aufgaben mit allen Ebenen von Unteraufgaben, Zuständigen, Beteiligten, Tags, eigenen Feldern, Abhängigkeiten, Kommentaren und Anhängen. Personen werden über die E-Mail-Adresse zugeordnet, Verweise auf andere Aufgaben zeigen danach auf die übernommenen Aufgaben.

Er liest nur aus Asana und verändert dort nichts. Er läuft auf dem Server von Sprint (oder lokal gegen dieselbe Datenbank) und ist wiederholbar: Was schon übernommen wurde, wird übersprungen, Neues kommt dazu. So lässt sich erst proben und kurz vor dem Umstieg noch einmal nachziehen.

> Der Import wurde gegen die dokumentierte Asana-API und mit nachgestellten Antworten getestet, nicht gegen ein echtes Asana-Konto. Fang deshalb mit `--dry-run` und einem kleinen Projekt an, bevor du alles übernimmst.

## Vorbereitung

1. **Zugang in Asana:** unter *Profil → Einstellungen → Apps → Entwicklerkonsole* ein **persönliches Zugriffstoken** erzeugen. Es sieht, was die Person in Asana sieht. Am besten nimmt man ein Konto, das alle zu übernehmenden Projekte sehen darf.
2. **Token hinterlegen**, ohne dass es in der Shell-Historie landet: als `ASANA_TOKEN` in der `.env` (danach wieder entfernen) oder den Befehl interaktiv starten, dann fragt er danach. `--token=…` geht auch, ist aber die unsicherste Variante. Das Token gehört wie ein Passwort behandelt; es wird weder ausgegeben noch gespeichert.
3. **Personen in Sprint anlegen**, mit denselben E-Mail-Adressen wie in Asana (`php artisan user:create "Anna Beispiel" anna@firma.de`, siehe [Installation](installation.md)). Gibt es keine passende Adresse, wird als Zweites nach dem Namen gesucht, sofern er eindeutig ist. Wer fehlt, steht im Bericht; mit `--create-users` legt der Import die Konten selbst an, und zwar **deaktiviert** und ohne E-Mail. Zuweisungen, Mitgliedschaften und Erwähnungen stimmen dann schon, die Personen werden mit „(deaktiviert)“ gekennzeichnet und bekommen keine Mails. Wer sich anmelden soll, wird von einer Administratorin unter *Personen* wieder aktiviert (oder per `php artisan user:deactivate name@firma.de --reactivate`) und fordert über „Passwort vergessen“ ein Passwort an.
4. **Projekte finden:** `php artisan asana:import --list` zeigt Workspaces und Projekte samt Asana-ID (die Zahl in der Adresse des Projekts).

## Ablauf

```bash
# 1. Probelauf: liest alles, ändert nichts und berichtet
php artisan asana:import 1200000000000001 1200000000000002 --dry-run

# 2. Der eigentliche Import
php artisan asana:import 1200000000000001 1200000000000002
```

Im Docker-Setup läuft der Befehl im Container: `docker compose exec -e ASANA_TOKEN=… sprint php artisan asana:import …`.

Die Reihenfolge der Projekte zählt: **Eine Aufgabe, die in mehreren Asana-Projekten liegt, wird nur im ersten angegeben Projekt angelegt** (Sprint-Aufgaben gehören zu genau einem Projekt). In den anderen Projekten fehlt sie, der Bericht nennt, wie viele es waren.

Der Probelauf stellt alle Anfragen an Asana, schreibt aber nichts. Er zeigt, wie viele Aufgaben, Kommentare, Anhänge (mit Größe) und Abhängigkeiten kommen, und welche Personen in Sprint fehlen. Ohne Probelauf bricht der Import in dem Fall ab, solange du nicht entscheidest: Konten anlegen (`--create-users`), sie vorher selbst anlegen oder die Zuordnungen dieser Personen weglassen (`--ignore-missing-users`).

### Optionen

| Option | Wirkung |
| --- | --- |
| `--dry-run` | nur lesen und berichten |
| `--list` | Workspaces und Projekte des Tokens anzeigen |
| `--sections=status` (Standard) | Asana-Abschnitte werden **Status** des Projekts (Board-Spalten) |
| `--sections=headings` | Abschnitte werden **Überschriften** in der Liste, die Status bleiben Standard |
| `--create-users` | Personen ohne Konto deaktiviert und ohne Mail anlegen; später aktivierbar |
| `--ignore-missing-users` | trotz fehlender Konten weitermachen, deren Zuständigkeiten entfallen |
| `--skip-followers` | Follower nicht als Beteiligte übernehmen |
| `--as=anna@firma.de` | wer als importierende Person eingetragen wird (Standard: der erste Administrator) |
| `GID=ID` statt `GID` | in das bestehende Sprint-Projekt mit dieser ID importieren, statt ein neues anzulegen |

## Was wie übernommen wird

| Asana | Sprint |
| --- | --- |
| Projekt (Name, Notizen, Farbe, archiviert) | Projekt; die importierende Person wird Verwalter. Archivierte Projekte werden archiviert angelegt |
| Mitglieder des Projekts | Mitglieder mit der Rolle *Bearbeiten*, der Besitzer mit *Verwalten* |
| Abschnitt | Status (oder Überschrift, siehe oben). „Untitled section“ bleibt der erste Standard-Status. Abschnitte, die so heißen wie ein vorhandener Status, nutzen diesen. Neue Status stehen vor den erledigten |
| Aufgabe | Aufgabe in der Reihenfolge von Asana, auch Unteraufgaben in beliebiger Tiefe |
| Erledigt | der erledigte Status samt Erledigt-Datum (die Spalte, in der die Aufgabe stand, geht dabei verloren) |
| Beschreibung, Kommentare | Markdown: Fett, Kursiv, Durchgestrichen, Code, Listen, Überschriften, Zitate und Links bleiben erhalten; Bilder in der Beschreibung nicht (sie kommen, soweit Asana sie als Anhang führt, als Anhang) |
| Zuständige, Ersteller | Zuständige und Ersteller, per E-Mail zugeordnet; ohne Konto bleibt die Aufgabe ohne Zuständige, in der Beschreibung steht dann „Zuständig in Asana: Name“, und als Ersteller steht die importierende Person |
| Follower | Beteiligte, aber nur, wenn die Person Mitglied des Projekts ist (sonst gäbe es keine Rechte dafür) |
| Start und Fällig | Start- und Fälligkeitsdatum; ein Zeitpunkt wird in der Zeitzone der Anwendung zum Datum |
| Tags | Tags des Projekts, nach Namen; ein Meilenstein bekommt zusätzlich den Tag „Meilenstein“ |
| Eigene Felder | Auswahl → Auswahlfeld (mit seinen Optionen), Zahl, Datum, Text; Mehrfachauswahl und Personen werden Textfelder mit den Werten durch Komma getrennt; Formeln und Verweise entfallen. Ein Feld, das in Sprint schon so heißt (z. B. *Priorität*), wird benutzt |
| Abhängigkeiten („blockiert von“) | Abhängigkeiten zwischen den übernommenen Aufgaben, auch über Projekte hinweg; auf nicht übernommene Aufgaben verweisende entfallen mit einem Hinweis |
| Links auf andere Aufgaben in Texten | Erwähnungen der übernommenen Aufgaben (`@[Titel](task:…)`); @-Erwähnungen von Personen werden Erwähnungen, ohne Konto bleibt der Name |
| Kommentare | Kommentare mit Verfasser:in und Zeitpunkt. Stammt ein Kommentar von jemandem ohne Konto, steht „Von Name in Asana“ am Anfang und die importierende Person ist die Verfasser:in |
| Kommentare ohne Text | werden nicht übernommen (etwa ein Kommentar, der in Asana nur aus einem Anhang oder Emoji besteht). Der Befehl nennt sie mit Aufgabe und Asana-ID in den Hinweisen, auch im Probelauf, und zählt sie in der Übersicht |
| Anhänge | Dateien bis 20 MB werden heruntergeladen und angehängt; Dateien anderer Dienste (Dropbox, Google Drive …) und zu große Dateien werden als Links an die Beschreibung angehängt |
| Zeitpunkt der Anlage und Änderung | bleibt bei Aufgaben, Kommentaren und Anhängen erhalten |

**Benachrichtigungen:** Der Import verschickt nichts, weder E-Mails noch Push noch Einträge im Posteingang, auch nicht an erwähnte, zuständige oder beteiligte Personen.

## Was nicht übernommen wird

- **Wiederholungen**, Reaktionen („Gefällt mir“), Genehmigungsaufgaben, Teilnahme an Portfolios, Ziele, Formulare, Automatisierungsregeln und Sichtweisen (Boards, Zeitleisten): Sprint hat eigene.
- **Die Aktivitätsliste** einer Aufgabe (Zuweisungen, Datumsänderungen). Der Verlauf in Sprint beginnt mit dem Import.
- **Änderungen nach dem Import:** Ein zweiter Lauf übernimmt neue Aufgaben, Unteraufgaben, Kommentare und Anhänge, ändert aber keine bereits übernommene Aufgabe (Titel, Status, Datum …). Ein Link in einem früher übernommenen Text auf eine Aufgabe, die erst jetzt dazukommt, bleibt ein Asana-Link.
- **Aufgaben aus anderen Projekten** (siehe oben), solange sie nicht im ersten angegebenen Projekt liegen.

## Dauer und Grenzen von Asana

Pro Aufgabe sind mindestens zwei Anfragen nötig (Kommentare, Anhänge), dazu je eine für Unteraufgaben. Asana begrenzt die Anfragen pro Minute (je nach Tarif etwa 150 bis 1500). Der Befehl wartet bei Überschreitung selbst und versucht es erneut, ein Projekt mit tausend Aufgaben dauert deshalb im kostenlosen Tarif eine Weile. Bricht der Lauf ab, startet man ihn einfach neu: Was fertig ist, bleibt, der Rest folgt.

Was der Import sich gemerkt hat, steht in der Tabelle `asana_imports` (Asana-ID → Sprint-ID). Löscht man eine übernommene Aufgabe oder ein übernommenes Projekt in Sprint, legt ein weiterer Lauf es wieder an.
