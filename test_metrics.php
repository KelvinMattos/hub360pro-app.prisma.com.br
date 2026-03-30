<?php
$pdo = new PDO("mysql:host=69.6.213.165;dbname=kelvi593_hub360pro", "kelvi593_hub360pro", "#jbb@QTA#NLe");
$stmt = $pdo->query("SELECT seller_id, access_token FROM integrations WHERE id = 3");
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) die("No ML integration found.\n");

$token = $row['access_token'];
$sellerId = $row['seller_id'];

$endpoints = [
    "/users/me",
    "/advertising/MLB/advertisers/{$sellerId}/product_ads/campaigns/search?date_from=2024-10-01&date_to=2024-10-31&metrics=clicks,prints,ctr,cost,cpc,acos,roas"
];

foreach ($endpoints as $endpoint) {
    echo "Testing GET $endpoint \n";
    $url = "https://api.mercadolibre.com" . $endpoint;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_ENCODING, "");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token",
        "Accept: application/json",
        "api-version: 2"
    ]);
    
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    echo "Status: " . $info['http_code'] . "\n";
    echo "Response:\n" . substr($response, 0, 1000) . "\n\n";
}
