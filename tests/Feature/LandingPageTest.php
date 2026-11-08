<?php

use App\Http\Controllers\LandingController;
use App\Models\SaasPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the home page presents Zafiro with sign-up for guests and the panel for signed-in users', function () {
    $this->get(route('home'))->assertOk()
        ->assertSee('Весь клуб - в одном окне')
        ->assertSee(route('auth.show-register'), false)
        ->assertSee(LandingController::AGENT_DOWNLOAD, false)
        ->assertSee(route('wiki'), false);

    $this->actingAs(User::factory()->create())->get(route('home'))->assertOk()
        ->assertSee(route('dashboard'), false)
        ->assertSee('Открыть панель');
});

test('the header menus lead to the features, agent and app pages', function () {
    $this->get(route('home'))->assertOk()
        ->assertSee('О продукте')
        ->assertSee(route('product.features'), false)
        ->assertSee(route('product.agent'), false);

    $this->get(route('product.features'))->assertOk()
        ->assertSee('Возможности Zafiro')
        ->assertSee('Касса и деньги')
        ->assertSee('Клиенты и лояльность')
        ->assertSee(route('product.agent'), false)
        ->assertSee(route('home').'#faq', false);

    $this->get(route('product.agent'))->assertOk()
        ->assertSee('Агент Zafiro на ПК')
        ->assertSee('Обойти оплату не получится')
        ->assertSee(LandingController::AGENT_DOWNLOAD, false)
        ->assertSee(route('auth.show-register'), false);

    $this->get(route('home'))->assertSee('Продукты')
        ->assertSee(route('product.guest-app'), false)
        ->assertSee(route('product.staff-app'), false);
    $this->get(route('product.guest-app'))->assertOk()->assertSee('Приложение для гостей')->assertSee('Вход на ПК по QR-коду');
    $this->get(route('product.staff-app'))->assertOk()->assertSee('Приложение для сотрудников')->assertSee('Права как в панели');

    $this->actingAs(User::factory()->create())->get(route('product.agent'))->assertOk()->assertSee(route('dashboard'), false);
});

test('the pricing page lists the plans open for subscription', function () {
    SaasPlan::query()->create(['slug' => 'club', 'name' => 'Клуб до 30 ПК', 'price_monthly' => 25000, 'trial_days' => 0, 'features' => ['До 30 ПК'], 'is_active' => true]);
    SaasPlan::query()->create(['slug' => 'old', 'name' => 'Старый тариф', 'price_monthly' => 1000, 'trial_days' => 0, 'is_active' => false]);

    $this->get(route('home'))->assertSee(route('pricing'), false);
    $this->get(route('pricing'))->assertOk()
        ->assertSee('Клуб до 30 ПК')
        ->assertSee('25 000 ₸', false)
        ->assertSee('за ПК в месяц')
        ->assertSee('До 30 ПК')
        ->assertDontSee('Старый тариф')
        ->assertSee(route('auth.show-register'), false);
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
