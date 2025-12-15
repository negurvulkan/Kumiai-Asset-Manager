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
- Dynamische Zusatzfelder pro Entity-Typ: Definition von Feldern (Text, Zahl, Select, Boolean) im UI (Type-Editor), die automatisch in den Entity-Formularen gerendert und validiert werden.
- Suchfilterung basierend auf Entity-Typ und dessen dynamischen Feldern.
- Zusatzinformationen (Entity Infos): Beliebige Anzahl an Textblöcken (z. B. Hintergrundgeschichte, Persönlichkeit) mit Markdown-Support, direkt in der Detailansicht bearbeitbar.
- Entities lassen sich inkl. Slug, Typ, Beschreibung und dynamischen Metadaten im UI aktualisieren.
- Profilbilder: Möglichkeit, 1-3 Bilder hochzuladen, die als Profilbilder für die Entity dienen (Upload via UI, Speicherung im Projekt-Meta-Ordner).

## Assets, Versionen & Datei-Bezug
- Assets mit Projektbezug, **asset_key** (deterministisch aus Entity-Slug + Achsenkombination), optionalem display_name, Asset-Typ, primärer Entity, optionalen Entity-Verknüpfungen, Status (`active`, `deprecated`, `archived`), Beschreibung sowie Audit-Feldern. Existiert eine Entity+Achsen-Kombination bereits, wird sie automatisch wiederverwendet; achsenlose Assets nutzen `{entity_slug}_misc_{id}` als Fallback-Key.
- Asset-Revisions als Datei-Versionen mit Versionszählung, relativem Pfad, Hash, MIME-Type, Abmessungen, Größe, Audit-Feldern und Review-Status (`pending`, `approved`, `rejected`). Klassifikationen werden sowohl auf Asset- als auch auf Revisions-Ebene gespeichert (`asset_classifications`, `revision_classifications`).
- Workflow: Upload erzeugt `pending` Revision; Uploads werden automatisch einsortiert (Template + Konfliktauflösung), Metadaten (Hash, MIME, Maße) werden ausgelesen und Thumbnails generiert. Editor setzt Status auf `approved` oder `rejected`.
- Assets können im UI nachträglich im Anzeige-Namen, Typ, Beschreibung und Status angepasst werden; der asset_key bleibt unverändert und steuert die Dateinamen.

## Dateiinventar & Scanner
- File-Inventory speichert Projekt, relativen Pfad, Hash, Größe, MIME/Metadaten, Last-Seen sowie optionales Revision-Mapping mit Status (`untracked`, `linked`, `orphaned`, optional `missing`).
- Scanner durchsucht rekursiv den Projekt-Root, berechnet SHA-256-Hashes, legt neue Einträge an, markiert neue Dateien als `untracked`, erzeugt neue Revisionen bei Änderungen und markiert gelöschte Dateien als `missing`.
- Automatischer Scan beim Öffnen der „Untracked Files“-Übersicht; optionaler Cronjob (z. B. minütlich) für kontinuierliche Synchronisation.
- Soft-Run-Modus für schnelle Deltas (fügt nur neue Dateien hinzu) plus manuelle Trigger im Files-Bereich für Owner/Admin; Scan-UI zeigt Status und letzte Ausführung.

## Review-Workflow für untracked Dateien
- Zentraler „Untracked Files / Review Center“-Screen mit linker Dateiliste, mittlerer Preview/Metadaten (Hash, Pfad, Größe) und rechter Entity/Asset-Zuweisung.
- Dateiliste zeigt Thumbnails/Vorschauen direkt in der linken Spalte für schnelle Sichtung.
- File-First-Flow: Datei auswählen → Entity wählen oder per Formular „+ Neue Entity“ anlegen; Asset auswählen oder per Formular „+ Neues Asset aus Datei“ erzeugen (Name-Vorschlag aus Dateiname, Asset-Typ, optionale Beschreibung/Entity-Verknüpfung).
- Speichern legt neue Asset-Revision an, benennt die Datei nach Naming-Template um, verschiebt sie in den Zielordner, aktualisiert `file_inventory.asset_revision_id` und setzt den Status auf `linked`.
- Batch-Verarbeitung mit Multi-Select: mehrere Dateien → eine Revisionenfolge eines Assets oder neues Asset + Revisions in einem Schritt; gemeinsame Entity-/Asset-Zuweisung, optional Naming-Template + Move pro Datei. Auto-Vorschläge für Asset-Namen und Entity-Hints aus Ordnerstruktur.

