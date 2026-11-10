<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Paginates in two steps: the ids for the page first (answered from the indexes),
 * then the full rows for just those ids. Much faster on large tables, since the
 * first query never reads whole rows. All WHERE clauses (visibility included) stay.
 */
final class DeferredPagination
{
    public static function paginate(Builder $query, int $perPage, Closure $rows): LengthAwarePaginator
    {
        $model = $query->getModel();

        $page = (clone $query)->select($model->qualifyColumn($model->getKeyName()))->paginate($perPage);
        $ids = $page->getCollection()->modelKeys();

        if ($ids === []) {
            return $page;
        }

        $loaded = $rows($model->newQuery()->whereKey($ids))->get()->keyBy($model->getKeyName());

        // Keep the id order; drop ids that vanished in between.
        $page->setCollection($loaded->make(array_values(array_filter(
            array_map(fn ($id) => $loaded->get($id), $ids),
        ))));

        return $page;
    }
}
