<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/naming.php';
require_once __DIR__ . '/../includes/files.php';
require_login();

$entityId = isset($_GET['entity_id']) ? (int)$_GET['entity_id'] : 0;

$entityStmt = $pdo->prepare('SELECT e.*, t.name AS type_name FROM entities e JOIN entity_types t ON t.id = e.type_id WHERE e.id = :id');
$entityStmt->execute(['id' => $entityId]);
$entity = $entityStmt->fetch();

if (!$entity) {
    render_header('Entity Details');
    echo '<div class="alert alert-danger">Entity nicht gefunden.</div>';
    render_footer();
    exit;
}

$projectId = (int)$entity['project_id'];
$projects = user_projects($pdo);
$projectAccess = false;
foreach ($projects as $p) {
    if ((int)$p['id'] === $projectId) {
        $projectAccess = true;
        break;
    }
}

if (!$projectAccess) {
    render_header('Entity Details');
    echo '<div class="alert alert-danger">Keine Berechtigung für dieses Projekt.</div>';
    render_footer();
    exit;
}

$projectStmt = $pdo->prepare('SELECT * FROM projects WHERE id = :id');
$projectStmt->execute(['id' => $projectId]);
$projectFull = $projectStmt->fetch();
$projectRoot = rtrim($projectFull['root_path'] ?? '', '/');

// Assets laden
$assetsStmt = $pdo->prepare('SELECT * FROM assets WHERE primary_entity_id = :entity_id AND project_id = :project_id ORDER BY created_at DESC');
$assetsStmt->execute(['entity_id' => $entityId, 'project_id' => $projectId]);
$assets = $assetsStmt->fetchAll();

// Thumbnails für die neueste Revision laden
$assetThumbs = [];
$assetVersions = [];

foreach ($assets as $asset) {
    $revStmt = $pdo->prepare('SELECT * FROM asset_revisions WHERE asset_id = :asset_id ORDER BY version DESC LIMIT 1');
    $revStmt->execute(['asset_id' => $asset['id']]);
    $latestRev = $revStmt->fetch();

    if ($latestRev) {
        $assetVersions[$asset['id']] = $latestRev;
        $thumb = thumbnail_public_if_exists($projectId, $latestRev['file_path']);
        $absolutePath = $projectRoot . $latestRev['file_path'];
        if (!$thumb && $projectRoot !== '' && file_exists($absolutePath)) {
            $thumb = generate_thumbnail($projectId, $latestRev['file_path'], $absolutePath, 300);
        }
        $assetThumbs[$asset['id']] = $thumb;
    }
}