## Entity-First & Klassifizierungsachsen
- Neue Dateien bleiben `untracked`, können aber per Multi-Select sofort einer Entity zugeordnet werden (`entity_file_links`), wodurch `classification_state` auf `entity_only` gesetzt wird und die Files in der Entity-Ansicht unter „Unklassifiziert“ erscheinen.
- Schrittweise Klassifizierung: Outfit → Pose → View/Angle, jeder Schritt leitet `classification_state` logisch ab (`entity_only`, `outfit_assigned`, `pose_assigned`, `view_assigned`, `fully_classified`). On-the-fly-Assets für neue Outfits/Posen binden die aktuelle Revision automatisch an und sortieren/benennen nach Template.
- Naming-Engine-Templates können `{character_slug}`, `{outfit}`, `{pose}`, `{view}`, `{version}`, `{ext}` nutzen; Auswertung erst, wenn alle benötigten Achsen belegt sind.
- Flexible Classification Axes je Entity-Typ: konfigurierbar über `classification_axes` (Key, Label, applies_to), optionale Werte in `classification_axis_values`. Jede Revision erhält ihre Achsenzuordnung in `revision_classifications`, die UI zeigt passende Achsen dynamisch (Character: Outfit/Pose/View; Location: TimeOfDay/Weather/CameraAngle), Filter nutzen dieselben Achsen.
- Entity-Ansicht `/entity_files.php` bündelt Preview, Multi-Select, Klassifizierung und Asset-Anlage; `entities.php` enthält ein Admin-Panel zum Pflegen der Achsen und vordefinierten Werte.

## Naming-Templates & Auto-Renaming
- Projekt- und Asset-Typ-bezogene Templates mit Platzhaltern wie `{project}`, `{project_slug}`, `{entity_type}`, `{entity_slug}`, `{asset_type}`, `{asset_key}`, `{character_slug}`, `{outfit}`, `{pose}`, `{view}`, `{version}`, `{date}`, `{datetime}`, `{ext}`.
- Renaming-Engine nutzt Entity-/Asset-Daten in Echtzeit, benennt Dateien beim Anlegen/Updaten um, verschiebt sie in Zielordner, aktualisiert DB-Pfade, erzeugt Thumbnails und löst Konflikte über Suffixe oder Fehler (Uploads und verknüpfte Inventory-Dateien werden automatisch gemäß Template bewegt). Standard-Dateinamen folgen `{asset_key}_v{version}.{ext}` für alle Revisionen.

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
- Files/Review: „Untracked Files / Review Center“ mit Auto-Scan, Preview, Batch-Linking, On-the-fly-Entity/Asset-Anlage und Orphan-Markierung.
- Users & Roles: Nutzerverwaltung und Projektrollenzuweisung.
- Settings: Globale Instanz-Einstellungen (E-Mail, Locale etc. optional).

## Setup & Betrieb
- Web-Setup unter `/setup.php` konfiguriert DB-Zugang, Branding, Basis-URL und Session-Name; kann einen Admin-User anlegen/reaktivieren und schreibt `includes/config.php`.
- Beiliegendes DB-Schema in `database/schema.sql` (User, Projekte, Rollen, Entity-Typen, Entities, Assets, Revisionen, File-Inventory); Import via `mysql kumiai_asset_manager < database/schema.sql`. Upgrade bestehender Installationen auf den Stand mit Klassifizierungsachsen via `mysql kumiai_asset_manager < database/upgrade_v1_to_v2.sql`.
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
- Review-Center listet untracked Dateien (Auto-Scan beim Öffnen), bietet Preview, Orphan-Markierung, Batch-Linking und On-the-fly-Asset/Entity-Anlage.
- Naming-/Folder-Logik aktiv: Templates für Revisionen (mit {project_slug}, {entity_slug}, {view}, {version}, {ext}) schlagen Pfade vor, setzen Pfade beim Speichern und verschieben Dateien in Zielordner, sofern vorhanden.
