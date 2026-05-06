<?php

namespace App\Http\Controllers;

use App\Imports\AreaPriceImport;
use App\Models\AreaPrice;
use App\Services\AreaPriceService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class AreaPriceController extends Controller
{
    public function getPrice(Request $request, AreaPriceService $service)
    {
        $request->validate([
            'country' => 'required|string',
            'state' => 'required|string',
            'city' => 'required|string',   // ✅ added
            'area' => 'required|string',
            'property_type' => 'required|string',
            'category' => 'required|string',
            'sqft' => 'required|numeric|min:1',
        ]);

        try {
            $aiData = $service->getEstimate(
                $request->country,
                $request->state,
                $request->city,   // ✅ pass city
                $request->area,
                $request->property_type,
                $request->category
            );

            $totalPrice = $aiData['avg_price'] * $request->sqft;

            return response()->json([
                'status' => true,
                'data' => [
                    'min_price' => $aiData['min_price'],
                    'max_price' => $aiData['max_price'],
                    'avg_price' => $aiData['avg_price'],
                    'per_sqft' => $aiData['avg_price'],
                    'total_price' => $totalPrice,
                    'location' => [
                        'country' => $request->country,
                        'state' => $request->state,
                        'city' => $request->city,
                        'area' => $request->area,
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            dd($e);
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required'
        ]);
        Excel::import(new AreaPriceImport, $request->file('file'));

        return response()->json([
            'status' => true,
            'message' => 'Data imported successfully'
        ]);
    }

    // ✅ Download Sample Format
    public function downloadFormat()
    {
        $fileName = 'area_price_format.csv';

        $headers = [
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

        $callback = function () use ($headers) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $headers);
            fclose($file);
        };

        return response()->streamDownload($callback, $fileName);
    }

    public function getPriceClient(Request $request)
    {
        $request->validate([
            'country' => 'required|string',
            'state' => 'required|string',
            'city' => 'required|string',   // ✅ added
            'area' => 'required|string',
            'property_type' => 'required|string',
            'category' => 'required|string',
        ]);

       $data = AreaPrice::where('country', $request->country)
            ->where('state', $request->state)
            ->where('city', $request->city)   // ✅ filter by city
            ->where('area', $request->area)
            ->where('property_type', $request->property_type)
            ->where('category', $request->category)
            ->first();

         if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'No price data found for the given parameters'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => [
                'min_price' => $data->min_price,
                'max_price' => $data->max_price,
                'avg_price' => $data->avg_price,
            ]
        ]);
    }
}
