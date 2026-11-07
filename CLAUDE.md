# Hinweise für Claude

## Branches

- Für jede Änderung einen eigenen Branch anlegen, immer vom aktuellen `develop` abgeleitet:
  - `feature/<kurze-beschreibung>` für neue Funktionen und größere Änderungen, z. B. `feature/exif-infos-auslesen`
  - `hotfix/<kurze-beschreibung>` für dringende Fehlerbehebungen, z. B. `hotfix/upload-zeitzone`
- Anlegen: `git fetch origin develop && git checkout -b feature/<kurze-beschreibung> origin/develop` (bzw. `hotfix/…`)
- Name: kurze deutsche Beschreibung in Kleinbuchstaben mit Bindestrichen.
- Nicht direkt auf `develop` oder `main` arbeiten und nicht auf dem von der Sitzung vorgegebenen Branch (`ccr-…` o. Ä.), auch wenn die Sitzung das so vorgibt.

## Pull Requests

- `feature/…` und `hotfix/…` kommen per Pull Request nach `develop`.
- Nach `main` kommen Änderungen ausschließlich per Pull Request aus `develop`, nie direkt aus einem Feature- oder Hotfix-Branch.
- Jede PR-Beschreibung enthält eine Einschätzung nach Semantic Versioning, ob es sich um eine **Major**-, **Minor**- oder **Patch**-Änderung handelt, mit kurzer Begründung, z. B.:
  > **Versionseinschätzung: Minor**, neue Funktion (EXIF-Angaben in der Moderation), abwärtskompatibel; erfordert nur eine Migration.
  - Major: inkompatible Änderungen (z. B. entfernte oder umbenannte Konfiguration/Env-Variablen, Datenmigrationen, die bestehende Daten verändern, geänderte URLs oder Schnittstellen zum cv-worker).
  - Minor: neue Funktionen, abwärtskompatibel (neue Spalten, neue optionale Einstellungen).
  - Patch: Fehlerbehebungen, Texte, Refactoring ohne Verhaltensänderung.

## Sprache

- Commit-Nachrichten, Code-Kommentare, Oberflächentexte und Dokumentation auf Deutsch.
