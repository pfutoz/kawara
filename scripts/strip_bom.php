<?php
$dir = __DIR__ . '/..';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

$fixed = [];
foreach ($iterator as $file) {
    if ($file->isDir()) continue;
    $path = $file->getPathname();
    if (pathinfo($path, PATHINFO_EXTENSION) !== 'php') continue;

    $content = file_get_contents($path);
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        $clean = substr($content, 3);
        file_put_contents($path, $clean);
        $fixed[] = $path;
        echo "Fixed BOM in: {$path}\n";
    }
}

if (empty($fixed)) {
    echo "No BOM found in any PHP files.\n";
} else {
    echo "Total fixed: " . count($fixed) . " files.\n";
}
