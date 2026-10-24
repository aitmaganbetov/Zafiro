<?php

namespace App\Support;

/**
 * A Font Awesome icon for a product category, by words in its name (the club names categories
 * itself, in any language). Unknown names get a box.
 */
final class CategoryIcon
{
    /** icon => name fragments (lower case) */
    private const ICONS = [
        'fa-mug-hot' => ['кофе', 'чай', 'coffee', 'tea', 'шай', 'кофе'],
        'fa-bolt' => ['энерг', 'energy'],
        'fa-bottle-water' => ['напит', 'сусын', 'drink', 'вод', 'water', 'сок', 'juice', 'кола', 'cola', 'газир'],
        'fa-cookie-bite' => ['снэк', 'снек', 'снак', 'snack', 'чипс', 'chips', 'печен', 'орех'],
        'fa-candy-cane' => ['слад', 'конфет', 'шокол', 'candy', 'sweet', 'тәтті'],
        'fa-burger' => ['еда', 'food', 'тағам', 'пицц', 'pizza', 'бургер', 'burger', 'хот-дог', 'hot dog', 'лапш', 'обед'],
        'fa-ice-cream' => ['морож', 'ice cream', 'балмұздақ'],
        'fa-gamepad' => ['консол', 'console', 'playstation', 'xbox', 'игр', 'game', 'ойын'],
        'fa-headphones' => ['аксесс', 'accessor', 'наушн', 'headphone', 'гарнитур'],
        'fa-smoking' => ['табак', 'кальян', 'вейп', 'vape', 'hookah'],
        'fa-gift' => ['подар', 'gift', 'сертиф', 'сыйлық'],
    ];

    public static function for(?string $name): string
    {
        $name = mb_strtolower((string) $name);
        foreach (self::ICONS as $icon => $words) {
            foreach ($words as $word) {
                if (str_contains($name, $word)) {
                    return $icon;
                }
            }
        }

        return 'fa-box';
    }
}
