<?php

namespace Tests\Feature;

use App\Models\Farm;
use App\Models\User;
use App\Models\Warning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class WarningTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::put('json/warnings.json', json_encode([
            'frost_warning' => [
                'related-to' => 'garden',
                'setting-message' => 'Warn me :days days before a potential frost event.',
                'setting-message-parameters' => ['days'],
                'warning-message' => 'There is a risk of frost in your garden in the next :days days. Take precautions.',
                'warning-message-parameters' => ['days']
            ],
            'tractor_maintenance' => [
                'related-to' => 'tractors',
                'setting-message' => 'Warn me when tractor needs maintenance after :hours hours.',
                'setting-message-parameters' => ['hours'],
                'warning-message' => 'Tractor needs maintenance after :hours hours of operation.',
                'warning-message-parameters' => ['hours']
            ]
        ]));

        $this->user = User::factory()->create();
        $this->farm = Farm::factory()->create();
        $this->user->farms()->attach($this->farm);
        $this->user->preferences = ['working_environment' => $this->farm->id];
        $this->user->save();
    }

    #[Test]
    public function it_returns_warnings_for_specific_section(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/warnings?related-to=garden')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'key',
                        'setting_message',
                        'enabled',
                        'parameters',
                        'setting_message_parameters'
                    ]
                ]
            ]);
    }

    #[Test]
    public function it_returns_400_when_related_to_parameter_is_missing(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/warnings')
            ->assertStatus(400);
    }

    #[Test]
    public function it_creates_new_warning_setting(): void
    {
        $data = [
            'key' => 'frost_warning',
            'enabled' => true,
            'parameters' => ['days' => '3']
        ];

        $this->actingAs($this->user)
            ->postJson('/api/v1/warnings', $data)
            ->assertOk()
            ->assertJsonStructure([
                'message',
                'warning' => [
                    'id',
                    'farm_id',
                    'key',
                    'enabled',
                    'parameters'
                ]
            ]);

        $this->assertDatabaseHas('warnings', [
            'farm_id' => $this->farm->id,
            'key' => 'frost_warning',
            'enabled' => true
        ]);
    }

    #[Test]
    public function it_updates_existing_warning_setting(): void
    {
        Warning::create([
            'farm_id' => $this->farm->id,
            'key' => 'frost_warning',
            'enabled' => true,
            'parameters' => ['days' => '3']
        ]);

        $updateData = [
            'key' => 'frost_warning',
            'enabled' => false,
            'parameters' => ['days' => '5']
        ];

        $this->actingAs($this->user)
            ->postJson('/api/v1/warnings', $updateData)
            ->assertOk();

        $this->assertDatabaseHas('warnings', [
            'farm_id' => $this->farm->id,
            'key' => 'frost_warning',
            'enabled' => false
        ]);

        $warning = Warning::where('farm_id', $this->farm->id)
            ->where('key', 'frost_warning')
            ->first();

        $this->assertEquals(['days' => '5'], $warning->parameters);
    }

    #[Test]
    public function it_validates_warning_parameters(): void
    {
        $data = [
            'key' => 'frost_warning',
            'enabled' => true,
            'parameters' => ['invalid_param' => 'value']
        ];

        $this->actingAs($this->user)
            ->postJson('/api/v1/warnings', $data)
            ->assertStatus(422);
    }

    #[Test]
    public function it_validates_required_parameters_are_present(): void
    {
        $data = [
            'key' => 'frost_warning',
            'enabled' => true,
            'parameters' => []
        ];

        $this->actingAs($this->user)
            ->postJson('/api/v1/warnings', $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parameters.days']);
    }

    #[Test]
    public function it_validates_multiple_parameters_for_different_warning_types(): void
    {
        // Test frost warning parameters
        $frostData = [
            'key' => 'frost_warning',
            'enabled' => true,
            'parameters' => ['wrong_param' => '3']
        ];

        $this->actingAs($this->user)
            ->postJson('/api/v1/warnings', $frostData)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parameters.days']);

        // Test tractor maintenance parameters
        $tractorData = [
            'key' => 'tractor_maintenance',
            'enabled' => true,
            'parameters' => ['wrong_param' => '100']
        ];

        $this->actingAs($this->user)
            ->postJson('/api/v1/warnings', $tractorData)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parameters.hours']);
    }

    #[Test]
    public function it_validates_presence_of_key_and_enabled_fields(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/warnings', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['key', 'enabled']);
    }

    #[Test]
    public function it_validates_parameters_must_be_array(): void
    {
        $data = [
            'key' => 'frost_warning',
            'enabled' => true,
            'parameters' => 'not_an_array'
        ];

        $this->actingAs($this->user)
            ->postJson('/api/v1/warnings', $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parameters']);
    }

    #[Test]
    public function it_requires_authentication(): void
    {
        $this->getJson('/api/v1/warnings?related-to=garden')
            ->assertUnauthorized();

        $this->postJson('/api/v1/warnings', [
            'key' => 'frost_warning',
            'enabled' => true,
            'parameters' => ['days' => '3']
        ])->assertUnauthorized();
    }
}
