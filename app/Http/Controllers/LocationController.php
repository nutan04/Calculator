<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    private $base = "http://geodb-free-service.wirefreethought.com/v1/geo";

    // ✅ Countries
    public function countries()
    {
        $res = Http::get($this->base . '/countries');

        return response()->json($res->json()['data']);
    }

    // ✅ States
    // ✅ Get all states of India
    public function getIndiaStates()
    {
        $res = Http::get('https://countriesnow.space/api/v0.1/countries/states');

        $data = $res->json();

        $india = collect($data['data'])
            ->firstWhere('name', 'India');

        $states = collect($india['states'] ?? [])
            ->pluck('name')
            ->values();

        return response()->json($states);
    }

    // ✅ Get cities based on state
    public function getCities(Request $request)
    {
        $res = Http::post('https://countriesnow.space/api/v0.1/countries/state/cities', [
            'country' => 'India',
            'state' => $request->state
        ]);

        return response()->json($res->json()['data'] ?? []);
    }
}


