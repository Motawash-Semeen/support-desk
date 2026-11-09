<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Pagination as a "deferred join": page through primary keys first, then load
 * full rows for that page only.
 *
 * Why (measured on 100k tickets, docs/DEVELOPER_GUIDE.md): a list query selects
 * display columns no index contains, so the engine had to read a full row for
 * every candidate before it could sort and keep 15 (an 83-second cold read for
 * "SLA approaching", a full table scan for the closed list). The id query is
 * answered from the covering indexes alone; the second query reads exactly the
 * rows on the page by primary key. Cost per page: COUNT (index-only) + ids
 * (index-only) + one PK lookup per row shown, whatever the filter.
 *
 * The filtered query keeps all its WHERE clauses (visibleTo() included), so
 * only ids the user may see are ever loaded.
 */
final class DeferredPagination
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  filtered and ordered; no select needed
     * @param  Closure(Builder<TModel>): Builder<TModel>  $rows  columns and eager loads for the page rows
     * @return LengthAwarePaginator<int, TModel>
     */
    public static function paginate(Builder $query, int $perPage, Closure $rows): LengthAwarePaginator
    {
        $model = $query->getModel();

        $page = (clone $query)->select($model->qualifyColumn($model->getKeyName()))->paginate($perPage);
        $ids = $page->getCollection()->modelKeys();

        if ($ids === []) {
            return $page;
        }

        $loaded = $rows($model->newQuery()->whereKey($ids))->get()->keyBy($model->getKeyName());

        // Keep the order the id query decided; drop ids that vanished in between.
        $page->setCollection($loaded->make(array_values(array_filter(
            array_map(fn ($id) => $loaded->get($id), $ids),
        ))));

        return $page;
    }
}
