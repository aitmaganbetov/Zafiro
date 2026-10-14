<?php

use App\Models\ClubMembership;
use App\Models\Language;
use App\Models\PlatformLog;
use App\Models\Translation;
use App\Models\User;
use App\Services\TranslationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

/** @return list<list<string>> */
function csvRows(string $csv): array
{
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);

    return array_map(fn (string $line) => str_getcsv($line, ';', '"', ''), array_values(array_filter(explode("\n", $csv))));
}

function csvFile(array $rows, string $delimiter = ';'): UploadedFile
{
    $out = fopen('php://temp', 'r+');
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($rows as $row) {
        fputcsv($out, $row, $delimiter, '"', '');
    }
    rewind($out);

    return UploadedFile::fake()->createWithContent('translations.csv', stream_get_contents($out));
}

function exportFor(string $code, bool $missing = false): array
{
    $response = test()->get(route('languages.export', ['language' => $code, 'missing' => $missing ? 1 : null]))->assertOk();

    return csvRows($response->streamedContent());
}

test('a language exports as CSV with source, English hint and its translations', function () {
    $this->actingAs(User::factory()->superadmin()->create());

    $response = $this->get(route('languages.export', ['language' => 'kk']))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload('zafiro-translations-kk-'.now()->format('Y-m-d').'.csv');
    $rows = csvRows($response->streamedContent());
    $warehouse = collect($rows)->first(fn (array $row) => $row[2] === 'Склад');

    expect(str_starts_with($response->streamedContent(), "\xEF\xBB\xBF"))->toBeTrue()
        ->and($rows[0])->toBe(['id', 'group', 'ru', 'en', 'kk'])
        ->and($warehouse)->toBe([Translation::hashKey('*', 'Склад'), '*', 'Склад', 'Warehouse', 'Қойма'])
        ->and(count($rows) - 1)->toBe(app(TranslationCatalog::class)->sourceKeyCount());
});

test('only untranslated texts can be exported', function () {
    $this->actingAs(User::factory()->superadmin()->create());
    $this->post(route('languages.store'), ['code' => 'uz', 'name' => 'Oʻzbekcha']);
    $uz = Language::query()->where('code', 'uz')->sole();
    Translation::query()->create(['language_id' => $uz->id, 'group' => '*', 'key' => 'Склад', 'key_hash' => Translation::hashKey('*', 'Склад'), 'value' => 'Ombor']);

    $rows = exportFor('uz', missing: true);

    expect(collect($rows)->pluck(2))->not->toContain('Склад')
        ->and(count($rows) - 1)->toBe(app(TranslationCatalog::class)->sourceKeyCount() - 1);
});

test('an edited export imports back: filled cells update, empty cells keep translations', function () {
    $owner = User::factory()->superadmin()->create();
    $this->actingAs($owner);
    $kk = Language::query()->where('code', 'kk')->sole();
    $rows = exportFor('kk');
    $before = Translation::query()->where('language_id', $kk->id)->count();

    foreach ($rows as $i => $row) {
        if ($row[2] === 'Склад') {
            $rows[$i][4] = 'Қойма (CSV)';
        } elseif ($row[2] === 'Касса') {
            $rows[$i][4] = ''; // translator left it empty
        }
    }
    $rows[] = ['not-a-real-id', '*', 'x', 'x', 'y'];

    $this->post(route('languages.import'), ['language' => 'kk', 'file' => csvFile($rows)])
        ->assertRedirect()
        ->assertSessionHas('status', fn (string $status) => str_contains($status, '1'));

    expect(Translation::query()->where('language_id', $kk->id)->where('key', 'Склад')->value('value'))->toBe('Қойма (CSV)')
        ->and(Translation::query()->where('language_id', $kk->id)->where('key', 'Касса')->exists())->toBeTrue()
        ->and(Translation::query()->where('language_id', $kk->id)->count())->toBe($before)
        ->and(PlatformLog::query()->where('event', 'translations.imported')->sole()->details)->toMatchArray(['changed' => 1, 'empty' => 1, 'unknown' => 1]);
    $this->get(route('dashboard'))->assertOk();
});

