<?php

namespace Tests\Feature;

use App\Exceptions\EstimateUnavailableException;
use App\Services\AreaPriceService;
use RuntimeException;
use Tests\TestCase;

class PriceEstimateResponseTest extends TestCase
{
    public function test_price_estimate_returns_safe_message_when_service_throws_unexpected(): void
    {
        $this->mock(AreaPriceService::class, function ($mock) {
            $mock->shouldReceive('getEstimate')
                ->once()
                ->andThrow(new RuntimeException('Invalid AI response: {"min_price":null,"raw":"secret"}'));
        });

        $response = $this->postJson('/api/price-estimate', $this->validPayload());

        $response->assertStatus(500)
            ->assertJson([
                'status' => false,
                'message' => AreaPriceService::USER_FACING_FAILURE,
            ]);

        $this->assertStringNotContainsString('Invalid AI', $response->getContent());
        $this->assertStringNotContainsString('secret', $response->getContent());
    }

    public function test_price_estimate_returns_user_message_for_estimate_unavailable(): void
    {
        $this->mock(AreaPriceService::class, function ($mock) {
            $mock->shouldReceive('getEstimate')
                ->once()
                ->andThrow(new EstimateUnavailableException(AreaPriceService::USER_FACING_FAILURE));
        });

        $this->postJson('/api/price-estimate', $this->validPayload())
            ->assertStatus(422)
            ->assertJson([
                'status' => false,
                'message' => AreaPriceService::USER_FACING_FAILURE,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'country' => 'India',
            'state' => 'Karnataka',
            'city' => 'Bengaluru',
            'area' => 'Indiranagar',
            'property_type' => 'Flat',
            'category' => 'Sell',
            'sqft' => 1000,
        ];
    }
}
