# Funktionsübersicht

## Stack & Deployment
- PHP 8.x auf LAMP-Stack mit Apache und MySQL/MariaDB.
- Frontend mit HTML5, Bootstrap 5 und Vanilla JS (AJAX/fetch), optionale kleine Drag&Drop-Libraries.
- Selbstgehosteter Betrieb (vServer/Shared Hosting) mit projektbezogenem Filesystem-Root.

## Benutzer- & Rechte-Modell
- Benutzeranlage/Registrierung durch Admin mit E-Mail, Passwort-Hash, Display-Name und Aktiv-Flag.
- Rollen pro Projekt: `owner`, `admin`, `artist`, `editor`, `viewer`; Nutzer können unterschiedliche Rollen je Projekt haben.
- Rechtebeispiele: Owner darf alles inkl. Löschen/Konfiguration, Admin verwaltet User und Einstellungen, Artist legt Assets an und lädt hoch, Editor prüft/ändert Metadaten, Viewer ist read-only.

## Projekt-Modell
- Mehrprojekt-fähige Instanz mit Feldern für Name, Slug, Beschreibung, Root-Pfad und projektweite Einstellungen (Entity-Typen, Naming-Templates, Standard-Tag-Sets).
- Projekte können im UI bearbeitet werden (Name, Slug, Beschreibung, Root-Pfad) – nur Owner/Admin.

## Generisches Entity-System
- Projekt-spezifische Entity-Typen (z. B. character, location, scene, chapter, prop, background, item, creature).
- Entities mit Feldern für Projekt, Typ, Name, Slug, Beschreibung und Metadaten-JSON (Erfassung im UI inkl. JSON-Validierung und Anzeige).
- Optional: dynamische Zusatzfelder pro Entity-Typ.
- Entities lassen sich inkl. Slug, Typ, Beschreibung und Metadaten im UI aktualisieren.

## Assets, Versionen & Datei-Bezug
- Assets mit Projektbezug, Namen, Asset-Typ, primärer Entity, optionalen Entity-Verknüpfungen, Status (`active`, `deprecated`, `archived`), Beschreibung sowie Audit-Feldern.
- Asset-Revisions als Datei-Versionen mit Versionszählung, relativem Pfad, Hash, MIME-Type, Abmessungen, Größe, Audit-Feldern und Review-Status (`pending`, `approved`, `rejected`).
- Workflow: Upload erzeugt `pending` Revision; Uploads werden automatisch einsortiert (Template + Konfliktauflösung), Metadaten (Hash, MIME, Maße) werden ausgelesen und Thumbnails generiert. Editor setzt Status auf `approved` oder `rejected`.
- Assets können im UI nachträglich in Name, Typ, primärer Entity, Beschreibung und Status angepasst werden.

## Dateiinventar & Scanner
- File-Inventory speichert Projekt, relativen Pfad, Hash, Last-Seen sowie optionales Revision-Mapping mit Status (`untracked`, `linked`, `orphaned`, optional `missing`).
- Scanner durchsucht rekursiv den Projekt-Root, berechnet Hashes, legt neue Einträge an, markiert neue Dateien als `untracked`, erzeugt neue Revisionen bei Änderungen und markiert gelöschte Dateien als `missing`.
- Scanner-UI erlaubt manuelle Scans und konfigurierbare Intervalle.

## Review-Workflow für untracked Dateien
- Untracked-Ansicht filtert nach Ordner, Dateityp, Größe und Änderungsdatum.
- Aktionen: Neues Asset erstellen und verknüpfen, bestehendem Asset als neue Revision zuordnen oder Datei ignorieren/löschen/als orphan markieren.

## Naming-Templates & Auto-Renaming
- Projekt- und Asset-Typ-bezogene Templates mit Platzhaltern wie `{project}`, `{project_slug}`, `{entity_type}`, `{entity_slug}`, `{asset_type}`, `{view}`, `{version}`, `{date}`, `{datetime}`, `{ext}`.
- Renaming-Engine benennt Dateien beim Anlegen/Updaten um, verschiebt sie in Zielordner, aktualisiert DB-Pfade, erzeugt Thumbnails und löst Konflikte über Suffixe oder Fehler (Uploads und verknüpfte Inventory-Dateien werden automatisch gemäß Template bewegt).

