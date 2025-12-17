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
- Felder: project_id, **asset_key** (deterministisch aus Entity-Slug + Achsen), optionaler display_name (frei editierbar), Asset-Typ (z. B. character_ref, background, scene_frame, concept, other), primäre Entity, optionale zusätzliche Entity-Links (Join-Tabelle), Status (`active`, `deprecated`, `archived`), Beschreibung, erstellt_von/erstellt_am.
- asset_key wird aus Entity + klassifizierten Achsenwerten generiert; existiert die Kombination bereits, wird sie wiederverwendet, andernfalls entsteht ein neues Asset. Assets ohne Achsen nutzen `{entity_slug}_misc_{id}` als Fallback.

### 6.2 Asset-Revisions
- Jede Datei-Version ist eine Revision.
- Felder: asset_id, version (auto-increment pro Asset), relativer `file_path`, `file_hash`, MIME-Type, width, height, file_size_bytes, created_by/created_at, Review-Status (`pending`, `approved`, `rejected`), reviewed_by/at, Kommentar.
- Klassifikationen liegen sowohl auf Asset-Ebene (`asset_classifications`) als auch gespiegelt je Revision (`revision_classifications`), damit alle Versionen die gleiche Achsenkombination teilen.
- Dateinamen folgen `{asset_key}_v{version}.{ext}` und bleiben damit konsistent maschinenlesbar.

### 6.3 Revision-Logik
- Version hochzählen pro Asset.
- Workflow: Artist lädt hoch → `pending`; Editor prüft → `approved`/`rejected`.

## 7) Dateiinventar & Scanner
### 7.1 File Inventory
- Speichert: project_id, relativen file_path, file_hash, file_size, MIME/Metadaten, last_seen_at, optional asset_revision_id.
- Status: `untracked` (neu/unbekannt), `linked` (Teil einer Asset-Revision), `orphaned` (Revision gelöscht, Datei liegt noch), optional `missing` bei Löschung.

### 7.2 Scanner
- Rekursiver Scan des Projekt-Roots (PHP CLI oder Web-Aktion): SHA-256 berechnen, `file_inventory` anlegen/updaten. Neue Dateien → `untracked`; geänderte verknüpfte Dateien → neue Revision + Inventar-Update; gelöschte Dateien → `missing` oder Delete-Eintrag.
- **Automatische Auslösung:** Öffnen der „Untracked Files“-Übersicht triggert sofort einen Scan. Optional kann ein Cronjob (z. B. minütlich) den Scan kontinuierlich ausführen.
- **Soft-Run-Modus:** Optionaler Lauf fügt nur neue Dateien hinzu (keine Updates bestehender Einträge), um Hash-Cache und Batch-Updates performant zu halten.

### 7.3 Scanner-UI
- Manuelle Scans (Button) und Cron/Task-Intervalle konfigurierbar (Admin/Owner); UI zeigt Scan-Status und letzte Ausführung.

## 8) File-First Review-Center für untracked Dateien
### 8.1 Zentraler Flow („Untracked Files / Review Center“)
- Der gesamte Import findet in einer Ansicht statt. Layout: links Liste aller `untracked` Dateien, Mitte mit Preview/Metadaten (Hash, Größe, Pfad), rechts Entity/Asset-Zuweisung.
- Beim Öffnen lädt die Ansicht den aktuellen Scan und aktualisiert die Liste automatisch.

### 8.2 File-First Aktionen pro Datei
1. Datei auswählen → Preview + Metadaten erscheinen.
2. **Entity-Zuordnung:** Dropdown für bestehende Entities oder Modal „+ Neue Entity anlegen“ (Name, Typ, optionale Metadaten) mit sofortiger Rückgabe in die Auswahl.
3. **Asset-Zuordnung:** Dropdown für bestehende Assets oder Modal „+ Neues Asset aus dieser Datei erstellen“ (Name-Vorschlag aus Dateiname, Asset-Typ, optionale Beschreibung/Entity-Verknüpfung); erstellt sofort Asset + Revision 1.
4. **Speichern:** legt neue Asset-Revision an, benennt Datei nach Naming-Template um, verschiebt sie in den Zielordner, aktualisiert `file_inventory.asset_revision_id` und setzt `status='linked'`.

### 8.3 Batch-Verarbeitung
- Multi-Select für mehrere `untracked` Dateien (eine Auswahl steuert Preview und Formularwerte).
- Optionen: mehrere Dateien → eine Revisionenfolge eines Assets oder mit einem Klick neues Asset anlegen und alle ausgewählten Dateien als Revisions anhängen.
- Einheitliche Entity-/Asset-Zuweisung via Dropdown, Naming-Template-Anwendung inkl. Rename/Move pro Datei.
- Auto-Vorschläge für Asset-Namen aus Dateinamen sowie Entity-Hints aus Ordnerstruktur (z. B. `/import/kei/` → Entity „Kei“).

