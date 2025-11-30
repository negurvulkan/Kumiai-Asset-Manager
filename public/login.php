<?php
require_once __DIR__ . '/../includes/layout.php';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (attempt_login($pdo, $email, $password)) {
        header('Location: /index.php');
        exit;
    }
    $error = 'Login fehlgeschlagen. Bitte prüfen Sie Ihre Zugangsdaten.';
}
render_header('Login');
?>
<div class="row justify-content-center">
    <div class="col-md-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h4 mb-3">Anmelden</h1>
                <?php if ($error): ?>
                    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label" for="email">E-Mail</label>
                        <input class="form-control" type="email" name="email" id="email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">Passwort</label>
                        <input class="form-control" type="password" name="password" id="password" required>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Login</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php render_footer(); ?>
