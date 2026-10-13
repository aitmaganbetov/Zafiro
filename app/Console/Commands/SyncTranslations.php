<?php

namespace App\Console\Commands;

use App\Services\TranslationCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('translations:sync')]
#[Description('Add shipped translations that are missing in the database (edited texts are kept)')]
class SyncTranslations extends Command
{
    public function handle(TranslationCatalog $catalog): int
    {
        $this->components->info("Added {$catalog->sync()} translation rows.");

        return self::SUCCESS;
    }
}
