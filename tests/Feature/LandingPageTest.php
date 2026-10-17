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

test('the wiki opens at /wiki', function () {
    $this->get(route('wiki'))->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8');

    expect(file_get_contents(resource_path('wiki/index.html')))->toContain('<title>Zafiro Wiki</title>')->toContain('Быстрый старт');
});
