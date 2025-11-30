<?php
require_once __DIR__ . '/../includes/layout.php';
require_login();
$projects = user_projects($pdo);
render_header('Dashboard');
?>
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h3 mb-1">Dashboard</h1>
        <p class="text-muted mb-0">Schneller Überblick über Projekte und offene Aufgaben.</p>
    </div>
    <a href="/projects.php" class="btn btn-primary">Projekt öffnen</a>
</div>
<div class="row g-3">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <h2 class="h5">Meine Projekte</h2>
                <?php if (empty($projects)): ?>
                    <p class="text-muted mb-0">Noch keine Projekte zugewiesen.</p>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($projects as $project): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?= htmlspecialchars($project['name']) ?></strong><br>
                                    <small class="text-muted">Rolle: <?= htmlspecialchars($project['role']) ?></small>
                                </div>
                                <a class="btn btn-sm btn-outline-primary" href="/projects.php?project_id=<?= (int)$project['id'] ?>">Öffnen</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body">
                <h2 class="h5">Schnellaktionen</h2>
                <ul class="mb-0">
                    <li><a href="/files.php">Untracked Dateien prüfen</a></li>
                    <li><a href="/assets.php">Assets durchsuchen</a></li>
                    <li><a href="/entities.php">Entities verwalten</a></li>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php render_footer(); ?>
