<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/** Base controller producing the {success, message, data, meta} envelope documented in docs/API.md. */
abstract class ApiController extends Controller
{
    protected function ok(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): JsonResponse
    {
        if ($data instanceof ResourceCollection && $data->resource instanceof LengthAwarePaginator) {
            return $this->paginated($data, $message, $meta);
        }

        if ($data instanceof JsonResource) {
            $data = $data->resolve(request());
        }

        $body = ['success' => true];
        if ($message !== null) {
            $body['message'] = $message;
        }
        $body['data'] = $data ?? (object) [];
        if ($meta) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }

    protected function created(mixed $data, ?string $message = null): JsonResponse
    {
        return $this->ok($data, $message, 201);
    }

    protected function paginated(ResourceCollection $collection, ?string $message = null, array $extraMeta = []): JsonResponse
    {
        /** @var LengthAwarePaginator $paginator */
        $paginator = $collection->resource;

        return $this->ok($collection->collection->map->resolve(request())->values(), $message, 200, [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            ...$extraMeta,
        ]);
    }

    protected function perPage(int $default = 15): int
    {
        return max(1, min(50, (int) request()->integer('per_page', $default)));
    }
}
