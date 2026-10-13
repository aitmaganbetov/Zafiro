<?php

use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the manifest makes the app installable and follows the browser language', function () {
    app(CurrentClub::class)->clear();

    $response = $this->get(route('pwa.manifest'), ['Accept-Language' => 'kk-KZ'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json');
    $manifest = $response->json();

    expect($manifest['display'])->toBe('standalone')
        ->and($manifest['start_url'])->toBe('/dashboard')
        ->and($manifest['scope'])->toBe('/')
        ->and($manifest['lang'])->toBe('kk')
        ->and(collect($manifest['icons'])->pluck('sizes')->all())->toContain('192x192', '512x512')
        ->and(collect($manifest['icons'])->pluck('purpose')->all())->toContain('maskable')
        ->and(collect($manifest['shortcuts'])->pluck('name')->all())->toContain('Касса');

    foreach ($manifest['icons'] as $icon) {
        expect(public_path(parse_url($icon['src'], PHP_URL_PATH)))->toBeFile();
    }
});

test('the offline page is public and has no club data', function () {
    app(CurrentClub::class)->clear();

    $this->get(route('pwa.offline'))->assertOk()->assertSee('Zafiro')->assertDontSee('csrf');
});

test('every page links the manifest and the install button', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertSee(route('pwa.manifest'))
        ->assertSee('apple-touch-icon', false)
        ->assertSee('data-pwa-install', false)
        ->assertSee('data-pwa-dialog', false);
    $this->flushSession();
    auth()->logout();
    app(CurrentClub::class)->clear();
    $this->get(route('auth.show-login'))->assertSee(route('pwa.manifest'));
});

test('the service worker never caches pages or API responses', function () {
    $sw = file_get_contents(public_path('sw.js'));

    expect($sw)->toContain("request.mode === 'navigate'")
        ->toContain('fetch(request).catch(() => caches.match(OFFLINE_URL))')
        ->toContain("if (request.method !== 'GET') return;")
        ->not->toContain('/api/');
});
