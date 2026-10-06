# Idee: optionales Core-Caching über naf/cache

Status: nach erneuter Messung am 6.10.2026 bewusst zurückgestellt; kein Cache-Paket erforderlich.
Festgehalten am 22.09.2026.

## Ziel

Ein optional installiertes Paket `naf/cache` kann wiederverwendbare Ergebnisse der
Framework-Initialisierung speichern. Ohne das Paket bleibt NAF vollständig funktionsfähig.
Der erste Anwendungsfall ist ein Plugin-Katalog, weitere Caches werden einzeln beurteilt.

## Plugin-Katalog

Speicherbar sind Paketnamen und Versionen, berechnete Boot-Reihenfolge einschließlich
Diagnoseinformationen sowie die gefundenen Bootstrap-, Konfigurations-, Routen-, Helper-
und View-Ressourcen. Pfade müssen nach einem Build weiterhin auflösbar sein; keine absoluten
Pfade des Build-Rechners speichern.

Der ausgeführte Plugin-Bootstrap lässt sich nicht pauschal wiederverwenden: Services,
Funktionen und Event-Listener müssen im neuen PHP-Prozess weiterhin registriert werden.
Keine serialisierten Container, offenen Verbindungen oder laufzeitabhängigen Objekte.

Der frühe Cache-Zugriff erfolgt vor der Plugin-Erkennung über einen kleinen Framework-
Erweiterungspunkt. Die optionale Implementierung ist über Composer autoloadbar und darf
keinen DI-Container, normalen Plugin-Boot oder eine Datenbankverbindung voraussetzen.
Reguläre Cache-Services kann das Paket später beim normalen Boot bereitstellen.

## Invalidierung

Für Produktion bevorzugt ein versioniertes, unveränderliches Artefakt pro Release:

- Nach Installation aller Pakete und Kopieren der endgültigen Host-Dateien den Katalog
  erzeugen, vollständig validieren und atomar veröffentlichen.
- Build-Generation und Cache-Formatversion identifizieren den passenden Katalog.
- Ein neues Release verwendet eine neue Generation; ein Rollback die zugehörige alte.
- Der Katalog gehört zum Release/Image, nicht in ein zwischen Releases geteiltes Storage.
- Ein Fingerabdruck umfasst Paketbestand, relevante Manifest-Daten, den optionalen
  Host-Fallback `plugins.php` und Ressourcen. Neu hinzugefügte oder entfernte Dateien und
  zuvor nicht vorhandene Ressourcen müssen erfasst werden. `composer.lock` allein reicht
  insbesondere bei lokalen Path-Repositories nicht aus.
- Fingerabdrücke beim Build beziehungsweise expliziten Prüfen verwenden. Alle Dateien bei
  jedem Request erneut zu lesen und zu hashen würde wesentliche Einsparungen aufheben.
- Fehlt ein passender Katalog oder ist das Format inkompatibel, dynamisch auflösen.
  Fehlerhafte neue Konfigurationen dürfen nicht durch einen alten Katalog verdeckt werden.
- Generator und Reparatur müssen ohne vollständigen App-Boot funktionieren.
- Keine zeitbasierte Gültigkeit und keine Cache-Schreibzugriffe aus normalen Webrequests.
- Deployment muss auch OPcache und langlebige Worker auf das neue Release umstellen.

Entwicklung mit veränderlichen lokalen Paketen zunächst ohne persistenten Plugin-Katalog.
Ein späterer Entwicklungsmodus könnte Dateien und Verzeichnisse überwachen. Beliebige
Änderungen lassen sich nur mit Prüfungen, einem Watcher oder einem verbindlichen Schreib-/
Build-Prozess zuverlässig erkennen; die bloße Installation des Pakets garantiert das nicht.

## Weitere Caches

Veränderliche Anwendungsdaten benötigen fachliche Invalidierung nach erfolgreichem Commit,
mit klaren Regeln für Transaktionen und konkurrierende Zugriffe. TTL begrenzt die Lebensdauer,
garantiert aber keine sofortige Aktualität. Installation, Mandant und gegebenenfalls Benutzer
müssen in die Gültigkeitsgrenzen einfließen.

Konfiguration und Routen sind heute ausführbares PHP und können Umgebungswerte, Closures
oder dynamische Entscheidungen enthalten. Eine dauerhafte Speicherung ihrer Ergebnisse
setzt einen ausdrücklich definierten Vertrag und vollständige Eingaben voraus.

## Bisherige Messung als Ausgangspunkt

Lokales Docker, PHP 8.5.10, CLI ohne OPcache, 20 Plugins: Boot-Planung inklusive Manifest-
Zugriffen über Entwicklungs-Mounts im Median 0,49 ms, reine Planung 0,03 ms. Mit denselben
Manifesten auf Container-Dateisystem 0,16 ms. Je 60 App-Starts: bisher 14,89 ms, automatische
Reihenfolge 15,58 ms. Dies ist kein HTTP-Lasttest oder Produktionsbenchmark. Die zusätzlichen
Einsparungen durch gecachte Ressourcensuche wurden noch nicht gemessen.

## Offene Entscheidungen

Konkreter früher Erweiterungspunkt, Artefaktformat und Ablageort, Build-Integration,
Diagnose-/Prüfkommandos, Vertrag für weitere Cache-Erzeuger und Anwendungscache-Backends.
Erst den Plugin-Katalog umsetzen und messen; weitere Caches nur mit klaren
Invalidierungsregeln ergänzen.

## Entscheidung am 6. Oktober 2026

Die vollständige Messreihe liegt unter [`benchmarks/2026-10-06`](benchmarks/2026-10-06/).
0, 10, 25, 50, 100 und 250 synthetische Plugins wurden getrennt nach Discovery,
Manifestzugriff, Reihenfolge, Ressourcen und frischem Bootstrap gemessen. Bei 25
Plugins auf dem Container-Dateisystem brauchen Manifestzugriff/Reihenfolge im Median
0,115 ms und Ressourcensuche 0,153 ms; der frische CLI-Boot 1,52 ms.
250 Plugins brauchen 10,97 ms Boot statt 47,31 ms über Entwicklungs-Mounts.

Im realen Produktionshost mit OPcache liegt der HTTP-Boot im Median bei rund 2–2,5 ms.
Ein persistenter Katalog würde derzeit wenig dieser Zeit sparen, benötigt aber weiterhin
Invalidierung, Build-/Rollback-Integration und einen zusätzlichen öffentlichen Vertrag.
Die vorhandene verzögerte Event-Sortierung funktioniert bereits: bei 500 Listenern
0,143 ms erster Dispatch und 0,031 ms warmer Dispatch auf dem Container-Dateisystem.

Der größere gemessene Hebel ist das Karten-Rebalancing mit über 10.000 SQL-Anweisungen
bei 5.000 Karten. Deshalb wird dieser Pfad optimiert. Die Cache-Idee bleibt als Entwurf
erhalten und wird bei größeren realen Plugin-Katalogen oder nachweisbar höherem Boot-Anteil
neu beurteilt. Es gibt keinen neuen frühen Cache-Erweiterungspunkt in diesem Durchgang.
