<?php

namespace Modules\GHInstaller\Services;

class Installer
{
    const TTL = 600;
    private $work;
    private $modules;
    private $client;
    private $isActive;

    public function __construct($work, $modules, GitHubClient $client, $isActive = null)
    {
        $this->work = rtrim($work, '/\\');
        $this->modules = rtrim($modules, '/\\');
        $this->client = $client;
        $this->isActive = $isActive;
    }

    public function prepare($url, $ref, $updateAlias = null)
    {
        $repository = Repository::parse($url);
        $ref = Repository::ref($ref);
        $this->cleanup();
        $this->assertWritableDirectories();
        $id = bin2hex(random_bytes(24));
        $job = $this->job($id);
        if (!@mkdir($job, 0700, true)) {
            throw new \RuntimeException('Das Arbeitsverzeichnis ist nicht beschreibbar.');
        }
        try {
            $source = $this->client->download($repository, $ref, $job.'/source.zip');
            (new Archive())->unpack($job.'/source.zip', $job.'/payload');
            $module = $this->inspect($job.'/payload');
            $previous = null;
            if ($updateAlias !== null) {
                $previous = $this->updateTarget($updateAlias, $module['manifest'], $source['repository']);
                if ($previous['source']['commit'] === $source['commit']) {
                    throw new \RuntimeException('Dieses Modul ist bereits auf dem aktuellen Commit.');
                }
            } else {
                $this->assertAvailable($module['manifest']);
            }
            return array_merge($source, [
                'id' => $id,
                'manifest' => $module['manifest'],
                'expires' => time() + self::TTL,
                'previous' => $previous,
            ]);
        } catch (\Throwable $error) {
            $this->discard($id);
            throw $error;
        }
    }

    public function install(array $pending)
    {
        $job = $this->job($pending['id']);
        if ($pending['expires'] < time() || !is_dir($job)) {
            $this->discard($pending['id']);
            throw new \RuntimeException('Die Vorschau ist abgelaufen. Bitte das Repository erneut prüfen.');
        }
        $lock = fopen($this->work.'/install.lock', 'c');
        if (!$lock) {
            throw new \RuntimeException('Die Installationssperre konnte nicht geöffnet werden.');
        }
        $stage = $this->modules.'/.ghinstaller-'.$pending['id'];
        $backup = $this->modules.'/.ghinstaller-backup-'.$pending['id'];
        $locked = false;
        $stageCreated = false;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('Eine andere Installation läuft bereits. Bitte erneut prüfen.');
            }
            $locked = true;
            $module = $this->inspect($job.'/payload');
            if ($module['manifest'] !== $pending['manifest']) {
                throw new \RuntimeException('Die Modulbeschreibung hat sich seit der Vorschau geändert.');
            }
            $previous = $pending['previous'] ?? null;
            if ($previous) {
                if ($this->isActive && call_user_func($this->isActive, $module['manifest']['alias'])) {
                    throw new \RuntimeException('Das Modul vor dem Update unter Verwalten → Module deaktivieren. Nach dem Update dort wieder aktivieren, damit FreeScout auch Migrationen ausführt.');
                }
                $current = $this->updateTarget($module['manifest']['alias'], $module['manifest'], $pending['repository']);
                if ($current !== $previous) {
                    throw new \RuntimeException('Das installierte Modul hat sich seit der Vorschau geändert. Bitte erneut prüfen.');
                }
            } else {
                $this->assertAvailable($module['manifest']);
            }
            if (file_exists($stage) || is_link($stage) || file_exists($backup) || is_link($backup)) {
                throw new \RuntimeException('Das Installationsverzeichnis ist bereits belegt.');
            }
            $stageCreated = true;
            $this->copy($module['path'], $stage);
            $receipt = [
                'repository' => $pending['repository'], 'ref' => $pending['ref'],
                'commit' => $pending['commit'], 'installed_at' => gmdate('c'),
            ];
            if (file_put_contents($stage.'/.ghinstaller-source.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n") === false) {
                throw new \RuntimeException('Der Installationsnachweis konnte nicht geschrieben werden.');
            }
            $destination = $this->modules.'/'.$module['folder'];
            if ($previous) {
                if (!rename($destination, $backup)) {
                    throw new \RuntimeException('Das bisherige Modul konnte nicht gesichert werden.');
                }
            } else {
                $this->assertAvailable($module['manifest']);
            }
            try {
                if (!rename($stage, $destination)) {
                    throw new \RuntimeException('Das Modul konnte nicht in das Modules-Verzeichnis verschoben werden.');
                }
            } catch (\Throwable $error) {
                if ($previous && !rename($backup, $destination)) {
                    throw new \RuntimeException('Die Wiederherstellung ist fehlgeschlagen. Die bisherige Version liegt im versteckten .ghinstaller-backup-Verzeichnis unter Modules; bitte manuell wiederherstellen.');
                }
                throw $error;
            }
            if ($previous && is_dir($backup)) {
                $this->remove($backup);
            }
            if (!is_dir($destination)) {
                throw new \RuntimeException('Das Modul konnte nicht in das Modules-Verzeichnis verschoben werden.');
            }
            return $module['manifest'];
        } finally {
            if ($stageCreated && is_dir($stage) && !is_link($stage)) {
                $this->remove($stage);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
            if ($locked) { $this->discard($pending['id']); }
        }
    }