- Templates pro Projekt & Asset-Typ mit Platzhaltern (z. B. `{project}_{entity_type}_{entity_slug}_{asset_type}_{view}_v{version}.{ext}`; `SCN_{scene_slug}_Panel_{panel_number}_{chars}_v{version}.{ext}`; `REF_{character_slug}_{outfit}_{pose}_{view}_v{version}.{ext}`).
- Platzhalter: `{project}`, `{project_slug}`, `{entity_type}`, `{entity_slug}`, `{entity_name}`, `{asset_type}`, `{character_slug}`, `{outfit}`, `{pose}`, `{view}`, `{version}`, `{date}`, `{datetime}`, `{ext}`.
- Renaming-Engine nutzt Entity-/Asset-Daten in Echtzeit, wendet Templates beim Anlegen/Updaten an, benennt Dateien um, verschiebt sie in Zielordner, aktualisiert DB-Pfad; Konflikte via Suffix oder Fehler.

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
5. Files/Review: „Untracked Files / Review Center“ mit Auto-Scan, Preview/Metadaten, Batch-Linking zu Assets und On-the-fly-Erstellung von Entities/Assets.
6. Users & Roles: Nutzerverwaltung, Projektrollen.
7. Settings: globale Instanz-Einstellungen (E-Mail, Locale etc. optional).

## 13) Entity-First Workflow & Klassifizierungsachsen
- **Upload & Rohzuordnung:** Neue Dateien erscheinen im Scanner als `untracked`. Mehrfachauswahl kann direkt einer Entity (z. B. Charakter, Location, Szene) zugeordnet werden. Diese Zuordnung erzeugt `entity_file_links` (`entity_id`, `file_inventory_id`, optionale Notiz), speichert optional Achsenwerte in `inventory_classifications` und leitet `classification_state` daraus ab.
- **Entity-Ansicht „Unklassifizierte Dateien“:** Jede Entity zeigt offene Dateien (inkl. Vor-Klassifizierung), erlaubt Preview, Mehrfachauswahl sowie Klassifizierung und Asset-Anlage in einem Schritt. Filter greifen auf gespeicherte Achsenwerte (z. B. Outfit) zu.
- **Schrittweise Klassifizierung:** Optional nacheinander Outfit → Pose → View/Angle. Nach jedem Schritt wird `classification_state` logisch abgeleitet (`entity_only` → `outfit_assigned` → `pose_assigned` → `view_assigned` → `fully_classified`).
- **On-the-fly-Assets:** Beim Klassifizieren können neue Assets (z. B. Outfit „Schuluniform Sommer“, Pose „T-Pose“) angelegt werden; die aktuelle Revision wird automatisch verknüpft, umbenannt und einsortiert.
- **Deterministische Asset-Erzeugung:** Kombiniert Entity-Slug mit der konfigurierten Achsen-Reihenfolge zu einem `asset_key`; existiert die Kombination, wird automatisch eine neue Revision angelegt, ansonsten ein neues Asset inklusive `asset_classifications` (Fallback `{entity_slug}_misc_{id}` ohne Achsen).
- **Persistenz der Klassifizierung:** Vor-Klassifizierungen bleiben erhalten, werden beim finalen Speichern in `asset_classifications`/`revision_classifications` übernommen und steuern Naming/Folder-Templates.
- **Naming-Engine:** Templates unterstützen `{asset_key}`, `{character_slug}`, `{outfit}`, `{pose}`, `{view}`, `{version}`, `{ext}`. Auswertung erfolgt erst, wenn alle notwendigen Achsen gesetzt sind, damit Turnarounds/Layout-Serien konsistent benannt werden und Dateien als `{asset_key}_v{version}.{ext}` abgelegt werden.
- **Flexible Classification Axes:** Achsen sind konfigurierbar über `classification_axes` (Key, Label, applies_to wie `character`, `location`, `scene`, optional vordefinierte Werte). Werte liegen in `classification_axis_values`. Jede Revision erhält ihre Zuordnung in `revision_classifications` (axis_id + value_key). Die UI zeigt Achsen dynamisch je Entity-Typ (z. B. Outfit/Pose/View für Characters, TimeOfDay/Weather/CameraAngle für Locations), Filter greifen automatisch auf diese Achsen zu.
- **Verwaltung der Classification Axes:** In `entities.php` lassen sich Achsen und optionale Werte ohne Code-Änderung pflegen; die Entity-First-Ansicht bindet diese Achsen sofort in die Schritt-für-Schritt-Klassifizierung ein.

