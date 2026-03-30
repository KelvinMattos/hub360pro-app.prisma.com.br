<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Vite Manifest Path: " . public_path('build/manifest.json') . "\n";
if (file_exists(public_path('build/manifest.json'))) {
    echo "Manifest exists.\n";
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
    print_r($manifest);
} else {
    echo "Manifest NOT found.\n";
}

try {
    echo "Testing Vite helper for app.js:\n";
    // We can't easily call @vite from CLI without a view factory, but we can check the manifest manually or use the Vite class.
    $vite = app(\Illuminate\Foundation\Vite::class);
    // This might fail if not in a web request, but let's see.
} catch (\Exception $e) {
    echo "Error calling Vite helper: " . $e->getMessage() . "\n";
}
