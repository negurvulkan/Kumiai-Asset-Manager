<?php
require_once __DIR__ . '/../includes/layout.php';
require_login();


$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['project_id'])) {
    $_GET['project_id'] = (int)$_POST['project_id'];
}

function findProject(array $projects, int $projectId): ?array
{
    foreach ($projects as $project) {
        if ((int)$project['id'] === $projectId) {
            return $project;
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'create_project') {
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $rootPath = trim($_POST['root_path'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name === '' || $slug === '' || $rootPath === '') {
            $error = 'Name, Slug und Root-Pfad sind Pflichtfelder.';
        } else {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO projects (name, slug, description, root_path, created_at) VALUES (:name, :slug, :description, :root_path, NOW())');
            $stmt->execute([
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'root_path' => $rootPath,
            ]);
            $newProjectId = (int)$pdo->lastInsertId();

            $stmtRole = $pdo->prepare('INSERT INTO project_roles (project_id, user_id, role) VALUES (:project_id, :user_id, :role)');
            $stmtRole->execute([
                'project_id' => $newProjectId,
                'user_id' => current_user()['id'],
                'role' => 'owner',
            ]);
            $pdo->commit();
            $message = 'Projekt wurde angelegt und Sie sind nun Owner.';
            $_GET['project_id'] = $newProjectId;
        }
    }

    if (($_POST['action'] ?? '') === 'update_project') {
        $targetProjectId = (int)($_POST['project_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $rootPath = trim($_POST['root_path'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $projects = user_projects($pdo);
        $projectContext = findProject($projects, $targetProjectId);

        if (!$projectContext || !user_can_manage_project($projectContext)) {
            $error = 'Keine Berechtigung für dieses Projekt.';
        } elseif ($name === '' || $slug === '' || $rootPath === '') {
            $error = 'Name, Slug und Root-Pfad sind Pflichtfelder.';
        } else {
            $stmt = $pdo->prepare('UPDATE projects SET name = :name, slug = :slug, description = :description, root_path = :root_path WHERE id = :id');
            $stmt->execute([
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'root_path' => $rootPath,
                'id' => $targetProjectId,
            ]);
            $message = 'Projekt aktualisiert.';
        }
    }

    if (($_POST['action'] ?? '') === 'add_member') {
        $targetProjectId = (int)($_POST['project_id'] ?? 0);
        $role = $_POST['role'] ?? '';
        $email = trim($_POST['user_email'] ?? '');
        $projects = user_projects($pdo);
        $projectContext = findProject($projects, $targetProjectId);
        $validRoles = ['owner', 'admin', 'artist', 'editor', 'viewer'];
        if (!$projectContext || !user_can_manage_project($projectContext)) {
            $error = 'Keine Berechtigung für dieses Projekt.';
        } elseif ($email === '' || !in_array($role, $validRoles, true)) {
            $error = 'Bitte E-Mail und Rolle angeben.';
        } else {
            $userStmt = $pdo->prepare('SELECT id, display_name FROM users WHERE email = :email AND is_active = 1');
            $userStmt->execute(['email' => $email]);
            $user = $userStmt->fetch();
            if (!$user) {
                $error = 'User nicht gefunden oder inaktiv.';
            } else {
                $assignStmt = $pdo->prepare('INSERT INTO project_roles (project_id, user_id, role) VALUES (:project_id, :user_id, :role) ON DUPLICATE KEY UPDATE role = VALUES(role)');
                $assignStmt->execute([
                    'project_id' => $targetProjectId,
                    'user_id' => $user['id'],
                    'role' => $role,
                ]);
                $message = sprintf('Rolle %s für %s gesetzt.', $role, $email);
                $_GET['project_id'] = $targetProjectId;
            }
        }
    }
}

$projects = user_projects($pdo);
$selectedProjectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : ((int)($projects[0]['id'] ?? 0));

$projectDetails = null;
if ($selectedProjectId) {
    $stmt = $pdo->prepare('SELECT p.*, pr.role FROM projects p LEFT JOIN project_roles pr ON pr.project_id = p.id AND pr.user_id = :uid WHERE p.id = :pid');
    $stmt->execute(['uid' => current_user()['id'], 'pid' => $selectedProjectId]);
    $projectDetails = $stmt->fetch();
    if ($projectDetails) {
        $typesStmt = $pdo->prepare('SELECT * FROM entity_types WHERE project_id = :pid ORDER BY name');
        $typesStmt->execute(['pid' => $projectDetails['id']]);
        $projectDetails['entity_types'] = $typesStmt->fetchAll();

        $memberStmt = $pdo->prepare('SELECT pr.role, u.email, u.display_name FROM project_roles pr JOIN users u ON u.id = pr.user_id WHERE pr.project_id = :pid ORDER BY FIELD(pr.role, "owner","admin","artist","editor","viewer"), u.display_name');
        $memberStmt->execute(['pid' => $projectDetails['id']]);
        $projectDetails['members'] = $memberStmt->fetchAll();
    }
}

$canManage = $projectDetails && user_can_manage_project($projectDetails);

render_header('Projects');
?>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h1 class="h4 mb-0">Meine Projekte</h1>
                    <span class="badge bg-secondary"><?= count($projects) ?></span>
                </div>
                <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
                <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <?php if (empty($projects)): ?>
                    <p class="text-muted">Noch keine Projekte vorhanden.</p>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($projects as $project): ?>
                            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="?project_id=<?= (int)$project['id'] ?>">
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($project['name']) ?></div>
                                    <small class="text-muted">Rolle: <?= htmlspecialchars($project['role']) ?></small>
                                </div>
                                <span class="text-muted">›</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5 mb-3">Neues Projekt anlegen</h2>
                <form method="post">
                    <input type="hidden" name="action" value="create_project">
                    <div class="mb-3">
                        <label class="form-label" for="name">Name</label>
                        <input class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="slug">Slug</label>
                        <input class="form-control" id="slug" name="slug" required>
                        <div class="form-text">Kürzerer, URL-tauglicher Name.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="root_path">Root-Pfad</label>
                        <input class="form-control" id="root_path" name="root_path" required placeholder="/srv/projects/mangaA">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="description">Beschreibung</label>
                        <textarea class="form-control" id="description" name="description" rows="2"></textarea>
                    </div>
                    <button class="btn btn-primary" type="submit">Speichern</button>
                </form>
            </div>
        </div>
        <?php if ($projectDetails): ?>
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Projektdetails</h2>
                <dl class="row mb-3">
                    <dt class="col-sm-4">Name</dt><dd class="col-sm-8"><?= htmlspecialchars($projectDetails['name']) ?></dd>
                    <dt class="col-sm-4">Slug</dt><dd class="col-sm-8"><?= htmlspecialchars($projectDetails['slug']) ?></dd>
                    <dt class="col-sm-4">Root</dt><dd class="col-sm-8"><code><?= htmlspecialchars($projectDetails['root_path']) ?></code></dd>
                    <dt class="col-sm-4">Rolle</dt><dd class="col-sm-8"><?= htmlspecialchars($projectDetails['role'] ?? 'n/a') ?></dd>
                </dl>
                <?php if ($canManage): ?>
                    <div class="border rounded p-3 mb-3 bg-light">
                        <h3 class="h6">Projekt bearbeiten</h3>
                        <form method="post" class="row g-3">
                            <input type="hidden" name="action" value="update_project">
                            <input type="hidden" name="project_id" value="<?= (int)$projectDetails['id'] ?>">
                            <div class="col-md-6">
                                <label class="form-label" for="edit_name">Name</label>
                                <input class="form-control" id="edit_name" name="name" value="<?= htmlspecialchars($projectDetails['name']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="edit_slug">Slug</label>
                                <input class="form-control" id="edit_slug" name="slug" value="<?= htmlspecialchars($projectDetails['slug']) ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="edit_root">Root-Pfad</label>
                                <input class="form-control" id="edit_root" name="root_path" value="<?= htmlspecialchars($projectDetails['root_path']) ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="edit_description">Beschreibung</label>
                                <textarea class="form-control" id="edit_description" name="description" rows="2"><?= htmlspecialchars($projectDetails['description'] ?? '') ?></textarea>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary" type="submit">Änderungen speichern</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
                <h3 class="h6">Entity-Typen</h3>
                <?php if (empty($projectDetails['entity_types'])): ?>
                    <p class="text-muted">Noch keine Entity-Typen angelegt.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($projectDetails['entity_types'] as $type): ?>
                            <li><span class="badge bg-light text-dark border"><?= htmlspecialchars($type['name']) ?></span> <small class="text-muted"><?= htmlspecialchars($type['description']) ?></small></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <div class="card shadow-sm mt-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h3 class="h6 mb-0">Rollen im Projekt</h3>
                    <?php if ($canManage): ?><span class="badge bg-info text-dark">admin</span><?php endif; ?>
                </div>
                <?php if (empty($projectDetails['members'])): ?>
                    <p class="text-muted">Noch keine Mitglieder.</p>
                <?php else: ?>
                    <ul class="list-group list-group-flush mb-3">
                        <?php foreach ($projectDetails['members'] as $member): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($member['display_name']) ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars($member['email']) ?></div>
                                </div>
                                <span class="badge bg-light text-dark border text-uppercase"><?= htmlspecialchars($member['role']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($canManage): ?>
                    <form method="post" class="border-top pt-3">
                        <input type="hidden" name="action" value="add_member">
                        <input type="hidden" name="project_id" value="<?= (int)$projectDetails['id'] ?>">
                        <div class="mb-3">
                            <label class="form-label" for="user_email">User E-Mail</label>
                            <input class="form-control" type="email" id="user_email" name="user_email" placeholder="user@example.com" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="role">Rolle</label>
                            <select class="form-select" name="role" id="role" required>
                                <option value="owner">owner</option>
                                <option value="admin">admin</option>
                                <option value="artist">artist</option>
                                <option value="editor">editor</option>
                                <option value="viewer" selected>viewer</option>
                            </select>
                            <div class="form-text">Owner/Admin können Projekt-Settings pflegen.</div>
                        </div>
                        <button class="btn btn-primary" type="submit">Rolle speichern</button>
                    </form>
                <?php else: ?>
                    <p class="text-muted mb-0">Nur Owner/Admin können Rollen verwalten.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php render_footer(); ?>
