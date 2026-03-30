<?php
// public/debug.php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::capture();
$kernel->handle($request);

echo "<h1>🕵️ Diagnóstico de Configuração</h1>";

// Tenta ler do .env direto
$envAppId = env('ML_APP_ID');
$configAppId = config('services.mercadolibre.app_id');

echo "<p><strong>O que está no .env:</strong> " . ($envAppId ? $envAppId : '<span style="color:red">VAZIO ou NÃO LIDO</span>') . "</p>";
echo "<p><strong>O que o Laravel (Config) está lendo:</strong> " . ($configAppId ? $configAppId : '<span style="color:red">VAZIO</span>') . "</p>";

if ($configAppId === 'SEU_APP_ID_AQUI' || $configAppId === 'seu_numero_app_id_aqui') {
    echo "<h3 style='color:red'>⚠️ ALERTA: Você ainda não substituiu o texto de exemplo pelos números reais no arquivo .env!</h3>";
} elseif ($envAppId && !$configAppId) {
    echo "<h3 style='color:orange'>⚠️ O .env existe, mas o Config está vazio. Você precisa limpar o cache.</h3>";
} elseif ($configAppId) {
    echo "<h3 style='color:green'>✅ Tudo parece correto. O ID carregado é: $configAppId</h3>";
}

echo "<hr>";
echo "<h3>Conteúdo esperado em config/services.php:</h3>";
echo "<pre>
'mercadolibre' => [
    'app_id' => env('ML_APP_ID'),
    'client_secret' => env('ML_SECRET_KEY'),
    'redirect' => env('ML_REDIRECT_URI'),
],
</pre>";

echo "<hr>";
echo "<a href='/limpar.php' style='background:blue; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>LIMPAR CACHE AGORA</a>";
?>