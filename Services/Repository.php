<?php

namespace Modules\GHInstaller\Services;

class Repository
{
    public static function parse($url)
    {
        if (!is_string($url) || !preg_match('~\Ahttps://github\.com/([A-Za-z0-9](?:[A-Za-z0-9-]{0,38}))/([A-Za-z0-9_.-]{1,100})(?:/)?\z~i', trim($url), $match)) {
            throw new \RuntimeException('Bitte eine Repository-URL wie https://github.com/organisation/modul eingeben.');
        }
        $repo = preg_replace('/\.git$/i', '', $match[2]);
        if ($repo === '' || $repo === '.' || $repo === '..') {
            throw new \RuntimeException('Ungültiger Repository-Name.');
        }
        return $match[1].'/'.$repo;
    }

    public static function ref($value)
    {
        if (!is_string($value) || strlen($value) > 200 || preg_match('/[\x00-\x20\x7f?#]/', $value)) {
            throw new \RuntimeException('Ungültiger Branch, Tag oder Commit.');
        }
        return $value;
    }
}
