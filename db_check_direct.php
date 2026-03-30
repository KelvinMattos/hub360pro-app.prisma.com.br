<?php
$mysqli = new mysqli("localhost", "kelvi593_hub360pro", "#jbb@QTA#NLe", "kelvi593_hub360pro");
if ($mysqli->connect_error) {
    die("Connect Error: " . $mysqli->connect_error);
}
$res = $mysqli->query("SELECT id, platform, status, app_id, client_secret, access_token, refresh_token, seller_id FROM integrations WHERE company_id = 1");
if (!$res) {
    die("Query Error: " . $mysqli->error);
}
while ($row = $res->fetch_assoc()) {
    echo "ID: " . $row['id'] . "\n";
    echo "  Platform: " . $row['platform'] . "\n";
    echo "  Status: " . $row['status'] . "\n";
    echo "  AppID: " . ($row['app_id'] ? 'YES' : 'NO') . "\n";
    echo "  Secret: " . ($row['client_secret'] ? 'YES' : 'NO') . "\n";
    echo "  Token: " . ($row['access_token'] ? 'YES' : 'NO') . "\n";
    echo "  Refresh: " . ($row['refresh_token'] ? 'YES' : 'NO') . "\n";
    echo "  SellerID: " . ($row['seller_id'] ?? 'NULL') . "\n";
    echo "-------------------\n";
}
$mysqli->close();
echo "Done\n";
