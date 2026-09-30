<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The documented list shape (docs/api/openapi.json, "Links" and "PageMeta"):
 * data, links, and meta with exactly current_page, per_page, last_page and
 * total, plus extra keys such as the transaction totals. It is built by hand
 * because Laravel's default resource pagination adds keys the contract
 * does not allow.
 */
trait RespondsWithPages
{
    /**
     * @param  LengthAwarePaginator<int, covariant \Illuminate\Database\Eloquent\Model>  $page
     * @param  class-string<JsonResource>  $resource
     * @param  array<string, mixed>  $extraMeta
     */
    protected function pageResponse(LengthAwarePaginator $page, string $resource, Request $request, array $extraMeta = []): JsonResponse
    {
        return response()->json([
            'data' => $resource::collection($page->getCollection())->resolve($request),
            'links' => [
                'first' => $page->url(1),
                'last' => $page->url($page->lastPage()),
                'prev' => $page->previousPageUrl(),
                'next' => $page->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                ...$extraMeta,
            ],
        ]);
    }
}
