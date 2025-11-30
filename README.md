# Kumiai Asset Manager – Funktionsübersicht

## 1) Kurzbeschreibung
Selbstgehostete LAMP-WebApp zur Verwaltung von Kreativprojekten (Manga, Comics, Games, Art) mit Multi-User-/Multi-Project-Support, rollenbasierten Rechten, generischem Entity-Modell, Asset- & Dateiverwaltung, Versionierung, automatischer Benennung sowie Scan- & Review-Workflow für neue Dateien. Die Datenbank ist immer die Single Source of Truth; alle Dateien im Projektordner ohne DB-Verknüpfung gelten als „untracked“ und müssen im UI reviewed werden.

## 2) Stack & Deployment
- **Stack:** PHP 8.x, Apache, MySQL/MariaDB, Linux (LAMP).
- **Frontend:** HTML5, Bootstrap 5, Vanilla JS (AJAX/fetch), optionale kleine Drag&Drop-Libs.
- **Deployment:** selbstgehostet (vServer/Shared Hosting).
- **Filesystem:** pro Projekt konfiguriertes Root-Verzeichnis mit Lese-/Schreibzugriff.

## 3) Benutzer- & Rechte-Modell
### 3.1 Benutzer
- Registrierung/Anlage durch Admin.
- Felder: E-Mail, Passwort-Hash, Display-Name, Aktiv-Flag.
- Login/Logout, Sessions, Passwort-Reset optional.

### 3.2 Rollen pro Projekt
- Rollen: `owner`, `admin`, `artist`, `editor`, `viewer`.
- Ein User kann in verschiedenen Projekten unterschiedliche Rollen haben.

### 3.3 Rechte (Beispiele)
- **owner:** alle Rechte, Projekte löschen/konfigurieren.
- **admin:** User zuweisen, Entity-Typen & Templates pflegen, Projekteinstellungen.
- **artist:** Assets anlegen, Dateien hochladen, untracked Dateien verknüpfen.
- **editor:** Reviews/Status, Metadaten anpassen.
- **viewer:** read-only.

## 4) Projekt-Modell
- Eine Instanz verwaltet mehrere Projekte.
- Projektfelder: Name, Slug, Beschreibung, Root-Pfad, projektweite Einstellungen (zulässige Entity-Typen, Naming-Templates, Standard-Tag-Sets).

## 5) Generisches Entity-System
### 5.1 Entity-Typen
- Projekt-spezifisch definierbar (z. B. character, location, scene, chapter, prop, background, item, creature). System-Typen können vordefiniert sein.

### 5.2 Entities
- Felder: project_id, type_id, Name, Slug, Beschreibung, Metadaten-JSON (freie Zusatzinfos).
- Entities repräsentieren logische Einheiten wie Charaktere, Orte, Szenen.

### 5.3 Dynamische Felder (optional)
- Pro Entity-Typ definierbare Zusatzfelder (Definition + Value-Tabellen oder JSON).

## 6) Assets, Versionen & Datei-Bezug
### 6.1 Assets
- Felder: project_id, Name, Asset-Typ (z. B. character_ref, background, scene_frame, concept, other), primäre Entity, optionale zusätzliche Entity-Links (Join-Tabelle), Status (`active`, `deprecated`, `archived`), Beschreibung, erstellt_von/erstellt_am.

### 6.2 Asset-Revisions
- Jede Datei-Version ist eine Revision.
- Felder: asset_id, version (auto-increment pro Asset), relativer `file_path`, `file_hash`, MIME-Type, width, height, file_size_bytes, created_by/created_at, Review-Status (`pending`, `approved`, `rejected`), reviewed_by/at, Kommentar.

### 6.3 Revision-Logik
- Version hochzählen pro Asset.
- Workflow: Artist lädt hoch → `pending`; Editor prüft → `approved`/`rejected`.

## 7) Dateiinventar & Scanner
### 7.1 File Inventory
- Speichert: project_id, file_path (relativ), file_hash, last_seen_at, optional asset_revision_id.
- Status: `untracked` (bekannt, aber keinem Asset zugeordnet), `linked` (Teil einer Revision), `orphaned` (Revision gelöscht, Datei liegt noch), optional `missing` bei Löschung.