## Ordnerstruktur & Autosortierung
- Standardstruktur mit Charakter-, Szenen-, Background-, Concept-, Export- und Temp-Ordnern.
- Zielordner-Regeln pro Asset-Typ steuern Auto-Move aus Upload-/Temp-Ordnern; Pfade werden in der DB aktualisiert.

## Tags & Suche
- Projektweite Tags (z. B. uniform, morning, school, action, rough, final, needs_fix).
- Suche/Filter über Projekt, Entity, Asset-Typ, Tags, Status, Review-Status, Dateityp sowie Volltext über Name/Beschreibung; Tags optional auch auf Revisionsebene.

## UI-Bereiche
- Dashboard mit Projektwahl, zuletzt bearbeiteten Assets und offenen Reviews.
- Projects: Liste, Rollenübersicht und Einstellungen (Root-Pfad, Entity-Typen, Naming-Templates, Ordnerregeln).
- Entities: Listen/Filter nach Typ, Detailansicht mit Basisinfos, dynamischen Feldern und verknüpften Assets/Entities.
- Assets: Listen/Details mit Entities, Tags, Revision-Timeline und Aktionen (neue Revision, rename, archivieren).
- Files/Review: Inventory-Ansicht mit 100-Eintrag-Paginierung und Verknüpfungs-/Orphan-Aktionen für `untracked` Dateien.
- Users & Roles: Nutzerverwaltung und Projektrollenzuweisung.
- Settings: Globale Instanz-Einstellungen (E-Mail, Locale etc. optional).

## Setup & Betrieb
- Web-Setup unter `/setup.php` konfiguriert DB-Zugang, Branding, Basis-URL und Session-Name; kann einen Admin-User anlegen/reaktivieren und schreibt `includes/config.php`.
- Beiliegendes DB-Schema in `database/schema.sql` (User, Projekte, Rollen, Entity-Typen, Entities, Assets, Revisionen, File-Inventory); Import via `mysql kumiai_asset_manager < database/schema.sql`.
- Auth & Rollen über `users` und `project_roles`; Beispiel-Adminanlage per SQL, Passwort-Hash über `password_hash` generierbar.
- Projektverwaltung und Rollenvergabe über `public/projects.php`.
- Generische Entities über `public/entities.php` inkl. Anlage von Typen und Entities.
- Assets & Revisionen über `public/assets.php`; Inventory wird bei verknüpften Revisionen auf `linked` gesetzt.
- Review-Status einer Revision im Revisions-Panel setzbar (Owner/Admin/Editor) mit Notiz, Reviewer und Zeitstempel.
- File-Inventory-Workflow via `scripts/scan.php <project_id>` und `public/files.php` zum Markieren, Verknüpfen oder Anlegen neuer Revisionen.
- Naming-/Folder-Logik mit Default-Templates pro Asset-Typ (Character/Background/Scene/Concept/Other), automatischer Pfadberechnung, Konfliktauflösung per Suffix und optionalem Auto-Move beim Verknüpfen.
- Deployment: Webroot auf `public/`; PHP-CLI für `scripts/scan.php`; Uploads werden im Assets-Formular angenommen, nach Templates einsortiert und mit Thumbnails versehen.

## Nicht-funktionale Anforderungen
- Optionales Audit-Logging für kritische Aktionen.
- Thumbnail-Generierung und Caching für Bilddateien (CLI-Hook oder PHP-Extension).
- Performance-Fokus durch Hash-Cache, Batch-Operationen und DB als Source of Truth.
- Sicherheit: Role-Based Access Control, CSRF-Schutz, Passwort-Hashing.

## Erweiterungen (optional)
- Webhooks/Callbacks nach Review-Events.
- Export/Backup von Metadaten (JSON/CSV).
- REST-API für Integrationen.
- Mehrsprachigkeit (i18n) über Sprachdateien.

## MVP-Hinweise
- Einstieg über `public/index.php` (Dashboard) bzw. `public/login.php`.
- Fallback auf `config.example.php`, wenn kein eigenes `includes/config.php` vorhanden ist; Sessions steuerbar über `app.session_name` (Standard `kumiai_session`).
- File-Inventory listet 100 Einträge und erlaubt Orphan-Markierung oder Revisionserstellung für `untracked` Dateien.
- Naming-/Folder-Logik aktiv: Templates für Revisionen (mit {project_slug}, {entity_slug}, {view}, {version}, {ext}) schlagen Pfade vor, setzen Pfade beim Speichern und verschieben Dateien in Zielordner, sofern vorhanden.
