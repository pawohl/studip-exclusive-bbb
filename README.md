# ExclusiveBBB – exklusiv buchbare BigBlueButton-Server für Stud.IP

Stud.IP-Plugin (Stud.IP 6.2), mit dem einzelne BigBlueButton-Server (BBB 3.0) über die
**Raumverwaltung** exklusiv gebucht werden. So können große oder kritische Konferenzen
(z. B. des Rektorats) nicht von anderen, wachsenden Meetings auf demselben Server beeinträchtigt werden.

## Funktionsweise

- Jeder exklusive Server ist ein **Raum** (Ressourcenklasse `Room`) in der Raumverwaltung, z. B.
  „BBB Exklusiv 1“. Anfragen, Raumvergabe, Belegungspläne und Benachrichtigungen funktionieren wie bei
  normalen Räumen.
- Zu jeder **normalen Buchung** eines solchen Raums legt das Plugin eine **Konferenz** an:
  - eigene **Teilnahmeseite** mit unratbarem Link (`plugins.php/exclusivebbb/join/index/<token>`),
    auch ohne Stud.IP-Zugang nutzbar;
  - getrennte **Passwörter** für Teilnehmende und Moderierende (zufällig erzeugt, nur als Hash gespeichert);
  - E-Mail mit Link und Passwörtern an die anfragende Person bzw. die Gebuchten und, bei Veranstaltungsterminen,
    an die Lehrenden.
  Reservierungen, Sperrbuchungen und Planungsbuchungen erhalten keine Konferenz.
- Wiederholte Buchungen haben **einen** Link und **ein** Passwortpaar, aber je Termin ein eigenes BBB-Meeting.
- **Zeiten** (konfigurierbar):
  - *Vorlauf*: Moderierende können den Raum so viele Minuten vor Beginn betreten. Er wird als
    Vorbereitungszeit an der Buchung gespeichert.
  - *Überziehungspuffer*: Nach Buchungsende läuft die Konferenz noch so lange weiter und wird dann beendet.
    Er wird als Nachbereitungszeit an der Buchung gespeichert. Damit verhindert die Raumverwaltung selbst
    Folgebuchungen, die zu nah anschließen.
- Ein **Cronjob** (jede Minute) fragt alle Server ab und beendet jedes Meeting,
  - dessen Buchung samt Puffer vorbei ist,
  - dessen Buchung gelöscht wurde oder
  - das gar nicht über dieses Plugin gestartet wurde (Server bleiben exklusiv).
- Wer darf was:

  | Rolle | Rechte |
  |---|---|
  | Gast mit Link + Passwort | Teilnahme (Passwort bestimmt die Rolle) |
  | angemeldete Mitglieder der gebuchten Veranstaltung | Teilnahme ohne Passwort |
  | anfragende/gebuchte Person, Lehrende der Veranstaltung, Ressourcen-Tutor/Admin des Servers, root | Konferenz bearbeiten (Name, Beschreibung, Bild, Passwörter), Teilnahme als Moderator/in ohne Passwort |
  | root, globale Ressourcen-Admins, Ressourcen-Tutoren der Server | Verwaltungsansicht (Live-Status, Teilnehmendenzahlen, Meeting beenden) |
  | root | Einstellungen und Server |

- Protokoll: Ereignisse landen im Stud.IP-Log (Aktionen `EXBBB_*`, *Admin → System → Log*).
  Der Debug-Modus protokolliert zusätzlich API-Aufrufe (nie Secrets oder Prüfsummen) und jede Statusprüfung.

Aufzeichnungen sind in Version 0.1 abgeschaltet (`record=false`).

## Installation

1. **Server vorbereiten**: Die exklusiven BBB-Server dürfen *nicht* (mehr) in Scalelite eingetragen sein
   (`servers:cordon`, leerlaufen lassen, `servers:remove`). Vergeben Sie jedem Server ein neues Secret
   (`bbb-conf --setsecret`), das nur dieses Plugin kennt.
