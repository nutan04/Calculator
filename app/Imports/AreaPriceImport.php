<?php

namespace App\Imports;

use App\Models\AreaPrice;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class AreaPriceImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        return new AreaPrice([
            'country' => $row['country'],
            'state' => $row['state'],
            'area' => $row['area'],
            'property_type' => $row['property_type'],
            'category' => $row['category'],
            'min_price' => $row['min_price'],
            'max_price' => $row['max_price'],
            'avg_price' => $row['avg_price'],
        ]);
    }
}