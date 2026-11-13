<?php

namespace App\Support;

/**
 * Cities a club can be in: the guest app lists the clubs of the guest's city and finds the city
 * nearest to the phone by these coordinates. Keys are stored in clubs.city.
 */
final class Cities
{
    /** @var array<string, array{0: string, 1: float, 2: float}> key => [name, latitude, longitude] */
    private const LIST = [
        'astana' => ['Астана', 51.1694, 71.4491],
        'almaty' => ['Алматы', 43.2389, 76.8897],
        'shymkent' => ['Шымкент', 42.3417, 69.5901],
        'aktobe' => ['Актобе', 50.2839, 57.1670],
        'karaganda' => ['Караганда', 49.8047, 73.1094],
        'taraz' => ['Тараз', 42.9000, 71.3667],
        'pavlodar' => ['Павлодар', 52.2873, 76.9674],
        'oskemen' => ['Усть-Каменогорск', 49.9483, 82.6275],
        'semey' => ['Семей', 50.4111, 80.2275],
        'atyrau' => ['Атырау', 47.1167, 51.8833],
        'kostanay' => ['Костанай', 53.2144, 63.6246],
        'kyzylorda' => ['Кызылорда', 44.8528, 65.5092],
        'oral' => ['Уральск', 51.2333, 51.3667],
        'petropavl' => ['Петропавловск', 54.8667, 69.1500],
        'aktau' => ['Актау', 43.6500, 51.1600],
        'temirtau' => ['Темиртау', 50.0549, 72.9646],
        'turkistan' => ['Туркестан', 43.2973, 68.2518],
        'kokshetau' => ['Кокшетау', 53.2833, 69.3833],
        'taldykorgan' => ['Талдыкорган', 45.0156, 78.3739],
        'ekibastuz' => ['Экибастуз', 51.7298, 75.3266],
        'zhezkazgan' => ['Жезказган', 47.7833, 67.7667],
        'konaev' => ['Конаев', 43.8667, 77.0667],
        'rudny' => ['Рудный', 52.9667, 63.1167],
        'zhanaozen' => ['Жанаозен', 43.3412, 52.8619],
        'balkhash' => ['Балхаш', 46.8481, 74.9950],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LIST);
    }

    public static function name(?string $key): ?string
    {
        return isset(self::LIST[$key]) ? __(self::LIST[$key][0]) : null;
    }

    /** @return array<string, string> key => name, by name */
    public static function options(): array
    {
        $options = array_map(fn (array $city) => __($city[0]), self::LIST);
        asort($options);

        return $options;
    }

    /** @return array{lat: float, lng: float} */
    public static function coordinates(string $key): array
    {
        return ['lat' => self::LIST[$key][1], 'lng' => self::LIST[$key][2]];
    }
}
