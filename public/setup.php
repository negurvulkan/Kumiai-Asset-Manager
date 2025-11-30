<?php
$configPath = __DIR__ . '/../includes/config.php';
$schemaPath = __DIR__ . '/../database/schema.sql';
$defaults = [
    'db_host' => 'localhost',
    'db_name' => 'kumiai_asset_manager',
    'db_user' => 'kumiai',
    'db_pass' => '',
    'db_charset' => 'utf8mb4',
    'base_url' => '/',
    'session_name' => 'kumiai_session',
    'studio_name' => 'Your Studio',
];

if (file_exists($configPath)) {
    $existing = require $configPath;
    $defaults['db_host'] = $existing['db']['host'] ?? $defaults['db_host'];
    $defaults['db_name'] = $existing['db']['name'] ?? $defaults['db_name'];
    $defaults['db_user'] = $existing['db']['user'] ?? $defaults['db_user'];
    $defaults['db_pass'] = $existing['db']['pass'] ?? $defaults['db_pass'];
    $defaults['db_charset'] = $existing['db']['charset'] ?? $defaults['db_charset'];
    $defaults['base_url'] = $existing['app']['base_url'] ?? $defaults['base_url'];
    $defaults['session_name'] = $existing['app']['session_name'] ?? $defaults['session_name'];
    $defaults['studio_name'] = $existing['app']['studio_name'] ?? $defaults['studio_name'];
}

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? '');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';
    $dbCharset = trim($_POST['db_charset'] ?? 'utf8mb4');
    $baseUrl = rtrim(trim($_POST['base_url'] ?? '/'), '/') ?: '/';
    $sessionName = trim($_POST['session_name'] ?? 'kumiai_session');
    $studioName = trim($_POST['studio_name'] ?? 'Kumiai');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPassword = $_POST['admin_password'] ?? '';
    $adminDisplay = trim($_POST['admin_display_name'] ?? 'Admin');

    foreach ([['Database Host', $dbHost], ['Database Name', $dbName], ['Database User', $dbUser], ['Studio/Company Name', $studioName]] as [$label, $value]) {
        if ($value === '') {
            $errors[] = "$label darf nicht leer sein.";
        }
    }

    if (!$errors) {
        try {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $dbHost, $dbName, $dbCharset);
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            $errors[] = 'Datenbank-Verbindung fehlgeschlagen: ' . $e->getMessage();
        }
    }

    if (!$errors) {
        $schemaSql = file_get_contents($schemaPath);
        $statements = array_filter(array_map('trim', explode(';', $schemaSql)));
        foreach ($statements as $statement) {
            if ($statement === '') {
                continue;
            }
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                if ($e->getCode() === '42S01') {
                    continue; // table already exists
                }
                $errors[] = 'Schema-Import fehlgeschlagen: ' . $e->getMessage();
                break;
            }
        }
    }

    if (!$errors && $adminEmail && $adminPassword) {
        try {
            $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, display_name, is_active) VALUES (:email, :hash, :display_name, 1)
                ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), display_name = VALUES(display_name), is_active = 1');
            $stmt->execute([
                'email' => $adminEmail,
                'hash' => $hash,
                'display_name' => $adminDisplay ?: 'Admin',
            ]);
        } catch (PDOException $e) {
            $errors[] = 'Admin-Anlage fehlgeschlagen: ' . $e->getMessage();
        }
    }

    if (!$errors) {
        $configArray = [
            'db' => [
                'host' => $dbHost,
                'name' => $dbName,
                'user' => $dbUser,
                'pass' => $dbPass,
                'charset' => $dbCharset,
            ],
            'app' => [
                'base_url' => $baseUrl,
                'session_name' => $sessionName ?: 'kumiai_session',
                'studio_name' => $studioName ?: 'Kumiai',
            ],
        ];
        $configPhp = "<?php\nreturn " . var_export($configArray, true) . ";\n";
        if (file_put_contents($configPath, $configPhp) === false) {
            $errors[] = 'Konfigurationsdatei konnte nicht geschrieben werden. Prüfe Schreibrechte von includes/.';
        } else {
            $success = 'Setup abgeschlossen. Konfiguration gespeichert und Schema vorbereitet.';
        }
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kumiai Asset Manager Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white">
                    <h1 class="h4 mb-0">Setup – Kumiai Asset Manager</h1>
                </div>
                <div class="card-body">
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                        <p class="mb-4">Du kannst dich jetzt <a href="/login.php">anmelden</a> oder zur <a href="/index.php">Startseite</a> wechseln.</p>
                    <?php endif; ?>
                    <?php if ($errors): ?>
                        <div class="alert alert-danger">
                            <strong>Bitte prüfen:</strong>
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <form method="post" novalidate>
                        <h2 class="h5">Allgemein</h2>
                        <div class="mb-3">
                            <label class="form-label" for="studio_name">Studio / Firmenname *</label>
                            <input type="text" class="form-control" id="studio_name" name="studio_name" required value="<?= htmlspecialchars($_POST['studio_name'] ?? $defaults['studio_name']) ?>">
                            <div class="form-text">Wird im UI als Branding verwendet.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="base_url">Basis-URL</label>
                            <input type="text" class="form-control" id="base_url" name="base_url" value="<?= htmlspecialchars($_POST['base_url'] ?? $defaults['base_url']) ?>">
                            <div class="form-text">z. B. "/" oder "/kumiai" je nach Hosting.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="session_name">Session-Name</label>
                            <input type="text" class="form-control" id="session_name" name="session_name" value="<?= htmlspecialchars($_POST['session_name'] ?? $defaults['session_name']) ?>">
                        </div>
                        <hr>
                        <h2 class="h5">Datenbank</h2>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="db_host">Host *</label>
                                <input type="text" class="form-control" id="db_host" name="db_host" required value="<?= htmlspecialchars($_POST['db_host'] ?? $defaults['db_host']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="db_name">Datenbankname *</label>
                                <input type="text" class="form-control" id="db_name" name="db_name" required value="<?= htmlspecialchars($_POST['db_name'] ?? $defaults['db_name']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="db_user">User *</label>
                                <input type="text" class="form-control" id="db_user" name="db_user" required value="<?= htmlspecialchars($_POST['db_user'] ?? $defaults['db_user']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="db_pass">Passwort</label>
                                <input type="password" class="form-control" id="db_pass" name="db_pass" value="<?= htmlspecialchars($_POST['db_pass'] ?? $defaults['db_pass']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="db_charset">Charset</label>
                                <input type="text" class="form-control" id="db_charset" name="db_charset" value="<?= htmlspecialchars($_POST['db_charset'] ?? $defaults['db_charset']) ?>">
                            </div>
                        </div>
                        <hr>
                        <h2 class="h5">Initialer Admin (optional)</h2>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="admin_email">E-Mail</label>
                                <input type="email" class="form-control" id="admin_email" name="admin_email" value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="admin_display_name">Anzeigename</label>
                                <input type="text" class="form-control" id="admin_display_name" name="admin_display_name" value="<?= htmlspecialchars($_POST['admin_display_name'] ?? 'Admin') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="admin_password">Passwort</label>
                                <input type="password" class="form-control" id="admin_password" name="admin_password">
                            </div>
                        </div>
                        <div class="form-text mb-3">Wenn Admin-E-Mail & Passwort angegeben sind, wird der Nutzer angelegt oder reaktiviert.</div>
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <?php if (file_exists($configPath)): ?>
                                    <span class="badge text-bg-secondary">config.php existiert – wird bei Speichern überschrieben.</span>
                                <?php else: ?>
                                    <span class="badge text-bg-success">config.php wird neu angelegt.</span>
                                <?php endif; ?>
                            </div>
                            <button type="submit" class="btn btn-primary">Setup ausführen</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
