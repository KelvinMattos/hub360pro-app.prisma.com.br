<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Product;
use App\Services\QualityAuditService;

$auditService = new QualityAuditService();

// Mock de um produto para teste
$product = new Product([
    'title' => 'Produto Teste Curto',
    'description' => 'Desc',
    'image_url' => 'http://img.com'
]);

// Como não temos banco real funcional no ambiente de script unitário as-is com relações, 
// vamos apenas checar se a classe instancia e responde a lógica básica
echo "Iniciando Teste de Auditoria...\n";
$result = $auditService->audit($product);
echo "Score: " . $result['score'] . "/10\n";
echo "Melhorias: " . implode(', ', $result['improvements']) . "\n";
echo "Teste Concluído.\n";
