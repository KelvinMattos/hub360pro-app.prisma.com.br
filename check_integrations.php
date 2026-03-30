<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(Illuminate\Http\Request::capture());

use App\Models\Integration;

$companyId = 1; // ID padrão que vimos no log
$count = Integration::where('company_id', $companyId)->count();
$activeCount = Integration::where('company_id', $companyId)->whereNotNull('access_token')->count();

echo "Total: $count\n";
echo "Active: $activeCount\n";

$integrations = Integration::where('company_id', $companyId)->get();
foreach ($integrations as $i) {
    echo "ID: {$i->id}, Platform: {$i->platform}, Status: {$i->status}, Token: " . ($i->access_token ? 'YES' : 'NO') . "\n";
}
