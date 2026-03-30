<?php
$mysqli = new mysqli("localhost", "kelvi593_hub360pro", "#jbb@QTA#NLe", "kelvi593_hub360pro");
if ($mysqli->connect_error) {
    die("Connect Error: " . $mysqli->connect_error);
}
$res = $mysqli->query("SELECT id, platform, status, access_token FROM integrations WHERE company_id = 1");
if (!$res) {
    die("Query Error: " . $mysqli->error);
}
while ($row = $res->fetch_assoc()) {
    echo "ID: " . $row['id'] . ", Platform: " . $row['platform'] . ", Status: " . $row['status'] . ", Token: " . ($row['access_token'] ? 'YES' : 'NO') . "\n";
}
$mysqli->close();
echo "Done\n";
