<?php

use App\Http\Controllers\LandingController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the home page presents Zafiro with sign-up for guests and the panel for signed-in users', function () {
    $this->get(route('home'))->assertOk()
        ->assertSee('Весь клуб — в одном окне')
        ->assertSee(route('auth.show-register'), false)
        ->assertSee(LandingController::AGENT_DOWNLOAD, false)
        ->assertSee(route('wiki'), false);

    $this->actingAs(User::factory()->create())->get(route('home'))->assertOk()
        ->assertSee(route('dashboard'), false)
        ->assertSee('Открыть панель');
});

test('the wiki opens on its own host; the old /wiki and other pages there lead to it', function () {
    $wiki = 'http://'.config('app.wiki_domain');
    expect(route('wiki'))->toBe($wiki);

    $this->get($wiki.'/')->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8');
    $this->get('/wiki')->assertStatus(301)->assertRedirect($wiki);
    $this->get($wiki.'/login')->assertRedirect($wiki);
    $this->get('/')->assertOk()->assertSee($wiki, false);

    expect(file_get_contents(resource_path('wiki/index.html')))->toContain('<title>Zafiro Wiki</title>')->toContain('Быстрый старт');
});
