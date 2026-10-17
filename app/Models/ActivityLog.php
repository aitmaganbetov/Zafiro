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
        'time.purchased' => 'Покупка времени поминутно',
        'todo.created' => 'Новая задача',
        'todo.done' => 'Задача выполнена',
        'todo.reopened' => 'Задача снова не выполнена',
        'todo.deleted' => 'Удаление задачи',
        'worker.added' => 'Добавление работника',
        'worker.role_changed' => 'Изменение роли работника',
        'worker.removed' => 'Удаление работника',
        'news.created' => 'Добавление новости',
        'news.updated' => 'Изменение новости',
        'news.deleted' => 'Удаление новости',
        'auth.registered' => 'Регистрация',
        'auth.login' => 'Вход в систему',
        'auth.logout' => 'Выход из системы',
        'auth.api_login' => 'Вход через API',
        'auth.api_logout' => 'Выход через API',
        'user.created' => 'Создание пользователя',
        'user.balance_topped_up' => 'Пополнение баланса пользователя',
        'user.updated' => 'Изменение клиента',
        'user.registered' => 'Регистрация клиента на ПК',
        'user.password_reset' => 'Сброс пароля клиента',
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
        'computer.guest_session' => 'Гостевая сессия с кассы',
        'cash.z_report_printed' => 'Печать Z-отчёта',
        'club.language_changed' => 'Смена языка клуба',
        'language.created' => 'Добавление языка',
        'language.updated' => 'Изменение языка',
        'language.deleted' => 'Удаление языка',
        'translations.updated' => 'Изменение переводов',
        'translations.imported' => 'Импорт переводов',
        'club.timezone_changed' => 'Изменение часового пояса клуба',
        'computer.session_ended' => 'Досрочное завершение сессии ПК',
        'club.agent_pin_changed' => 'Изменение PIN-кода агента',
        'club.agent_staff_time_changed' => 'Изменение времени обслуживания ПК',
        'club.logo_changed' => 'Изменение логотипа клуба',
        'agent.staff_exit' => 'Выход из агента по PIN-коду',
        'agent.staff_signin' => 'Вход сотрудника на ПК',
        'agent.exit_pin_failed' => 'Неверный PIN-код агента',
        'reservation.created' => 'Бронирование ПК',
        'reservation.cancelled' => 'Отмена брони ПК',
        'reservation.arrived' => 'Клиент пришёл по брони',
        'auth.qr_login' => 'Вход на ПК по QR-коду',
        'session.transferred' => 'Пересадка гостя на другой ПК',
        'friend.requested' => 'Заявка в друзья',
        'friend.accepted' => 'Принятие заявки в друзья',
        'friend.removed' => 'Удаление из друзей',
        'balance.transferred' => 'Перевод баланса другу',
        'shop.purchased' => 'Заказ товаров из агента',
        'shop.order_done' => 'Заказ выдан',
        'shop.order_cancelled' => 'Заказ отменён',
        'computer.guest_session_reset' => 'Гостевая сессия сброшена после перезагрузки',
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