## 14) Setup & Betrieb
- Web-Setup unter `/setup.php` ausführen, um DB-Zugangsdaten, Studio-/Firmenname (Branding), Basis-URL und Session-Name einzutragen. Optional kann direkt ein Admin-User (E-Mail/Passwort) angelegt bzw. reaktiviert werden. Das Skript schreibt `includes/config.php` und importiert das Schema.
- Bestehende Installationen auf den Stand mit Entity-First-Klassifizierung und flexiblen Achsen bringen: `mysql <datenbankname> < database/upgrade_v1_to_v2.sql` (fügt `classification_state`, `entity_file_links`, `classification_axes`, `classification_axis_values` und `revision_classifications` hinzu bzw. aktualisiert bestehende Spalten).
- Upgrade für deterministische Asset-Keys und Asset-Klassifikationen: `mysql <datenbankname> < database/upgrade_v2_to_v3.sql` (fügt `asset_key`, `display_name`, `asset_classifications` hinzu und hinterlegt einen eindeutigen Key pro Projekt).
- Upgrade für Inventory-Vor-Klassifizierungen: `mysql <datenbankname> < database/upgrade_v3_to_v4.sql` (legt `inventory_classifications` für vorgezogene Achsenwerte an).
- Upgrade für KI-Audit/Review und Queue: `mysql <datenbankname> < database/upgrade_v4_to_v5.sql` (führt `ai_audit_logs`, `ai_review_queue` ein).
- Upgrade für AI-Jobs/Embeddings: `mysql <datenbankname> < database/upgrade_v5_to_v6.sql` (führt `ai_jobs`, `ai_runs`, `asset_ai`, `entity_embeddings` ein).
- Upgrade für SUBJECT_FIRST-Prepass: `mysql <datenbankname> < database/upgrade_v6_to_v7.sql` (legt `asset_ai_prepass` an und tracked Confidence).
- Läuft auf Standard-PHP/MySQL-Hosting ohne Spezialdienste.

## 15) Nicht-funktionale Anforderungen
- Audit-Logging für kritische Aktionen (optional).
- Thumbnail-Generierung für Bilddateien (CLI-Hook oder PHP-Extension), Caching.
- Performance: Scanner mit Hash-Cache, Batch-Insert/Update; DB als Quelle der Wahrheit.
- Sicherheit: Role-Based Access Control auf Projekt-Ebene; CSRF-Schutz; Passwort-Hashing (password_hash).

## 16) Erweiterungen (optional)
- Webhooks/Callbacks nach Review-Events.
- Export/Backup von Metadaten (JSON/CSV).
- API-Endpunkte (REST) für Integrationen.
- Mehrsprachigkeit (i18n) via Sprach-Dateien.

## 17) Erste Implementierung (MVP)
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

## 18) KI-gestützte Klassifizierung & Audit
- Service-Layer unter `includes/services/` (OpenAI Vision + Embeddings) mit strikter JSON-Schema-Validierung, automatischen Retries bei invalidem Output und Cosine-Similarity in PHP. Prepass/Classification teilen sich denselben Client, der Enumerationen/Min/Max prüft.
- SUBJECT_FIRST Vision-Prepass: JSON-Schema (primary_subject, subjects_present, counts, human_attributes, image_kind, background_type, notes, caption, confidence), Strict-Output, Speicherung in `asset_ai_prepass` inkl. revision_id, Modell und Confidence. Soft-Priors (character/location/scene/prop/effect) über `PrepassScoringService`, Audit mit Diff (`ai_audit_logs`). Endpoint `POST /api/v1/ai/prepass-subject` + CLI `php bin/ai-prepass-subject.php --asset=123 [--revision=ID]`; UI-Button & Panel im Asset-Detail.
- Pipeline: lokales Bild laden → SUBJECT_FIRST-Prepass (optional cached) → Vision-Analyse (asset_type grob/fein, subjects, scene_hints, attributes, free_caption, analysis_confidence) mit Hinweis auf Priors → Regel-Kandidaten (horse / location / stable / teen+school+uniform + Prior-basierte Kandidaten) → Embeddings aus Caption + Stichworten + Priors → Cosine-Similarity (TopK) → Auto-Assign mit Score-Threshold & Margin; sonst Review-Queue.
- Audit-Logging (`ai_audit_logs`) protokolliert Input/Output/Confidence/Fehler. Ergebnisse landen in `ai_review_queue` (auto_assigned vs. needs_review) und sind nur für berechtigte Rollen (owner/admin/editor) über `public/ajax_ai_classification.php` abrufbar. Prepass-Diffs werden zusätzlich im Audit abgelegt.
- Konfiguration über `openai`-Block in `includes/config.php` (API-Key, Modelle, Schwellenwerte, Prepass-Detail/Retry); DB-Upgrade via `database/upgrade_v6_to_v7.sql` für den Prepass.
