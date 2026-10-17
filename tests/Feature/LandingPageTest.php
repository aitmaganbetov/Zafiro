<?php

use App\Http\Controllers\LandingController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the home page presents Zafiro with sign-up for guests and the panel for signed-in users', function () {
    $this->get(route('home'))->assertOk()
        ->assertSee('Весь клуб — в одном окне')
        ->assertSee(route('auth.show-register'), false)
        ->assertSee(LandingController::AGENT_DOWNLOAD, false);

    $this->actingAs(User::factory()->create())->get(route('home'))->assertOk()
        ->assertSee(route('dashboard'), false)
        ->assertSee('Открыть панель');
});
