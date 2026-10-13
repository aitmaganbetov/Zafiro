<?php

namespace Tests;

use App\Models\Club;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('clubs')) {
            return;
        }

        $club = Club::query()->where('slug', 'legacy')->first();

        if ($club !== null) {
            app(CurrentClub::class)->set($club);
        }
    }
}
