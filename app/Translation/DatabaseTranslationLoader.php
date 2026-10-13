<?php

namespace App\Translation;

use App\Services\TranslationCatalog;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Arr;

/**
 * Layers database translations over Laravel's file translations (e.g. the
 * framework's validation messages). Database rows win.
 */
class DatabaseTranslationLoader implements Loader
{
    public function __construct(private Loader $files, private TranslationCatalog $catalog) {}

    public function load($locale, $group, $namespace = null)
    {
        $fileLines = $this->files->load($locale, $group, $namespace);

        if ($namespace !== null && $namespace !== '*') {
            return $fileLines;
        }

        $dbLines = $this->catalog->lines($locale, $group);

        if ($group === '*') {
            // Laravel does not fall back to another locale for JSON keys: put the
            // fallback language underneath so untranslated phrases show in English.
            $fallback = (string) config('app.fallback_locale');
            $fallbackLines = $locale === $fallback ? [] : $this->catalog->lines($fallback, '*');

            return array_merge($fileLines, $fallbackLines, $dbLines);
        }

        return array_replace_recursive($fileLines, Arr::undot($dbLines));
    }

    public function addNamespace($namespace, $hint)
    {
        $this->files->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->files->addJsonPath($path);
    }

    public function namespaces()
    {
        return $this->files->namespaces();
    }
}
