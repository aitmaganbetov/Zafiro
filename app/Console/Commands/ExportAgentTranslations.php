<?php

namespace App\Console\Commands;

use App\Services\TranslationCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agent:translations')]
#[Description('Copy the agent texts (translations group "agent") into WindowsAgent/Frontend/i18n')]
class ExportAgentTranslations extends Command
{
    /** Built-in languages shipped with the agent; others reach the PC through the API. */
    public const LANGUAGES = ['ru' => 'Русский', 'kk' => 'Қазақша', 'en' => 'English'];

    public function handle(TranslationCatalog $catalog): int
    {
        $directory = base_path('WindowsAgent/Frontend/i18n');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        foreach (self::LANGUAGES as $code => $name) {
            file_put_contents("{$directory}/{$code}.json", self::json($code, $name, $catalog->shippedGroup($code, 'agent')));
        }

        $this->components->info('Agent texts written to WindowsAgent/Frontend/i18n.');

        return self::SUCCESS;
    }

    /** @param  array<string, string>  $lines */
    public static function json(string $code, string $name, array $lines): string
    {
        return json_encode(['locale' => $code, 'name' => $name, 'lines' => (object) $lines], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    }
}
