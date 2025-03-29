<?php

namespace Tests\Feature\Controllers;

use App\Models\Farm;
use App\Models\CropType;
use App\Models\Pest;
use App\Models\User;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

class DayDegreeCalculationControllerTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;
    private CropType $cropType;
    private Pest $pest;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create and authenticate user
        $this->user = User::factory()->create();

        // Create test data
        $this->farm = Farm::factory()->create([
            'center' => '35.7219,51.3347' // Tehran coordinates
        ]);

        // Attach farm to user and set as working environment
        $this->farm->users()->attach($this->user, [
            'is_owner' => true,
            'role' => 'admin'
        ]);

        // Authenticate user with Sanctum
        $this->actingAs($this->user);

        $this->cropType = CropType::factory()->create();
        $this->pest = Pest::factory()->create();

        // Mock WeatherApi service
        $weatherApi = $this->mock(\App\Services\WeatherApi::class);
        $weatherApi->shouldReceive('history')
            ->andReturn([
                'forecast' => [
                    'forecastday' => [
                        [
                            'date' => '2024-01-22',
                            'day' => [
                                'mintemp_c' => 15.5,
                                'maxtemp_c' => 25.5,
                                'avgtemp_c' => 20.5,
                            ]
                        ],
                        [
                            'date' => '2024-01-23',
                            'day' => [
                                'mintemp_c' => 14.5,
                                'maxtemp_c' => 24.5,
                                'avgtemp_c' => 19.5,
                            ]
                        ]
                    ]
                ]
            ]);
    }

    #[Test]
    public function it_calculates_day_degree_for_crop_type()
    {
        $this->withoutExceptionHandling();

        $response = $this->postJson("/api/farms/{$this->farm->id}/phonology/day_degree/calculate", [
            'start_dt' => '1402/08/01',
            'end_dt' => '1402/08/02',
            'model_type' => 'crop_type',
            'model_id' => $this->cropType->id,
            'min_temp' => 10,
            'max_temp' => 30
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'model_id',
                        'model_type',
                        'mintemp_c',
                        'maxtemp_c',
                        'avgtemp_c',
                        'satisfied_day_degree',
                        'date'
                    ]
                ]
            ]);
    }

    #[Test]
    public function it_calculates_day_degree_for_pest()
    {
        $response = $this->postJson("/api/farms/{$this->farm->id}/phonology/day_degree/calculate", [
            'start_dt' => '1402/08/01',
            'end_dt' => '1402/08/02',
            'model_type' => 'pest',
            'model_id' => $this->pest->id,
            'min_temp' => 5,
            'max_temp' => 25
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'model_id',
                        'model_type',
                        'mintemp_c',
                        'maxtemp_c',
                        'avgtemp_c',
                        'satisfied_day_degree',
                        'date'
                    ]
                ]
            ]);
    }

    #[Test]
    public function it_validates_required_fields()
    {
        $response = $this->postJson("/api/farms/{$this->farm->id}/phonology/day_degree/calculate", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['start_dt', 'end_dt', 'model_type', 'model_id']);
    }

    #[Test]
    public function it_validates_max_temp_must_be_greater_than_min_temp()
    {
        $response = $this->postJson("/api/farms/{$this->farm->id}/phonology/day_degree/calculate", [
            'start_dt' => '1402/08/01',
            'end_dt' => '1402/08/02',
            'model_type' => 'pest',
            'model_id' => $this->pest->id,
            'min_temp' => 20,
            'max_temp' => 10
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['max_temp']);
    }
}
