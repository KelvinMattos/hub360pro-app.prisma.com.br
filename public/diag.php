<?php
header('Content-Type: text/plain');
echo "--- Environment Diagnostic ---\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Current Directory: " . __DIR__ . "\n";
echo "Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "\n\n";

$envPath = __DIR__ . '/.env';
if (!file_exists($envPath)) {
    // Tenta subir um nível (caso esteja no /public)
    $envPath = dirname(__DIR__) . '/.env';
}

echo "Checking .env at: $envPath\n";
if (file_exists($envPath)) {
    echo "Status: EXISTS\n";
    echo "Size: " . filesize($envPath) . " bytes\n";
    echo "Permissions: " . substr(sprintf('%o', fileperms($envPath)), -4) . "\n";
    echo "Can read: " . (is_readable($envPath) ? 'YES' : 'NO') . "\n";
    
    // Tenta ler a primeira linha (sem mostrar valores sensíveis)
    $f = fopen($envPath, 'r');
    $firstLine = fgets($f);
    fclose($f);
    echo "First line starts with: " . substr($firstLine, 0, 10) . "...\n";
} else {
    echo "Status: NOT FOUND\n";
}

echo "\n--- Build Assets Check ---\n";
$buildPath = __DIR__ . '/build/manifest.json';
if (!file_exists($buildPath)) {
    $buildPath = __DIR__ . '/public/build/manifest.json';
}

echo "Manifest at: $buildPath\n";
if (file_exists($buildPath)) {
    echo "Manifest Status: EXISTS\n";
    $manifest = json_decode(file_get_contents($buildPath), true);
    foreach ($manifest as $key => $data) {
        $file = $data['file'];
        $path = dirname($buildPath) . '/' . $file;
        echo "Asset: $file - " . (file_exists($path) ? "OK" : "MISSING") . " ($path)\n";
    }
} else {
    echo "Manifest Status: NOT FOUND\n";
}
?>
