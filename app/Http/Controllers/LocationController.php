<?php

namespace App\Http\Controllers;

use App\Models\AreaPrice;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Intl\Countries;

class LocationController extends Controller
{
    private const COUNTRIESNOW_BASE_URL = 'https://countriesnow.space/api/v0.1';

    private const EXTERNAL_CACHE_DAYS = 7;

    private const DB_FALLBACK_THRESHOLD = 5;

    private const GOOGLE_AUTOCOMPLETE_TTL_HOURS = 24;

    private const GOOGLE_GEOCODE_TTL_DAYS = 30;

    private const PLACES_NEW_AUTOCOMPLETE_URL = 'https://places.googleapis.com/v1/places:autocomplete';

    private const PLACES_NEW_SEARCH_TEXT_URL = 'https://places.googleapis.com/v1/places:searchText';

    private const PLACES_NEW_DETAILS_BASE_URL = 'https://places.googleapis.com/v1/places/';

    /** Short TTL for Text Search supplement cache (normalized query key). */
    private const GOOGLE_TEXT_SEARCH_TTL_HOURS = 1;

    /** When autocomplete + legacy yield fewer than this many labels, supplement via Text Search (New). */
    private const AREA_AUTOCOMPLETE_TEXT_SEARCH_SUPPLEMENT_BELOW = 5;

    private const AREA_AUTOCOMPLETE_RADIUS_METERS = 8000.0;

    private const PLACE_DETAILS_TTL_DAYS = 30;

    /** Short TTL for Place Details fetch failures (avoid poisoning cache). */
    private const PLACE_DETAILS_FAILURE_TTL_MINUTES = 30;

    private const PLACE_DETAILS_VERIFY_LIMIT = 10;

    /** Max predictions to consider before Place Details (text-filtered; details still capped at VERIFY_LIMIT). */
    private const AREA_AUTOCOMPLETE_PRE_DETAILS_LIMIT = 12;

    private const AREA_AUTOCOMPLETE_NO_MATCH_MESSAGE = 'No matching localities found for this city.';

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

    private function containsLoose(string $haystack, string $needle): bool
    {
        $h = $this->normalizeForLooseCompare($haystack);
        $n = $this->normalizeForLooseCompare($needle);
        if ($h === '' || $n === '') {
            return false;
        }

        return str_contains($h, $n);
    }

    private function predictionMatchesSelectedLocation(string $fullText, string $city, string $state, string $country): bool
    {
        $fullText = trim($fullText);
        if ($fullText === '') {
            return false;
        }

        // Require at least city + country; state may be missing in some suggestions.
        if (! $this->containsLoose($fullText, $city)) {
            return false;
        }
        if (! $this->containsLoose($fullText, $country)) {
            return false;
        }

        $state = trim($state);
        if ($state !== '' && ! $this->containsLoose($fullText, $state)) {
            return false;
        }

        return true;
    }

    /**
     * @param  string[]  $suggestions
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
            if ($s === '') {
                continue;
            }

            $loose = $this->normalizeForLooseCompare($s);
            if ($loose === '') {
                continue;
            }

            // Drop pure city suggestions (including Bangalore/Bengaluru normalization).
            if ($loose === $cityNormLoose) {
                continue;
            }

            // Drop obvious POIs / transport hubs.
            if (preg_match($badWordRe, $s)) {
                continue;
            }

            // If suggestion itself is "City District"/etc, not a locality.
            if ($cityNormLoose !== '' && str_starts_with($loose, $cityNormLoose.' ') && preg_match($genericCityRe, $s)) {
                continue;
            }

            // Prefer locality-like short names (avoid full addresses with numbers).
            if (preg_match('/\d/', $s) && mb_strlen($s) > 18) {
                continue;
            }

            $key = $loose;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $s;
        }

        return array_slice($out, 0, 20);
    }

    /**
     * Whether a visible label matches autocomplete input after normalizing case/spacing.
     *
     * Keeps rows when: full label is prefixed by input, any comma/space token is prefixed by input,
     * or the normalized label contains the input (substring). Covers partial typing vs locality names
     * (e.g. "lak" + "Lakhala", "Lakhala Road").
     */
    private function suggestionLabelMatchesAutocompleteInput(string $label, string $input): bool
    {
        $inputNorm = $this->normalizeForCache($input);
        if ($inputNorm === '') {
            return false;
        }

        $sugNorm = $this->normalizeForCache($label);
        if ($sugNorm === '') {
            return false;
        }

        if (str_starts_with($sugNorm, $inputNorm)) {
            return true;
        }

        $tokens = preg_split('/[\s,]+/u', $sugNorm, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($tokens)) {
            $tokens = [];
        }
        foreach ($tokens as $token) {
            $token = (string) $token;
            if ($token !== '' && str_starts_with($token, $inputNorm)) {
                return true;
            }
        }

        return mb_strpos($sugNorm, $inputNorm, 0, 'UTF-8') !== false;
    }

    /**
     * Final guard: every suggestion must relate to what the user typed (drops unrelated API noise).
     *
     * @param  string[]  $suggestions
     * @return string[]
     */
    private function filterSuggestionsByInputPrefix(array $suggestions, string $input): array
    {
        $inputNorm = $this->normalizeForCache($input);
        if ($inputNorm === '') {
            return [];
        }

        $out = [];
        foreach ($suggestions as $s) {
            if (! is_string($s) || trim($s) === '') {
                continue;
            }
            if ($this->suggestionLabelMatchesAutocompleteInput($s, $input)) {
                $out[] = $s;
            }
        }

        return $out;
    }

