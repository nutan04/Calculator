<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AreaPrice extends Model
{
    protected $fillable = [
    'country',
    'state',
    'area',
    'property_type',
    'category',
    'min_price',
    'max_price',
    'avg_price'
];
}