test('a new language is translated entirely through a file', function () {
    $this->actingAs(User::factory()->superadmin()->create());
    $this->post(route('languages.store'), ['code' => 'uz', 'name' => 'Oʻzbekcha']);
    $rows = exportFor('uz');
    $rows = array_map(fn (array $row) => $row[2] === 'Склад' ? [...array_slice($row, 0, 4), 'Ombor'] : $row, $rows);

    $this->post(route('languages.import'), ['language' => 'uz', 'file' => csvFile($rows, ',')])->assertRedirect();

    $uz = Language::query()->where('code', 'uz')->sole();
    expect(Translation::query()->where('language_id', $uz->id)->pluck('value', 'key')->all())->toBe(['Склад' => 'Ombor']);
});

test('rows with broken placeholders are skipped and listed', function () {
    $this->actingAs(User::factory()->superadmin()->create());
    $source = 'Создан товар: :product';
    $row = [Translation::hashKey('*', $source), '*', $source, '', 'Тауар жасалды: :тауар'];

    $this->post(route('languages.import'), ['language' => 'kk', 'file' => csvFile([['id', 'group', 'ru', 'en', 'kk'], $row])])
        ->assertRedirect()
        ->assertSessionHas('import_invalid_count', 1)
        ->assertSessionHas('import_invalid', [$source]);

    expect(Translation::query()->where('key', $source)->whereHas('language', fn ($q) => $q->where('code', 'kk'))->value('value'))->not->toBe('Тауар жасалды: :тауар');
});

test('a file for another language or without the header is refused', function (array $header, string $message) {
    $this->actingAs(User::factory()->superadmin()->create());

    $this->from(route('settings.languages'))
        ->post(route('languages.import'), ['language' => 'kk', 'file' => csvFile([$header, ['x', '*', 'Склад', '', 'Ombor']])])
        ->assertRedirect(route('settings.languages'))
        ->assertSessionHasErrors(['file' => $message]);
})->with([
    'other language' => [['id', 'group', 'ru', 'en', 'en'], 'Файл выгружен для языка «en», а импорт идёт в «kk».'],
    'no header' => [['a', 'b', 'c', 'd', 'e'], 'Это не файл переводов Zafiro: нужна первая строка «id;group;ru;en;код языка».'],
]);

test('cells that spreadsheets would run as formulas are neutralised and restored', function () {
    $this->actingAs(User::factory()->superadmin()->create());
    $kk = Language::query()->where('code', 'kk')->sole();
    $key = 'Склад';
    Translation::query()->where('language_id', $kk->id)->where('key', $key)->update(['value' => '=HYPERLINK("http://evil")']);

    $row = collect(exportFor('kk'))->first(fn (array $r) => $r[2] === $key);
    expect($row[4])->toBe('\'=HYPERLINK("http://evil")');

    $this->post(route('languages.import'), ['language' => 'kk', 'file' => csvFile([['id', 'group', 'ru', 'en', 'kk'], [$row[0], '*', $key, '', '\'-Қойма']])]);
    expect(Translation::query()->where('language_id', $kk->id)->where('key', $key)->value('value'))->toBe('-Қойма');
});

test('club roles cannot export or import translations', function (string $role) {
    $user = User::factory()->create();
    ClubMembership::query()->where('user_id', $user->id)->update(['role' => $role]);

    $this->actingAs($user)->get(route('languages.export', ['language' => 'kk']))->assertForbidden();
    $this->actingAs($user)->post(route('languages.import'), ['language' => 'kk', 'file' => csvFile([['id', 'group', 'ru', 'en', 'kk']])])->assertForbidden();
})->with(['owner', 'admin', 'manager', 'cashier']);
