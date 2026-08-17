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

        return $this->ok($query->limit(200)->get());
    }
}
