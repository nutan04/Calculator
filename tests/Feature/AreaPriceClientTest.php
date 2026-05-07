<?php

namespace Tests\Feature;

use App\Models\AreaPrice;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AreaPriceClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('area_prices');
        Schema::create('area_prices', function (Blueprint $table) {
            $table->id();
            $table->string('country');
            $table->string('state');
            $table->string('city')->nullable();
            $table->string('area');
            $table->string('property_type')->nullable();
            $table->string('category')->nullable();
            $table->decimal('min_price', 10, 2)->nullable();
            $table->decimal('max_price', 10, 2)->nullable();
            $table->decimal('avg_price', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function test_client_price_matches_case_insensitive_type_and_category(): void
    {
        AreaPrice::create($this->areaPriceRow([
            'property_type' => 'flat',
            'category' => 'sell',
            'min_price' => 1234,
        ]));

        $res = $this->postJson('/api/price-estimate-client', $this->priceRequest([
            'property_type' => 'Flat',
            'category' => 'Sell',
        ]));
        $res->assertOk();
        $this->assertEqualsWithDelta(1234.0, (float) $res->json('data.min_price'), 0.01);
    }

    public function test_client_price_matches_swapped_type_and_category(): void
    {
        AreaPrice::create($this->areaPriceRow([
            'property_type' => 'sell',
            'category' => 'Flat',
            'min_price' => 2000,
        ]));

        $res = $this->postJson('/api/price-estimate-client', $this->priceRequest([
            'property_type' => 'Flat',
            'category' => 'Sell',
        ]));
        $res->assertOk();
        $this->assertEqualsWithDelta(2000.0, (float) $res->json('data.min_price'), 0.01);
    }

    public function test_client_price_matches_rows_with_null_city(): void
    {
        AreaPrice::create($this->areaPriceRow([
            'city' => null,
            'min_price' => 1500,
        ]));

        $res = $this->postJson('/api/price-estimate-client', $this->priceRequest());
        $res->assertOk();
        $this->assertEqualsWithDelta(1500.0, (float) $res->json('data.min_price'), 0.01);
    }

    public function test_client_price_returns_not_found_when_min_price_is_null(): void
    {
        AreaPrice::create($this->areaPriceRow([
            'min_price' => null,
            'avg_price' => 2500,
        ]));

        $this->postJson('/api/price-estimate-client', $this->priceRequest())
            ->assertNotFound()
            ->assertJson([
                'status' => false,
                'message' => 'Minimum price is not available for the given parameters',
            ]);
    }

    private function areaPriceRow(array $overrides = []): array
    {
        return array_merge([
            'country' => 'India',
            'state' => 'Karnataka',
            'city' => 'Bengaluru',
            'area' => 'Indiranagar',
            'property_type' => 'Flat',
            'category' => 'Sell',
            'min_price' => 1000,
            'max_price' => 3000,
            'avg_price' => 2000,
        ], $overrides);
    }

    private function priceRequest(array $overrides = []): array
    {
        return array_merge([
            'country' => 'India',
            'state' => 'Karnataka',
            'city' => 'Bengaluru',
            'area' => 'Indiranagar',
            'property_type' => 'Flat',
            'category' => 'Sell',
        ], $overrides);
    }
}
