<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\AreaPrice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;

class LocationController extends Controller
{
    private const COUNTRIESNOW_BASE_URL = 'https://countriesnow.space/api/v0.1';
    private const EXTERNAL_CACHE_DAYS = 7;
    private const DB_FALLBACK_THRESHOLD = 5;
    private const GOOGLE_AUTOCOMPLETE_TTL_HOURS = 24;
    private const GOOGLE_GEOCODE_TTL_DAYS = 30;
    private const PLACES_NEW_AUTOCOMPLETE_URL = 'https://places.googleapis.com/v1/places:autocomplete';

    private function normalizeCountry(string $country): string
    {
        $country = trim($country);
        return $country;
    }

    private function isIndia(string $country): bool
    {
        return mb_strtolower(trim($country)) === 'india';
    }

    private function normalizeCityForMatching(string $city, string $country): string
    {
        $city = trim(preg_replace('/\s+/u', ' ', $city) ?? '');
        $lower = mb_strtolower($city);

        // CountriesNow sometimes returns Indian districts like "Bangalore Urban"
        if ($this->isIndia($country)) {
            $lower = preg_replace('/\s+urban$/u', '', $lower) ?? $lower;
            $lower = trim(preg_replace('/\s+/u', ' ', $lower) ?? $lower);
        }

        return $lower;
    }

    /**
     * @return string[]
     */
    private function indianCityGeocodeVariants(string $city): array
    {
        $city = trim($city);
        if ($city === '') {
            return [];
        }

        $variants = [$city];
        $lower = mb_strtolower($city);

        if (str_contains($lower, 'bangalore')) {
            $variants[] = str_ireplace('bangalore', 'Bengaluru', $city);
        }
        if (str_contains($lower, 'bengaluru')) {
            $variants[] = str_ireplace('bengaluru', 'Bangalore', $city);
        }

        return array_values(array_unique(array_filter($variants, fn ($v) => is_string($v) && trim($v) !== '')));
    }

    private function normalizeForCache(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        return mb_strtolower($s);
    }

