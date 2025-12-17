<?php
require_once __DIR__ . '/db.php';

session_name($config['app']['session_name']);
session_start();

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function require_login(): void
{
    if (!current_user()) {
        header('Location: /login.php');
        exit;
    }
}

function attempt_login(PDO $pdo, string $email, string $password): bool
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email AND is_active = 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user'] = [
            'id' => $user['id'],
            'email' => $user['email'],
            'display_name' => $user['display_name'],
        ];
        return true;
    }
    return false;
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}

function user_projects(PDO $pdo): array
{
    if (!current_user()) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT p.*, pr.role FROM project_roles pr JOIN projects p ON p.id = pr.project_id WHERE pr.user_id = :user ORDER BY p.name');
    $stmt->execute(['user' => current_user()['id']]);
    return $stmt->fetchAll();
}

function user_can_manage_project(array $project): bool
{
    $role = $project['role'] ?? null;
    return in_array($role, ['owner', 'admin'], true);
}
