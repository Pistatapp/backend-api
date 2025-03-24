<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Farm;
use App\Models\Field;
use App\Models\NutrientDiagnosisRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Http\UploadedFile;
use App\Notifications\NewNutrientDiagnosisRequest;
use App\Notifications\NutrientDiagnosisResponse;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Storage;

/**
 * Tests for nutrient diagnosis request functionality.
 * Covers request creation, authorization, response handling, and notifications.
 */
class NutrientDiagnosisTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $seed = true;

    private User $user;
    private User $rootUser;
    private Farm $farm;
    private Field $field;

    /**
     * Set up test environment.
     * Creates necessary test data and configures storage and notifications.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::where('mobile', '09369238614')->first();
        $this->rootUser = User::where('mobile', '09107529334')->first();

        $this->farm = $this->user->farms()->first();

        $this->field = $this->farm->fields()->first();
        $this->actingAs($this->user);

        Notification::fake();
        Storage::fake('public');
    }

    /**
     * @test
     * Test that a user can create a nutrient diagnosis request successfully.
     */
    public function user_can_create_nutrient_diagnosis_request()
    {
        $response = $this->actingAs($this->user)->postJson("/api/farms/{$this->farm->id}/nutrient_diagnosis", [
            'samples' => [
                [
                    'field_id' => $this->field->id,
                    'field_area' => 1000.0,
                    'load_amount' => 50.3,
                    'nitrogen' => 12.5,
                    'phosphorus' => 8.3,
                    'potassium' => 15.7,
                    'calcium' => 20.1,
                    'magnesium' => 5.4,
                    'iron' => 3.2,
                    'copper' => 0.8,
                    'zinc' => 1.2,
                    'boron' => 0.5,
                ]
            ]
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('nutrient_diagnosis_requests', [
            'user_id' => $this->user->id,
            'farm_id' => $this->farm->id,
            'status' => 'pending'
        ]);

        $this->assertDatabaseHas('nutrient_samples', [
            'field_id' => $this->field->id,
            'field_area' => 1000.0,
            'load_amount' => 50.3,
        ]);

        // Check if root user was notified
        Notification::assertSentTo(
            $this->rootUser,
            NewNutrientDiagnosisRequest::class
        );
    }

    /**
     * @test
     * Test that a user cannot create a nutrient diagnosis request for an unauthorized farm.
     */
    public function user_cannot_create_request_for_unauthorized_farm()
    {
        $unauthorizedFarm = Farm::factory()->create();
        $unauthorizedField = Field::factory()->create(['farm_id' => $unauthorizedFarm->id]);

        $response = $this->actingAs($this->user)->postJson("/api/farms/{$unauthorizedFarm->id}/nutrient_diagnosis", [
            'samples' => [
                [
                    'field_id' => $unauthorizedField->id,
                    'field_area' => 1000.0,
                    'load_amount' => 50.3,
                    'nitrogen' => 12.5,
                    'phosphorus' => 8.3,
                    'potassium' => 15.7,
                    'calcium' => 20.1,
                    'magnesium' => 5.4,
                    'iron' => 3.2,
                    'copper' => 0.8,
                    'zinc' => 1.2,
                    'boron' => 0.5,
                ]
            ]
        ]);

        $response->assertStatus(403);
    }

    /**
     * @test
     * Test that a user can view their nutrient diagnosis requests.
     */
    public function user_can_view_their_requests()
    {
        // Create a request
        $request = NutrientDiagnosisRequest::factory()->create([
            'user_id' => $this->user->id,
            'farm_id' => $this->farm->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/farms/{$this->farm->id}/nutrient_diagnosis");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $request->id);
    }

    /**
     * @test
     * Test that a root user can view all nutrient diagnosis requests.
     */
    public function root_user_can_view_all_requests()
    {
        // Create requests for different users
        NutrientDiagnosisRequest::factory()->create([
            'user_id' => $this->user->id,
            'farm_id' => $this->farm->id,
        ]);

        $otherUser = User::factory()->create();
        NutrientDiagnosisRequest::factory()->create([
            'user_id' => $otherUser->id,
            'farm_id' => $this->farm->id,
        ]);

        $response = $this->actingAs($this->rootUser)
            ->getJson("/api/farms/{$this->farm->id}/nutrient_diagnosis");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    /**
     * @test
     * Test that a root user can respond to a nutrient diagnosis request.
     */
    public function root_user_can_respond_to_request()
    {
        // Create a request
        $diagnosisRequest = NutrientDiagnosisRequest::factory()->create([
            'user_id' => $this->user->id,
            'farm_id' => $this->farm->id,
            'status' => 'pending'
        ]);

        $response = $this->actingAs($this->rootUser)->postJson(
            "/api/farms/{$this->farm->id}/nutrient_diagnosis/{$diagnosisRequest->id}/response",
            [
                'description' => 'Analysis complete. Please see attached report.',
                'attachment' => UploadedFile::fake()->create('report.pdf', 100)
            ]
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('nutrient_diagnosis_requests', [
            'id' => $diagnosisRequest->id,
            'status' => 'completed',
            'response_description' => 'Analysis complete. Please see attached report.'
        ]);

        // Check if user was notified
        Notification::assertSentTo(
            $this->user,
            NutrientDiagnosisResponse::class
        );
    }

    /**
     * @test
     * Test that a user can delete their pending nutrient diagnosis request.
     */
    public function user_can_delete_their_pending_request()
    {
        $request = NutrientDiagnosisRequest::factory()->create([
            'user_id' => $this->user->id,
            'farm_id' => $this->farm->id,
            'status' => 'pending'
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/farms/{$this->farm->id}/nutrient_diagnosis/{$request->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('nutrient_diagnosis_requests', ['id' => $request->id]);
    }

    /**
     * @test
     * Test that a user cannot delete a completed nutrient diagnosis request.
     */
    public function user_cannot_delete_completed_request()
    {
        $request = NutrientDiagnosisRequest::factory()->create([
            'user_id' => $this->user->id,
            'farm_id' => $this->farm->id,
            'status' => 'completed'
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/farms/{$this->farm->id}/nutrient_diagnosis/{$request->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('nutrient_diagnosis_requests', ['id' => $request->id]);
    }
}
