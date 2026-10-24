<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the sidebar can be toggled and closed from the admin layout', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('id="sidebarToggle"', false)
        ->assertSee('aria-controls="adminSidebar"', false)
        ->assertSee('data-sidebar-close', false)
        ->assertSee('zafiro.sidebarCollapsed', false);
});

test('the top bar switches the day and night theme, chosen before the styles load', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertOk()
        ->assertSeeInOrder(["localStorage.getItem('zafiro.theme')==='dark'", 'build/assets/app-'], false)
        ->assertSee('data-theme-toggle aria-pressed="false" aria-label="Ночная тема"', false)
        ->assertSee('data-label-light="Дневная тема"', false);
});
