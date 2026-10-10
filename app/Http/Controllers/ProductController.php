<?php

namespace App\Http\Controllers;

use App\Actions\CreateProductAction;
use App\Http\Requests\StoreProductRequest;
use Illuminate\Http\RedirectResponse;

class ProductController extends Controller
{
    public function __invoke(StoreProductRequest $request, CreateProductAction $createProduct): RedirectResponse
    {
        $createProduct->handle($request->validated(), $request->user()?->getKey());

        return back()->with('status', 'Товар создан.');
    }
}
