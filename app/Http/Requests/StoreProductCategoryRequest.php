<?php

namespace App\Http\Requests;

use App\Models\ProductCategory;
use App\Services\CurrentClub;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100', Rule::unique((new ProductCategory)->getTable(), 'name')->where('club_id', app(CurrentClub::class)->id())]];
    }
}
