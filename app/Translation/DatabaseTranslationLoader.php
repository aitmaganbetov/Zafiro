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

        // One cached pack per locale; groups that are not stored in the database (e.g.
        // a missing phrase that Laravel retries as "group.item") never reach MySQL.
        $dbLines = $this->catalog->lines($locale, $group);

        if ($dbLines === null) {
            return $fileLines;
        }

        // For JSON phrases the pack already contains the English fallback underneath.
        return $group === '*'
            ? array_merge($fileLines, $dbLines)
            : array_replace_recursive($fileLines, Arr::undot($dbLines));
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