render_header('Entity: ' . htmlspecialchars($entity['name']));
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0"><?= htmlspecialchars($entity['name']) ?> <small class="text-muted">(<?= htmlspecialchars($entity['type_name']) ?>)</small></h1>
        <div class="text-muted small">Slug: <code><?= htmlspecialchars($entity['slug']) ?></code></div>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-primary" href="/entity_files.php?entity_id=<?= (int)$entity['id'] ?>">Unklassifizierte Dateien</a>
        <a class="btn btn-sm btn-outline-secondary" href="/entities.php?project_id=<?= (int)$projectId ?>">Zurück zur Übersicht</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-4">
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title h6 mb-0">Profilbilder</h5>
                <button class="btn btn-sm btn-outline-primary" id="btn-add-profile" title="Bild hochladen" style="display: none;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-upload" viewBox="0 0 16 16">
                      <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5"/>
                      <path d="M7.646 1.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1-.708.708L8.5 2.707V11.5a.5.5 0 0 1-1 0V2.707L5.354 4.854a.5.5 0 1 1-.708-.708z"/>
                    </svg>
                </button>
            </div>
            <div class="card-body p-2" id="profile-pictures-container">
                <div class="text-center p-3 text-muted">Lade Bilder...</div>
            </div>
            <input type="file" id="profile-upload-input" style="display: none;" accept="image/*">
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h5 class="card-title">Beschreibung</h5>
                <p class="card-text"><?= nl2br(htmlspecialchars($entity['description'] ?? '')) ?></p>

                <?php if (!empty($entity['metadata_json'])): ?>
                    <h6 class="mt-3">Metadaten</h6>
                    <pre class="bg-light p-2 border rounded small" style="white-space: pre-wrap;"><?= htmlspecialchars($entity['metadata_json']) ?></pre>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title h6 mb-0">Zusatzinformationen</h5>
                <button class="btn btn-sm btn-outline-primary" id="btn-add-info" title="Neue Info hinzufügen">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-plus-lg" viewBox="0 0 16 16">
                      <path fill-rule="evenodd" d="M8 2a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 2"/>
                    </svg>
                </button>
            </div>
            <div class="card-body p-0" id="entity-infos-container">
                <div class="text-center p-3 text-muted">Lade Informationen...</div>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <h2 class="h5 mb-3">Assets (<?= count($assets) ?>)</h2>
        <?php if (empty($assets)): ?>
            <div class="alert alert-light border">Keine Assets für diese Entity gefunden.</div>
        <?php else: ?>
            <div class="row row-cols-2 row-cols-lg-3 g-3">
                <?php foreach ($assets as $asset): ?>
                    <div class="col">
                        <div class="card h-100 shadow-sm">
                            <?php
                                $thumb = $assetThumbs[$asset['id']] ?? null;
                            ?>
                            <div class="ratio ratio-1x1 bg-light border-bottom d-flex align-items-center justify-content-center overflow-hidden">
                                <?php if ($thumb): ?>
                                    <img src="<?= htmlspecialchars($thumb) ?>" class="w-100 h-100" style="object-fit: contain;" alt="<?= htmlspecialchars($asset['display_name'] ?? $asset['asset_key']) ?>">
                                <?php else: ?>
                                    <div class="text-muted small">Keine Vorschau</div>
                                <?php endif; ?>
                            </div>
                            <div class="card-body p-2">
                                <h6 class="card-title text-truncate mb-1" title="<?= htmlspecialchars($asset['display_name'] ?? '') ?>">
                                    <?= htmlspecialchars($asset['display_name'] ?? $asset['asset_key']) ?>
                                </h6>
                                <div class="small text-muted text-truncate mb-1">
                                    <code><?= htmlspecialchars($asset['asset_key']) ?></code>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($asset['asset_type']) ?></span>
                                    <small class="text-muted"><?= htmlspecialchars($asset['status']) ?></small>
                                </div>
                            </div>
                            <div class="card-footer bg-white p-2 text-end">
                                <a href="asset_details.php?id=<?= (int)$asset['id'] ?>" class="btn btn-sm btn-outline-primary stretched-link">Details</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="infoModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="infoModalLabel">Info hinzufügen</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="infoForm">
            <input type="hidden" id="infoId" name="id">
            <input type="hidden" name="entity_id" value="<?= (int)$entity['id'] ?>">
            <div class="mb-3">
                <label for="infoTitle" class="form-label">Titel</label>
                <input type="text" class="form-control" id="infoTitle" name="title" required placeholder="z. B. Hintergrundgeschichte">
            </div>
            <div class="mb-3">
                <label for="infoContent" class="form-label">Inhalt</label>
                <textarea class="form-control" id="infoContent" name="content" rows="10" required placeholder="Markdown wird unterstützt..."></textarea>
                <div class="form-text">Markdown wird unterstützt.</div>
            </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
        <button type="button" class="btn btn-primary" id="btn-save-info">Speichern</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.0.6/purify.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const entityId = <?= (int)$entity['id'] ?>;
    const container = document.getElementById('entity-infos-container');
    const modalEl = document.getElementById('infoModal');
    const modal = new bootstrap.Modal(modalEl);
    const form = document.getElementById('infoForm');
    const saveBtn = document.getElementById('btn-save-info');
    const addBtn = document.getElementById('btn-add-info');

    // Load Infos
    // Profile Pictures Logic
    const profileContainer = document.getElementById('profile-pictures-container');
    const profileAddBtn = document.getElementById('btn-add-profile');
    const profileInput = document.getElementById('profile-upload-input');

    const loadProfiles = () => {
        fetch(`/ajax_profile_pictures.php?action=list&entity_id=${entityId}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    renderProfiles(data.data);
                } else {
                    profileContainer.innerHTML = `<div class="text-danger small p-2">${data.error}</div>`;
                }
            })
            .catch(err => console.error(err));
    };

    const renderProfiles = (pics) => {
        profileContainer.innerHTML = '';
        if (pics.length === 0) {
            profileContainer.innerHTML = '<div class="text-center text-muted small p-2">Keine Profilbilder.</div>';
        } else {
            const row = document.createElement('div');
            row.className = 'd-flex flex-wrap gap-2 justify-content-center';
            pics.forEach(pic => {
                const wrapper = document.createElement('div');
                wrapper.className = 'position-relative';
                wrapper.style.width = '100px';
                wrapper.style.height = '100px';

                const img = document.createElement('img');
                img.src = pic.url || '#';
                img.className = 'img-thumbnail w-100 h-100';
                img.style.objectFit = 'cover';

                const delBtn = document.createElement('button');
                delBtn.className = 'btn btn-danger btn-sm position-absolute top-0 end-0 p-0 d-flex align-items-center justify-content-center';
                delBtn.style.width = '20px';
                delBtn.style.height = '20px';
                delBtn.style.transform = 'translate(30%, -30%)';
                delBtn.innerHTML = '&times;';
                delBtn.title = 'Löschen';
                delBtn.onclick = () => deleteProfile(pic.id);

                wrapper.appendChild(img);
                wrapper.appendChild(delBtn);
                row.appendChild(wrapper);
            });
            profileContainer.appendChild(row);
        }

        // Toggle add button
        profileAddBtn.style.display = pics.length < 3 ? 'block' : 'none';
    };

    const deleteProfile = (id) => {
        if (!confirm('Bild wirklich löschen?')) return;
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);

        fetch('/ajax_profile_pictures.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) loadProfiles();
                else alert('Fehler: ' + data.error);
            });
    };

    profileAddBtn.addEventListener('click', () => profileInput.click());

    profileInput.addEventListener('change', () => {
        if (profileInput.files.length === 0) return;
        const file = profileInput.files[0];
        const formData = new FormData();
        formData.append('action', 'upload');
        formData.append('entity_id', entityId);
        formData.append('file', file);

        profileAddBtn.disabled = true;

        fetch('/ajax_profile_pictures.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                profileAddBtn.disabled = false;
                profileInput.value = '';
                if (data.success) {
                    loadProfiles();
                } else {
                    alert('Fehler: ' + data.error);
                }
            })
            .catch(err => {
                profileAddBtn.disabled = false;
                alert('Upload Fehler');
            });
    });

    loadProfiles();

    const loadInfos = () => {
        fetch(`/ajax_entity_infos.php?action=list&entity_id=${entityId}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    renderInfos(data.data);
                } else {
                    container.innerHTML = `<div class="p-3 text-danger">Fehler: ${data.error}</div>`;
                }
            })
            .catch(err => {
                container.innerHTML = `<div class="p-3 text-danger">Fehler beim Laden.</div>`;
                console.error(err);
            });
    };

    const renderInfos = (infos) => {
        if (!infos || infos.length === 0) {
            container.innerHTML = '<div class="p-3 text-muted text-center small">Keine Zusatzinformationen vorhanden.</div>';
            return;
        }

        container.innerHTML = '';
        infos.forEach(info => {
            const rawHtml = marked.parse(info.content || '');
            const html = DOMPurify.sanitize(rawHtml);
            const item = document.createElement('div');
            item.className = 'border-bottom p-3';
            item.innerHTML = `
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <h6 class="fw-bold mb-0 text-primary">${escapeHtml(info.title)}</h6>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-light btn-sm text-secondary py-0 px-1 btn-edit" data-id="${info.id}">Edit</button>
                        <button class="btn btn-light btn-sm text-danger py-0 px-1 btn-delete" data-id="${info.id}">Del</button>
                    </div>
                </div>
                <div class="markdown-body small text-secondary" style="overflow-wrap: anywhere;">${html}</div>
            `;
            container.appendChild(item);

            const btnEdit = item.querySelector('.btn-edit');
            btnEdit.infoData = info; // Attach data directly
            btnEdit.addEventListener('click', (e) => openEdit(e.currentTarget.infoData));

            const btnDelete = item.querySelector('.btn-delete');
            btnDelete.infoId = info.id;
            btnDelete.addEventListener('click', (e) => deleteInfo(e.currentTarget.infoId));
        });
    };

    const escapeHtml = (unsafe) => {
        return unsafe
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    };

    const openEdit = (info) => {
        document.getElementById('infoModalLabel').textContent = 'Info bearbeiten';
        document.getElementById('infoId').value = info.id;
        document.getElementById('infoTitle').value = info.title;
        document.getElementById('infoContent').value = info.content;
        modal.show();
    };

    addBtn.addEventListener('click', () => {
        document.getElementById('infoModalLabel').textContent = 'Info hinzufügen';
        document.getElementById('infoId').value = ''; // Empty for new
        form.reset();
        modal.show();
    });

    saveBtn.addEventListener('click', () => {
        const id = document.getElementById('infoId').value;
        const action = id ? 'update' : 'create';
        const formData = new FormData(form);
        formData.append('action', action);

        fetch('/ajax_entity_infos.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                modal.hide();
                loadInfos();
            } else {
                alert('Fehler: ' + (data.error || 'Unbekannt'));
            }
        })
        .catch(err => alert('Fehler beim Speichern.'));
    });

    const deleteInfo = (id) => {
        if (!confirm('Wirklich löschen?')) return;
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);

        fetch('/ajax_entity_infos.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                loadInfos();
            } else {
                alert('Fehler: ' + data.error);
            }
        });
    };

    loadInfos();
});
</script>

<?php render_footer(); ?>
