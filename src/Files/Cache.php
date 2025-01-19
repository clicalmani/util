<?php
namespace Clicalmani\Util\Files;

abstract class Cache
{
    /**
     * Cache directory
     * 
     * @var string
     */
    protected static $dir = '';

    /**
     * Key prefix
     * 
     * @var string
     */
    protected static string $prefix = '';

    /**
     * Sets the cache directory.
     * 
     * @param string $dir
     * @return void
     */
    public static function create(string $key, string $data, int $expiration)
    {
        $cacheFile = static::getCacheFilePath($key);
        $cacheData = [
            'data' => $data,
            'expiration' => time() + $expiration
        ];
        file_put_contents($cacheFile, serialize($cacheData));
    }

    /**
     * Set prefix
     * 
     * @param string $new_prefix
     * @return void
     */
    public static function setPrefix(string $new_prefix) : void
    {
        static::$prefix = $new_prefix;
    }

    /**
     * Set directory
     * 
     * @param string $new_dir
     * @return void
     */
    public static function setDir(string $new_dir) : void
    {
        static::$dir = $new_dir;
    }

    /**
     * Retrieves the cache file path for the given key.
     * 
     * @param string $key
     * @return string
     */
    private static function getCacheFilePath(string $key) : string
    {
        if ( !is_dir(storage_path(static::$dir)) ) {
            mkdir(storage_path(static::$dir), 0777, true);
        }
        return storage_path(static::$dir) . DIRECTORY_SEPARATOR . md5(static::$prefix.$key) . '.cache';
    }
}