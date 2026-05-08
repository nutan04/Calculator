<?php

namespace Tests\Unit;

use App\Http\Controllers\LocationController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Documents expected area-autocomplete filtering: normalized labels match via full-string prefix,
 * any token prefix (space/comma-separated), or substring; loose API rows without the selected city
 * in the description can be kept for Place Details verification when country/state/context match.
 */
class LocationControllerAreaAutocompleteFilterTest extends TestCase
{
    private function invokePrivate(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod(LocationController::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke(new LocationController, ...$args);
    }

    public function test_filter_suggestions_three_char_substring_anywhere_in_label(): void
    {
        $filtered = $this->invokePrivate('filterSuggestionsByInputPrefix', [
            'Lakhala',
            'North Lakhala',
            'Unrelated',
        ], 'lak');

        $this->assertSame(['Lakhala', 'North Lakhala'], array_values($filtered));
    }

    public function test_filter_suggestions_token_prefix_matches_second_word(): void
    {
        $filtered = $this->invokePrivate('filterSuggestionsByInputPrefix', [
            'MH State Highway, Lakhala Road',
            'Unrelated Road',
        ], 'lak');

        $this->assertSame(['MH State Highway, Lakhala Road'], array_values($filtered));
    }

    public function test_filter_suggestions_full_label_prefix(): void
    {
        $filtered = $this->invokePrivate('filterSuggestionsByInputPrefix', [
            'Lakhala',
        ], 'lak');

        $this->assertSame(['Lakhala'], array_values($filtered));
    }

    public function test_loose_new_suggestions_keeps_row_when_city_missing_from_description(): void
    {
        $row = [
            'placePrediction' => [
                'placeId' => 'places/test-place-id',
                'text' => ['text' => 'Lakhala, Maharashtra, India'],
                'structuredFormat' => [
                    'mainText' => ['text' => 'Lakhala'],
                    'secondaryText' => ['text' => 'Maharashtra, India'],
                ],
            ],
        ];

        $out = $this->invokePrivate(
            'filterNewSuggestionsForPlaceDetailsCandidates',
            [$row],
            'lak',
            'Maharashtra',
            'India'
        );

        $this->assertCount(1, $out);
        $this->assertSame($row, $out[0]);
    }

    public function test_loose_new_suggestions_drops_when_input_not_in_main_or_combined_text(): void
    {
        $row = [
            'placePrediction' => [
                'placeId' => 'places/test-place-id',
                'text' => ['text' => 'Pune, Maharashtra, India'],
                'structuredFormat' => [
                    'mainText' => ['text' => 'Pune'],
                    'secondaryText' => ['text' => 'Maharashtra, India'],
                ],
            ],
        ];

        $out = $this->invokePrivate(
            'filterNewSuggestionsForPlaceDetailsCandidates',
            [$row],
            'lak',
            'Maharashtra',
            'India'
        );

        $this->assertSame([], $out);
    }
}
