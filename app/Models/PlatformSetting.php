<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A platform-wide setting set in the superadmin cabinet. Read on every panel page (for
 * example by the agent update notice), so values are cached until they change.
 */
class PlatformSetting extends Model
{
    public const AGENT_RELEASE = 'agent_release';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /** @return array<string, mixed>|null */
    public static function read(string $key): ?array
    {
        return Cache::rememberForever(self::cacheKey($key), fn (): ?array => static::query()->find($key)?->value);
    }

    /** @param  array<string, mixed>|null  $value  null removes the setting */
    public static function write(string $key, ?array $value, ?User $by): void
    {
        if ($value === null) {
            static::query()->whereKey($key)->delete();
        } else {
            $setting = static::query()->findOrNew($key);
            $setting->forceFill(['key' => $key, 'value' => $value, 'updated_by' => $by?->getKey()])->save();
        }

        Cache::forget(self::cacheKey($key));
    }

    private static function cacheKey(string $key): string
    {
        return 'platform-setting:'.$key;
    }
}
