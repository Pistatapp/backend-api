<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SearchController extends Controller
{
    protected SearchService $searchService;

    public function __construct(SearchService $searchService)
    {
        $this->searchService = $searchService;
    }

    /**
     * Search for resources
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:1|max:255',
            'type' => 'nullable|string|in:users,crops,crop_types,labours,teams,maintenances',
            'filters' => 'nullable|array',
            'filters.farm_id' => 'nullable|integer|exists:farms,id',
            'filters.crop_id' => 'nullable|integer|exists:crops,id',
            'filters.active' => 'nullable|boolean',
        ]);

        try {
            $query = $request->input('q');
            $type = $request->input('type');
            $filters = $request->input('filters', []);
            $user = $request->user();

            $results = $this->searchService->search($query, $user, $type, $filters);

            // Format response based on whether searching all types or specific type
            if ($type) {
                return response()->json([
                    'data' => $results,
                    'meta' => [
                        'query' => $query,
                        'type' => $type,
                        'count' => $results->count(),
                    ],
                ]);
            }

            // For multi-type search, include counts per type
            $meta = [
                'query' => $query,
                'types' => $results->keys()->toArray(),
                'total_count' => $results->sum(fn($items) => $items->count()),
                'counts_by_type' => $results->map(fn($items) => $items->count())->toArray(),
            ];

            return response()->json([
                'data' => $results,
                'meta' => $meta,
            ]);

        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'available_types' => $this->searchService->getAvailableResourceTypes(),
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while searching.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
