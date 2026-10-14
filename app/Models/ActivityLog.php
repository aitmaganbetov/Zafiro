<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use App\Models\Concerns\UsesClubTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['event', 'description', 'user_id', 'subject_type', 'subject_id', 'details', 'ip_address', 'user_agent'])]
class ActivityLog extends Model
{
    use BelongsToClub, UsesClubTimezone;

    public const EVENT_LABELS = [
        'stock.received' => 'Оприходование',
        'stock.written_off' => 'Списание со склада',
        'product.created' => 'Создание товара',
        'product.updated' => 'Изменение товара',
        'api_key.created' => 'Создание API-ключа',
        'api_key.rotated' => 'Перевыпуск API-ключа',
        'api_key.revoked' => 'Отзыв API-ключа',
        'webhook.created' => 'Создание вебхука',
        'webhook.updated' => 'Изменение вебхука',
        'webhook.secret_rotated' => 'Перевыпуск секрета вебхука',
        'webhook.deleted' => 'Удаление вебхука',
        'webhook.retried' => 'Повторная отправка вебхука',
        'integrations.permission_changed' => 'Изменение доступа к интеграциям',
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
        'translations.imported' => 'Импорт переводов',
        'club.timezone_changed' => 'Изменение часового пояса клуба',
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
