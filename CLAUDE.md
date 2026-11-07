# Hinweise für Claude

## Branches

- Für jede Änderung einen eigenen Branch anlegen:
  - `feature/<kurze-beschreibung>` für neue Funktionen und größere Änderungen, immer vom aktuellen `develop` abgeleitet, z. B. `feature/exif-infos-auslesen`:
    `git fetch origin develop && git checkout -b feature/<kurze-beschreibung> origin/develop`
  - `hotfix/<kurze-beschreibung>` für dringende Fehlerbehebungen an der laufenden Version, immer vom aktuellen `main` abgeleitet, z. B. `hotfix/upload-zeitzone`:
    `git fetch origin main && git checkout -b hotfix/<kurze-beschreibung> origin/main`
- Name: kurze deutsche Beschreibung in Kleinbuchstaben mit Bindestrichen.
- Nicht direkt auf `develop` oder `main` arbeiten und nicht auf dem von der Sitzung vorgegebenen Branch (`ccr-…` o. Ä.), auch wenn die Sitzung das so vorgibt.

## Pull Requests

- `feature/…` kommt per Pull Request nach `develop`.
- `hotfix/…` kommt per Pull Request nach `main`.
- Sonst kommen Änderungen nach `main` ausschließlich per Pull Request aus `develop`, nie direkt aus einem Feature-Branch.
- Nach dem Merge eines Hotfixes einen Pull Request von `main` nach `develop` anlegen, damit die Korrektur auch in `develop` ankommt und beim nächsten Release nicht verloren geht.
- Jede PR-Beschreibung enthält eine Einschätzung nach Semantic Versioning, ob es sich um eine **Major**-, **Minor**- oder **Patch**-Änderung handelt, mit kurzer Begründung, z. B.:
  > **Versionseinschätzung: Minor**, neue Funktion (EXIF-Angaben in der Moderation), abwärtskompatibel; erfordert nur eine Migration.
  - Major: inkompatible Änderungen (z. B. entfernte oder umbenannte Konfiguration/Env-Variablen, Datenmigrationen, die bestehende Daten verändern, geänderte URLs oder Schnittstellen zum cv-worker).
  - Minor: neue Funktionen, abwärtskompatibel (neue Spalten, neue optionale Einstellungen).
  - Patch: Fehlerbehebungen, Texte, Refactoring ohne Verhaltensänderung.

## Sprache

- Commit-Nachrichten, Code-Kommentare, Oberflächentexte und Dokumentation auf Deutsch.