### 7.2 Scanner
- Rekursiver Scan des Projekt-Roots (PHP CLI oder Web-Aktion): Hash berechnen, `file_inventory` anlegen/updaten. Neue Dateien → `untracked`; geänderte verknüpfte Dateien → neue Revision + Inventar-Update; gelöschte Dateien → `missing` oder Delete-Eintrag.

### 7.3 Scanner-UI
- Manuelle Scans (Button) und Cron/Task-Intervalle konfigurierbar (Admin/Owner).

## 8) Review-Workflow für untracked Dateien
### 8.1 Untracked-Ansicht
- Listet `file_inventory` mit Status `untracked`. Filter: Ordner/Path, Dateityp (PNG/PSD/CLIP), Größe, Änderungsdatum.

### 8.2 Aktionen pro untracked Datei
1. **Neues Asset erstellen & verknüpfen:** Asset-Typ wählen, Entities auswählen, Name/Beschreibung; erstellt Asset + Revision (v1), setzt `status='linked'`, optional Auto-Renaming & Move.
2. **Bestehendem Asset als neue Revision zuordnen:** Asset auswählen, neue Version, Revision speichern, `status='linked'`.
3. **Ignorieren/Löschen/Orphan markieren:** Status `orphaned` oder Datei löschen (rechteabhängig).

## 9) Naming-Templates & Auto-Renaming
- Templates pro Projekt & Asset-Typ mit Platzhaltern (z. B. `{project}_{entity_type}_{entity_slug}_{asset_type}_{view}_v{version}.{ext}`; `SCN_{scene_slug}_Panel_{panel_number}_{chars}_v{version}.{ext}`; `REF_{character_slug}_TPose_Front_v{version}.{ext}`).
- Platzhalter: `{project}`, `{project_slug}`, `{entity_type}`, `{entity_slug}`, `{entity_name}`, `{asset_type}`, `{view}`, `{version}`, `{date}`, `{datetime}`, `{ext}`.
- Renaming-Engine: wendet Template beim Anlegen/Updaten an, benennt Datei um, verschiebt in Zielordner, aktualisiert DB-Pfad; Konflikte via Suffix oder Fehler.

## 10) Ordnerstruktur & Autosortierung
- Standard-Struktur (anpassbar):
```
/project_root/
  /01_CHARACTER/
    /{character_slug}/
      /reference/
      /expressions/
      /poses/
  /02_SCENES/{scene_slug}/
  /03_BACKGROUNDS/{location_slug}/
  /04_CONCEPT_ART/
  /05_EXPORT/
  /99_TEMP/
```
- Admin definiert pro Projekt Zielordner-Regeln je Asset-Typ (z. B. `scene_frame` → `/02_SCENES/{scene}/`). Auto-Move verschiebt Dateien aus Upload/Temp in Zielordner und aktualisiert DB-Pfade.

## 11) Tags & Suche
- Projektweite Tags (z. B. uniform, morning, school, action, rough, final, needs_fix).
- Tags an Assets (optional auch Revisions). Suche/Filter nach Projekt, Entity, Asset-Typ, Tags, Status, Review-Status, Dateityp sowie Volltext über Name/Beschreibung.

## 12) UI-Bereiche
1. Dashboard: Projektwahl, zuletzt bearbeitete Assets, offene Reviews (untracked/pending).
2. Projects: Liste, Rollen anzeigen, Einstellungen (Root-Pfad, Entity-Typen, Naming-Templates, Ordnerregeln).
3. Entities: Listen/Filter nach Type, Detail mit Basisinfos, dynamischen Feldern, verknüpften Assets/Entities.
4. Assets: Liste, Detail mit Infos, Entities, Tags, Revision-Timeline mit Thumbnails; Aktionen: neue Revision, rename, archivieren.
5. Files/Review: Untracked-Ansicht mit Filtern/Batch-Aktionen; Zuordnung/Neuanlage wie oben.
6. Users & Roles: Nutzerverwaltung, Projektrollen.
7. Settings: globale Instanz-Einstellungen (E-Mail, Locale etc. optional).

