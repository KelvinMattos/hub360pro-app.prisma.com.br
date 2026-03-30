<?php
/**
 * fix_manifest.php
 * Use este script para sincronizar o manifest.json com os arquivos reais no servidor.
 */

$manifestPath = __DIR__ . '/build/manifest.json';
$assetsDir = __DIR__ . '/build/assets';

echo "<h1>🔍 Corretor de Manifest Vite</h1>";

if (!file_exists($manifestPath)) {
    die("<p style='color:red'>❌ Erro: manifest.json não encontrado em $manifestPath</p>");
}

$manifest = json_decode(file_get_contents($manifestPath), true);
$realFiles = scandir($assetsDir);

echo "<h3>Arquivos reais no servidor:</h3><ul>";
foreach ($realFiles as $f) if ($f !== '.' && $f !== '..') echo "<li>$f</li>";
echo "</ul>";

$updated = false;
foreach ($manifest as $key => &$data) {
    $currentFile = basename($data['file']);
    if (!in_array($currentFile, $realFiles)) {
        echo "<p style='color:orange'>⚠️ Arquivo no manifest não existe: $currentFile</p>";
        
        // Tenta encontrar o arquivo real baseado no prefixo (app-*.js / app-*.css)
        $prefix = explode('-', $currentFile)[0];
        $ext = pathinfo($currentFile, PATHINFO_EXTENSION);
        
        foreach ($realFiles as $real) {
            if (str_starts_with($real, $prefix . '-') && str_ends_with($real, '.' . $ext)) {
                echo "<p style='color:green'>✅ Encontrei substituto: $real</p>";
                $data['file'] = 'assets/' . $real;
                $updated = true;
                break;
            }
        }
    }
}

if ($updated) {
    if (file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
        echo "<h2 style='color:green'>✨ Manifest atualizado com sucesso!</h2>";
    } else {
        echo "<h2 style='color:red'>❌ Erve ao salvar manifest.json</h2>";
    }
} else {
    echo "<h2>Nada para atualizar.</h2>";
}

echo "<hr><a href='/login'>Tentar acessar o sistema</a>";
?>
