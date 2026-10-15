<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'category' => $this->category,
            'price' => (float) $this->price,
            'currency' => 'KZT',
            'quantity' => $this->quantity,
            'in_stock' => $this->quantity > 0,
            'image_url' => $this->imageUrl($request),
        ];
    }

    /** The product photo (public disk, absolute: the PC agent loads it from zafiro.kz), else the placeholder. */
    private function imageUrl(Request $request): string
    {
        $path = $this->resource->getAttribute('image_path');

        return is_string($path) && $path !== '' && ! str_contains($path, '..') && Storage::disk('public')->exists($path)
            ? $request->root().'/storage/'.ltrim($path, '/')
            : $request->root().'/images/product-placeholder.svg';
    }
}
