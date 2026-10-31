<?php

namespace Modules\GHInstaller\Services;

class GitHubClient
{
    const MAX_ARCHIVE = 26214400;
    private $token;

    public function __construct($token = '')
    {
        $this->token = $token;
    }

    public function download($repository, $ref, $destination)
    {
        $source = $this->resolve($repository, $ref);
        $handle = fopen($destination, 'xb');
        if (!$handle) {
            throw new \RuntimeException('Das Download-Verzeichnis ist nicht beschreibbar.');
        }
        try {
            $this->request('https://codeload.github.com/'.$repository.'/zip/'.$source['commit'], self::MAX_ARCHIVE, function ($chunk) use ($handle) {
                return fwrite($handle, $chunk);
            });
        } finally {
            fclose($handle);
        }
        return $source;
    }

    public function resolve($repository, $ref)
    {
        // Validate even when the source came from an on-disk installation receipt.
        $repository = Repository::parse('https://github.com/'.$repository);
        $ref = Repository::ref($ref);
        // Resolve the requested ref once; download exactly the commit shown in the preview.
        $repo = $this->json('https://api.github.com/repos/'.$repository);
        $ref = $ref !== '' ? $ref : ($repo['default_branch'] ?? '');
        if (!$ref) {
            throw new \RuntimeException('Das Repository enthält keinen Standard-Branch.');
        }
        $commit = $this->json('https://api.github.com/repos/'.$repository.'/commits/'.rawurlencode($ref));
        $sha = $commit['sha'] ?? '';
        if (!is_string($sha) || !preg_match('/\A[0-9a-f]{40}\z/', $sha)) {
            throw new \RuntimeException('GitHub lieferte keinen gültigen Commit.');
        }
        return ['repository' => $repository, 'ref' => $ref, 'commit' => $sha];
    }

    private function json($url)
    {
        $body = '';
        $this->request($url, 1048576, function ($chunk) use (&$body) {
            $body .= $chunk;
            return strlen($chunk);
        });
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('GitHub lieferte eine ungültige Antwort.');
        }
        return $data;
    }

    private function request($url, $limit, $writer)
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('Die PHP-Erweiterung cURL fehlt.');
        }
        // No caller-controlled host, redirects, TLS overrides or shell commands.
        $host = parse_url($url, PHP_URL_HOST);
        if (!in_array($host, ['api.github.com', 'codeload.github.com'], true)) {
            throw new \RuntimeException('Nicht erlaubtes Download-Ziel.');
        }
        $headers = ['Accept: application/vnd.github+json', 'User-Agent: FreeScout-GHInstaller/0.1.0'];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer '.$this->token;
        }
        $received = 0;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$received, $limit, $writer) {
                $received += strlen($chunk);
                return $received > $limit ? 0 : $writer($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($received > $limit) {
            throw new \RuntimeException('Die GitHub-Antwort überschreitet das Größenlimit (Archiv: 25 MiB).');
        }
        if ($status === 404) {
            throw new \RuntimeException('Repository oder Referenz nicht gefunden. Bei privaten Repositories den serverseitigen GitHub-Token prüfen.');
        }
        if ($status === 403 || $status === 429) {
            throw new \RuntimeException('GitHub verweigert den Zugriff oder das API-Limit ist erreicht. Bitte Token/Rechte prüfen oder später erneut versuchen.');
        }
        if ($ok === false || $status !== 200) {
            throw new \RuntimeException('Der GitHub-Download ist fehlgeschlagen. Netzwerk, TLS-Zertifikate und Token prüfen.');
        }
    }
}
