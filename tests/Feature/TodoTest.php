<?php

use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\TodoTask;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function todoUser(string $role, string $name): User
{
    $user = User::factory()->create(['name' => $name]);
    ClubMembership::query()->where('user_id', $user->id)->update(['role' => $role]);

    return $user;
}

test('the manager writes tasks for a day; a cashier marks one done and the operator sees it done', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-16 08:00:00', 'UTC'));
    Club::query()->findOrFail(app(CurrentClub::class)->id())->forceFill(['timezone' => 'UTC'])->save();
    $manager = todoUser('manager', 'Менеджер');
    $cashier = todoUser('cashier', 'Айгерим');
    $operator = todoUser('operator', 'Тимур');

    $this->actingAs($manager)->post(route('todos.store'), ['title' => 'Протереть ПК в зоне VIP', 'due_on' => '2026-10-16'])->assertSessionHasNoErrors();
    $this->post(route('todos.store'), ['title' => 'Вчерашнее', 'due_on' => '2026-10-15'])->assertSessionHasErrors('due_on');
    $task = TodoTask::query()->sole();

    $this->actingAs($cashier)->get(route('todos.index'))->assertOk()->assertSee('Протереть ПК в зоне VIP')->assertDontSee('Добавить задачу');
    $this->post(route('todos.store'), ['title' => 'x', 'due_on' => '2026-10-16'])->assertForbidden();
    $this->post(route('todos.toggle', $task), ['done' => 1])->assertRedirect();

    $this->actingAs($operator)->getJson(route('todos.state'))->assertOk()
        ->assertJsonPath('tasks.0.done', true)
        ->assertJsonPath('tasks.0.done_by', 'Айгерим');
    $this->get(route('todos.index'))->assertSee('Выполнил(а) Айгерим');

    $this->post(route('todos.toggle', $task), ['done' => 0]);
    expect($task->fresh()->done_at)->toBeNull();
});

test('undone tasks of earlier days stay on today as overdue; done ones do not', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-16 08:00:00', 'UTC'));
    Club::query()->findOrFail(app(CurrentClub::class)->id())->forceFill(['timezone' => 'UTC'])->save();
    $cashier = todoUser('cashier', 'Касса');
    TodoTask::query()->forceCreate(['title' => 'Старая открытая', 'due_on' => '2026-10-14']);
    TodoTask::query()->forceCreate(['title' => 'Старая закрытая', 'due_on' => '2026-10-14', 'done_at' => now()]);
    TodoTask::query()->forceCreate(['title' => 'Завтрашняя', 'due_on' => '2026-10-17']);

    $this->actingAs($cashier)->get(route('todos.index'))->assertOk()
        ->assertSee('Старая открытая')->assertSee('Просрочено с 14.10')
        ->assertDontSee('Старая закрытая')->assertDontSee('Завтрашняя');
    $this->get(route('todos.index', ['date' => '2026-10-17']))->assertSee('Завтрашняя')->assertDontSee('Старая открытая');
});
