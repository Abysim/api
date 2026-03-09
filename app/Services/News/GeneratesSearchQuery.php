<?php

namespace App\Services\News;

trait GeneratesSearchQuery
{
    public function generateSearchQuery(array $words, array $excludeWords): string
    {
        $query = '(' . implode(' OR ', $words) . ')';
        foreach ($excludeWords as $exclude) {
            if (str_contains($exclude, ' ')) {
                $exclude = '"' . $exclude . '"';
            }
            $query .= ' !' . $exclude;
        }

        return $query;
    }
}
