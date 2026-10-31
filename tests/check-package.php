<?php

$zip = new ZipArchive();
if (empty($argv[1]) || $zip->open($argv[1]) !== true) {
    fwrite(STDERR, "Cannot read release ZIP.\n");
    exit(1);
}
try {
    $manifest = json_decode($zip->getFromName('GHInstaller/module.json'), true);
    if (!is_array($manifest) || ($manifest['name'] ?? '') !== 'GHInstaller'
        || substr($manifest['latestVersionUrl'] ?? '', -12) !== '/version.txt'
        || substr($manifest['latestVersionZipUrl'] ?? '', -16) !== '/GHInstaller.zip') {
        throw new RuntimeException('Missing manifest or invalid self-update metadata.');
    }
    foreach (['Providers/GHInstallerServiceProvider.php', 'Services/Installer.php', 'Http/routes.php', 'Resources/views/index.blade.php'] as $path) {
        if ($zip->locateName('GHInstaller/'.$path) === false) {
            throw new RuntimeException('Missing module file: '.$path);
        }
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (strpos($name, 'GHInstaller/') !== 0 || preg_match('~^GHInstaller/(?:tests/|\.github/|\.git/|\.gitattributes$|\.gitignore$)~', $name)) {
            throw new RuntimeException('Unexpected package entry: '.$name);
        }
    }
    echo "OK: release ZIP has the expected module root and self-update metadata.\n";
} finally {
    $zip->close();
}
