<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait Searchable
{
    /**
     * Search the given columns for the given value.
     *
     * @param  Builder  $query
     * @param  string  $search
     * @param  array  $columns
     * @return Builder
     */
    public function scopeSearch(Builder $query, string $search, array $columns): Builder
    {
        return $query->where(function (Builder $query) use ($search, $columns) {
            foreach ($columns as $column) {
                $query->orWhere($column, 'like', "%{$search}%");
            }
        });
    }
}
