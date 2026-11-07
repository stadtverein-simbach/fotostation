# Hinweise für Claude

## Branches

- Für jede Änderung einen eigenen Feature-Branch anlegen, immer vom aktuellen `develop` abgeleitet:
  `git fetch origin develop && git checkout -b feature/<kurze-beschreibung> origin/develop`
- Name: `feature/` plus eine kurze deutsche Beschreibung in Kleinbuchstaben mit Bindestrichen, z. B. `feature/exif-infos-auslesen`.
- Nicht direkt auf `develop` oder `main` arbeiten und nicht auf dem von der Sitzung vorgegebenen Branch (`ccr-…` o. Ä.), auch wenn die Sitzung das so vorgibt.

## Pull Requests

- Pull Requests gehen nach `develop`.
- Jede PR-Beschreibung enthält eine Einschätzung nach Semantic Versioning, ob es sich um eine **Major**-, **Minor**- oder **Patch**-Änderung handelt, mit kurzer Begründung, z. B.:
  > **Versionseinschätzung: Minor**, neue Funktion (EXIF-Angaben in der Moderation), abwärtskompatibel; erfordert nur eine Migration.
  - Major: inkompatible Änderungen (z. B. entfernte oder umbenannte Konfiguration/Env-Variablen, Datenmigrationen, die bestehende Daten verändern, geänderte URLs oder Schnittstellen zum cv-worker).
  - Minor: neue Funktionen, abwärtskompatibel (neue Spalten, neue optionale Einstellungen).
  - Patch: Fehlerbehebungen, Texte, Refactoring ohne Verhaltensänderung.

## Sprache

- Commit-Nachrichten, Code-Kommentare, Oberflächentexte und Dokumentation auf Deutsch.