2. **Plugin installieren** (Admin → System → Plugins → Plugin hochladen, oder per CLI):
   ```sh
   php cli/studip plugin:register public/plugins_packages/exclusivebbb/ExclusiveBBB
   php cli/studip plugin:migrate ExclusiveBBB
   php cli/studip plugin:activate ExclusiveBBB
   ```
   Die Migration legt Tabellen, Konfiguration und Log-Aktionen an, gibt dem Plugin die Rolle `Nobody`
   (für die öffentliche Teilnahmeseite) und registriert den Cronjob. Prüfen Sie, dass Cronjobs aktiv sind
   (`CRONJOBS_ENABLE` und der System-Cron für `cli/cronjobs.php` bzw. `cronjobs:worker`).
3. **Räume anlegen** in der Raumverwaltung: Am besten eine eigene Kategorie, z. B.
   „Videokonferenz-Server (exklusiv)“, mit der Klasse `Room`, darin je Server einen Raum. Geben Sie
   bei *Sitzplätze* die Kapazität an. Vergeben Sie Rechte wie bei normalen Räumen: Raumvergabe als
   Admin/Tutor, und schalten Sie die Räume für Anfragen frei.
4. **Server eintragen**: *Admin → System → Konfiguration → Exklusive BBB-Server*:
   - je Server Raum, URL, Secret, Prüfsummen-Algorithmus (wie `supportedChecksumAlgorithms` auf dem
     Server) und optional die maximale Teilnehmendenzahl;
   - mit „Verbindung testen“ prüfen;
   - Puffer, Vorlauf, Logo usw. einstellen.

## Entwicklung

Voraussetzung: Docker, der Stud.IP-Checkout in `../studip` auf Branch `6.2`.

```sh
docker compose -f dev/docker-compose.yml up -d --build   # Stud.IP auf Port 8000
dev/setup.sh                                             # Plugin registrieren, Demo-Räume/Server anlegen
```

- Demo-Logins aus den Stud.IP-Demodaten, z. B. `root@studip` / `testing`.
- E-Mails landen in Mailpit (nur im Docker-Netz, Port 8025).
- Die BBB-Server sind Mocks (`dev/bbb-mock`, nur im Docker-Netz):
  - `http://bbb-node1:8090/bigbluebutton/` mit Secret `node1-dev-secret`,
  - `http://bbb-node2:8090/bigbluebutton/` mit Secret `node2-dev-secret`.
  - `/mock/calls` liefert die empfangenen API-Aufrufe.

Unit-Tests (PHP 8.1 im Web-Container):

```sh
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app composer:2 install --ignore-platform-reqs
docker compose -f dev/docker-compose.yml exec \
  -w /var/www/studip/public/plugins_packages/exclusivebbb/ExclusiveBBB web vendor/bin/phpunit
```

Cronjob von Hand ausführen:

```sh
docker compose -f dev/docker-compose.yml exec web php cli/studip cronjobs:list
docker compose -f dev/docker-compose.yml exec web php cli/studip cronjobs:execute <task-id>
```

## Design

Die Entscheidungen und ihre Gründe:

| Entscheidung | Grund |
|---|---|
| Direkter Zugriff auf die Server statt Scalelite-Server-Tags | Scalelite wird mit Greenlight, ILIAS und PILOS geteilt. Jedes System mit dem Scalelite-Secret könnte per `meta_server-tag` auf einem gebuchten Server landen. Kennt nur dieses Plugin das Secret der Server, kann zudem der Cronjob jedes fremde Meeting beenden. |
| Server als Räume der Klasse `Room` | Der Anfrage-/Vergabe-Workflow der Raumverwaltung ist in der Oberfläche an `Room` gebunden. So bleibt er unverändert nutzbar. |
| Eine Konferenz je Buchung, ein Meeting je Termin | Stud.IP legt die Intervalle einer Buchung bei jeder Änderung neu an, deren IDs sind also nicht stabil. Die Meeting-ID ist `<uuid>-<YmdHi des Raumöffnens>`. |
| Meeting wird beim ersten Betreten erzeugt | Kein Scheduler und kein „Dummy-Join“ nötig. `create` ist idempotent. |
| Beenden per Cronjob, `duration` nur als Netz | `duration` wird bei `create` festgelegt und kann Verlängerungen oder Kürzungen der Buchung nicht folgen. |
| Rolle per `role=MODERATOR|VIEWER` | Die Passwörter der Nutzenden werden nur in PHP geprüft und nie an BBB übertragen. |

Lizenz: GPL-2.0-or-later (siehe `LICENSE`).
