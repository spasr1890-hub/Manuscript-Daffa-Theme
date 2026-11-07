<?php

/**
 * @file plugins/themes/manuscriptDaffa/classes/MsdCache.inc.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class MsdCache
 *
 * @brief A small file cache with a lifetime, in the OJS cache directory
 *  (cache/manuscriptDaffa/). OJS 3.3 has no cache with expiry for plugins.
 *  Values are arrays or scalars; failures fall back to computing the value.
 */

class MsdCache
{
    /**
     * The cached value of $key, or the result of $callback (then stored).
     */
    public static function remember(string $key, int $ttl, callable $callback)
    {
        $file = self::file($key);
        if ($file && is_file($file) && filemtime($file) + $ttl > time()) {
            $data = @unserialize((string) @file_get_contents($file), ['allowed_classes' => false]);
            if (is_array($data) && array_key_exists('v', $data)) {
                return $data['v'];
            }
        }
        $value = $callback();
        self::put($key, $value);
        return $value;
    }

    /**
     * Store a value (replacing the cached one).
     */
    public static function put(string $key, $value): void
    {
        $file = self::file($key);
        if (!$file) {
            return;
        }
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, serialize(['v' => $value])) !== false) {
            @rename($tmp, $file);
        }
    }

    /**
     * Cache file of a key, or null when the directory can not be used.
     */
    protected static function file(string $key): ?string
    {
        static $dir = null;
        if ($dir === null) {
            $base = class_exists('Core') ? Core::getBaseDir() . '/cache' : sys_get_temp_dir();
            $dir = $base . '/manuscriptDaffa';
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                $dir = false;
            }
        }
        return $dir ? $dir . '/' . md5($key) . '.cache' : null;
    }
}
