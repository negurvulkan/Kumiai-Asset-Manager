<?php
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    if (php_sapi_name() !== 'cli' && basename($_SERVER['PHP_SELF'] ?? '') !== 'setup.php') {
        header('Location: /setup.php');
        exit;
    }
    $configFile = __DIR__ . '/config.example.php';
}
$config = require $configFile;

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['db']['host'], $config['db']['name'], $config['db']['charset']);
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], $options);
