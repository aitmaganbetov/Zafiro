<?php

namespace App\Services;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** The club's "Guests" settings: what customers may do themselves in the PC agent. */
class GuestFeatures
{
    public const SETTINGS = [
        'qr_login' => 'show_qr_code_on_login',
        'self_transfer' => 'allow_guest_self_transfer',
        'friends' => 'allow_adding_friends',
        'balance_transfer' => 'allow_balance_transfer_to_friends',
    ];

    public function __construct(private CurrentClub $currentClub) {}

    public function enabled(string $feature): bool
    {
        return filter_var($this->currentClub->settings()['guests'][self::SETTINGS[$feature]] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array<string, bool> feature => enabled, as the agent reads it */
    public function all(): array
    {
        return array_map(fn (string $setting): bool => $this->enabled(array_search($setting, self::SETTINGS, true)), self::SETTINGS);
    }

    /** Stops the request when the club has turned the feature off. */
    public function ensure(string $feature): void
    {
        if (! $this->enabled($feature)) {
            throw new HttpException(403, __('Эта возможность отключена в клубе.'));
        }
    }
}
