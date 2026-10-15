<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Superadmin actions across the platform; not tied to the current club. */
#[Fillable(['user_id', 'club_id', 'event', 'description', 'subject_type', 'subject_id', 'details', 'ip_address', 'user_agent'])]
class PlatformLog extends Model
{
    public const EVENT_LABELS = [
        'superadmin.club_viewed' => 'Просмотр клуба',
        'superadmin.subscription_extended' => 'Продление подписки',
        'superadmin.subscription_suspended' => 'Приостановка подписки',
        'superadmin.subscription_plan_changed' => 'Смена тарифа клуба',
        'superadmin.plan_created' => 'Создание тарифа платформы',
        'superadmin.plan_updated' => 'Изменение тарифа платформы',
        'superadmin.user_blocked' => 'Блокировка пользователя',
        'superadmin.user_unblocked' => 'Разблокировка пользователя',
        'superadmin.granted' => 'Выдача прав суперадмина',
        'superadmin.revoked' => 'Снятие прав суперадмина',
        'superadmin.login' => 'Вход суперадмина',
        'subscription.expired' => 'Окончание оплаченной подписки',
        'superadmin.agent_release_set' => 'Объявление версии агента',
        'superadmin.agent_release_cleared' => 'Снятие объявления о версии агента',
        'language.created' => 'Добавление языка',
        'language.updated' => 'Изменение языка',
        'language.deleted' => 'Удаление языка',
        'translations.updated' => 'Изменение переводов',
        'translations.imported' => 'Импорт переводов',
    ];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function eventLabel(): string
    {
        return isset(self::EVENT_LABELS[$this->event]) ? __(self::EVENT_LABELS[$this->event]) : $this->event;
    }
}