    private function normalizeForLooseCompare(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s) ?? $s;
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        return $s;
    }

    /**
     * @param string[] $suggestions
     * @return string[]
     */
    private function filterAreaLikeSuggestions(array $suggestions, string $country, string $city): array
    {
        $suggestions = array_values(array_filter($suggestions, fn ($v) => is_string($v) && trim($v) !== ''));
        if ($suggestions === []) {
            return [];
        }

        $cityNorm = $this->normalizeCityForMatching($city, $country);
        $cityNormLoose = $this->normalizeForLooseCompare($cityNorm);

        $badWordRe = '/\b(?:airport|international\s+airport|railway|railway\s+station|train\s+station|station|metro|bus\s+stand|bus\s+station|junction|terminal|palace|fort|stadium|zoo|museum)\b/i';
        $genericCityRe = '/\b(?:district|division|taluk|tehsil|urban)\b/i';

        $seen = [];
        $out = [];
        foreach ($suggestions as $s) {
            $s = trim($s);
            if ($s === '') continue;

            $loose = $this->normalizeForLooseCompare($s);
            if ($loose === '') continue;

            // Drop pure city suggestions (including Bangalore/Bengaluru normalization).
            if ($loose === $cityNormLoose) {
                continue;
            }

            // Drop obvious POIs / transport hubs.
            if (preg_match($badWordRe, $s)) {
                continue;
            }

            // If suggestion itself is "City District"/etc, not a locality.
            if ($cityNormLoose !== '' && str_starts_with($loose, $cityNormLoose . ' ') && preg_match($genericCityRe, $s)) {
                continue;
            }

            // Prefer locality-like short names (avoid full addresses with numbers).
            if (preg_match('/\d/', $s) && mb_strlen($s) > 18) {
                continue;
            }

            $key = $loose;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $s;
        }

        return array_slice($out, 0, 20);
    }

    private function countryNameToIso2(?string $countryName): ?string
    {
        $countryName = trim((string) $countryName);
        if ($countryName === '') {
            return null;
        }

        if (class_exists(\Symfony\Component\Intl\Countries::class)) {
            $names = \Symfony\Component\Intl\Countries::getNames('en'); // [ISO2 => Name]
            foreach ($names as $iso2 => $name) {
                if (mb_strtolower((string) $name) === mb_strtolower($countryName)) {
                    return strtoupper((string) $iso2);
                }
            }
        }

        $path = base_path('resources/data/countries.json');
        if (is_file($path)) {
            try {
                $raw = file_get_contents($path);
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $c) {
                        if (!is_array($c)) continue;
                        $name = trim((string) ($c['name'] ?? ''));
                        $code = strtoupper(trim((string) ($c['code'] ?? '')));
                        if ($name !== '' && $code !== '' && mb_strtolower($name) === mb_strtolower($countryName)) {
                            return $code;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return null;
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function geocodeCityLatLng(string $city, string $state, string $country, ?string $iso2): ?array
    {
        $city = trim($city);
        $state = trim($state);
        $country = trim($country);
        if ($city === '' || $country === '') {
            return null;
        }

        $cacheKey = 'ai_estimator:google:geocode:v2:' . md5($this->normalizeForCache($city . '|' . $state . '|' . $country));
        return Cache::store('file')->remember($cacheKey, now()->addDays(self::GOOGLE_GEOCODE_TTL_DAYS), function () use ($city, $state, $country, $iso2) {
            $key = (string) config('services.google.maps_api_key');
            if (trim($key) === '') {
                return null;
            }

            $cityAttempts = $this->isIndia($country)
                ? $this->indianCityGeocodeVariants($city)
                : [trim($city)];
            if ($cityAttempts === []) {
                $cityAttempts = [trim($city)];
            }

            foreach ($cityAttempts as $cityTry) {
                $address = trim(implode(', ', array_values(array_filter(
                    [$cityTry, $state, $country],
                    fn ($v) => is_string($v) && trim($v) !== ''
                ))));
                if ($address === '') {
                    continue;
                }

                $query = [
                    'address' => $address,
                    'key' => $key,
                ];

                if ($iso2) {
                    $query['components'] = 'country:' . strtoupper($iso2);
                }

                try {
                    $res = Http::baseUrl('https://maps.googleapis.com/maps/api')
                        ->connectTimeout(2)
                        ->timeout(4)
                        ->retry(1, 200)
                        ->acceptJson()
                        ->get('/geocode/json', $query);

                    if (!$res->ok()) {
                        continue;
                    }

                    $json = $res->json();
                    $status = (string) data_get($json, 'status', '');
                    if ($status !== 'OK' && config('app.debug')) {
                        Log::debug('Google Geocoding API non-OK status', [
                            'status' => $status,
                            'error_message' => data_get($json, 'error_message'),
                        ]);
                    }
                    if ($status !== 'OK') {
                        continue;
                    }

                    $loc = data_get($json, 'results.0.geometry.location');
                    $lat = is_array($loc) ? ($loc['lat'] ?? null) : null;
                    $lng = is_array($loc) ? ($loc['lng'] ?? null) : null;
                    if (!is_numeric($lat) || !is_numeric($lng)) {
                        continue;
                    }

                    return ['lat' => (float) $lat, 'lng' => (float) $lng];
                } catch (\Throwable $e) {
                    continue;
                }
            }

            return null;
        });
    }

    /**
     * Legacy Places Autocomplete (https://developers.google.com/maps/documentation/places/web-service/autocomplete).
     *
     * @return array{predictions: array<int, mixed>, status: string, cacheable: bool, error_message: ?string}
     */
    private function fetchPlaceAutocompletePredictions(array $query): array
    {
        $key = (string) config('services.google.maps_api_key');
        if (trim($key) === '') {
            return ['predictions' => [], 'status' => 'MISSING_KEY', 'cacheable' => false, 'error_message' => null];
        }

        $query['key'] = $key;

        try {
            $res = Http::baseUrl('https://maps.googleapis.com/maps/api')
                ->connectTimeout(2)
                ->timeout(4)
                ->retry(1, 200)
                ->acceptJson()
                ->get('/place/autocomplete/json', $query);

            if (!$res->ok()) {
                if (config('app.debug')) {
                    Log::debug('Google Places Autocomplete HTTP error', ['status' => $res->status()]);
                }
                return ['predictions' => [], 'status' => 'HTTP_ERROR', 'cacheable' => false, 'error_message' => null];
            }

            $json = $res->json();
            if (!is_array($json)) {
                return ['predictions' => [], 'status' => 'INVALID_JSON', 'cacheable' => false, 'error_message' => null];
            }

            $status = (string) ($json['status'] ?? '');
            $predictions = $json['predictions'] ?? [];
            if (!is_array($predictions)) {
                $predictions = [];
            }
            $errMsg = isset($json['error_message']) ? (string) $json['error_message'] : null;

            if (!in_array($status, ['OK', 'ZERO_RESULTS'], true)) {
                if (config('app.debug')) {
                    Log::debug('Google Places Autocomplete API error', [
                        'status' => $status,
                        'error_message' => $errMsg,
                    ]);
                }
                return ['predictions' => [], 'status' => $status, 'cacheable' => false, 'error_message' => $errMsg];
            }

            return ['predictions' => $predictions, 'status' => $status, 'cacheable' => true, 'error_message' => null];
        } catch (\Throwable $e) {
            if (config('app.debug')) {
                Log::debug('Google Places Autocomplete exception', ['message' => $e->getMessage()]);
            }
            return ['predictions' => [], 'status' => 'EXCEPTION', 'cacheable' => false, 'error_message' => $e->getMessage()];
        }
    }

    /**
     * Places API (New) Autocomplete — works when the Cloud project enables Places API (New)
     * but not the legacy Places API.
     *
     * @return array{suggestions: array<int, mixed>, status: string, error_message: ?string, cacheable: bool, http_status: ?int}
     */
    private function fetchPlaceAutocompleteNew(string $input, ?string $iso2, ?array $latLng): array
    {
        $key = (string) config('services.google.maps_api_key');
        if (trim($key) === '') {
            return ['suggestions' => [], 'status' => 'MISSING_KEY', 'error_message' => null, 'cacheable' => false, 'http_status' => null];
        }

        $bodyBase = [
            'input' => $input,
            'languageCode' => 'en',
        ];

        if ($iso2) {
            $iso2u = strtoupper($iso2);
            $bodyBase['regionCode'] = strtolower($iso2u);
            $bodyBase['includedRegionCodes'] = [$iso2u];
        }

        if ($latLng) {
            $bodyBase['locationBias'] = [
                'circle' => [
                    'center' => [
                        'latitude' => $latLng['lat'],
                        'longitude' => $latLng['lng'],
                    ],
                    'radius' => 20000.0,
                ],
            ];
        }

        try {
            $attemptBodies = [];
            $bodyWithTypeHints = $bodyBase;
            // If supported by the Places API (New) backend, these narrow results to "areas".
            // If not supported, we fall back transparently on INVALID_ARGUMENT.
            $bodyWithTypeHints['includedPrimaryTypes'] = ['neighborhood', 'sublocality', 'sublocality_level_1', 'sublocality_level_2', 'locality', 'administrative_area_level_3'];
            $attemptBodies[] = $bodyWithTypeHints;
            $attemptBodies[] = $bodyBase;

            $res = null;
            $httpStatus = null;
            $lastErrStatus = null;
            $lastErrMsg = null;

            foreach ($attemptBodies as $body) {
                $res = Http::withHeaders([
                    'X-Goog-Api-Key' => $key,
                    'X-Goog-FieldMask' => 'suggestions.placePrediction.text.text,suggestions.placePrediction.structuredFormat.mainText.text',
                ])
                    ->connectTimeout(2)
                    ->timeout(4)
                    ->retry(1, 200)
                    ->acceptJson()
                    ->asJson()
                    ->post(self::PLACES_NEW_AUTOCOMPLETE_URL, $body);

                $httpStatus = $res->status();

                if ($res->ok()) {
                    break;
                }

                $jsonErr = $res->json();
                $lastErrMsg = is_array($jsonErr) ? (string) data_get($jsonErr, 'error.message', '') : '';
                $lastErrStatus = is_array($jsonErr) ? (string) data_get($jsonErr, 'error.status', '') : '';

                // If "includedPrimaryTypes" is unsupported, retry without it.
                if ($lastErrStatus === 'INVALID_ARGUMENT') {
                    continue;
                }

                break;
            }

            if (!$res) {
                return ['suggestions' => [], 'status' => 'HTTP_ERROR', 'error_message' => null, 'cacheable' => false, 'http_status' => null];
            }

            if (!$res->ok()) {
                $json = $res->json();
                $errMsg = is_array($json) ? (string) data_get($json, 'error.message', '') : ($lastErrMsg ?? '');
                $errStatus = is_array($json) ? (string) data_get($json, 'error.status', '') : ($lastErrStatus ?? '');
                if (config('app.debug')) {
                    Log::debug('Google Places Autocomplete (New) HTTP error', [
                        'http_status' => $httpStatus,
                        'error_status' => $errStatus,
                        'error_message' => $errMsg !== '' ? $errMsg : null,
                    ]);
                }

                return [
                    'suggestions' => [],
                    'status' => $errStatus !== '' ? $errStatus : 'HTTP_' . (string) $httpStatus,
                    'error_message' => $errMsg !== '' ? $errMsg : null,
                    'cacheable' => false,
                    'http_status' => $httpStatus,
                ];
            }

            $json = $res->json();
            if (!is_array($json)) {
                return ['suggestions' => [], 'status' => 'INVALID_JSON', 'error_message' => null, 'cacheable' => false, 'http_status' => $httpStatus];
            }

            if (isset($json['error']) && is_array($json['error'])) {
                $errStatus = (string) ($json['error']['status'] ?? 'API_ERROR');
                $errMsg = isset($json['error']['message']) ? (string) $json['error']['message'] : null;
                if (config('app.debug')) {
                    Log::debug('Google Places Autocomplete (New) API error', [
                        'status' => $errStatus,
                        'error_message' => $errMsg,
                    ]);
                }

                return ['suggestions' => [], 'status' => $errStatus, 'error_message' => $errMsg, 'cacheable' => false, 'http_status' => $httpStatus];
            }

            $suggestions = $json['suggestions'] ?? [];
            if (!is_array($suggestions)) {
                $suggestions = [];
            }

            return [
                'suggestions' => $suggestions,
                'status' => $suggestions === [] ? 'ZERO_RESULTS' : 'OK',
                'error_message' => null,
                'cacheable' => true,
                'http_status' => $httpStatus,
            ];
        } catch (\Throwable $e) {
            if (config('app.debug')) {
                Log::debug('Google Places Autocomplete (New) exception', ['message' => $e->getMessage()]);
            }

            return ['suggestions' => [], 'status' => 'EXCEPTION', 'error_message' => $e->getMessage(), 'cacheable' => false, 'http_status' => null];
        }
    }

    /**
     * @param array<int, mixed> $suggestions
     * @return string[]
     */
    private function newSuggestionsToMainTexts(array $suggestions): array
    {
        $out = [];
        foreach ($suggestions as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pp = $row['placePrediction'] ?? null;
            if (!is_array($pp)) {
                continue;
            }
            $mainText = trim((string) data_get($pp, 'structuredFormat.mainText.text', ''));
            if ($mainText === '') {
                $full = trim((string) data_get($pp, 'text.text', ''));
                if ($full !== '') {
                    $mainText = trim((string) (explode(',', $full)[0] ?? ''));
                }
            }
            if ($mainText !== '') {
                $out[] = $mainText;
            }
        }

        $out = array_values(array_unique(array_filter($out, fn ($v) => is_string($v) && trim($v) !== '')));

        return array_slice($out, 0, 20);
    }

    /**
     * @param array<int, mixed> $predictions
     * @return string[]
     */
    private function predictionsToMainTexts(array $predictions): array
    {
        $out = [];
        foreach ($predictions as $p) {
            if (!is_array($p)) {
                continue;
            }
            $mainText = trim((string) data_get($p, 'structured_formatting.main_text', ''));
            if ($mainText === '') {
                $mainText = trim((string) data_get($p, 'structured_formatting.main_text.text', ''));
            }
            if ($mainText === '') {
                $desc = trim((string) ($p['description'] ?? ''));
                if ($desc !== '') {
                    $mainText = trim((string) (explode(',', $desc)[0] ?? ''));
                }
            }
            if ($mainText !== '') {
                $out[] = $mainText;
            }
        }

        $out = array_values(array_unique(array_filter($out, fn ($v) => is_string($v) && trim($v) !== '')));

        return array_slice($out, 0, 20);
    }

    /**
     * @return string[]
     */
    private function countriesNowStates(string $country): array
    {
        $country = $this->normalizeCountry($country);
        if ($country === '') {
            return [];
        }

        $cacheKey = 'ai_estimator:countriesnow:states:v1:' . md5(mb_strtolower($country));

        return Cache::store('file')->remember($cacheKey, now()->addDays(self::EXTERNAL_CACHE_DAYS), function () use ($country) {
            try {
                $res = Http::baseUrl(self::COUNTRIESNOW_BASE_URL)
                    ->connectTimeout(2)
                    ->timeout(3)
                    ->retry(1, 200)
                    ->acceptJson()
                    ->asJson()
                    ->post('/countries/states', ['country' => $country]);

                if (!$res->ok()) {
                    return [];
                }

                $json = $res->json();
                $states = data_get($json, 'data.states', []);
                if (!is_array($states)) {
                    return [];
                }

                $names = [];
                foreach ($states as $s) {
                    $name = is_array($s) ? trim((string) ($s['name'] ?? '')) : '';
                    if ($name !== '') {
                        $names[] = $name;
                    }
                }

                $names = array_values(array_unique($names));
                sort($names, SORT_NATURAL | SORT_FLAG_CASE);
                return $names;
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    /**
     * @return string[]
     */
    private function countriesNowCities(string $country, string $state): array
    {
        $country = $this->normalizeCountry($country);
        $state = trim($state);
        if ($country === '' || $state === '') {
            return [];
        }

        $cacheKey = 'ai_estimator:countriesnow:cities:v1:' . md5(mb_strtolower($country . '|' . $state));

        return Cache::store('file')->remember($cacheKey, now()->addDays(self::EXTERNAL_CACHE_DAYS), function () use ($country, $state) {
            try {
                $res = Http::baseUrl(self::COUNTRIESNOW_BASE_URL)
                    ->connectTimeout(2)
                    ->timeout(3)
                    ->retry(1, 200)
                    ->acceptJson()
                    ->asJson()
                    ->post('/countries/state/cities', ['country' => $country, 'state' => $state]);

                if (!$res->ok()) {
                    return [];
                }

                $json = $res->json();
                $cities = data_get($json, 'data', []);
                if (!is_array($cities)) {
                    return [];
                }

                $names = array_values(array_filter(array_map(function ($c) {
                    $c = trim((string) $c);
                    return $c === '' ? null : $c;
                }, $cities)));

                $names = array_values(array_unique($names));
                sort($names, SORT_NATURAL | SORT_FLAG_CASE);
                return $names;
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    public function countries()
    {
        $countries = Cache::remember('ai_estimator:countries:v2', now()->addDays(30), function () {
            if (class_exists(\Symfony\Component\Intl\Countries::class)) {
                $names = \Symfony\Component\Intl\Countries::getNames('en');
                $out = [];
                foreach ($names as $code => $name) {
                    $code = strtoupper(trim((string) $code));
                    $name = trim((string) $name);
                    if ($code === '' || $name === '') {
                        continue;
                    }
                    $out[] = ['name' => $name, 'code' => $code];
                }

                usort($out, function ($a, $b) {
                    return strcmp($a['name'], $b['name']);
                });

                return $out;
            }

            $path = base_path('resources/data/countries.json');
            if (!is_file($path)) {
                return [];
            }

            $raw = file_get_contents($path);
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return [];
            }

            return array_values(array_filter(array_map(function ($c) {
                if (!is_array($c)) {
                    return null;
                }
                $name = isset($c['name']) ? trim((string) $c['name']) : '';
                $code = isset($c['code']) ? strtoupper(trim((string) $c['code'])) : '';
                if ($name === '') {
                    return null;
                }
                return ['name' => $name, 'code' => $code];
            }, $decoded)));
        });

        return response()->json($countries);
    }

    public function states(Request $request)
    {
        $country = $this->normalizeCountry((string) $request->query('country', ''));
        if (trim($country) === '') {
            return response()->json([]);
        }

        try {
            if (!Schema::hasColumn((new AreaPrice())->getTable(), 'country') || !Schema::hasColumn((new AreaPrice())->getTable(), 'state')) {
                return response()->json([]);
            }
        } catch (\Throwable $e) {
            return response()->json([]);
        }

        if ($this->isIndia($country)) {
            return response()->json($this->countriesNowStates($country));
        }

        $cacheKey = 'ai_estimator:states:v2:' . md5(mb_strtolower($country));

        $states = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($country) {
            $dbStates = AreaPrice::query()
                ->where('country', $country)
                ->whereNotNull('state')
                ->where('state', '<>', '')
                ->distinct()
                ->orderBy('state')
                ->pluck('state')
                ->values()
                ->all();

            if (count($dbStates) >= self::DB_FALLBACK_THRESHOLD) {
                return $dbStates;
            }

            $external = $this->countriesNowStates($country);
            return count($external) > 0 ? $external : $dbStates;
        });

        return response()->json($states);
    }

    public function cities(Request $request)
    {
        $country = $this->normalizeCountry((string) $request->query('country', ''));
        $state = trim((string) $request->query('state', ''));
        if ($country === '' || $state === '') {
            return response()->json([]);
        }

        $table = (new AreaPrice())->getTable();
        try {
            if (!Schema::hasColumn($table, 'country') || !Schema::hasColumn($table, 'state') || !Schema::hasColumn($table, 'city')) {
                return response()->json([]);
            }
        } catch (\Throwable $e) {
            return response()->json([]);
        }

        $cacheKey = 'ai_estimator:cities:v2:' . md5(mb_strtolower($country . '|' . $state));

        $cities = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($country, $state) {
            if ($this->isIndia($country)) {
                $external = $this->countriesNowCities($country, $state);
                if (count($external) > 0) {
                    return $external;
                }
            }

            $dbCities = AreaPrice::query()
                ->where('country', $country)
                ->where('state', $state)
                ->whereNotNull('city')
                ->where('city', '<>', '')
                ->distinct()
                ->orderBy('city')
                ->pluck('city')
                ->values()
                ->all();

            if (count($dbCities) >= self::DB_FALLBACK_THRESHOLD) {
                return $dbCities;
            }

            $external = $this->countriesNowCities($country, $state);
            return count($external) > 0 ? $external : $dbCities;
        });

        return response()->json($cities);
    }

    public function areas(Request $request)
    {
        $s = trim((string) $request->query('s', ''));
        $country = trim((string) $request->query('country', ''));
        $state = trim((string) $request->query('state', ''));
        $city = trim((string) $request->query('city', ''));

        if (mb_strlen($s) < 2 || $country === '' || $state === '' || $city === '') {
            return response()->json([]);
        }

        $table = (new AreaPrice())->getTable();
        try {
            if (!Schema::hasColumn($table, 'country') || !Schema::hasColumn($table, 'state') || !Schema::hasColumn($table, 'city') || !Schema::hasColumn($table, 'area')) {
                return response()->json([]);
            }
        } catch (\Throwable $e) {
            return response()->json([]);
        }

        $normCity = $this->normalizeCityForMatching($city, $country);
        $cacheKey = 'ai_estimator:areas:v2:' . md5(mb_strtolower($country . '|' . $state . '|' . $city . '|' . $normCity . '|' . $s));

        $areas = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($country, $state, $city, $normCity, $s) {
            $escaped = addcslashes($s, '\\%_');

            $baseQuery = AreaPrice::query()
                ->where('country', $country)
                ->where('state', $state)
                ->whereNotNull('area')
                ->where('area', '<>', '')
                ->where('area', 'like', '%' . $escaped . '%')
                ->distinct()
                ->orderBy('area')
                ->limit(20);

            // Fast path: exact city match
            $areas = (clone $baseQuery)
                ->where('city', $city)
                ->pluck('area')
                ->values()
                ->all();

            if (count($areas) > 0) {
                return $areas;
            }

            // Fallback: tolerate city naming differences (case/spacing/district suffixes)
            $candidates = [$normCity];
            if ($this->isIndia($country)) {
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
                            ->orWhereRaw('LOWER(city) LIKE ?', [$escapedCand . '%'])
                            ->orWhereRaw('? LIKE CONCAT(LOWER(city), \'%\')', [$cand]);
                    }
                })
                ->pluck('area')
                ->values()
                ->all();

            if (count($areas) > 0) {
                return $areas;
            }

            // Last-resort for India: allow suggestions by country+state only (keeps UX usable)
            if (mb_strtolower(trim($country)) === 'india') {
                return AreaPrice::query()
                    ->where('country', $country)
                    ->where('state', $state)
                    ->whereNotNull('area')
                    ->where('area', '<>', '')
                    ->where('area', 'like', '%' . $escaped . '%')
                    ->distinct()
                    ->orderBy('area')
                    ->limit(20)
                    ->pluck('area')
                    ->values()
                    ->all();
            }

            return [];
        });

        return response()->json($areas);
    }

    public function areaAutocomplete(Request $request)
    {
        $input = trim((string) $request->query('input', ''));
        $country = trim((string) $request->query('country', ''));
        $state = trim((string) $request->query('state', ''));
        $city = trim((string) $request->query('city', ''));

        if (mb_strlen($input) < 3 || $country === '' || $state === '' || $city === '') {
            return response()->json([]);
        }

        if (trim((string) config('services.google.maps_api_key')) === '') {
            return response()->json([]);
        }

        $iso2 = $this->countryNameToIso2($country);

        $cacheKey = 'ai_estimator:google:area_autocomplete:v3:' . md5($this->normalizeForCache($input . '|' . $country . '|' . $state . '|' . $city));
        $store = Cache::store('file');
        if ($store->has($cacheKey)) {
            $cached = $store->get($cacheKey);

            return response()->json(is_array($cached) ? $cached : []);
        }

        $latLng = $this->geocodeCityLatLng($city, $state, $country, $iso2);

        $inputVariants = [$input];
        $inputVariants[] = trim($input . ', ' . $city . ', ' . $state . ', ' . $country);
        if ($this->isIndia($country)) {
            $inputVariants[] = trim($input . ', ' . $city . ', India');
            $cityAlt = $city;
            if (str_contains(mb_strtolower($city), 'bangalore')) {
                $cityAlt = str_ireplace('bangalore', 'Bengaluru', $city);
            } elseif (str_contains(mb_strtolower($city), 'bengaluru')) {
                $cityAlt = str_ireplace('bengaluru', 'Bangalore', $city);
            }
            if ($cityAlt !== $city) {
                $inputVariants[] = trim($input . ', ' . $cityAlt . ', ' . $state . ', ' . $country);
                $inputVariants[] = trim($input . ', ' . $cityAlt . ', India');
            }
        }
        $inputVariants = array_values(array_unique(array_filter($inputVariants, fn ($v) => is_string($v) && trim($v) !== '')));

        $anyNonCacheableResponse = false;
        $suggestionPredictions = [];
        $newSuggestionsRaw = [];
        $lastNewStatus = null;
        $lastNewError = null;
        $lastLegacyStatus = null;
        $lastLegacyError = null;

        foreach ($inputVariants as $inputTry) {
            $fetchedNew = $this->fetchPlaceAutocompleteNew($inputTry, $iso2, $latLng);
            $lastNewStatus = $fetchedNew['status'];
            $lastNewError = $fetchedNew['error_message'];
            if (!$fetchedNew['cacheable']) {
                $anyNonCacheableResponse = true;
            }
            if ($fetchedNew['suggestions'] !== []) {
                $newSuggestionsRaw = $fetchedNew['suggestions'];
                break;
            }
        }

        $suggestions = $this->newSuggestionsToMainTexts($newSuggestionsRaw);

        if ($suggestions === []) {
            foreach (['address', 'geocode', 'none'] as $typesMode) {
                foreach ($inputVariants as $inputTry) {
                    $query = [
                        'input' => $inputTry,
                        'language' => 'en',
                    ];
                    if ($typesMode === 'geocode') {
                        $query['types'] = 'geocode';
                    } elseif ($typesMode === 'address') {
                        $query['types'] = 'address';
                    }
                    if ($iso2) {
                        $query['components'] = 'country:' . strtoupper($iso2);
                    }
                    if ($latLng) {
                        $query['location'] = $latLng['lat'] . ',' . $latLng['lng'];
                        $query['radius'] = 50000;
                    }

                    $fetched = $this->fetchPlaceAutocompletePredictions($query);
                    $lastLegacyStatus = $fetched['status'];
                    $lastLegacyError = $fetched['error_message'];
                    if (!$fetched['cacheable']) {
                        $anyNonCacheableResponse = true;
                    }
                    if ($fetched['predictions'] !== []) {
                        $suggestionPredictions = $fetched['predictions'];
                        break 2;
                    }
                }
            }

            if ($suggestionPredictions !== []) {
                $suggestions = $this->predictionsToMainTexts($suggestionPredictions);
            }
        }

        if (config('app.debug') && $suggestions === []) {
            Log::debug('area-autocomplete returned no suggestions', [
                'input' => $input,
                'country' => $country,
                'state' => $state,
                'city' => $city,
                'places_new_status' => $lastNewStatus,
                'places_new_error' => $lastNewError,
                'places_legacy_status' => $lastLegacyStatus,
                'places_legacy_error' => $lastLegacyError,
                'geocoded' => $latLng !== null,
            ]);
        }

        $shouldCache = $suggestions !== [] || !$anyNonCacheableResponse;

        // Post-filter to keep results "area-like" (localities/neighborhoods) within the selected city.
        // If filtering becomes too strict, fall back to original list to avoid breaking UX.
        $filtered = $this->filterAreaLikeSuggestions($suggestions, $country, $city);
        if ($filtered !== []) {
            $suggestions = $filtered;
        }

        if ($shouldCache) {
            $store->put($cacheKey, $suggestions, now()->addHours(self::GOOGLE_AUTOCOMPLETE_TTL_HOURS));
        }

        return response()->json($suggestions);
    }

    /**
     * Debug helper: verifies Google key / API enablement (only when APP_DEBUG=true).
     */
    public function areaAutocompleteDebug(Request $request)
    {
        if (!config('app.debug')) {
            abort(404);
        }

        $input = trim((string) $request->query('input', 'baner'));
        $country = trim((string) $request->query('country', 'India'));
        $state = trim((string) $request->query('state', 'Karnataka'));
        $city = trim((string) $request->query('city', 'Bangalore'));

        if (mb_strlen($input) < 3) {
            $input = 'baner';
        }

        if (trim((string) config('services.google.maps_api_key')) === '') {
            return response()->json([
                'ok' => false,
                'google_status' => 'MISSING_KEY',
                'error_message' => 'GOOGLE_MAPS_API_KEY is empty',
            ]);
        }

        $iso2 = $this->countryNameToIso2($country);
        $latLng = $this->geocodeCityLatLng($city, $state, $country, $iso2);

        $new = $this->fetchPlaceAutocompleteNew($input, $iso2, $latLng);
        $newOk = in_array($new['status'], ['OK', 'ZERO_RESULTS'], true);

        $legacyQuery = [
            'input' => $input,
            'language' => 'en',
        ];
        if ($iso2) {
            $legacyQuery['components'] = 'country:' . strtoupper($iso2);
        }
        if ($latLng) {
            $legacyQuery['location'] = $latLng['lat'] . ',' . $latLng['lng'];
            $legacyQuery['radius'] = 50000;
        }
        $legacy = $this->fetchPlaceAutocompletePredictions($legacyQuery);
        $legacyOk = in_array($legacy['status'], ['OK', 'ZERO_RESULTS'], true);

        $ok = $newOk || $legacyOk;

        $googleStatus = 'places_new:' . $new['status'] . ';legacy:' . $legacy['status'];
        $errorMessage = null;
        if (!$newOk && $new['error_message']) {
            $errorMessage = 'places_new: ' . $new['error_message'];
        } elseif (!$newOk && $new['status'] !== 'OK' && $new['status'] !== 'ZERO_RESULTS') {
            $errorMessage = 'places_new: ' . $new['status'];
        }
        if (!$legacyOk && $legacy['status'] !== 'OK' && $legacy['status'] !== 'ZERO_RESULTS') {
            $legacyDetail = $legacy['error_message'] ? $legacy['status'] . ' — ' . $legacy['error_message'] : $legacy['status'];
            $errorMessage = trim(($errorMessage ? $errorMessage . ' | ' : '') . 'legacy: ' . $legacyDetail);
        }

        return response()->json([
            'ok' => $ok,
            'google_status' => $googleStatus,
            'error_message' => $errorMessage,
        ]);
    }

    // Backward-compat: India states endpoint
    public function getIndiaStates(Request $request)
    {
        $request->merge(['country' => 'India']);
        return $this->states($request);
    }

    // Backward-compat: cities?state=... (India default)
    public function getCities(Request $request)
    {
        if (!$request->has('country')) {
            $request->merge(['country' => 'India']);
        }
        return $this->cities($request);
    }
}


