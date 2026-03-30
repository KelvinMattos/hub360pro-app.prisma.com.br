<?php
// public/limpar.php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::capture();
$kernel->handle($request);

use Illuminate\Support\Facades\Artisan;

echo "<h1>🧹 Limpeza Profunda do Sistema</h1>";

try {
    // Limpa cache de rotas (MUITO IMPORTANTE)
    $files = glob(__DIR__.'/../bootstrap/cache/*.php');
    foreach($files as $file) {
        @unlink($file);
        echo "<p>🗑️ Deletado: " . basename($file) . "</p>";
    }
    
    // Tenta limpar sessões antigas
    $sessions = glob(__DIR__.'/../storage/framework/sessions/*');
    foreach($sessions as $file) {
        if(basename($file) !== '.gitignore') @unlink($file);
    }
    echo "<p>✅ Sessões antigas removidas.</p>";
    
    // Limpa views compiladas
    $views = glob(__DIR__.'/../storage/framework/views/*.php');
    foreach($views as $file) {
        @unlink($file);
    }
    echo "<p>✅ Cache de Views limpo.</p>";

} catch(Exception $e) {
    echo "<p style='color:red'>Erro: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<a href='/' style='background:blue; color:white; padding:10px 20px; text-decoration:none; border-radius:5px; font-weight:bold;'>TENTAR ACESSAR AGORA &rarr;</a>";
?>