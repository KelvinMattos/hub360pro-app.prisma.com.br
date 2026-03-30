<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Artisan;

Config::set('database.connections.mysql.host', '69.6.213.165');

echo "Iniciando Sync...\n";
$exitCode = Artisan::call('orders:sync');
echo "Exit Code: $exitCode\n";
echo "Command Output:\n";
echo Artisan::output();
