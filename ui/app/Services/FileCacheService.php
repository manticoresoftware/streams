<?php

namespace App\Services;

use Storage;

class FileCacheService
{
    const string RULE_ADD = 'rule_add';
    const string RULE_REPLACE = 'rule_replace';
    const string RULE_DELETE = 'rule_delete';

    public static function put($key, string $value, $folder = 'file_cache'): bool
    {
        return Storage::put($folder.DIRECTORY_SEPARATOR.$key, $value);
    }

    public static function get($key, $folder = 'file_cache'): string
    {
        return Storage::get($folder.DIRECTORY_SEPARATOR.$key);
    }

    public static function getAll($folder = 'file_cache')
    {
        $result = [];

        $folders = Storage::files($folder);
        foreach ($folders as $key) {
            $fullPath     = $key;
            $key          = str_replace($folder.DIRECTORY_SEPARATOR, '', $key);
            $result[$key] = Storage::get($fullPath);
        }

        if ($result !== []) {
            return $result;
        }

        return false;
    }

    public static function release($folder = 'file_cache'): bool
    {
        $folders = Storage::files($folder);
        $removed = false;
        foreach ($folders as $key) {
            Storage::delete($key);
            $removed = true;
        }

        return $removed;
    }

    public static function increase($key, $folder = 'file_cache', $increaseStep = 1): bool
    {
        if (Storage::exists($folder.DIRECTORY_SEPARATOR.$key)) {
            $value = (int)self::get($key, $folder);
        } else {
            $value = 0;
        }

        $value += $increaseStep;

        return self::put($key, $value, $folder);
    }

}
