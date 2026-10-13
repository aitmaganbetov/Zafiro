<?php

namespace App\Http\Controllers;

use App\Actions\AdjustStockAction;
use App\Http\Requests\StoreStockMovementRequest;
use Illuminate\Http\RedirectResponse;

class StockMovementController extends Controller
{
    public function __invoke(
        StoreStockMovementRequest $request,
        AdjustStockAction $adjustStock,
    ): RedirectResponse {
        $adjustStock->handle($request->validated(), $request->user()?->getKey());

        return back()->with('status', 'Складская операция выполнена');
    }
}
