<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditLog;
use App\Support\AuditCatalog;
use App\Support\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('settings.manage');
        $query = AuditLog::query()->with('user:id,name,username')->latest('id');

        $term = ListSearch::term($request);
        if ($term !== null) {
            $like = '%'.$term.'%';
            $fragments = AuditCatalog::searchFragments($term);
            $query->where(function ($q) use ($like, $fragments) {
                $first = true;
                foreach (['action', 'reference', 'auditable_type', 'ip_address', 'new_values', 'old_values'] as $column) {
                    ListSearch::whereLike($q, $column, $like, $first);
                    $first = false;
                }
                $q->orWhereHas('user', function ($uq) use ($like) {
                    ListSearch::whereLike($uq, 'name', $like);
                    ListSearch::whereLike($uq, 'username', $like, false);
                });
                foreach ($fragments as $fragment) {
                    $fragLike = '%'.$fragment.'%';
                    ListSearch::whereLike($q, 'action', $fragLike, false);
                    ListSearch::whereLike($q, 'auditable_type', $fragLike, false);
                }
            });
        }

        $period = AuditCatalog::normalizePeriod($request->query('period', 'month'));
        AuditCatalog::applyPeriod($query, $period);

        $perPage = min(200, max(1, $request->integer('per_page', 25)));

        $page = max(1, $request->integer('page', 1));
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $paginator->getCollection()->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'period' => $period,
            ],
        ]);
    }
}
