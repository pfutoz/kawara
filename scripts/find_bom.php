<?php
$files = [
    'api/line_link_status.php',
    'includes/line_helper.php',
    'includes/db.php',
    'includes/auth_helper.php',
    'safety_contacts.php'
];

foreach ($files as $f) {
    $content = file_get_contents(__DIR__ . '/../' . $f);
    $bom = substr($content, 0, 3);
    if ($bom === "\xEF\xBB\xBF") {
        echo "🚨 BOM FOUND in {$f}!\n";
    } else {
        echo "OK: {$f}\n";
    }
}
