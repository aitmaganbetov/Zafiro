<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Api\AuthenticateApiKey;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared helpers of the v1 controllers. Every query runs through the models'
 * club scope; the club is the API key's club and is never read from the request.
 */
abstract class ApiV1Controller extends Controller
{
    /** @return array<string, mixed> verified key metadata (no secret) */
    protected function key(Request $request): array
    {
        return $request->attributes->get(AuthenticateApiKey::ATTRIBUTE);
    }

    /** Changes made through a key are attributed to the user who created it. */
    protected function actorId(Request $request): int
    {
        return (int) $this->key($request)['created_by'];
    }

    /**
     * Cursor pagination by id: constant cost per page however deep the client goes.
     *
     * @param  Closure(mixed): array<string, mixed>  $transform
     */
    protected function paginate(Request $request, Builder $query, Closure $transform): JsonResponse
    {
        $limit = (int) ($request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:100']])['limit'] ?? 50);
        $page = $query->orderBy($query->getModel()->qualifyColumn('id'))->cursorPaginate($limit);

        return response()->json([
            'data' => $page->getCollection()->map($transform)->values(),
            'meta' => ['per_page' => $page->perPage(), 'next_cursor' => $page->nextCursor()?->encode()],
        ]);
    }
}
