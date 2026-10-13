<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\CurrentClub;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:100', Rule::unique((new Product)->getTable(), 'sku')->where('club_id', app(CurrentClub::class)->id())],
            'name' => ['required', 'string', 'max:150'],
            'product_category_id' => ['required', 'integer', Rule::exists((new ProductCategory)->getTable(), 'id')->where('club_id', app(CurrentClub::class)->id())],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
