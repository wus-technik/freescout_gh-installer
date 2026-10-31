# FreeScout GitHub Installer

FreeScout-Module über eine GitHub-Repository-URL installieren und Module ohne eigene Update-Anbindung anhand ihres Commit-SHA aktualisieren. Bedienung ausschließlich für FreeScout-Administratoren unter **Verwalten → GitHub-Installer**.

## Erstinstallation des Installers

1. `GHInstaller.zip` aus dem [neuesten Release](https://github.com/wus-technik/freescout_gh-installer/releases/latest) herunterladen und entpacken. Das ZIP enthält bereits den richtig benannten Ordner `GHInstaller/`. Alternativ dieses Repository klonen.
2. Den Repository-Inhalt in `<freescout>/Modules/GHInstaller` ablegen. Direkt darin müssen `module.json`, `Providers`, `Http` und `Services` liegen. Der Ordner muss **GHInstaller** heißen, nicht `freescout_gh-installer-main`.
3. PHP-Erweiterungen **curl**, **zip** und **json** aktivieren. Der PHP/Webserver-Benutzer benötigt Schreibrechte für FreeScouts `Modules` und `storage/app`. Ausgehendes HTTPS zu `api.github.com` und `codeload.github.com` muss erlaubt sein.
4. In FreeScout **Verwalten → Module** öffnen und **GHInstaller** aktivieren. Falls nötig den Cache über die Systemwerkzeuge leeren.
5. **Verwalten → GitHub-Installer** öffnen.

Alternativ ein mit `git archive` erstelltes ZIP mit dem Ordner `GHInstaller/` nach `<freescout>/Modules` entpacken. Für den Installer werden weder Composer noch Git noch Shell-Zugriff auf dem FreeScout-Server benötigt.

Zielplattform: FreeScout ab **1.8.124** mit Laravel 5.5 / Laravel-Modules 2.x; PHP-Syntax ab 7.1. Die konkrete Zielinstallation muss vor dem produktiven Einsatz geprüft werden.

## Ein Modul installieren

1. Die URL `https://github.com/organisation/repository` eingeben; eine abschließende `.git`-Endung ist erlaubt.
2. Optional einen Branch, Tag oder Commit angeben. Ohne Angabe wird der Standard-Branch des Repositories gewählt und für spätere Prüfungen gespeichert.
3. **Repository prüfen** lädt das Archiv eines fest aufgelösten Commits und zeigt Modulname, Version, Alias und vollständigen Commit-SHA. Die Vorschau gilt zehn Minuten.
4. Den Quellcode prüfen und das Vertrauen ausdrücklich bestätigen, dann **Modul installieren** wählen.
5. Unter **Verwalten → Module** das neue Modul aktivieren. FreeScout prüft dabei die Modulvoraussetzungen und führt die vorgesehenen Migrationen aus.

Unterstützt wird genau ein FreeScout-Modul mit `module.json` im Repository, entweder im Repository-Stamm oder in einem Unterordner, beispielsweise `Modules/ExampleModule/`. `name` muss dem PHP-Modulnamen und Provider-Namespace entsprechen, beispielsweise `ExampleModule`, Alias `examplemodule`, Provider `Modules\ExampleModule\Providers\ExampleModuleServiceProvider`. Mehrere `module.json`-Dateien sind mehrdeutig und werden abgewiesen. Es werden keine Composer-Abhängigkeiten installiert und keine Build- oder Installationsskripte ausgeführt. Das Repository muss das einsatzfähige Modul enthalten; Git-LFS-Objekte und Submodule werden nicht zusätzlich geladen.

Unterstützt wird GitHub.com. GitHub Enterprise, beliebige Download-URLs und GitHub-Unterseiten wie `/tree/main` werden nicht akzeptiert; die Referenz wird separat eingegeben.

## Commit-Prüfung und Updates

Jede Installation schreibt im Modulordner `.ghinstaller-source.json` mit Repository, Referenz, vollständigem Commit-SHA und UTC-Installationszeit. Die Übersicht zeigt diese Informationen für über den Installer installierte Module.

1. **Auf Änderungen prüfen** fragt den aktuellen Commit der gespeicherten Referenz bei GitHub ab. Die Prüfung verändert keine Moduldateien und erfolgt nur auf Anforderung.
2. Zeigt die Referenz auf einen anderen Commit, öffnet **Update prüfen** eine neue Vorschau mit bisherigem und neuem SHA. Der Commit wird dabei erneut aufgelöst; installiert wird exakt der in dieser Vorschau gezeigte Stand.
3. Datenbank und Modulordner sichern. Das betreffende Modul in der FreeScout-Modulverwaltung **deaktivieren**. Aktive Module werden vom Installer nicht ersetzt.
4. **Update installieren** bestätigen, anschließend das Modul wieder über **Verwalten → Module** aktivieren. So übernimmt FreeScout auch die Migrationen der neuen Modulversion.

Updates ersetzen den **gesamten Modulordner**, einschließlich lokaler Änderungen und dort gespeicherter Dateien. Die neue Version wird zunächst vollständig vorbereitet, die bisherige Version kurzzeitig gesichert und danach ersetzt. Bei einem Fehler beim Einsetzen wird die bisherige Version zurückgesetzt. Ein erfolgreicher Dateiaustausch bedeutet keine fachliche Prüfung der Modulversion oder ihrer Datenbankmigrationen. Datenbank-Rollback und automatische Rückkehr nach einer fehlerhaften Aktivierung sind nicht Bestandteil des Installers.

Bei einem Prozessabbruch während des Austauschs kann die bisherige Version in `Modules/.ghinstaller-backup-<id>` liegen. Vor einem erneuten Versuch den Zustand prüfen und gegebenenfalls diesen Ordner auf den ursprünglichen Modulnamen zurückbenennen. Backups werden nach einem erfolgreichen Austausch gelöscht.

Der SHA-Vergleich erkennt einen **anderen Stand**, auch nach einem Force-Push, und behauptet keine zeitliche Reihenfolge. Ein festgelegter Commit bleibt fest; Tags bleiben üblicherweise ebenfalls unverändert. Zum Verfolgen neuer Entwicklungen einen Branch wählen. Ein Update ist auch möglich, wenn der Autor die Modulversionsnummer unverändert lässt.

Hat ein Modul `latestVersionUrl` oder `latestVersionZipUrl` in seiner `module.json`, verweist der Installer auf die FreeScout-Modulverwaltung und bietet keinen eigenen Updateweg an. Bereits anderweitig installierte Module ohne Herkunftsnachweis werden nicht übernommen.

## Self-Update des GHInstallers

Der GHInstaller besitzt eine eigene Update-Anbindung nach demselben Prinzip wie [Sidebar-Webhook PR #22](https://github.com/fulldecent/freescout-sidebar-webhook/pull/22). Unter **Verwalten → Module** erkennt FreeScout neue GHInstaller-Releases; auch `php artisan freescout:module-update` kann die Versionsdatei auswerten.

- `latestVersionUrl` liefert die reine Versionsnummer aus `releases/latest/download/version.txt`.
- `latestVersionZipUrl` liefert `releases/latest/download/GHInstaller.zip` mit dem Wurzelordner **GHInstaller/**. Das Repository muss deshalb nicht wie der PHP-Modulordner heißen.
- Der integrierte FreeScout-Updater übernimmt den Austausch des Installers. Dessen eigene Commit-basierte Fallback-Updates bleiben für Module ohne native Update-Anbindung zuständig.

Die beiden Release-Dateien müssen ohne Anmeldung erreichbar sein. Ein privates GitHub-Repository liefert sie dem eingebauten FreeScout-Updater nicht; `GHINSTALLER_GITHUB_TOKEN` gilt für Installationen und Commit-Prüfungen dieses Moduls und wird nicht in FreeScouts nativen Updater injiziert.

### Releases erstellen

1. `version` in `module.json` erhöhen und committen.
2. Einen identischen Tag ohne `v`-Präfix pushen, zum Beispiel `git tag 0.1.1` und `git push origin 0.1.1`.
3. Der Release-Workflow prüft die Versionsübereinstimmung, führt die Installertests aus, baut und prüft `GHInstaller.zip`, erzeugt `version.txt` und veröffentlicht den Release mit beiden Dateien. GitHub erhält beim erstmaligen Veröffentlichen die Assets vor der Veröffentlichung, damit `releases/latest` auf einen vollständigen Release zeigt.

Der Workflow kann für einen bestehenden Tag auch manuell gestartet werden. `.gitattributes` schließt CI- und Testdateien aus dem Installations-ZIP aus. Tags sind als unveränderliche Release-Stände zu behandeln.

## Private Repositories / API-Limit

Optional in der **serverseitigen FreeScout-`.env`** setzen:

```dotenv
GHINSTALLER_GITHUB_TOKEN=github_pat_...
```

Einen Fine-grained Token mit Zugriff nur auf die benötigten Repositories und **Contents: Read-only** verwenden. Bei Organisations-Repositories gegebenenfalls die Organisationsfreigabe beachten. Der Token wird ausschließlich serverseitig an die beiden erlaubten GitHub-Hosts gesendet und nicht in Herkunftsnachweisen gespeichert. Tokens gehören weder in URLs noch in Git. Nach Änderungen gegebenenfalls den FreeScout-Konfigurationscache erneuern.

## Schutzmaßnahmen und Grenzen

- Alle Seiten und Aktionen erfordern eine angemeldete Administrator-Sitzung; Änderungen erfolgen per POST mit FreeScouts CSRF-Schutz und begrenzter Anfragerate.
- Ausschließlich HTTPS zu festen GitHub-Hosts, Zertifikatsprüfung, keine Weiterleitungen, feste Zeit- und Downloadlimits.
- Maximal 25 MiB Download, 100 MiB entpackter Inhalt und 5000 ZIP-Einträge. Archivpfade, doppelte Pfade, Symlinks und Spezialdateien werden vor dem Entpacken geprüft.
- Vorbereitete Dateien liegen unter `storage/app/ghinstaller`, nicht im öffentlichen Verzeichnis. Abgelaufene Vorschauen werden bei weiterer Nutzung aufgeräumt. Bei längerer Nichtbenutzung können sie dort bis zur nächsten Nutzung verbleiben.
- Installationen überschreiben keine bestehenden Namen/Aliase. Updates sind nur für ein passendes, nachverfolgtes Modul ohne eigene Update-Anbindung möglich. Ein Dateilock verhindert parallele Installationen durch diesen Installer.
- Modulcode wird beim Prüfen nicht ausgeführt. Ein aktiviertes Modul hat allerdings dieselben weitreichenden Rechte wie FreeScout. Die ZIP-Prüfung ersetzt keine Quellcodeprüfung und keine Signaturprüfung.
- FreeScout und die Modulverwaltung dürfen während eines Updates nicht parallel von anderen Administratoren oder Deploymentprozessen verändert werden. Im Wartungsfenster ausführen.

## Entwicklung und Prüfung

```sh
php tests/run.php
find . -name '*.php' ! -name '*.blade.php' -print0 | xargs -0 -n1 php -l
```

Windows mit optionalen PHP-Erweiterungen:

```powershell
php -d extension=zip tests/run.php
Get-ChildItem -Recurse -Filter *.php | Where-Object Name -NotLike '*.blade.php' | ForEach-Object { php -l $_.FullName }
```

Die eigenständigen Tests verwenden echte ZIP-Dateien und temporäre Modulverzeichnisse mit simuliertem GitHub-Client. Sie prüfen unter anderem Archivangriffe, Modul-Erkennung, Kollisionen, Installation, SHA-Nachweis, Commit-Prüfung, Updates, Vorrang nativer Updater und veraltete Vorschauen. GitHub Actions führt diese Tests mit PHP 7.1, 7.4, 8.2 und 8.4 aus.

Vor Produktiveinsatz in einer FreeScout-Testinstallation prüfen: Adminzugriff und Ablehnung normaler Benutzer, CSRF, Vorschau/Abbruch, Erstinstallation/Aktivierung und Update/erneute Aktivierung inklusive Migrationen. Ein privates Repository zusätzlich mit dem vorgesehenen Organisationstoken prüfen.

Ein Installationspaket aus einem Commit erstellen:

```sh
mkdir -p dist
git archive --format=zip --prefix=GHInstaller/ --output=dist/GHInstaller-0.1.0.zip HEAD
```

## FreeScout-Schnittstellen

- [Modulentwicklung](https://github.com/freescout-help-desk/freescout/wiki/Modules-Development)
- [Verwalten-Menü und Erweiterungspunkt](https://github.com/freescout-help-desk/freescout/blob/master/resources/views/layouts/app.blade.php)
- [Native Update-Metadaten](https://github.com/freescout-help-desk/freescout/blob/master/app/Http/Controllers/ModulesController.php)
- [Modul-Autoloading](https://github.com/freescout-help-desk/freescout/blob/master/composer.json)
