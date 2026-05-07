<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
