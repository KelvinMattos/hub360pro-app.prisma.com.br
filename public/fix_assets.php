<?php
$files = [
    'public/build/assets/app-C0c1Sc-G.js' => 'public/build/assets/app-BDAHwRX7.js',
    'public/build/assets/app-InRQqHsI.css' => 'public/build/assets/app-Bh1dLVcn.css'
];

echo "<h1>🛠️ Asset Aliasiager</h1>";

foreach ($files as $source => $dest) {
    if (file_exists($source)) {
        if (copy($source, $dest)) {
            echo "<p style='color:green'>✅ Copied: " . basename($source) . " -> " . basename($dest) . "</p>";
        } else {
            echo "<p style='color:red'>❌ Failed to copy: " . basename($source) . "</p>";
        }
    } else {
        echo "<p style='color:orange'>⚠️ Source not found: " . $source . "</p>";
    }
}

echo "<hr>";
echo "<a href='/login'>Go to Login</a>";
?>
