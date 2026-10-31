<?php

namespace Modules\GHInstaller\Services;

class Archive
{
    const MAX_FILES = 5000;
    const MAX_BYTES = 104857600;

    public function unpack($archive, $destination)
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('Die PHP-Erweiterung ZIP fehlt.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new \RuntimeException('Das Repository-Archiv ist keine gültige ZIP-Datei.');
        }
        try {
            if ($zip->numFiles > self::MAX_FILES) {
                throw new \RuntimeException('Das Archiv enthält zu viele Dateien (maximal 5000).');
            }
            $entries = [];
            $seen = [];
            $total = 0;
            // Validate every entry before writing any repository content.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                $this->validatePath($name);
                $key = strtolower(rtrim($name, '/'));
                if (isset($seen[$key])) {
                    throw new \RuntimeException('Das Archiv enthält doppelte Dateipfade.');
                }
                $seen[$key] = true;
                $opsys = 0;
                $attributes = 0;
                $zip->getExternalAttributesIndex($i, $opsys, $attributes);
                $type = ($attributes >> 16) & 0170000;
                if ($type !== 0 && $type !== 0100000 && $type !== 0040000) {
                    throw new \RuntimeException('Links und Spezialdateien im Archiv sind nicht erlaubt.');
                }
                $total += $stat['size'];
                if ($stat['size'] < 0 || $total > self::MAX_BYTES) {
                    throw new \RuntimeException('Das entpackte Archiv überschreitet 100 MiB.');
                }
                $entries[] = $stat;
            }
            if (!mkdir($destination, 0700)) {
                throw new \RuntimeException('Das Arbeitsverzeichnis konnte nicht angelegt werden.');
            }
            foreach ($entries as $stat) {
                $name = $stat['name'];
                $target = $destination.'/'.$name;
                if (substr($name, -1) === '/') {
                    $this->directory($target);
                    continue;
                }
                $this->directory(dirname($target));
                $source = $zip->getStream($name);
                $output = fopen($target, 'xb');
                if (!$source || !$output) {
                    if (is_resource($source)) { fclose($source); }
                    if (is_resource($output)) { fclose($output); }
                    throw new \RuntimeException('Eine Archivdatei konnte nicht geöffnet werden.');
                }
                try {
                    $copied = stream_copy_to_stream($source, $output, $stat['size'] + 1);
                    if ($copied !== $stat['size']) {
                        throw new \RuntimeException('Eine Archivdatei hat eine ungültige Größe.');
                    }
                } finally {
                    fclose($source);
                    fclose($output);
                }
                chmod($target, 0600);
            }
        } finally {
            $zip->close();
        }
    }

    private function validatePath($name)
    {
        if ($name === '' || strlen($name) > 1000 || preg_match('~[\\\\:\x00-\x1f\x7f]~', $name) || $name[0] === '/') {
            throw new \RuntimeException('Unsicherer Dateipfad im Archiv.');
        }
        foreach (explode('/', rtrim($name, '/')) as $part) {
            // Also reject Windows device names, trailing dots/spaces and alternate streams.
            if ($part === '' || $part === '.' || $part === '..' || preg_match('/[. ]$/', $part)
                || preg_match('/\A(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $part)) {
                throw new \RuntimeException('Unsicherer Dateipfad im Archiv.');
            }
        }
    }

    private function directory($path)
    {
        if (!is_dir($path) && !mkdir($path, 0700, true)) {
            throw new \RuntimeException('Ein Archivverzeichnis konnte nicht angelegt werden.');
        }
    }
}