    private function countryNameToIso2(?string $countryName): ?string
    {
        $countryName = trim((string) $countryName);
        if ($countryName === '') {
            return null;
        }

        if (class_exists(Countries::class)) {
            $names = Countries::getNames('en'); // [ISO2 => Name]
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
                        if (! is_array($c)) {
                            continue;
                        }
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

        $cacheKey = 'ai_estimator:google:geocode:v2:'.md5($this->normalizeForCache($city.'|'.$state.'|'.$country));

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
                    $query['components'] = 'country:'.strtoupper($iso2);
                }

                try {
                    $res = Http::baseUrl('https://maps.googleapis.com/maps/api')
                        ->connectTimeout(2)
                        ->timeout(4)
                        ->retry(1, 200)
                        ->acceptJson()
                        ->get('/geocode/json', $query);

                    if (! $res->ok()) {
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
                    if (! is_numeric($lat) || ! is_numeric($lng)) {
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

            if (! $res->ok()) {
                if (config('app.debug')) {
                    Log::debug('Google Places Autocomplete HTTP error', ['status' => $res->status()]);
                }

                return ['predictions' => [], 'status' => 'HTTP_ERROR', 'cacheable' => false, 'error_message' => null];
            }

            $json = $res->json();
            if (! is_array($json)) {
                return ['predictions' => [], 'status' => 'INVALID_JSON', 'cacheable' => false, 'error_message' => null];
            }

            $status = (string) ($json['status'] ?? '');
            $predictions = $json['predictions'] ?? [];
            if (! is_array($predictions)) {
                $predictions = [];
            }
            $errMsg = isset($json['error_message']) ? (string) $json['error_message'] : null;

            if (! in_array($status, ['OK', 'ZERO_RESULTS'], true)) {
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
            $circle = [
                'circle' => [
                    'center' => [
                        'latitude' => $latLng['lat'],
                        'longitude' => $latLng['lng'],
                    ],
                    'radius' => self::AREA_AUTOCOMPLETE_RADIUS_METERS,
                ],
            ];
            $bodyBase['locationBias'] = $circle;
        }

        try {
            $attemptBodies = [];
            $bodyWithTypeHints = $bodyBase;
            // If supported by the Places API (New) backend, these narrow results to "areas".
            // If not supported, we fall back transparently on INVALID_ARGUMENT.
            $bodyWithTypeHints['includedPrimaryTypes'] = ['neighborhood', 'sublocality', 'sublocality_level_1', 'sublocality_level_2', 'locality', 'administrative_area_level_3'];

            // Prefer strict restriction (if backend supports it), then fall back to bias.
            if ($latLng) {
                $restricted = $bodyBase;
                $restricted['locationRestriction'] = $bodyBase['locationBias'];
                unset($restricted['locationBias']);

                $restrictedWithTypes = $restricted;
                $restrictedWithTypes['includedPrimaryTypes'] = $bodyWithTypeHints['includedPrimaryTypes'];

                $attemptBodies[] = $restrictedWithTypes;
                $attemptBodies[] = $restricted;
            }

            $attemptBodies[] = $bodyWithTypeHints;
            $attemptBodies[] = $bodyBase;

            $res = null;
            $httpStatus = null;
            $lastErrStatus = null;
            $lastErrMsg = null;

            foreach ($attemptBodies as $body) {
                $res = Http::withHeaders([
                    'X-Goog-Api-Key' => $key,
                    'X-Goog-FieldMask' => 'suggestions.placePrediction.placeId,suggestions.placePrediction.text.text,suggestions.placePrediction.structuredFormat.mainText.text,suggestions.placePrediction.structuredFormat.secondaryText.text',
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

                // If "includedPrimaryTypes" or "locationRestriction" is unsupported, retry next attempt.
                if ($lastErrStatus === 'INVALID_ARGUMENT') {
                    continue;
                }

                break;
            }

            if (! $res) {
                return ['suggestions' => [], 'status' => 'HTTP_ERROR', 'error_message' => null, 'cacheable' => false, 'http_status' => null];
            }

            if (! $res->ok()) {
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
                    'status' => $errStatus !== '' ? $errStatus : 'HTTP_'.(string) $httpStatus,
                    'error_message' => $errMsg !== '' ? $errMsg : null,
                    'cacheable' => false,
                    'http_status' => $httpStatus,
                ];
            }

            $json = $res->json();
            if (! is_array($json)) {
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
            if (! is_array($suggestions)) {
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
     * Places API (New) Text Search — supplements Autocomplete for short queries / small localities.
     *
     * @return array{places: array<int, mixed>, status: string, error_message: ?string, cacheable: bool, http_status: ?int}
     */
    private function fetchPlacesTextSearch(string $textQuery, ?string $iso2, ?array $latLng): array
    {
        $key = (string) config('services.google.maps_api_key');
        $textQuery = trim($textQuery);
        if (trim($key) === '') {
            return ['places' => [], 'status' => 'MISSING_KEY', 'error_message' => null, 'cacheable' => false, 'http_status' => null];
        }
        if ($textQuery === '') {
            return ['places' => [], 'status' => 'EMPTY_QUERY', 'error_message' => null, 'cacheable' => false, 'http_status' => null];
        }

        $body = [
            'textQuery' => $textQuery,
            'languageCode' => 'en',
            'pageSize' => 20,
        ];
        if ($iso2) {
            $body['regionCode'] = strtolower(strtoupper($iso2));
        }
        if ($latLng) {
            $body['locationBias'] = [
                'circle' => [
                    'center' => [
                        'latitude' => $latLng['lat'],
                        'longitude' => $latLng['lng'],
                    ],
                    'radius' => self::AREA_AUTOCOMPLETE_RADIUS_METERS,
                ],
            ];
        }

        try {
            $res = Http::withHeaders([
                'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => 'places.displayName,places.formattedAddress,places.id',
            ])
                ->connectTimeout(2)
                ->timeout(4)
                ->retry(1, 200)
                ->acceptJson()
                ->asJson()
                ->post(self::PLACES_NEW_SEARCH_TEXT_URL, $body);

            $httpStatus = $res->status();

            if (! $res->ok()) {
                $json = $res->json();
                $errMsg = is_array($json) ? (string) data_get($json, 'error.message', '') : '';
                $errStatus = is_array($json) ? (string) data_get($json, 'error.status', '') : '';
                if (config('app.debug')) {
                    Log::debug('Google Places Text Search (New) HTTP error', [
                        'http_status' => $httpStatus,
                        'error_status' => $errStatus,
                        'error_message' => $errMsg !== '' ? $errMsg : null,
                    ]);
                }

                return [
                    'places' => [],
                    'status' => $errStatus !== '' ? $errStatus : 'HTTP_'.(string) $httpStatus,
                    'error_message' => $errMsg !== '' ? $errMsg : null,
                    'cacheable' => false,
                    'http_status' => $httpStatus,
                ];
            }

            $json = $res->json();
            if (! is_array($json)) {
                return ['places' => [], 'status' => 'INVALID_JSON', 'error_message' => null, 'cacheable' => false, 'http_status' => $httpStatus];
            }

            if (isset($json['error']) && is_array($json['error'])) {
                $errStatus = (string) ($json['error']['status'] ?? 'API_ERROR');
                $errMsg = isset($json['error']['message']) ? (string) $json['error']['message'] : null;
                if (config('app.debug')) {
                    Log::debug('Google Places Text Search (New) API error', [
                        'status' => $errStatus,
                        'error_message' => $errMsg,
                    ]);
                }

                return ['places' => [], 'status' => $errStatus, 'error_message' => $errMsg, 'cacheable' => false, 'http_status' => $httpStatus];
            }

            $places = $json['places'] ?? [];
            if (! is_array($places)) {
                $places = [];
            }

            return [
                'places' => $places,
                'status' => $places === [] ? 'ZERO_RESULTS' : 'OK',
                'error_message' => null,
                'cacheable' => true,
                'http_status' => $httpStatus,
            ];
        } catch (\Throwable $e) {
            if (config('app.debug')) {
                Log::debug('Google Places Text Search (New) exception', ['message' => $e->getMessage()]);
            }

            return ['places' => [], 'status' => 'EXCEPTION', 'error_message' => $e->getMessage(), 'cacheable' => false, 'http_status' => null];
        }
    }

    /**
     * @return array{places: array<int, mixed>, status: string, error_message: ?string, cacheable: bool}
     */
    private function fetchPlacesTextSearchCached(string $textQuery, ?string $iso2, ?array $latLng): array
    {
        $norm = $this->normalizeForCache($textQuery);
        if ($norm === '') {
            return ['places' => [], 'status' => 'EMPTY_QUERY', 'error_message' => null, 'cacheable' => false];
        }

        $cacheKey = 'ai_estimator:google:places_text_search:v1:'.md5($norm);

        $store = Cache::store('file');
        if ($store->has($cacheKey)) {
            $cached = $store->get($cacheKey);
            if (is_array($cached) && isset($cached['places']) && is_array($cached['places'])) {
                return [
                    'places' => $cached['places'],
                    'status' => (string) ($cached['status'] ?? 'OK'),
                    'error_message' => isset($cached['error_message']) && is_string($cached['error_message']) ? $cached['error_message'] : null,
                    'cacheable' => true,
                ];
            }
        }

        $fetched = $this->fetchPlacesTextSearch($textQuery, $iso2, $latLng);
        if ($fetched['cacheable'] && in_array($fetched['status'], ['OK', 'ZERO_RESULTS'], true)) {
            $store->put($cacheKey, [
                'places' => $fetched['places'],
                'status' => $fetched['status'],
                'error_message' => $fetched['error_message'],
            ], now()->addHours(self::GOOGLE_TEXT_SEARCH_TTL_HOURS));
        }

        return [
            'places' => $fetched['places'],
            'status' => $fetched['status'],
            'error_message' => $fetched['error_message'],
            'cacheable' => $fetched['cacheable'],
        ];
    }

    /**
     * Bare place id for Place Details (New) URL path: GET .../v1/places/{id}
     */
    private function placeResourceNameFromSearchTextPlace(mixed $place): string
    {
        if (! is_array($place)) {
            return '';
        }
        $id = trim((string) data_get($place, 'id', ''));
        if ($id === '') {
            return '';
        }
        if (str_starts_with($id, 'places/')) {
            return trim(substr($id, strlen('places/')));
        }

        return $id;
    }

    private function placeDetailsCacheKey(string $placeId): string
    {
        // v2: invalidate previously cached empty components (failures used to be cached for 30 days).
        return 'ai_estimator:google:places_details:v2:'.md5($placeId);
    }

    /**
     * @return array<int, array{type: string, value: string}>
     */
    private function parsePlaceDetailsAddressComponentsFromJson(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }
        $acs = $json['addressComponents'] ?? [];
        if (! is_array($acs)) {
            return [];
        }

        $out = [];
        foreach ($acs as $ac) {
            if (! is_array($ac)) {
                continue;
            }
            $types = $ac['types'] ?? [];
            if (! is_array($types)) {
                continue;
            }
            $long = trim((string) data_get($ac, 'longText', ''));
            $short = trim((string) data_get($ac, 'shortText', ''));
            $val = $long !== '' ? $long : $short;
            if ($val === '') {
                continue;
            }

            foreach ($types as $t) {
                $t = trim((string) $t);
                if ($t === '') {
                    continue;
                }
                $out[] = ['type' => $t, 'value' => $val];
            }
        }

        return $out;
    }

    /**
     * Fetch Place Details for many place IDs; uses file cache per ID and Http::pool() for cache misses.
     *
     * @param  string[]  $placeIds
     * @return array<string, array<int, array{type: string, value: string}>>
     */
    private function placeDetailsAddressComponentsBatch(array $placeIds): array
    {
        $placeIds = array_values(array_unique(array_filter(array_map('trim', $placeIds), fn ($id) => $id !== '')));
        if ($placeIds === []) {
            return [];
        }

        $store = Cache::store('file');
        $ttl = now()->addDays(self::PLACE_DETAILS_TTL_DAYS);
        $failureTtl = now()->addMinutes(self::PLACE_DETAILS_FAILURE_TTL_MINUTES);
        $out = [];
        $toFetch = [];

        foreach ($placeIds as $id) {
            $cacheKey = $this->placeDetailsCacheKey($id);
            if ($store->has($cacheKey)) {
                $cached = $store->get($cacheKey);
                $out[$id] = is_array($cached) ? $cached : [];
            } else {
                $toFetch[] = $id;
            }
        }

        $key = trim((string) config('services.google.maps_api_key'));
        if ($toFetch !== [] && $key !== '') {
            try {
                $responses = Http::pool(function (Pool $pool) use ($toFetch, $key) {
                    $pending = [];
                    foreach ($toFetch as $placeId) {
                        $pending[] = $pool->as($placeId)
                            ->withHeaders([
                                'X-Goog-Api-Key' => $key,
                                'X-Goog-FieldMask' => 'addressComponents',
                            ])
                            ->connectTimeout(2)
                            ->timeout(5)
                            ->acceptJson()
                            ->get(self::PLACES_NEW_DETAILS_BASE_URL.rawurlencode($placeId));
                    }

                    return $pending;
                });

                foreach ($toFetch as $placeId) {
                    $components = [];
                    $res = $responses[$placeId] ?? null;
                    $cacheTtl = $failureTtl;
                    if ($res !== null && $res->successful()) {
                        $components = $this->parsePlaceDetailsAddressComponentsFromJson($res->json());
                        $cacheTtl = $ttl;
                    }
                    $store->put($this->placeDetailsCacheKey($placeId), $components, $cacheTtl);
                    $out[$placeId] = $components;
                }
            } catch (\Throwable $e) {
                foreach ($toFetch as $placeId) {
                    if (! array_key_exists($placeId, $out)) {
                        $store->put($this->placeDetailsCacheKey($placeId), [], $failureTtl);
                        $out[$placeId] = [];
                    }
                }
            }
        } elseif ($toFetch !== []) {
            foreach ($toFetch as $placeId) {
                $store->put($this->placeDetailsCacheKey($placeId), [], $failureTtl);
                $out[$placeId] = [];
            }
        }

        return $out;
    }

    /**
     * @return array<int, array{type: string, value: string}>
     */
    private function placeDetailsAddressComponents(string $placeId): array
    {
        $placeId = trim($placeId);
        if ($placeId === '') {
            return [];
        }

        $batch = $this->placeDetailsAddressComponentsBatch([$placeId]);

        return $batch[$placeId] ?? [];
    }

    private function addressComponentValue(array $components, string $type): ?string
    {
        foreach ($components as $c) {
            if (! is_array($c)) {
                continue;
            }
            if (($c['type'] ?? null) === $type) {
                $v = trim((string) ($c['value'] ?? ''));
                if ($v !== '') {
                    return $v;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{type: string, value: string}>  $components
     */
    private function placeComponentsMatchSelectedLocation(array $components, string $city, string $state, string $country): bool
    {
        if ($components === []) {
            return false;
        }

        $countryComp = $this->addressComponentValue($components, 'country');
        if ($countryComp && ! $this->containsLoose($countryComp, $country) && ! $this->containsLoose($country, $countryComp)) {
            return false;
        }

        $state = trim($state);
        if ($state !== '') {
            $admin1 = $this->addressComponentValue($components, 'administrative_area_level_1');
            if ($admin1 && ! $this->containsLoose($admin1, $state) && ! $this->containsLoose($state, $admin1)) {
                return false;
            }
        }

        $cityNorm = $this->normalizeCityForMatching($city, $country);

        $locality = $this->addressComponentValue($components, 'locality');
        if ($locality && $this->containsLoose($locality, $cityNorm)) {
            return true;
        }

        $admin3 = $this->addressComponentValue($components, 'administrative_area_level_3');
        if ($admin3 && $this->containsLoose($admin3, $cityNorm)) {
            return true;
        }

        $admin2 = $this->addressComponentValue($components, 'administrative_area_level_2');
        if ($admin2 && $this->containsLoose($admin2, $cityNorm)) {
            return true;
        }

        foreach ($components as $c) {
            if (! is_array($c)) {
                continue;
            }
            $v = trim((string) ($c['value'] ?? ''));
            if ($v === '') {
                continue;
            }
            if ($this->containsLoose($v, $cityNorm)) {
                return true;
            }
        }

        return false;
    }

    private function placeIdMatchesSelectedLocation(string $placeId, string $city, string $state, string $country): bool
    {
        $placeId = trim($placeId);
        if ($placeId === '') {
            return false;
        }

        $components = $this->placeDetailsAddressComponents($placeId);

        return $this->placeComponentsMatchSelectedLocation($components, $city, $state, $country);
    }

    /**
     * Single canonical description line for Places API (New) autocomplete rows.
     */
    private function canonicalFullTextFromNewSuggestionRow(mixed $row): string
    {
        if (! is_array($row)) {
            return '';
        }
        $pp = $row['placePrediction'] ?? null;
        if (! is_array($pp)) {
            return '';
        }
        $full = trim((string) data_get($pp, 'text.text', ''));
        if ($full !== '') {
            return $full;
        }
        $main = trim((string) data_get($pp, 'structuredFormat.mainText.text', ''));
        $sec = trim((string) data_get($pp, 'structuredFormat.secondaryText.text', ''));

        return trim($main.($sec !== '' ? ', '.$sec : ''));
    }

    /**
     * Single canonical description for legacy autocomplete predictions.
     */
    private function canonicalFullTextFromLegacyPrediction(mixed $p): string
    {
        if (! is_array($p)) {
            return '';
        }
        $full = trim((string) ($p['description'] ?? ''));
        if ($full !== '') {
            return $full;
        }
        $main = trim((string) data_get($p, 'structured_formatting.main_text', ''));
        $sec = trim((string) data_get($p, 'structured_formatting.secondary_text', ''));

        return trim($main.($sec !== '' ? ', '.$sec : ''));
    }

    /**
     * Aggressive scope filter: the full description must contain normalized city, country,
     * and state (when provided) — avoids cross-city matches before any Place Details calls.
     *
     * @param  array<int, mixed>  $suggestions
     * @return array<int, mixed>
     */
    private function filterNewSuggestionsByStrictFullDescription(array $suggestions, string $city, string $state, string $country): array
    {
        if ($suggestions === []) {
            return [];
        }

        $cityForMatch = $this->normalizeCityForMatching($city, $country);
        $out = [];
        foreach ($suggestions as $row) {
            $full = $this->canonicalFullTextFromNewSuggestionRow($row);
            if ($full === '' || ! $this->predictionMatchesSelectedLocation($full, $cityForMatch, $state, $country)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * When autocomplete text omits the selected city, strict full-description filtering drops every
     * row before Place Details runs. These candidates still have country/state context in the
     * description; Place Details confirms the place belongs to the selected city.
     *
     * @param  array<int, mixed>  $suggestions
     * @return array<int, mixed>
     */
    private function filterNewSuggestionsForPlaceDetailsCandidates(array $suggestions, string $input, string $state, string $country): array
    {
        if ($suggestions === [] || trim($input) === '') {
            return [];
        }

        $inputNorm = $this->normalizeForCache($input);
        if ($inputNorm === '' || mb_strlen($inputNorm) < 3) {
            return [];
        }

        $out = [];
        foreach ($suggestions as $row) {
            if (! is_array($row)) {
                continue;
            }
            $pp = $row['placePrediction'] ?? null;
            if (! is_array($pp)) {
                continue;
            }
            $placeId = trim((string) data_get($pp, 'placeId', ''));
            if ($placeId === '') {
                continue;
            }

            $full = $this->canonicalFullTextFromNewSuggestionRow($row);
            if ($full === '') {
                continue;
            }

            if (! $this->containsLoose($full, $country)) {
                continue;
            }

            $stateTrim = trim($state);
            if ($stateTrim !== '' && ! $this->containsLoose($full, $stateTrim)) {
                continue;
            }

            $main = trim((string) data_get($pp, 'structuredFormat.mainText.text', ''));
            $sec = trim((string) data_get($pp, 'structuredFormat.secondaryText.text', ''));
            $inputMatches = $this->suggestionLabelMatchesAutocompleteInput($main, $input)
                || ($full !== '' && $this->suggestionLabelMatchesAutocompleteInput($full, $input))
                || ($sec !== '' && $this->suggestionLabelMatchesAutocompleteInput($sec, $input));
            if (! $inputMatches) {
                continue;
            }

            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $predictions
     * @return array<int, mixed>
     */
    private function filterLegacyPredictionsByStrictFullDescription(array $predictions, string $city, string $state, string $country): array
    {
        if ($predictions === []) {
            return [];
        }

        $cityForMatch = $this->normalizeCityForMatching($city, $country);
        $out = [];
        foreach ($predictions as $p) {
            $full = $this->canonicalFullTextFromLegacyPrediction($p);
            if ($full === '' || ! $this->predictionMatchesSelectedLocation($full, $cityForMatch, $state, $country)) {
                continue;
            }
            $out[] = $p;
        }

        return $out;
    }

    /**
     * Legacy autocomplete: same role as {@see filterNewSuggestionsForPlaceDetailsCandidates} — allow
     * verify when the description lacks the selected city but still matches country/state and input.
     *
     * @param  array<int, mixed>  $predictions
     * @return array<int, mixed>
     */
    private function filterLegacyPredictionsForPlaceDetailsCandidates(array $predictions, string $input, string $state, string $country): array
    {
        if ($predictions === [] || trim($input) === '') {
            return [];
        }

        $inputNorm = $this->normalizeForCache($input);
        if ($inputNorm === '' || mb_strlen($inputNorm) < 3) {
            return [];
        }

        $out = [];
        foreach ($predictions as $p) {
            if (! is_array($p)) {
                continue;
            }
            $placeId = trim((string) ($p['place_id'] ?? ''));
            if ($placeId === '') {
                continue;
            }

            $full = $this->canonicalFullTextFromLegacyPrediction($p);
            if ($full === '') {
                continue;
            }

            if (! $this->containsLoose($full, $country)) {
                continue;
            }

            $stateTrim = trim($state);
            if ($stateTrim !== '' && ! $this->containsLoose($full, $stateTrim)) {
                continue;
            }

            $main = trim((string) data_get($p, 'structured_formatting.main_text', ''));
            if ($main === '') {
                $main = trim((string) data_get($p, 'structured_formatting.main_text.text', ''));
            }
            $sec = trim((string) data_get($p, 'structured_formatting.secondary_text', ''));
            $inputMatches = $this->suggestionLabelMatchesAutocompleteInput($main, $input)
                || ($full !== '' && $this->suggestionLabelMatchesAutocompleteInput($full, $input))
                || ($sec !== '' && $this->suggestionLabelMatchesAutocompleteInput($sec, $input));
            if (! $inputMatches) {
                continue;
            }

            $out[] = $p;
        }

        return $out;
    }

    /**
     * Strictly validates Place predictions by Place Details (parallel fetch for cache misses).
     *
     * @param  array<int, mixed>  $suggestions
     * @return array<int, mixed>
     */
    private function verifyNewSuggestionsByPlaceDetails(array $suggestions, string $city, string $state, string $country): array
    {
        if ($suggestions === []) {
            return [];
        }

        $candidates = [];
        foreach ($suggestions as $row) {
            if (count($candidates) >= self::PLACE_DETAILS_VERIFY_LIMIT) {
                break;
            }
            if (! is_array($row)) {
                continue;
            }
            $pp = $row['placePrediction'] ?? null;
            if (! is_array($pp)) {
                continue;
            }
            $placeId = trim((string) data_get($pp, 'placeId', ''));
            if ($placeId === '') {
                continue;
            }
            $candidates[] = ['row' => $row, 'placeId' => $placeId];
        }

        if ($candidates === []) {
            return [];
        }

        $ids = array_column($candidates, 'placeId');
        $batch = $this->placeDetailsAddressComponentsBatch($ids);

        $out = [];
        $emptyComponents = 0;
        foreach ($candidates as $c) {
            $components = $batch[$c['placeId']] ?? [];
            if ($components === []) {
                $emptyComponents++;
            }
            if ($this->placeComponentsMatchSelectedLocation($components, $city, $state, $country)) {
                $out[] = $c['row'];
            }
        }

        if (config('app.debug') && $out === []) {
            Log::debug('area-autocomplete Place Details rejected all new suggestions', [
                'candidates' => count($candidates),
                'empty_components' => $emptyComponents,
                'city' => $city,
                'state' => $state,
                'country' => $country,
            ]);
        }

        return $out;
    }

    /**
     * Strictly validates legacy Places predictions (place_id) via Place Details (parallel fetch for cache misses).
     *
     * @param  array<int, mixed>  $predictions
     * @return array<int, mixed>
     */
    private function verifyLegacyPredictionsByPlaceDetails(array $predictions, string $city, string $state, string $country): array
    {
        if ($predictions === []) {
            return [];
        }

        $candidates = [];
        foreach ($predictions as $p) {
            if (count($candidates) >= self::PLACE_DETAILS_VERIFY_LIMIT) {
                break;
            }
            if (! is_array($p)) {
                continue;
            }
            $placeId = trim((string) ($p['place_id'] ?? ''));
            if ($placeId === '') {
                continue;
            }
            $candidates[] = ['row' => $p, 'placeId' => $placeId];
        }

        if ($candidates === []) {
            return [];
        }

        $ids = array_column($candidates, 'placeId');
        $batch = $this->placeDetailsAddressComponentsBatch($ids);

        $out = [];
        $emptyComponents = 0;
        foreach ($candidates as $c) {
            $components = $batch[$c['placeId']] ?? [];
            if ($components === []) {
                $emptyComponents++;
            }
            if ($this->placeComponentsMatchSelectedLocation($components, $city, $state, $country)) {
                $out[] = $c['row'];
            }
        }

        if (config('app.debug') && $out === []) {
            Log::debug('area-autocomplete Place Details rejected all legacy predictions', [
                'candidates' => count($candidates),
                'empty_components' => $emptyComponents,
                'city' => $city,
                'state' => $state,
                'country' => $country,
            ]);
        }

        return $out;
    }

    /**
     * Parallel Place Details verification for Text Search rows (same limits as autocomplete verify).
     *
     * @param  string[]  $placeResourceNames
     * @return string[]
     */
    private function verifyPlaceResourceNamesForSelectedLocation(array $placeResourceNames, string $city, string $state, string $country): array
    {
        $placeResourceNames = array_values(array_unique(array_filter(array_map('trim', $placeResourceNames), fn ($id) => $id !== '')));
        if ($placeResourceNames === []) {
            return [];
        }

        $placeResourceNames = array_slice($placeResourceNames, 0, self::AREA_AUTOCOMPLETE_PRE_DETAILS_LIMIT);
        $batch = $this->placeDetailsAddressComponentsBatch($placeResourceNames);

        $out = [];
        foreach ($placeResourceNames as $id) {
            if (count($out) >= self::PLACE_DETAILS_VERIFY_LIMIT) {
                break;
            }
            $components = $batch[$id] ?? [];
            if ($this->placeComponentsMatchSelectedLocation($components, $city, $state, $country)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * @param  string[]  $existingSuggestions
     * @return array{suggestions: string[], text_search_status: ?string, text_search_error: ?string, text_search_response_cacheable: bool}
     */
    private function supplementSuggestionsFromTextSearchWithMeta(
        array $existingSuggestions,
        string $input,
        string $city,
        string $state,
        string $country,
        ?string $iso2,
        ?array $latLng
    ): array {
        $textQuery = trim(implode(' ', array_values(array_filter(
            [$input, $city, $state, $country],
            fn ($v) => is_string($v) && trim($v) !== ''
        ))));
        if ($textQuery === '') {
            return [
                'suggestions' => $existingSuggestions,
                'text_search_status' => null,
                'text_search_error' => null,
                'text_search_response_cacheable' => true,
            ];
        }

        $fetched = $this->fetchPlacesTextSearchCached($textQuery, $iso2, $latLng);
        $responseCacheable = (bool) $fetched['cacheable'];
        if ($fetched['places'] === []) {
            return [
                'suggestions' => $existingSuggestions,
                'text_search_status' => $fetched['status'],
                'text_search_error' => $fetched['error_message'],
                'text_search_response_cacheable' => $responseCacheable,
            ];
        }

        $orderedIds = [];
        $idToLabel = [];
        foreach ($fetched['places'] as $place) {
            if (count($orderedIds) >= self::AREA_AUTOCOMPLETE_PRE_DETAILS_LIMIT) {
                break;
            }
            if (! is_array($place)) {
                continue;
            }
            $resource = $this->placeResourceNameFromSearchTextPlace($place);
            if ($resource === '') {
                continue;
            }
            $label = trim((string) data_get($place, 'displayName.text', ''));
            if ($label === '') {
                continue;
            }

            $orderedIds[] = $resource;
            $idToLabel[$resource] = $label;
        }

        if ($orderedIds === []) {
            return [
                'suggestions' => $existingSuggestions,
                'text_search_status' => $fetched['status'],
                'text_search_error' => $fetched['error_message'],
                'text_search_response_cacheable' => $responseCacheable,
            ];
        }

        $verifiedIds = $this->verifyPlaceResourceNamesForSelectedLocation($orderedIds, $city, $state, $country);

        $existingNorm = [];
        foreach ($existingSuggestions as $s) {
            if (is_string($s) && trim($s) !== '') {
                $existingNorm[$this->normalizeForCache($s)] = true;
            }
        }

        $out = array_values(array_filter($existingSuggestions, fn ($s) => is_string($s) && trim($s) !== ''));
        foreach ($verifiedIds as $id) {
            $label = trim((string) ($idToLabel[$id] ?? ''));
            if ($label === '') {
                continue;
            }
            $k = $this->normalizeForCache($label);
            if (isset($existingNorm[$k])) {
                continue;
            }
            $existingNorm[$k] = true;
            $out[] = $label;
            if (count($out) >= 20) {
                break;
            }
        }

        return [
            'suggestions' => $out,
            'text_search_status' => $fetched['status'],
            'text_search_error' => $fetched['error_message'],
            'text_search_response_cacheable' => $responseCacheable,
        ];
    }

    /**
     * @param  array<int, mixed>  $suggestions
     * @return string[]
     */
    private function newSuggestionsToMainTexts(array $suggestions): array
    {
        $out = [];
        foreach ($suggestions as $row) {
            if (! is_array($row)) {
                continue;
            }
            $pp = $row['placePrediction'] ?? null;
            if (! is_array($pp)) {
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
     * @param  array<int, mixed>  $predictions
     * @return string[]
     */
    private function predictionsToMainTexts(array $predictions): array
    {
        $out = [];
        foreach ($predictions as $p) {
            if (! is_array($p)) {
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

        $cacheKey = 'ai_estimator:countriesnow:states:v1:'.md5(mb_strtolower($country));

        return Cache::store('file')->remember($cacheKey, now()->addDays(self::EXTERNAL_CACHE_DAYS), function () use ($country) {
            try {
                $res = Http::baseUrl(self::COUNTRIESNOW_BASE_URL)
                    ->connectTimeout(2)
                    ->timeout(3)
                    ->retry(1, 200)
                    ->acceptJson()
                    ->asJson()
                    ->post('/countries/states', ['country' => $country]);

                if (! $res->ok()) {
                    return [];
                }

                $json = $res->json();
                $states = data_get($json, 'data.states', []);
                if (! is_array($states)) {
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

        $cacheKey = 'ai_estimator:countriesnow:cities:v1:'.md5(mb_strtolower($country.'|'.$state));

        return Cache::store('file')->remember($cacheKey, now()->addDays(self::EXTERNAL_CACHE_DAYS), function () use ($country, $state) {
            try {
                $res = Http::baseUrl(self::COUNTRIESNOW_BASE_URL)
                    ->connectTimeout(2)
                    ->timeout(3)
                    ->retry(1, 200)
                    ->acceptJson()
                    ->asJson()
                    ->post('/countries/state/cities', ['country' => $country, 'state' => $state]);

                if (! $res->ok()) {
                    return [];
                }

                $json = $res->json();
                $cities = data_get($json, 'data', []);
                if (! is_array($cities)) {
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
            if (class_exists(Countries::class)) {
                $names = Countries::getNames('en');
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
            if (! is_file($path)) {
                return [];
            }

            $raw = file_get_contents($path);
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return [];
            }

            return array_values(array_filter(array_map(function ($c) {
                if (! is_array($c)) {
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
            if (! Schema::hasColumn((new AreaPrice)->getTable(), 'country') || ! Schema::hasColumn((new AreaPrice)->getTable(), 'state')) {
                return response()->json([]);
            }
        } catch (\Throwable $e) {
            return response()->json([]);
        }

        if ($this->isIndia($country)) {
            return response()->json($this->countriesNowStates($country));
        }

        $cacheKey = 'ai_estimator:states:v2:'.md5(mb_strtolower($country));

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

        $table = (new AreaPrice)->getTable();
        try {
            if (! Schema::hasColumn($table, 'country') || ! Schema::hasColumn($table, 'state') || ! Schema::hasColumn($table, 'city')) {
                return response()->json([]);
            }
        } catch (\Throwable $e) {
            return response()->json([]);
        }

        $cacheKey = 'ai_estimator:cities:v2:'.md5(mb_strtolower($country.'|'.$state));

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

        $table = (new AreaPrice)->getTable();
        try {
            if (! Schema::hasColumn($table, 'country') || ! Schema::hasColumn($table, 'state') || ! Schema::hasColumn($table, 'city') || ! Schema::hasColumn($table, 'area')) {
                return response()->json([]);
            }
        } catch (\Throwable $e) {
            return response()->json([]);
        }

        $normCity = $this->normalizeCityForMatching($city, $country);
        $cacheKey = 'ai_estimator:areas:v2:'.md5(mb_strtolower($country.'|'.$state.'|'.$city.'|'.$normCity.'|'.$s));

        $areas = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($country, $state, $city, $normCity, $s) {
            $escaped = addcslashes($s, '\\%_');

            $baseQuery = AreaPrice::query()
                ->where('country', $country)
                ->where('state', $state)
                ->whereNotNull('area')
                ->where('area', '<>', '')
                ->where('area', 'like', '%'.$escaped.'%')
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

            // Last-resort for India: allow suggestions by country+state only (keeps UX usable)
            if (mb_strtolower(trim($country)) === 'india') {
                return AreaPrice::query()
                    ->where('country', $country)
                    ->where('state', $state)
                    ->whereNotNull('area')
                    ->where('area', '<>', '')
                    ->where('area', 'like', '%'.$escaped.'%')
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

        // v14: bump to invalidate any cached empties for short inputs (e.g. 3-char prefixes).
        $cacheKey = 'ai_estimator:google:area_autocomplete:v14:'.md5($this->normalizeForCache($input.'|'.$country.'|'.$state.'|'.$city));
        $store = Cache::store('file');
        if ($store->has($cacheKey)) {
            $cached = $store->get($cacheKey);
            if (is_array($cached) && array_key_exists('suggestions', $cached)) {
                return response()->json($cached);
            }

            return response()->json([
                'suggestions' => is_array($cached) ? $cached : [],
                'message' => null,
            ]);
        }

        $latLng = $this->geocodeCityLatLng($city, $state, $country, $iso2);

        // Prefer city-scoped query first (faster, fewer cross-city predictions vs bare input).
        $inputVariants = [trim($input.', '.$city.', '.$state.', '.$country)];
        $inputVariants[] = $input;
        if ($this->isIndia($country)) {
            $inputVariants[] = trim($input.', '.$city.', India');
            $cityAlt = $city;
            if (str_contains(mb_strtolower($city), 'bangalore')) {
                $cityAlt = str_ireplace('bangalore', 'Bengaluru', $city);
            } elseif (str_contains(mb_strtolower($city), 'bengaluru')) {
                $cityAlt = str_ireplace('bengaluru', 'Bangalore', $city);
            }
            if ($cityAlt !== $city) {
                array_splice($inputVariants, 1, 0, [
                    trim($input.', '.$cityAlt.', '.$state.', '.$country),
                    trim($input.', '.$cityAlt.', India'),
                ]);
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
        $lastTextSearchStatus = null;
        $lastTextSearchError = null;

        foreach ($inputVariants as $inputTry) {
            $fetchedNew = $this->fetchPlaceAutocompleteNew($inputTry, $iso2, $latLng);
            $lastNewStatus = $fetchedNew['status'];
            $lastNewError = $fetchedNew['error_message'];
            if (! $fetchedNew['cacheable']) {
                $anyNonCacheableResponse = true;
            }
            if ($fetchedNew['suggestions'] !== []) {
                $scoped = $this->filterNewSuggestionsByStrictFullDescription($fetchedNew['suggestions'], $city, $state, $country);
                if ($scoped === []) {
                    $scoped = $this->filterNewSuggestionsForPlaceDetailsCandidates($fetchedNew['suggestions'], $input, $state, $country);
                }
                if ($scoped !== []) {
                    $newSuggestionsRaw = $scoped;
                    break;
                }
            }
        }

        // Hard guarantee: never return cross-city suggestions.
        // We only keep predictions whose Place Details confirm they belong to the selected city.
        if ($newSuggestionsRaw !== []) {
            $newSuggestionsRaw = array_slice($newSuggestionsRaw, 0, self::AREA_AUTOCOMPLETE_PRE_DETAILS_LIMIT);
            $newSuggestionsRaw = $this->verifyNewSuggestionsByPlaceDetails($newSuggestionsRaw, $city, $state, $country);
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
                        $query['components'] = 'country:'.strtoupper($iso2);
                    }
                    if ($latLng) {
                        $query['location'] = $latLng['lat'].','.$latLng['lng'];
                        $query['radius'] = (int) self::AREA_AUTOCOMPLETE_RADIUS_METERS;
                    }

                    $fetched = $this->fetchPlaceAutocompletePredictions($query);
                    $lastLegacyStatus = $fetched['status'];
                    $lastLegacyError = $fetched['error_message'];
                    if (! $fetched['cacheable']) {
                        $anyNonCacheableResponse = true;
                    }
                    if ($fetched['predictions'] !== []) {
                        $scopedLegacy = $this->filterLegacyPredictionsByStrictFullDescription($fetched['predictions'], $city, $state, $country);
                        if ($scopedLegacy === []) {
                            $scopedLegacy = $this->filterLegacyPredictionsForPlaceDetailsCandidates($fetched['predictions'], $input, $state, $country);
                        }
                        if ($scopedLegacy !== []) {
                            $suggestionPredictions = array_slice($scopedLegacy, 0, self::AREA_AUTOCOMPLETE_PRE_DETAILS_LIMIT);
                            $suggestionPredictions = $this->verifyLegacyPredictionsByPlaceDetails($suggestionPredictions, $city, $state, $country);
                            if ($suggestionPredictions !== []) {
                                break 2;
                            }
                        }
                    }
                }
            }

            if ($suggestionPredictions !== []) {
                $suggestions = $this->predictionsToMainTexts($suggestionPredictions);
            }
        }

        $labelsFromPlaces = $suggestions;

        $applyLabelFilters = function (array $labels) use ($input, $country, $city): array {
            $filtered = $this->filterAreaLikeSuggestions($labels, $country, $city);

            return $this->filterSuggestionsByInputPrefix($filtered, $input);
        };

        $suggestions = $applyLabelFilters($labelsFromPlaces);

        $inputNormLen = mb_strlen($this->normalizeForCache($input));
        $shouldSupplementTextSearch = count($suggestions) < self::AREA_AUTOCOMPLETE_TEXT_SEARCH_SUPPLEMENT_BELOW
            || $inputNormLen === 3;

        if ($shouldSupplementTextSearch) {
            $supplemented = $this->supplementSuggestionsFromTextSearchWithMeta(
                $labelsFromPlaces,
                $input,
                $city,
                $state,
                $country,
                $iso2,
                $latLng
            );
            $labelsFromPlaces = $supplemented['suggestions'];
            $lastTextSearchStatus = $supplemented['text_search_status'];
            $lastTextSearchError = $supplemented['text_search_error'];
            if (! $supplemented['text_search_response_cacheable']) {
                $anyNonCacheableResponse = true;
            }
            $suggestions = $applyLabelFilters($labelsFromPlaces);
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
                'places_text_search_status' => $lastTextSearchStatus,
                'places_text_search_error' => $lastTextSearchError,
                'geocoded' => $latLng !== null,
            ]);
        }

        $shouldCache = $suggestions !== [] || ! $anyNonCacheableResponse;

        $payload = [
            'suggestions' => $suggestions,
            'message' => $suggestions === [] ? self::AREA_AUTOCOMPLETE_NO_MATCH_MESSAGE : null,
        ];

        if ($shouldCache) {
            $store->put($cacheKey, $payload, now()->addHours(self::GOOGLE_AUTOCOMPLETE_TTL_HOURS));
        }

        return response()->json($payload);
    }

    /**
     * Debug helper: verifies Google key / API enablement (only when APP_DEBUG=true).
     */
    public function areaAutocompleteDebug(Request $request)
    {
        if (! config('app.debug')) {
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
            $legacyQuery['components'] = 'country:'.strtoupper($iso2);
        }
        if ($latLng) {
            $legacyQuery['location'] = $latLng['lat'].','.$latLng['lng'];
            $legacyQuery['radius'] = 50000;
        }
        $legacy = $this->fetchPlaceAutocompletePredictions($legacyQuery);
        $legacyOk = in_array($legacy['status'], ['OK', 'ZERO_RESULTS'], true);

        $ok = $newOk || $legacyOk;

        $googleStatus = 'places_new:'.$new['status'].';legacy:'.$legacy['status'];
        $errorMessage = null;
        if (! $newOk && $new['error_message']) {
            $errorMessage = 'places_new: '.$new['error_message'];
        } elseif (! $newOk && $new['status'] !== 'OK' && $new['status'] !== 'ZERO_RESULTS') {
            $errorMessage = 'places_new: '.$new['status'];
        }
        if (! $legacyOk && $legacy['status'] !== 'OK' && $legacy['status'] !== 'ZERO_RESULTS') {
            $legacyDetail = $legacy['error_message'] ? $legacy['status'].' — '.$legacy['error_message'] : $legacy['status'];
            $errorMessage = trim(($errorMessage ? $errorMessage.' | ' : '').'legacy: '.$legacyDetail);
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
        if (! $request->has('country')) {
            $request->merge(['country' => 'India']);
        }

        return $this->cities($request);
    }
}
