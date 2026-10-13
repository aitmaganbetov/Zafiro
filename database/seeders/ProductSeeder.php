<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $products = [
            ['sku' => 'DRK-001', 'name' => 'Coca-Cola 0,5 л', 'category' => 'Напитки', 'price' => 650, 'quantity' => 48],
            ['sku' => 'DRK-002', 'name' => 'Red Bull 0,25 л', 'category' => 'Напитки', 'price' => 950, 'quantity' => 32],
            ['sku' => 'DRK-003', 'name' => 'Вода BonAqua 0,5 л', 'category' => 'Напитки', 'price' => 400, 'quantity' => 60],
            ['sku' => 'SNK-001', 'name' => 'Snickers', 'category' => 'Снеки', 'price' => 500, 'quantity' => 40],
            ['sku' => 'SNK-002', 'name' => 'Чипсы Lay\'s', 'category' => 'Снеки', 'price' => 850, 'quantity' => 25],
            ['sku' => 'ACC-001', 'name' => 'Клавиатура Logitech G213', 'category' => 'Аксессуары', 'price' => 32_000, 'quantity' => 8],
            ['sku' => 'ACC-002', 'name' => 'Мышь Logitech G102', 'category' => 'Аксессуары', 'price' => 14_500, 'quantity' => 15],
            ['sku' => 'ACC-003', 'name' => 'Гарнитура HyperX Cloud II', 'category' => 'Аксессуары', 'price' => 48_000, 'quantity' => 6],
            ['sku' => 'ACC-004', 'name' => 'Коврик SteelSeries QcK', 'category' => 'Аксессуары', 'price' => 9_000, 'quantity' => 20],
            ['sku' => 'ACC-005', 'name' => 'Кабель HDMI 2 м', 'category' => 'Аксессуары', 'price' => 3_500, 'quantity' => 12],
        ];

        foreach ($products as $product) {
            Product::query()->updateOrCreate(['sku' => $product['sku']], $product);
        }
    }
}
