<?php

namespace Tests\Feature\Controllers;

use Tests\TestCase;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class WeatherForecastControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->farm = Farm::factory()->create([
            'center' => '35.7219, 51.3347', // Tehran coordinates
        ]);

        $this->actingAs($this->user);

        // Mock the WeatherAPI responses
        Http::fake([
            'api.weatherapi.com/v1/current.json*' => Http::response([
                'current' => [
                    'last_updated' => '2025-03-24 12:00',
                    'temp_c' => 20.5,
                    'condition' => [
                        'text' => 'Sunny',
                        'icon' => '//cdn.weatherapi.com/weather/64x64/day/113.png'
                    ],
                    'wind_kph' => 15.5,
                    'humidity' => 45,
                    'dewpoint_c' => 8.3,
                    'cloud' => 25,
                ]
            ], 200),
            'api.weatherapi.com/v1/forecast.json*' => Http::response([
                'forecast' => [
                    'forecastday' => [
                        [
                            'date' => '2025-03-24',
                            'day' => [
                                'mintemp_c' => 15.5,
                                'avgtemp_c' => 20.5,
                                'maxtemp_c' => 25.5,
                                'condition' => [
                                    'text' => 'Partly cloudy',
                                    'icon' => '//cdn.weatherapi.com/weather/64x64/day/116.png'
                                ],
                                'maxwind_kph' => 18.7,
                                'avghumidity' => 50,
                            ],
                            'hour' => array_fill(0, 24, [
                                'dewpoint_c' => 8.5,
                                'cloud' => 30,
                            ])
                        ]
                    ]
                ]
            ], 200),
            'api.weatherapi.com/v1/history.json*' => Http::response([
                'forecast' => [
                    'forecastday' => [
                        [
                            'date' => '2025-03-23',
                            'day' => [
                                'mintemp_c' => 14.5,
                                'avgtemp_c' => 19.5,
                                'maxtemp_c' => 24.5,
                                'condition' => [
                                    'text' => 'Clear',
                                    'icon' => '//cdn.weatherapi.com/weather/64x64/day/113.png'
                                ],
                                'maxwind_kph' => 16.8,
                                'avghumidity' => 48,
                            ],
                            'hour' => array_fill(0, 24, [
                                'dewpoint_c' => 7.5,
                                'cloud' => 20,
                            ])
                        ]
                    ]
                ]
            ], 200),
        ]);
    }

    #[Test]
    public function it_can_get_current_weather()
    {
        $response = $this->postJson(route('farms.weather_forecast', [
            'farm' => $this->farm,
            'type' => 'current'
        ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'last_updated',
                    'temp_c',
                    'condition',
                    'icon',
                    'wind_kph',
                    'humidity',
                    'dewpoint_c',
                    'cloud'
                ]
            ])
            ->assertJson([
                'data' => [
                    'temp_c' => '20.50',
                    'condition' => 'Sunny',
                    'wind_kph' => '15.50',
                    'humidity' => '45.00',
                    'dewpoint_c' => '8.30',
                    'cloud' => '25.00',
                ]
            ]);
    }

    #[Test]
    public function it_can_get_forecast_weather()
    {
        $response = $this->postJson(route('farms.weather_forecast', [
            'farm' => $this->farm,
            'type' => 'forecast'
        ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'date',
                        'mintemp_c',
                        'avgtemp_c',
                        'maxtemp_c',
                        'condition',
                        'icon',
                        'maxwind_kph',
                        'humidity',
                        'dewpoint_c',
                        'cloud'
                    ]
                ]
            ])
            ->assertJson([
                'data' => [
                    [
                        'mintemp_c' => '15.50',
                        'avgtemp_c' => '20.50',
                        'maxtemp_c' => '25.50',
                        'condition' => 'Partly cloudy',
                        'maxwind_kph' => '18.70',
                        'humidity' => '50.00',
                        'dewpoint_c' => '8.50',
                        'cloud' => '30.00',
                    ]
                ]
            ]);
    }

    #[Test]
    public function it_can_get_historical_weather()
    {
        $response = $this->postJson(route('farms.weather_forecast', [
            'farm' => $this->farm,
            'type' => 'history',
            'start_date' => '1404/01/03', // 2025-03-23
            'end_date' => '1404/01/03'
        ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'date',
                        'mintemp_c',
                        'avgtemp_c',
                        'maxtemp_c',
                        'condition',
                        'icon',
                        'maxwind_kph',
                        'humidity',
                        'dewpoint_c',
                        'cloud'
                    ]
                ]
            ])
            ->assertJson([
                'data' => [
                    [
                        'mintemp_c' => '14.50',
                        'avgtemp_c' => '19.50',
                        'maxtemp_c' => '24.50',
                        'condition' => 'Clear',
                        'maxwind_kph' => '16.80',
                        'humidity' => '48.00',
                        'dewpoint_c' => '7.50',
                        'cloud' => '20.00',
                    ]
                ]
            ]);
    }

    #[Test]
    public function it_validates_history_type_requires_dates()
    {
        $response = $this->postJson(route('farms.weather_forecast', [
            'farm' => $this->farm,
            'type' => 'history'
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['start_date', 'end_date']);
    }

    #[Test]
    public function it_validates_date_range_for_historical_weather()
    {
        $response = $this->postJson(route('farms.weather_forecast', [
            'farm' => $this->farm,
            'type' => 'history',
            'start_date' => '1403/01/01', // More than 300 days ago
            'end_date' => '1404/01/03'
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['start_date']);
    }

    #[Test]
    public function it_validates_end_date_must_be_after_start_date()
    {
        $response = $this->postJson(route('farms.weather_forecast', [
            'farm' => $this->farm,
            'type' => 'history',
            'start_date' => '1404/01/03',
            'end_date' => '1404/01/02'
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);
    }
}
