<?php

namespace App\Services;

use OpenAI;

class AreaPriceService
{
    public function getEstimate($country, $state, $city, $area, $propertyType, $category)
{
    $client = \OpenAI::client(env('OPENAI_API_KEY'));

    $response = $client->chat()->create([
        'model' => 'gpt-4o-mini',
        'response_format' => ['type' => 'json_object'],
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Return ONLY JSON with min_price, max_price, avg_price. No extra text.'
            ],
            [
                'role' => 'user',
                'content' => "Real estate price per square foot for:
                Area: {$area}
                City: {$city}
                State: {$state}
                Country: {$country}
                Property Type: {$propertyType}
                Category: {$category}"
            ],
        ],
    ]);

    $content = $response->choices[0]->message->content;
    $data = json_decode($content, true);

    if (!$data || !isset($data['avg_price'])) {
        throw new \Exception("Invalid AI response: " . $content);
    }

    return [
        'min_price' => (float) $data['min_price'],
        'max_price' => (float) $data['max_price'],
        'avg_price' => (float) $data['avg_price'],
    ];
}
}