    public function managed()
    {
        $result = [];
        if (!is_dir($this->modules) || !is_readable($this->modules)) { return $result; }
        foreach (new \DirectoryIterator($this->modules) as $directory) {
            if ($directory->isDot() || !$directory->isDir() || $directory->isLink() || $directory->getFilename()[0] === '.') { continue; }
            $manifestPath = $directory->getPathname().'/module.json';
            $receiptPath = $directory->getPathname().'/.ghinstaller-source.json';
            if (!is_file($manifestPath) || !is_file($receiptPath)) { continue; }
            $manifest = json_decode(file_get_contents($manifestPath), true);
            $source = json_decode(file_get_contents($receiptPath), true);
            if (!is_array($manifest) || !is_array($source) || !isset($manifest['name'], $manifest['alias'], $source['repository'], $source['ref'], $source['commit'])
                || !is_string($manifest['alias'])
                || !is_string($source['repository']) || !is_string($source['ref']) || !is_string($source['commit'])
                || !preg_match('/\A[0-9a-f]{40}\z/', $source['commit'])) { continue; }
            try { $folder = $this->moduleFolder($manifest); } catch (\RuntimeException $error) { continue; }
            if ($folder !== $directory->getFilename()) { continue; }
            $result[$manifest['alias']] = ['manifest' => $manifest, 'source' => $source];
        }
        return $result;
    }

    public function check($alias)
    {
        $module = $this->managedTarget($alias);
        $source = $this->client->resolve($module['source']['repository'], $module['source']['ref']);
        return ['installed' => $module['source']['commit'], 'available' => $source['commit'], 'changed' => $module['source']['commit'] !== $source['commit']];
    }

    public function prepareUpdate($alias)
    {
        $module = $this->managedTarget($alias);
        return $this->prepare('https://github.com/'.$module['source']['repository'], $module['source']['ref'], $alias);
    }

    private function managedTarget($alias)
    {
        $modules = $this->managed();
        if (!is_string($alias) || !isset($modules[$alias])) {
            throw new \RuntimeException('Kein über den GitHub-Installer installiertes Modul gefunden.');
        }
        $module = $modules[$alias];
        if (!empty($module['manifest']['latestVersionUrl']) || !empty($module['manifest']['latestVersionZipUrl'])) {
            throw new \RuntimeException('Dieses Modul hat eine eigene Update-Anbindung. Bitte die FreeScout-Modulverwaltung verwenden.');
        }
        return $module;
    }

    private function updateTarget($alias, array $manifest, $repository)
    {
        $existing = $this->managedTarget($alias);
        if ($manifest['alias'] !== $alias || $this->moduleFolder($manifest) !== $this->moduleFolder($existing['manifest']) || $repository !== $existing['source']['repository']) {
            throw new \RuntimeException('Das Update passt nicht zum installierten Modul oder Repository.');
        }
        // If the new version introduces native updates, use that mechanism after this update.
        return $existing;
    }