## 13) Setup & Betrieb
- Einfache Installation via Setup-Skript (DB-Connection, Admin-User, Projekt-Root).
- Läuft auf Standard-PHP/MySQL-Hosting ohne Spezialdienste.

## 14) Nicht-funktionale Anforderungen
- Audit-Logging für kritische Aktionen (optional).
- Thumbnail-Generierung für Bilddateien (CLI-Hook oder PHP-Extension), Caching.
- Performance: Scanner mit Hash-Cache, Batch-Insert/Update; DB als Quelle der Wahrheit.
- Sicherheit: Role-Based Access Control auf Projekt-Ebene; CSRF-Schutz; Passwort-Hashing (password_hash).

## 15) Erweiterungen (optional)
- Webhooks/Callbacks nach Review-Events.
- Export/Backup von Metadaten (JSON/CSV).
- API-Endpunkte (REST) für Integrationen.
- Mehrsprachigkeit (i18n) via Sprach-Dateien.

## 16) Erste Implementierung (MVP)
- **Code-Basis:** Plain PHP 8.x ohne Framework, Bootstrap 5 für das UI. Einstieg über `public/index.php` (Dashboard) bzw. `public/login.php`.
- **Konfiguration:**
  - `includes/config.php` (bzw. `config.example.php` als Vorlage) enthält DB-Zugang und Basis-URL. Ohne eigenes `config.php` fällt die App auf die Beispielwerte zurück.
  - Sessions werden über `app.session_name` gesteuert (Standard `kumiai_session`).
- **DB-Schema:** In `database/schema.sql` hinterlegt (User, Projekte, Rollen, Entity-Typen, Entities, Assets, Revisionen, File-Inventory). Import via `mysql kumiai_asset_manager < database/schema.sql`.
- **Auth & Rollen:** Login mit `email` + `password_hash` aus `users`. Projektrollen werden über `project_roles` gezogen, Owner/Admin können Projekte anlegen. Beispiel-Admin anlegen:
  ```sql
  INSERT INTO users (email, password_hash, display_name) VALUES ('admin@example.com', '<hash-aus-password_hash>', 'Admin');
  ```
  (`php -r "echo password_hash('admin123', PASSWORD_DEFAULT);"` liefert den passenden Hash.)
- **Projektverwaltung & Rollen:** `public/projects.php` listet eigene Projekte, legt neue an (Creator wird Owner) und erlaubt Owner/Admin, bestehende User per E-Mail mit einer Rolle dem Projekt zuzuweisen.
- **Generische Entities:** `public/entities.php` erlaubt pro Projekt das Anlegen von Entity-Typen sowie Entities (Name/Slug/Beschreibung, Metadata-JSON placeholder).
- **Assets & Revisionen:** `public/assets.php` legt Assets (Typ, primäre Entity) an und erfasst Revisionen mit Dateipfad/Hash/MIME. File-Inventory wird beim Anlegen einer Revision auf `linked` gesetzt.
- **Review-Workflow für Revisionen:** Im Revisions-Panel können Owner/Admin/Editor den Review-Status (pending/approved/rejected) setzen und eine kurze Notiz hinterlegen; Reviewer und Zeitpunkt werden gespeichert.
- **File-Inventory & Review:**
  - `scripts/scan.php <project_id>` scannt das Projekt-Root (aus `projects.root_path`), speichert Hash/Size/MIME als `untracked`.
  - `public/files.php` listet Inventory (100 Einträge) und erlaubt für `untracked` Dateien: als `orphaned` markieren oder per Asset-Auswahl eine Revision anlegen (Status `linked`).
- **Naming/Folder-Logik:** Noch als Platzhalter; Dateipfade werden aktuell manuell eingetragen bzw. aus dem Scanner übernommen.
- **Deployment-Hinweise:** Webroot auf `public/` zeigen lassen; PHP-CLI für `scripts/scan.php` verfügbar machen (Cron). Thumbnails/Uploads sind im MVP noch nicht integriert.
