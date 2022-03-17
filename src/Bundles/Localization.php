<?php

namespace Ions\Bundles;

use Ions\Foundation\Singleton;
use Ions\Support\JsonResponse;
use Ions\Support\Storage;
use Ions\Support\Str;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

class Localization extends Singleton
{
    public static Translator $localization;

    public static function init(string $file = 'web', string $locale = 'en'): Translator
    {
        static::$localization = new Translator($locale);
        return self::fileTranslate($file, $locale);
    }

    public static function fileTranslate($file, $locale, $domain = null): Translator
    {
        static::$localization->setLocale($locale);
        static::$localization->addLoader('array', new ArrayLoader());
        if (file_exists(Path::locale($locale . '/' . $file . '.php'))) {
            static::$localization->addResource('array', include Path::locale($locale . '/' . $file . '.php'), $locale,$domain);
        }
        return static::$localization;
    }

    public static function AddfileTranslate($file, $locale,$domain): Translator
    {
        static::$localization->setLocale($locale);
        static::$localization->addLoader('array', new ArrayLoader());
        if (file_exists(Path::locale($locale . '/' . $file . '.php'))) {
            static::$localization->addResource('array', include Path::locale($locale . '/' . $file . '.php'), $locale);
        }
        return static::$localization;
    }

    public static function localeTranslate($locale): Translator
    {
        static::$localization->setLocale($locale);
        static::$localization->addLoader('array', new ArrayLoader());
        $files = Storage::allFiles(Path::locale($locale));
        foreach ($files as $file) {
            $domain_name = Str::remove('.php', $file->getFileName());
            static::$localization->addResource('array', include $file->getPathName(), $locale, $domain_name);
        }

        return static::$localization;
    }

    public static function localeJson($locale): bool|string
    {
        return (new JsonResponse(
            static::$localization->getCatalogue(
                $locale
            )->all('messages')
        ))->getContent();
    }
}