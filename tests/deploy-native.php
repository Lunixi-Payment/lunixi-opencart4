<?php

declare (strict_types=1);
$site = getenv('LUNIXI_TEST_SITE');
if (!is_string($site) || !is_file($site . '/config.php')) {
    throw new RuntimeException('Disposable installed fixture required.');
}
$zip = new ZipArchive();
if ($zip->open(dirname(__DIR__) . '/dist/lunixi.ocmod.zip') !== true) {
    throw new RuntimeException('Build the merchant artifact first.');
}
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (!preg_match('~^(admin/|catalog/|system/|install[.]json$)~', $name) || str_contains($name, '..') || str_ends_with($name, '/')) {
        continue;
    }
    $target = $site . '/extension/lunixi/' . $name;
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0755, true);
    }
    file_put_contents($target, $zip->getFromIndex($i));
}
$zip->close();
echo "Merchant ZIP deployed into disposable official native fixture.\n";
