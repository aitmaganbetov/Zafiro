<?php

namespace Tests;

use App\Models\Club;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Club pages are on the club site, as in production: a request to the cabinet host does not
        // move route() of the club panel there (cabinet routes carry their own domain).
        URL::forceRootUrl(config('app.url'));

        if (! Schema::hasTable('clubs')) {
            return;
        }

        $club = Club::query()->where('slug', 'legacy')->first();

        if ($club !== null) {
            app(CurrentClub::class)->set($club);
        }
    }
}
