<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('superadmin:grant {phone : Phone of an existing user, e.g. +77001234567} {--revoke : Take the superadmin rights away}')]
#[Description('Give or take platform superadmin rights (languages and translations of all clubs)')]
class GrantSuperadmin extends Command
{
    public function handle(): int
    {
        $digits = preg_replace('/\D+/', '', (string) $this->argument('phone'));
        // +7 700 …, 8 700 … and 700 … all mean the same number.
        if (strlen($digits) === 11 && in_array($digits[0], ['7', '8'], true)) {
            $digits = substr($digits, 1);
        }

        $user = User::query()->where('phone', '+7'.$digits)->first();

        if ($user === null) {
            $this->components->error('No user with this phone.');

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');
        $user->forceFill(['is_superadmin' => $grant])->save();
        Log::warning($grant ? 'Superadmin granted' : 'Superadmin revoked', ['user_id' => $user->getKey()]);
        $this->components->info(($grant ? 'Superadmin granted to ' : 'Superadmin revoked from ').$user->name.' ('.$user->phone.').');

        return self::SUCCESS;
    }
}
