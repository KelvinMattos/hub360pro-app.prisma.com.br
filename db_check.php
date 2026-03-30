<?php
$env = parse_ini_file('.env');
$host = $env['DB_HOST'];
$user = $env['DB_USERNAME'];
$pass = $env['DB_PASSWORD'];
$db = $env['DB_DATABASE'];

$mysqli = new mysqli($host, $user, $pass, $db);

if ($mysqli->connect_error) {
    die("Connect Error: " . $mysqli->connect_error);
}

$res = $mysqli->query("SELECT id, platform, status, access_token FROM integrations WHERE company_id = 1");
while ($row = $res->fetch_assoc()) {
    echo "ID: " . $row['id'] . ", Platform: " . $row['platform'] . ", Status: " . $row['status'] . ", Token: " . ($row['access_token'] ? 'YES' : 'NO') . "\n";
}

$mysqli->close();
