<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class AreaPrice extends Model
{
    protected $fillable = [
        'country',
        'state',
        'city',
        'area',
        'property_type',
        'category',
        'min_price',
        'max_price',
        'avg_price',
    ];

    /**
     * Distinct area names for country/state with the same flexible city matching as GET /api/areas
     * (exact city, then normalized LOWER(city) variants, then India state-wide fallback when allowed).
     * $escapedSearchFragment must be safe for SQL LIKE (wildcards escaped with addcslashes($s, '\\%_')).
     *
     * @return string[]
     */
    public static function distinctAreasForFlexibleCity(
        string $country,
        string $state,
        string $city,
        string $normCity,
        string $escapedSearchFragment,
        int $limit = 20,
        bool $allowIndiaStateOnlyFallback = false
    ): array {
        $table = (new static)->getTable();
        try {
            if (! Schema::hasColumn($table, 'country') || ! Schema::hasColumn($table, 'state') || ! Schema::hasColumn($table, 'city') || ! Schema::hasColumn($table, 'area')) {
                return [];
            }
        } catch (\Throwable $e) {
            return [];
        }

        $baseQuery = static::query()
            ->where('country', $country)
            ->where('state', $state)
            ->whereNotNull('area')
            ->where('area', '<>', '')
            ->where('area', 'like', '%'.$escapedSearchFragment.'%')
            ->distinct()
            ->orderBy('area')
            ->limit($limit);

        $areas = (clone $baseQuery)
            ->where('city', $city)
            ->pluck('area')
            ->values()
            ->all();

        if (count($areas) > 0) {
            return $areas;
        }

        $candidates = [$normCity];
        if (mb_strtolower(trim($country)) === 'india') {
            if (str_contains($normCity, 'bangalore')) {
                $candidates[] = str_replace('bangalore', 'bengaluru', $normCity);
            }
            if (str_contains($normCity, 'bengaluru')) {
                $candidates[] = str_replace('bengaluru', 'bangalore', $normCity);
            }
        }
        $candidates = array_values(array_unique(array_filter($candidates, fn ($c) => is_string($c) && trim($c) !== '')));

        $areas = (clone $baseQuery)
            ->where(function ($q) use ($candidates) {
                $q->whereRaw('1=0');
                foreach ($candidates as $cand) {
                    $escapedCand = addcslashes($cand, '\\%_');
                    $q->orWhereRaw('LOWER(city) = ?', [$cand])
                        ->orWhereRaw('LOWER(city) LIKE ?', [$escapedCand.'%'])
                        ->orWhereRaw('? LIKE CONCAT(LOWER(city), \'%\')', [$cand]);
                }
            })
            ->pluck('area')
            ->values()
            ->all();

        if (count($areas) > 0) {
            return $areas;
        }

        if ($allowIndiaStateOnlyFallback && mb_strtolower(trim($country)) === 'india') {
            return static::query()
                ->where('country', $country)
                ->where('state', $state)
                ->whereNotNull('area')
                ->where('area', '<>', '')
                ->where('area', 'like', '%'.$escapedSearchFragment.'%')
                ->distinct()
                ->orderBy('area')
                ->limit($limit)
                ->pluck('area')
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * Same flexible matching as client price lookup: case-insensitive type/category,
     * swapped type/category, and rows with null city.
     */
    public static function flexibleMatchFirst(
        string $country,
        string $state,
        string $city,
        string $area,
        string $propertyType,
        string $category
    ): ?self {
        return static::where('country', $country)
            ->where('state', $state)
            ->where('area', $area)
            ->where(function ($query) use ($city) {
                $query->where('city', $city)
                    ->orWhereNull('city');
            })
            ->where(function ($query) use ($propertyType, $category) {
                $query->where(function ($query) use ($propertyType, $category) {
                    $query->whereRaw('LOWER(property_type) = LOWER(?)', [$propertyType])
                        ->whereRaw('LOWER(category) = LOWER(?)', [$category]);
                })->orWhere(function ($query) use ($propertyType, $category) {
                    $query->whereRaw('LOWER(property_type) = LOWER(?)', [$category])
                        ->whereRaw('LOWER(category) = LOWER(?)', [$propertyType]);
                });
            })
            ->orderByRaw('CASE WHEN city = ? THEN 0 ELSE 1 END', [$city])
            ->first();
    }
}
