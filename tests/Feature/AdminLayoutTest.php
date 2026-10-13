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
