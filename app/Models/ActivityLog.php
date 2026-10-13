<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['event', 'description', 'user_id', 'subject_type', 'subject_id', 'details', 'ip_address', 'user_agent'])]
class ActivityLog extends Model
{
    use BelongsToClub;

    public const EVENT_LABELS = [
        'stock.received' => 'Оприходование',
        'stock.written_off' => 'Списание со склада',
        'product.created' => 'Создание товара',
        'warehouse.category_created' => 'Создание категории склада',
        'product.purchased' => 'Покупка товаров',
        'cash.income' => 'Пополнение кассы',
        'cash.expense' => 'Расход из кассы',
        'cash.shift_opened' => 'Открытие смены',
        'cash.shift_closed' => 'Закрытие смены',
        'tariff.created' => 'Создание тарифа',
        'tariff.purchased' => 'Покупка тарифа',
        'auth.registered' => 'Регистрация',
        'auth.login' => 'Вход в систему',
        'auth.logout' => 'Выход из системы',
        'auth.api_login' => 'Вход через API',
        'auth.api_logout' => 'Выход через API',
        'user.created' => 'Создание пользователя',
        'user.balance_topped_up' => 'Пополнение баланса пользователя',
    ];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
