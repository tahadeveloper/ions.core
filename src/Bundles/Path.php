<?php

namespace Ions\Bundles;

use Ions\Foundation\Singleton;

class Path extends Singleton
{
    protected static string $environmentPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR;

    /**
     * config folder in app folder
     *
     * @param string $file
     * @return string
     */
    public static function config(string $file = ''): string
    {
        return realpath(self::$environmentPath) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * src folder
     *
     * @param string $file
     * @return string
     */
    public static function src(string $file): string
    {
        return realpath(self::$environmentPath) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * views folder
     *
     * @param string $file
     * @return string
     */
    public static function views(string $file): string
    {
        return realpath(self::$environmentPath) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * src folder
     *
     * @param string $file
     * @return string
     */
    public static function api(string $file = ''): string
    {
        return realpath(self::$environmentPath) . DIRECTORY_SEPARATOR . '/src/Http/Api' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * core folder
     *
     * @param string $file
     * @return string
     */
    public static function core(string $file): string
    {
        return realpath(__DIR__ . DIRECTORY_SEPARATOR . '..') . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * var folder
     *
     * @param string $file
     * @return string
     */
    public static function var(string $file): string
    {
        return realpath(self::$environmentPath) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * Routes folder
     *
     * @param string $file
     * @return string
     */
    public static function route(string $file = ''): string
    {
        return realpath(self::$environmentPath) . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * root is base of project
     *
     * @param string $file
     * @param bool $url
     * @return string
     */
    public static function root(string $file, bool $url = false): string
    {
        if ($url) {
            return env('APP_URL') . '/' . $file;
        }
        return realpath(self::$environmentPath) . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * root folder is base of project
     *
     * @param string $file
     * @param string|null $folder_name
     * @return string
     */
    public static function rootFolder(string $file, string $folder_name = null): string
    {
        if ($folder_name) {
            return env('APP_URL') . '/' . $folder_name . '/' . $file;
        }
        return env('APP_URL') . '/' . $file;
    }

    /**
     * cache folder in var folder
     *
     * @param string $file
     * @return string
     */
    public static function cache(string $file): string
    {
        return realpath(self::$environmentPath) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * assets folder in web folder
     *
     * @param string $file
     * @param string $folder_name
     * @return string
     */
    public static function assets(string $file, string $folder_name = 'default'): string
    {
        return env('APP_URL') . '/' . 'views' . '/' . $folder_name . '/' . $file;
    }

    /**
     * public folder
     *
     * @param string $file
     * @return string
     */
    public static function public(string $file = '', bool $url = false): string
    {
        if ($url) {
            return env('APP_URL') . '/' . 'public' . '/' . $file;
        }
        return realpath(self::$environmentPath) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * lang folder
     *
     * @param string $file
     * @return string
     */
    public static function locale(string $file = ''): string
    {
        return realpath(self::$environmentPath) . DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * logs folder in var folder
     *
     * @param string $file
     * @return string
     */
    public static function logs(string $file): string
    {
        return realpath(self::$environmentPath) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * files folder in var folder
     *
     * @param string $file
     * @param bool $url
     * @return string
     */
    public static function files(string $file, bool $url = false, string $mainFolder = ''): string
    {
        if (env('FILESYSTEM_DISK', 'local') === 's3' && env('AWS_BASE_PATH')) {
            $mainFolder = env('AWS_BASE_PATH');
            $file = $mainFolder . '/' . $file;
        }
        if (env('FILESYSTEM_DISK', 'local') === 's3') {
            if ($url) {
                // get cdn url if available
                $options = [];
                if (env('AWS_CDN_URL')) {
                    $options['cdn_base_url'] = env('AWS_CDN_URL');
                }
                return IonDisk::getUrl($file, collect($options));
            }
            return $file;
        }
        if ($url) {
            return env('APP_URL') . '/' . 'public' . '/' . 'uploads' . '/' . $file;
        }
        return realpath(self::$environmentPath) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $file;
    }

    public static function filesRoot(string $file, bool $url = false, string $mainFolder = '', string $bucket = ''): string
    {
        if (env('FILESYSTEM_DISK', 'local') === 's3' && $mainFolder) {
            $file = $mainFolder . '/' . $file;
        }
        if (env('FILESYSTEM_DISK', 'local') === 's3') {
            if ($url) {
                return IonDisk::getSignedUrl($file);
            }
            return IonDisk::getSignedUrl($file);
        }
        if ($url) {
            return env('APP_URL') . '/' . $file;
        }
        return realpath(self::$environmentPath) . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * database folder
     *
     * @param string $file
     * @return string
     */
    public static function database(string $file): string
    {
        return realpath(self::$environmentPath) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * bin folder
     *
     * @param string $file
     * @return string
     */
    public static function bin(string $file): string
    {
        return realpath(__DIR__ . DIRECTORY_SEPARATOR . '..') . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * auth folder
     *
     * @param string $file
     * @return string
     */
    public static function auth(string $file): string
    {
        return realpath(__DIR__ . DIRECTORY_SEPARATOR . '..') . DIRECTORY_SEPARATOR . 'Auth' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * templates folder in var folder
     *
     * @param string $file
     * @return string
     */
    public static function templates(string $file): string
    {
        return realpath(self::$environmentPath) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $file;
    }
}