    public function inspect($payload)
    {
        $candidates = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($payload, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new \RuntimeException('Links im Modul sind nicht erlaubt.');
            }
            if ($file->getFilename() === 'module.json') {
                $candidates[] = $file->getPathname();
            }
        }
        if (count($candidates) !== 1) {
            throw new \RuntimeException('Das Repository muss genau ein FreeScout-Modul mit module.json enthalten.');
        }
        $manifest = json_decode(file_get_contents($candidates[0]), true);
        if (!is_array($manifest) || !isset($manifest['name'], $manifest['alias'], $manifest['version'])
            || !is_string($manifest['name']) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9 ._-]{0,119}\z/', $manifest['name'])
            || !is_string($manifest['alias']) || !preg_match('/\A[a-z][a-z0-9_-]{0,79}\z/', $manifest['alias'])
            || !is_string($manifest['version']) || !preg_match('/\A[0-9]+\.[0-9]+(?:\.[0-9]+)?(?:[-+][A-Za-z0-9.-]+)?\z/', $manifest['version'])) {
            throw new \RuntimeException('Ungültige module.json: Modulname, alias und version sind erforderlich.');
        }
        if (empty($manifest['providers']) || !is_array($manifest['providers'])) {
            throw new \RuntimeException('Das Modul enthält keinen Service Provider.');
        }
        $folder = $this->moduleFolder($manifest);
        foreach ($manifest['providers'] as $provider) {
            $prefix = 'Modules\\'.$folder.'\\';
            if (!is_string($provider) || strpos($provider, $prefix) !== 0
                || !preg_match('/\A[A-Za-z0-9_\\\\]+\z/', $provider)
                || !is_file(dirname($candidates[0]).'/'.str_replace('\\', '/', substr($provider, strlen($prefix))).'.php')) {
                throw new \RuntimeException('Modulname, Provider-Namespace und Provider-Datei passen nicht zusammen.');
            }
        }
        return ['path' => dirname($candidates[0]), 'manifest' => $manifest, 'folder' => $folder];
    }

    private function moduleFolder(array $manifest)
    {
        $provider = $manifest['providers'][0] ?? null;
        if (!is_string($provider) || !preg_match('/\AModules\\\\([A-Z][A-Za-z0-9]{0,79})\\\\/', $provider, $match)) {
            throw new \RuntimeException('Der Provider muss im Namespace Modules\\Modulname liegen.');
        }
        return $match[1];
    }

    public function diagnostics()
    {
        $parent = $this->work;
        while (!file_exists($parent) && dirname($parent) !== $parent) { $parent = dirname($parent); }
        $user = null;
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $account = posix_getpwuid(posix_geteuid());
            $user = $account['name'] ?? null;
        }
        return [
            'php' => PHP_VERSION,
            'user' => $user,
            'modules' => $this->modules,
            'modules_writable' => is_dir($this->modules) && is_writable($this->modules),
            'work' => $this->work,
            'work_writable' => is_dir($parent) && is_writable($parent),
            'extensions' => ['curl' => extension_loaded('curl'), 'zip' => extension_loaded('zip'), 'json' => extension_loaded('json')],
        ];
    }

    private function assertWritableDirectories()
    {
        $status = $this->diagnostics();
        if (!$status['modules_writable']) {
            throw new \RuntimeException('Das Modules-Verzeichnis ist für den PHP-Prozess nicht beschreibbar. Eigentümer und Schreibrechte prüfen.');
        }
        if (!$status['work_writable'] || (!is_dir($this->work) && !@mkdir($this->work, 0700, true))) {
            throw new \RuntimeException('storage/app/ghinstaller ist für den PHP-Prozess nicht beschreibbar oder kann nicht angelegt werden. Eigentümer und Schreibrechte von storage/app prüfen.');
        }
    }

    private function assertAvailable(array $manifest)
    {
        $folder = $this->moduleFolder($manifest);
        if (!is_dir($this->modules) || !is_writable($this->modules) || is_link($this->modules.'/'.$folder)) {
            throw new \RuntimeException('Das Modules-Verzeichnis ist nicht beschreibbar oder das Ziel ist ein Link.');
        }
        foreach (new \DirectoryIterator($this->modules) as $directory) {
            if ($directory->isDot() || strpos($directory->getFilename(), '.ghinstaller-') === 0) {
                continue;
            }
            if (strcasecmp($directory->getFilename(), $folder) === 0) {
                throw new \RuntimeException('Ein Modul mit diesem Namen ist bereits vorhanden. Bestehende Module werden nicht überschrieben.');
            }
            $existing = $directory->getPathname().'/module.json';
            if ($directory->isDir() && is_file($existing)) {
                $data = json_decode(file_get_contents($existing), true);
                if (is_array($data) && isset($data['alias']) && is_string($data['alias']) && strcasecmp($data['alias'], $manifest['alias']) === 0) {
                    throw new \RuntimeException('Ein Modul mit diesem Alias ist bereits vorhanden.');
                }
            }
        }
    }

    private function copy($source, $target)
    {
        if (is_link($source)) {
            throw new \RuntimeException('Links im Modul sind nicht erlaubt.');
        }
        if (!mkdir($target, 0755)) {
            throw new \RuntimeException('Das Modulverzeichnis konnte nicht angelegt werden.');
        }
        foreach (new \DirectoryIterator($source) as $file) {
            if ($file->isDot()) { continue; }
            if ($file->isLink()) { throw new \RuntimeException('Links im Modul sind nicht erlaubt.'); }
            $destination = $target.'/'.$file->getFilename();
            if ($file->isDir()) {
                $this->copy($file->getPathname(), $destination);
            } elseif (!$file->isFile() || !copy($file->getPathname(), $destination) || !chmod($destination, 0644)) {
                throw new \RuntimeException('Eine Moduldatei konnte nicht kopiert werden.');
            }
        }
    }

    private function job($id)
    {
        if (!is_string($id) || !preg_match('/\A[0-9a-f]{48}\z/', $id)) {
            throw new \RuntimeException('Ungültige Installationsvorschau.');
        }
        return $this->work.'/'.$id;
    }

    public function discard($id)
    {
        $path = $this->job($id);
        if (is_dir($path) && !is_link($path)) { $this->remove($path); }
    }

    public function cleanup()
    {
        if (!is_dir($this->work) || !is_readable($this->work)) { return; }
        foreach (new \DirectoryIterator($this->work) as $file) {
            if (preg_match('/\A[0-9a-f]{48}\z/', $file->getFilename()) && $file->getMTime() < time() - self::TTL - 300) {
                $this->discard($file->getFilename());
            }
        }
    }

    private function remove($path)
    {
        foreach (new \DirectoryIterator($path) as $file) {
            if ($file->isDot()) { continue; }
            if ($file->isDir() && !$file->isLink()) { $this->remove($file->getPathname()); }
            else { unlink($file->getPathname()); }
        }
        rmdir($path);
    }
}
