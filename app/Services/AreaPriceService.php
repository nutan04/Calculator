<?php

namespace App\Services;

use App\Exceptions\EstimateUnavailableException;
use App\Models\AreaPrice;
use Illuminate\Support\Facades\Log;
use OpenAI;

class AreaPriceService
{
    public const USER_FACING_FAILURE = 'We couldn\'t estimate this location right now. Please try different details.';

    public function getEstimate(string $country, string $state, string $city, string $area, string $propertyType, string $category): array
    {
        $client = OpenAI::client(env('OPENAI_API_KEY'));

        $triple = $this->fetchNormalizedPricesFromOpenAI($client, $country, $state, $city, $area, $propertyType, $category, strict: false);

        if ($triple === null) {
            $triple = $this->fetchNormalizedPricesFromOpenAI($client, $country, $state, $city, $area, $propertyType, $category, strict: true);
        }

        if ($triple === null) {
            $triple = $this->pricesFromDatabase($country, $state, $city, $area, $propertyType, $category);
        }

        if ($triple === null) {
            throw new EstimateUnavailableException(self::USER_FACING_FAILURE);
        }

        return $triple;
    }

    /**
     * @return array{min_price: float, max_price: float, avg_price: float}|null
     */
    private function fetchNormalizedPricesFromOpenAI(
        $client,
        string $country,
        string $state,
        string $city,
        string $area,
        string $propertyType,
        string $category,
        bool $strict
    ): ?array {
        $system = $strict
            ? 'Return ONLY a JSON object with keys min_price, max_price, avg_price. Each value MUST be a positive number (not null). Use realistic per-square-foot prices for the location.'
            : 'Return ONLY JSON with min_price, max_price, avg_price. No extra text.';

        try {
            $response = $client->chat()->create([
                'model' => 'gpt-4o-mini',
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $system,
                    ],
                    [
                        'role' => 'user',
                        'content' => "Real estate price per square foot for:\n"
                            ."Area: {$area}\n"
                            ."City: {$city}\n"
                            ."State: {$state}\n"
                            ."Country: {$country}\n"
                            ."Property Type: {$propertyType}\n"
                            ."Category: {$category}",
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('OpenAI estimate request failed', ['exception' => $e]);

            return null;
        }

        $content = $response->choices[0]->message->content ?? '';
        $data = json_decode($content, true);

        if (! is_array($data)) {
            Log::warning('OpenAI estimate returned non-JSON or empty content');

            return null;
        }

        return $this->normalizePriceTriple($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{min_price: float, max_price: float, avg_price: float}|null
     */
    private function normalizePriceTriple(array $data): ?array
    {
        $min = $this->toPositiveFloat($data['min_price'] ?? null);
        $max = $this->toPositiveFloat($data['max_price'] ?? null);
        $avg = $this->toPositiveFloat($data['avg_price'] ?? null);

        if ($avg === null) {
            if ($min !== null && $max !== null) {
                $avg = ($min + $max) / 2.0;
            } elseif ($min !== null) {
                $avg = $min;
            } elseif ($max !== null) {
                $avg = $max;
            } else {
                return null;
            }
        }

        if ($min === null) {
            $min = $avg;
        }
        if ($max === null) {
            $max = $avg;
        }

        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        return [
            'min_price' => $min,
            'max_price' => $max,
            'avg_price' => $avg,
        ];
    }

    /**
     * Prefer avg_price; otherwise derive from min/max/min.
     *
     * @return array{min_price: float, max_price: float, avg_price: float}|null
     */
    private function pricesFromDatabase(
        string $country,
        string $state,
        string $city,
        string $area,
        string $propertyType,
        string $category
    ): ?array {
        $row = AreaPrice::flexibleMatchFirst($country, $state, $city, $area, $propertyType, $category);

        if (! $row) {
            return null;
        }

        $avg = $this->toPositiveFloat($row->avg_price);
        $min = $this->toPositiveFloat($row->min_price);
        $max = $this->toPositiveFloat($row->max_price);

        if ($avg === null) {
            if ($min !== null && $max !== null) {
                $avg = ($min + $max) / 2.0;
            } elseif ($min !== null) {
                $avg = $min;
            } elseif ($max !== null) {
                $avg = $max;
            } else {
                return null;
            }
        }

        if ($min === null) {
            $min = $avg;
        }
        if ($max === null) {
            $max = $avg;
        }

        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        return [
            'min_price' => $min,
            'max_price' => $max,
            'avg_price' => $avg,
        ];
    }

    private function toPositiveFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && ! is_numeric($value)) {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $f = (float) $value;

        if (! is_finite($f) || $f < 0) {
            return null;
        }

        return $f;
    }
}
