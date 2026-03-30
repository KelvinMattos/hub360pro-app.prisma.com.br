<?php
$pdo = new PDO("mysql:host=69.6.213.165;dbname=kelvi593_hub360pro", "kelvi593_hub360pro", "#jbb@QTA#NLe");
$stmt = $pdo->query("SELECT id, user_id, company_id, platform, account_nickname, access_token, refresh_token FROM integrations WHERE platform IN ('mercadolibre', 'mercado_livre')");
$integrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($integrations);
