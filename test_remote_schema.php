<?php
$host = '69.6.213.165';
$db   = 'kelvi593_hub360pro';
$user = 'kelvi593_hub360pro';
$pass = '#jbb@QTA#NLe';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    echo "Successfully connected to remote database!\n\n";

    $stmt = $pdo->query("DESCRIBE integrations");
    $columns = $stmt->fetchAll();

    echo "Table: integrations\n";
    echo str_repeat("-", 40) . "\n";
    foreach ($columns as $column) {
        echo sprintf("%-20s | %s\n", $column['Field'], $column['Type']);
    }
    echo str_repeat("-", 40) . "\n";

} catch (\PDOException $e) {
    echo "Connection error: " . $e->getMessage() . "\n";
}
