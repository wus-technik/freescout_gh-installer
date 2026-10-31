<?php

// Standalone integration tests: real ZIP/filesystem, no FreeScout or GitHub credentials needed.
use Modules\GHInstaller\Services\Archive;
use Modules\GHInstaller\Services\GitHubClient;
use Modules\GHInstaller\Services\Installer;
use Modules\GHInstaller\Services\Repository;

foreach (['Repository', 'GitHubClient', 'Archive', 'Installer'] as $class) {
    require __DIR__.'/../Services/'.$class.'.php';
}
if (!extension_loaded('zip')) {
    fwrite(STDERR, "PHP ZIP extension required.\n");
    exit(1);
}
// Match Laravel's conversion of PHP warnings/deprecations into exceptions.
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$tests = 0;
function check($condition, $message) {
    global $tests;
    $tests++;
    if (!$condition) { throw new RuntimeException('FAIL: '.$message); }
}
function rejects($call, $message) {
    try { $call(); } catch (RuntimeException $error) { check(true, $message); return; }
    check(false, $message);
}
function removeFixture($path) {
    if (is_dir($path) && !is_link($path)) {
        foreach (new DirectoryIterator($path) as $file) {
            if (!$file->isDot()) { removeFixture($file->getPathname()); }
        }
        rmdir($path);
    } else { unlink($path); }
}
function archiveFixture($path, array $entries) {
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create test ZIP.');
    }
    foreach ($entries as $name => $content) { $zip->addFromString($name, $content); }
    $zip->close();
}
function moduleFixture($prefix = 'repo-commit/', $overrides = []) {
    $manifest = array_merge([
        'name' => 'ExampleModule', 'alias' => 'examplemodule', 'version' => '1.2.3',
        'providers' => ['Modules\\ExampleModule\\Providers\\ExampleModuleServiceProvider'],
    ], $overrides);
    return [
        $prefix.'module.json' => json_encode($manifest),
        $prefix.'Providers/ExampleModuleServiceProvider.php' => '<?php // Fixture; never executed.',
        $prefix.'Public/example.css' => 'body {}',
    ];
}
class FixtureClient extends GitHubClient {
    public $archive;
    public $sha;
    public $calls = [];
    public function download($repository, $ref, $destination) {
        $this->calls[] = [$repository, $ref];
        copy($this->archive, $destination);
        return $this->resolve($repository, $ref);
    }
    public function resolve($repository, $ref) {
        return ['repository' => $repository, 'ref' => $ref ?: 'main', 'commit' => $this->sha ?: str_repeat('a', 40)];
    }
}
$root = sys_get_temp_dir().'/ghinstaller-tests-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    check(Repository::parse('https://github.com/wus-technik/example.git/') === 'wus-technik/example', 'normalize .git URL');
    check(Repository::parse('https://github.com/Owner/repo') === 'Owner/repo', 'plain repository URL');
    foreach (['http://github.com/a/b', 'https://evil.test/a/b', 'https://github.com.evil.test/a/b', 'https://github.com@127.0.0.1/a/b', 'https://github.com/a/b?x=1', 'https://github.com/a/b#main', 'https://github.com/a/../b', 'https://github.com/a/b/tree/main', 'https://github.com:443/a/b', 'https://github.com/a/..', 'file:///etc/passwd', 'https://github.com/a/.git', 'https://github.com/a/b%2f..'] as $url) {
        rejects(function () use ($url) { Repository::parse($url); }, 'reject URL '.$url);
    }
    check(Repository::ref('feature/module') === 'feature/module', 'branch with slash');
    check(Repository::ref('') === '', 'default branch');
    rejects(function () { Repository::ref("main\r\nHost: evil"); }, 'reject header injection in ref');
    rejects(function () { Repository::ref('main?x=1'); }, 'reject query in ref');

    // libzip converts control characters to CP437 glyphs on Windows when creating a ZIP.
    $pathValidator = new ReflectionMethod(Archive::class, 'validatePath');
    if (PHP_VERSION_ID < 80100) { $pathValidator->setAccessible(true); }
    rejects(function () use ($pathValidator) { $pathValidator->invoke(new Archive(), "repo/evil\0.php"); }, 'reject NUL path');
    rejects(function () use ($pathValidator) { $pathValidator->invoke(new Archive(), "repo/evil\x01.php"); }, 'reject control character path');
    foreach (['../escape', '/absolute', 'repo/../../escape', 'repo\\escape', 'repo/a:b', 'repo/./file', 'repo//file', 'repo/CON.txt', 'repo/file.'] as $i => $path) {
        archiveFixture($root.'/unsafe.zip', [$path => 'bad']);
        rejects(function () use ($root, $i) { (new Archive())->unpack($root.'/unsafe.zip', $root.'/unsafe-'.$i); }, 'reject archive path '.$i);
        check(!is_dir($root.'/unsafe-'.$i), 'validate before extracting');
    }
    archiveFixture($root.'/link.zip', ['repo/link' => '/etc/passwd']);
    $zip = new ZipArchive();
    $zip->open($root.'/link.zip');
    $zip->setExternalAttributesName('repo/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zip->close();
    rejects(function () use ($root) { (new Archive())->unpack($root.'/link.zip', $root.'/links'); }, 'reject ZIP symlink');
    archiveFixture($root.'/duplicate.zip', ['repo/Test.php' => 'a', 'repo/test.php' => 'b']);
    rejects(function () use ($root) { (new Archive())->unpack($root.'/duplicate.zip', $root.'/duplicates'); }, 'reject case-insensitive duplicates');
    $entries = [];
    for ($i = 0; $i <= Archive::MAX_FILES; $i++) { $entries['repo/file'.$i] = ''; }
    archiveFixture($root.'/many.zip', $entries);
    rejects(function () use ($root) { (new Archive())->unpack($root.'/many.zip', $root.'/many'); }, 'reject too many files');
    $large = fopen($root.'/bomb', 'wb');
    ftruncate($large, Archive::MAX_BYTES + 1);
    fclose($large);
    $zip = new ZipArchive();
    $zip->open($root.'/large.zip', ZipArchive::CREATE);
    $zip->addFile($root.'/bomb', 'repo/bomb');
    $zip->close();
    unlink($root.'/bomb');
    rejects(function () use ($root) { (new Archive())->unpack($root.'/large.zip', $root.'/large'); }, 'reject excessive expanded size');
    file_put_contents($root.'/invalid.zip', 'not a zip');
    rejects(function () use ($root) { (new Archive())->unpack($root.'/invalid.zip', $root.'/invalid'); }, 'reject invalid ZIP');

    mkdir($root.'/Modules');
    $client = new FixtureClient();
    $client->archive = $root.'/source.zip';
    $installer = new Installer($root.'/work', $root.'/Modules', $client);
    foreach (['repo-commit/', 'repo-commit/Modules/ExampleModule/', 'repo-commit/ExampleModule/'] as $prefix) {
        archiveFixture($client->archive, moduleFixture($prefix));
        $pending = $installer->prepare('https://github.com/test/module', 'v1.2.3');
        check($pending['manifest']['name'] === 'ExampleModule', 'detect module in '.$prefix);
        check(!is_dir($root.'/Modules/ExampleModule'), 'preview does not publish module');
        $installer->discard($pending['id']);
    }
    foreach ([['name' => '../Escape'], ['alias' => '../escape'], ['version' => []], ['providers' => ['Outside\\Wrong\\Providers\\ExampleModuleServiceProvider']], ['providers' => []], ['name' => []]] as $overrides) {
        archiveFixture($client->archive, moduleFixture('repo/', $overrides));
        rejects(function () use ($installer) { $installer->prepare('https://github.com/test/module', ''); }, 'reject invalid manifest');
    }
    archiveFixture($client->archive, array_merge(moduleFixture('repo/one/'), moduleFixture('repo/two/')));
    rejects(function () use ($installer) { $installer->prepare('https://github.com/test/module', ''); }, 'reject ambiguous multi-module repo');
    archiveFixture($client->archive, ['repo/README.md' => 'no module']);
    rejects(function () use ($installer) { $installer->prepare('https://github.com/test/module', ''); }, 'reject missing module');
    archiveFixture($client->archive, moduleFixture());
    $pending = $installer->prepare('https://github.com/test/module', '');
    $expired = $pending;
    $expired['expires'] = time() - 1;
    rejects(function () use ($installer, $expired) { $installer->install($expired); }, 'reject expired preview');
    check(!is_dir($root.'/work/'.$pending['id']), 'expired job removed');

    $pending = $installer->prepare('https://github.com/test/module', '');
    $tampered = $pending;
    $tampered['manifest']['version'] = '9.0.0';
    rejects(function () use ($installer, $tampered) { $installer->install($tampered); }, 'reject changed preview');
    $pending = $installer->prepare('https://github.com/test/module', '');
    mkdir($root.'/Modules/OtherModule');
    file_put_contents($root.'/Modules/OtherModule/module.json', json_encode(['alias' => 'examplemodule']));
    rejects(function () use ($installer, $pending) { $installer->install($pending); }, 'recheck alias collision at installation');
    removeFixture($root.'/Modules/OtherModule');

    $pending = $installer->prepare('https://github.com/test/module', '');
    $installed = $installer->install($pending);
    check($installed['name'] === 'ExampleModule', 'installation succeeds');
    check(file_get_contents($root.'/Modules/ExampleModule/Public/example.css') === 'body {}', 'module files installed');
    $receipt = json_decode(file_get_contents($root.'/Modules/ExampleModule/.ghinstaller-source.json'), true);
    check($receipt['commit'] === str_repeat('a', 40), 'record exact installed commit');
    check(!is_dir($root.'/work/'.$pending['id']), 'successful job cleaned');
    rejects(function () use ($installer) { $installer->prepare('https://github.com/test/module', ''); }, 'existing module not overwritten');
    check(file_get_contents($root.'/Modules/ExampleModule/Public/example.css') === 'body {}', 'existing files preserved');
    check($installer->check('examplemodule')['changed'] === false, 'matching commit reports current');
    rejects(function () use ($installer) { $installer->prepareUpdate('examplemodule'); }, 'no update for same commit');
    $client->sha = str_repeat('c', 40);
    check($installer->check('examplemodule')['changed'] === true, 'different commit detected');
    $updatedFiles = moduleFixture('repo/', ['version' => '1.2.4']);
    $updatedFiles['repo/Public/new.css'] = 'new';
    unset($updatedFiles['repo/Public/example.css']);
    archiveFixture($client->archive, $updatedFiles);
    $update = $installer->prepareUpdate('examplemodule');
    check($update['previous']['source']['commit'] === str_repeat('a', 40), 'update remembers old commit');
    $activeInstaller = new Installer($root.'/work', $root.'/Modules', $client, function () { return true; });
    rejects(function () use ($activeInstaller, $update) { $activeInstaller->install($update); }, 'active module update blocked');
    check(is_file($root.'/Modules/ExampleModule/Public/example.css'), 'blocked update preserves installed files');
    $update = $installer->prepareUpdate('examplemodule');
    $updated = $installer->install($update);
    check($updated['version'] === '1.2.4', 'update installs new version');
    check(!is_file($root.'/Modules/ExampleModule/Public/example.css'), 'update removes obsolete files');
    check(file_get_contents($root.'/Modules/ExampleModule/Public/new.css') === 'new', 'update installs new files');
    check($installer->managed()['examplemodule']['source']['commit'] === str_repeat('c', 40), 'update records new SHA');
    check($installer->check('examplemodule')['changed'] === false, 'update is current');
    $client->sha = str_repeat('d', 40);
    $update = $installer->prepareUpdate('examplemodule');
    $receiptPath = $root.'/Modules/ExampleModule/.ghinstaller-source.json';
    $changedReceipt = json_decode(file_get_contents($receiptPath), true);
    $changedReceipt['commit'] = str_repeat('e', 40);
    file_put_contents($receiptPath, json_encode($changedReceipt));
    rejects(function () use ($installer, $update) { $installer->install($update); }, 'stale update cannot replace intervening installation');
    check(file_get_contents($root.'/Modules/ExampleModule/Public/new.css') === 'new', 'stale update preserves files');
    $manifestPath = $root.'/Modules/ExampleModule/module.json';
    $nativeManifest = json_decode(file_get_contents($manifestPath), true);
    $nativeManifest['latestVersionUrl'] = 'https://example.com/version';
    $nativeManifest['latestVersionZipUrl'] = 'https://example.com/module.zip';
    file_put_contents($manifestPath, json_encode($nativeManifest));
    rejects(function () use ($installer) { $installer->check('examplemodule'); }, 'native updater check takes priority');
    rejects(function () use ($installer) { $installer->prepareUpdate('examplemodule'); }, 'native updater installation takes priority');
    rejects(function () use ($installer) { $installer->check('unknown'); }, 'unmanaged module cannot be updated');
    rejects(function () use ($installer) { $installer->discard('../outside'); }, 'reject forged cleanup ID');
    $oldId = str_repeat('b', 48);
    mkdir($root.'/work/'.$oldId);
    touch($root.'/work/'.$oldId, time() - Installer::TTL - 500);
    $installer->cleanup();
    check(!is_dir($root.'/work/'.$oldId), 'clean abandoned expired job');
    check(count(glob($root.'/Modules/.ghinstaller-*')) === 0, 'no publication staging left behind');

    // The project itself must be a discoverable FreeScout module when installed from GitHub.
    $self = $installer->inspect(dirname(__DIR__));
    check($self['manifest']['name'] === 'Github.com Installer', 'self module display name');
    check($self['folder'] === 'GHInstaller', 'self module keeps stable installation folder');
    archiveFixture($client->archive, moduleFixture('repo/', ['name' => 'Example Module']));
    removeFixture($root.'/Modules/ExampleModule');
    $displayPending = $installer->prepare('https://github.com/test/module', '');
    $installer->install($displayPending);
    check(is_file($root.'/Modules/ExampleModule/module.json'), 'display name independent of namespace folder');
    check(isset($installer->managed()['examplemodule']), 'display name module remains tracked');
    $client->sha = str_repeat('f', 40);
    $displayUpdate = $installer->prepareUpdate('examplemodule');
    $installer->install($displayUpdate);
    check($installer->managed()['examplemodule']['source']['commit'] === str_repeat('f', 40), 'display name module can update');
    check($installer->diagnostics()['modules_writable'], 'module directory diagnostics');
    $badInstaller = new Installer($root.'/work', $root.'/MissingModules', $client);
    rejects(function () use ($badInstaller) { $badInstaller->prepare('https://github.com/test/module', ''); }, 'missing module directory reported before download');
    echo 'OK: '.$tests." checks passed.\n";
} finally {
    removeFixture($root);
}
