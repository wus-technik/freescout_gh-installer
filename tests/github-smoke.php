<?php

// Optional read-only GitHub test; never executed by the offline CI suite.
// php tests/github-smoke.php https://github.com/owner/repo [ref]
// Optional server-side GHINSTALLER_GITHUB_TOKEN environment variable.
foreach (['Repository', 'GitHubClient', 'Archive', 'Installer'] as $class) {
    require __DIR__.'/../Services/'.$class.'.php';
}
use Modules\GHInstaller\Services\GitHubClient;
use Modules\GHInstaller\Services\Installer;

if (empty($argv[1])) {
    fwrite(STDERR, "Usage: php tests/github-smoke.php https://github.com/owner/repo [ref]\n");
    exit(1);
}
$root = sys_get_temp_dir().'/ghinstaller-smoke-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
mkdir($root.'/Modules', 0700);
$installer = new Installer($root.'/work', $root.'/Modules', new GitHubClient(getenv('GHINSTALLER_GITHUB_TOKEN') ?: ''));
$failed = false;
try {
    $pending = $installer->prepare($argv[1], $argv[2] ?? '');
    echo 'OK: downloaded and validated '.$pending['manifest']['name'].' at '.$pending['commit']."\n";
    $installer->discard($pending['id']);
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    $failed = true;
} finally {
    if (is_dir($root.'/work')) { rmdir($root.'/work'); }
    rmdir($root.'/Modules');
    rmdir($root);
}
exit($failed ? 1 : 0);
