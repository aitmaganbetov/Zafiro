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
        'product.updated' => 'Изменение товара',
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
        'auth.login_failed' => 'Неудачный вход',
        'auth.api_login_failed' => 'Неудачный вход через API',
        'invitation.created' => 'Создание приглашения',
        'invitation.accepted' => 'Принятие приглашения',
        'club.switched' => 'Переключение клуба',
        'settings.updated' => 'Изменение настроек',
        'zone.created' => 'Создание зоны',
        'zone.updated' => 'Изменение зоны',
        'zone.deleted' => 'Удаление зоны',
        'computer.created' => 'Добавление ПК',
        'computer.registered' => 'Регистрация ПК агентом',
        'computer.controlled' => 'Управление ПК',
        'cash.z_report_printed' => 'Печать Z-отчёта',
        'club.language_changed' => 'Смена языка клуба',
        'language.created' => 'Добавление языка',
        'language.updated' => 'Изменение языка',
        'language.deleted' => 'Удаление языка',
        'translations.updated' => 'Изменение переводов',
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
