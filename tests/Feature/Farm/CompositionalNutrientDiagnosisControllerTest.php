<?php

namespace Tests\Feature\Farm;

use App\Models\Farm;
use App\Models\Field;
use App\Models\NutrientDiagnosisRequest;
use App\Models\NutrientSample;
use App\Models\User;
use App\Notifications\NewNutrientDiagnosisRequest;
use App\Notifications\NutrientDiagnosisResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompositionalNutrientDiagnosisControllerTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;
    private Field $field;
    private User $farmUser;
    private User $otherFarmUser;
    private User $rootUser;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Role::where('name', 'root')->exists()) {
            Role::create(['name' => 'root']);
        }

        $this->farm = Farm::factory()->create();
        $this->field = Field::factory()->create(['farm_id' => $this->farm->id]);

        $this->farmUser = User::factory()->create(['is_active' => true]);
        $this->otherFarmUser = User::factory()->create(['is_active' => true]);
        $this->rootUser = User::factory()->create(['is_active' => true]);
        $this->outsider = User::factory()->create(['is_active' => true]);

        $this->farm->users()->attach($this->farmUser->id);
        $this->farm->users()->attach($this->otherFarmUser->id);
        $this->rootUser->assignRole('root');
    }

    private function nutrientDiagnosisUrl(Farm $farm, string $path = ''): string
    {
        $base = "/api/farms/{$farm->id}/nutrient-diagnosis";

        return $path === '' ? $base : "{$base}/{$path}";
    }

    /**
     * @return array<string, mixed>
     */
    private function validSamplePayload(?Field $field = null): array
    {
        $field ??= $this->field;

        return [
            'samples' => [
                [
                    'field_id' => $field->id,
                    'field_area' => 250.5,
                    'load_amount' => 42.0,
                    'nitrogen' => 1.2,
                    'phosphorus' => 0.8,
                    'potassium' => 2.1,
                    'calcium' => 3.4,
                    'magnesium' => 0.5,
                    'iron' => 0.15,
                    'copper' => 0.05,
                    'zinc' => 0.12,
                    'boron' => 0.03,
                ],
            ],
        ];
    }

    private function createDiagnosisRequest(
        User $user,
        ?Farm $farm = null,
        string $status = 'pending'
    ): NutrientDiagnosisRequest {
        $farm ??= $this->farm;

        $request = NutrientDiagnosisRequest::factory()->create([
            'user_id' => $user->id,
            'farm_id' => $farm->id,
            'status' => $status,
            'approved_at' => $status === 'approved' ? now() : null,
        ]);

        NutrientSample::factory()->create([
            'nutrient_diagnosis_request_id' => $request->id,
            'field_id' => $this->field->id,
        ]);

        return $request;
    }

    public function test_unauthenticated_user_cannot_access_nutrient_diagnosis_routes(): void
    {
        $request = $this->createDiagnosisRequest($this->farmUser);

        $this->getJson($this->nutrientDiagnosisUrl($this->farm))->assertUnauthorized();
        $this->getJson($this->nutrientDiagnosisUrl($this->farm, (string) $request->id))->assertUnauthorized();
        $this->postJson($this->nutrientDiagnosisUrl($this->farm), $this->validSamplePayload())->assertUnauthorized();
    }

    public function test_user_without_username_cannot_access_nutrient_diagnosis_routes(): void
    {
        $userWithoutUsername = User::factory()->create(['username' => null]);
        $this->farm->users()->attach($userWithoutUsername->id);

        $this->actingAs($userWithoutUsername, 'sanctum')
            ->getJson($this->nutrientDiagnosisUrl($this->farm))
            ->assertForbidden();
    }

    public function test_farm_user_sees_only_own_requests_in_index(): void
    {
        $ownRequest = $this->createDiagnosisRequest($this->farmUser);
        $otherRequest = $this->createDiagnosisRequest($this->otherFarmUser);

        $response = $this->actingAs($this->farmUser, 'sanctum')
            ->getJson($this->nutrientDiagnosisUrl($this->farm));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $ownRequest->id);
        $response->assertJsonMissing(['id' => $otherRequest->id]);
    }

    public function test_root_user_sees_all_farm_requests_in_index(): void
    {
        $firstRequest = $this->createDiagnosisRequest($this->farmUser);
        $secondRequest = $this->createDiagnosisRequest($this->otherFarmUser);

        $response = $this->actingAs($this->rootUser, 'sanctum')
            ->getJson($this->nutrientDiagnosisUrl($this->farm));

        $response->assertOk();
        $response->assertJsonCount(2, 'data');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing(
            [$firstRequest->id, $secondRequest->id],
            $ids
        );
    }

    public function test_index_returns_expected_resource_structure(): void
    {
        $this->createDiagnosisRequest($this->farmUser);

        $response = $this->actingAs($this->farmUser, 'sanctum')
            ->getJson($this->nutrientDiagnosisUrl($this->farm));

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'farm_id',
                    'status',
                    'approved_at',
                    'response_description',
                    'response_attachment',
                    'created_at',
                    'can' => ['respond', 'update', 'delete'],
                    'user' => ['id', 'name'],
                    'samples' => [
                        '*' => [
                            'id',
                            'field_area',
                            'load_amount',
                            'nitrogen',
                            'field' => ['id', 'name'],
                        ],
                    ],
                ],
            ],
            'links',
            'meta',
        ]);
    }

    public function test_farm_member_can_show_any_request_on_their_farm(): void
    {
        $request = $this->createDiagnosisRequest($this->otherFarmUser);

        $this->actingAs($this->farmUser, 'sanctum')
            ->getJson($this->nutrientDiagnosisUrl($this->farm, (string) $request->id))
            ->assertOk()
            ->assertJsonPath('data.id', $request->id)
            ->assertJsonPath('data.farm_id', $this->farm->id);
    }

    public function test_outsider_cannot_show_farm_request(): void
    {
        $request = $this->createDiagnosisRequest($this->farmUser);

        $this->actingAs($this->outsider, 'sanctum')
            ->getJson($this->nutrientDiagnosisUrl($this->farm, (string) $request->id))
            ->assertForbidden();
    }

    public function test_farm_user_can_store_nutrient_diagnosis_request(): void
    {
        Notification::fake();

        $response = $this->actingAs($this->farmUser, 'sanctum')
            ->postJson($this->nutrientDiagnosisUrl($this->farm), $this->validSamplePayload());

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'pending');
        $response->assertJsonPath('data.farm_id', $this->farm->id);
        $response->assertJsonCount(1, 'data.samples');

        $this->assertDatabaseHas('nutrient_diagnosis_requests', [
            'farm_id' => $this->farm->id,
            'user_id' => $this->farmUser->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('nutrient_samples', [
            'field_id' => $this->field->id,
        ]);

        Notification::assertSentTo($this->rootUser, NewNutrientDiagnosisRequest::class);
    }

    public function test_outsider_cannot_store_nutrient_diagnosis_request(): void
    {
        $this->actingAs($this->outsider, 'sanctum')
            ->postJson($this->nutrientDiagnosisUrl($this->farm), $this->validSamplePayload())
            ->assertForbidden();

        $this->assertDatabaseCount('nutrient_diagnosis_requests', 0);
    }

    public function test_store_validates_required_sample_fields(): void
    {
        $this->actingAs($this->farmUser, 'sanctum')
            ->postJson($this->nutrientDiagnosisUrl($this->farm), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['samples']);
    }

    public function test_store_validates_field_exists(): void
    {
        $payload = $this->validSamplePayload();
        $payload['samples'][0]['field_id'] = 99999;

        $this->actingAs($this->farmUser, 'sanctum')
            ->postJson($this->nutrientDiagnosisUrl($this->farm), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['samples.0.field_id']);
    }

    public function test_owner_can_update_pending_request(): void
    {
        $request = $this->createDiagnosisRequest($this->farmUser);
        $otherField = Field::factory()->create(['farm_id' => $this->farm->id]);

        $payload = $this->validSamplePayload($otherField);
        $payload['samples'][0]['nitrogen'] = 9.99;

        $response = $this->actingAs($this->farmUser, 'sanctum')
            ->putJson($this->nutrientDiagnosisUrl($this->farm, (string) $request->id), $payload);

        $response->assertOk();
        $response->assertJsonPath('data.samples.0.field.id', $otherField->id);
        $response->assertJsonPath('data.samples.0.nitrogen', '9.99');

        $this->assertDatabaseHas('nutrient_samples', [
            'nutrient_diagnosis_request_id' => $request->id,
            'field_id' => $otherField->id,
        ]);
        $this->assertDatabaseMissing('nutrient_samples', [
            'nutrient_diagnosis_request_id' => $request->id,
            'field_id' => $this->field->id,
        ]);
    }

    public function test_cannot_update_approved_request(): void
    {
        $request = $this->createDiagnosisRequest($this->farmUser, status: 'approved');

        $this->actingAs($this->farmUser, 'sanctum')
            ->putJson(
                $this->nutrientDiagnosisUrl($this->farm, (string) $request->id),
                $this->validSamplePayload()
            )
            ->assertUnprocessable()
            ->assertJsonFragment([
                'message' => __('Approved requests cannot be edited.'),
            ]);
    }

    public function test_user_cannot_update_another_users_request(): void
    {
        $request = $this->createDiagnosisRequest($this->otherFarmUser);

        $this->actingAs($this->farmUser, 'sanctum')
            ->putJson(
                $this->nutrientDiagnosisUrl($this->farm, (string) $request->id),
                $this->validSamplePayload()
            )
            ->assertForbidden();
    }

    public function test_owner_can_delete_pending_request(): void
    {
        $request = $this->createDiagnosisRequest($this->farmUser);

        $this->actingAs($this->farmUser, 'sanctum')
            ->deleteJson($this->nutrientDiagnosisUrl($this->farm, (string) $request->id))
            ->assertNoContent();

        $this->assertDatabaseMissing('nutrient_diagnosis_requests', ['id' => $request->id]);
    }

    public function test_cannot_delete_approved_request(): void
    {
        $request = $this->createDiagnosisRequest($this->farmUser, status: 'approved');

        $this->actingAs($this->farmUser, 'sanctum')
            ->deleteJson($this->nutrientDiagnosisUrl($this->farm, (string) $request->id))
            ->assertForbidden();

        $this->assertDatabaseHas('nutrient_diagnosis_requests', ['id' => $request->id]);
    }

    public function test_user_cannot_delete_another_users_request(): void
    {
        $request = $this->createDiagnosisRequest($this->otherFarmUser);

        $this->actingAs($this->farmUser, 'sanctum')
            ->deleteJson($this->nutrientDiagnosisUrl($this->farm, (string) $request->id))
            ->assertForbidden();
    }

    public function test_root_can_delete_any_pending_request(): void
    {
        $request = $this->createDiagnosisRequest($this->farmUser);

        $this->actingAs($this->rootUser, 'sanctum')
            ->deleteJson($this->nutrientDiagnosisUrl($this->farm, (string) $request->id))
            ->assertNoContent();

        $this->assertDatabaseMissing('nutrient_diagnosis_requests', ['id' => $request->id]);
    }

    public function test_root_can_approve_pending_request(): void
    {
        $request = $this->createDiagnosisRequest($this->farmUser);

        $response = $this->actingAs($this->rootUser, 'sanctum')
            ->postJson($this->nutrientDiagnosisUrl($this->farm, "{$request->id}/approve"));

        $response->assertOk();
        $response->assertJsonPath('data.status', 'approved');
        $response->assertJsonPath('data.can.respond', true);

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertNotNull($request->approved_at);
    }

    public function test_non_root_cannot_approve_request(): void
    {
        $request = $this->createDiagnosisRequest($this->farmUser);

        $this->actingAs($this->farmUser, 'sanctum')
            ->postJson($this->nutrientDiagnosisUrl($this->farm, "{$request->id}/approve"))
            ->assertForbidden();
    }

    public function test_root_can_reject_pending_request(): void
    {
        $request = $this->createDiagnosisRequest($this->farmUser);

        $response = $this->actingAs($this->rootUser, 'sanctum')
            ->postJson($this->nutrientDiagnosisUrl($this->farm, "{$request->id}/reject"));

        $response->assertOk();
        $response->assertJsonPath('data.status', 'rejected');

        $request->refresh();
        $this->assertSame('rejected', $request->status);
        $this->assertNull($request->approved_at);
    }

    public function test_non_root_cannot_reject_request(): void
    {
        $request = $this->createDiagnosisRequest($this->farmUser);

        $this->actingAs($this->farmUser, 'sanctum')
            ->postJson($this->nutrientDiagnosisUrl($this->farm, "{$request->id}/reject"))
            ->assertForbidden();
    }

    public function test_send_response_requires_approved_status(): void
    {
        Storage::fake('public');
        $request = $this->createDiagnosisRequest($this->farmUser, status: 'pending');

        $this->actingAs($this->rootUser, 'sanctum')
            ->postJson($this->nutrientDiagnosisUrl($this->farm, "{$request->id}/response"), [
                'description' => 'Analysis complete',
                'attachment' => UploadedFile::fake()->create('report.pdf', 100, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonFragment([
                'message' => __('Only approved requests can receive a response file.'),
            ]);
    }

    public function test_root_can_send_response_for_approved_request(): void
    {
        Notification::fake();
        Storage::fake('public');

        $request = $this->createDiagnosisRequest($this->farmUser, status: 'approved');

        $response = $this->actingAs($this->rootUser, 'sanctum')
            ->postJson($this->nutrientDiagnosisUrl($this->farm, "{$request->id}/response"), [
                'description' => 'Nutrient analysis results',
                'attachment' => UploadedFile::fake()->create('report.pdf', 100, 'application/pdf'),
            ]);

        $response->assertOk();
        $response->assertJson(['message' => 'Response sent successfully']);

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame('Nutrient analysis results', $request->response_description);
        $this->assertNotNull($request->response_attachment);
        Storage::disk('public')->assertExists($request->response_attachment);

        Notification::assertSentTo($this->farmUser, NutrientDiagnosisResponse::class);
    }

    public function test_send_response_validates_required_fields(): void
    {
        $request = $this->createDiagnosisRequest($this->farmUser, status: 'approved');

        $this->actingAs($this->rootUser, 'sanctum')
            ->postJson($this->nutrientDiagnosisUrl($this->farm, "{$request->id}/response"), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['description', 'attachment']);
    }

    public function test_non_root_cannot_send_response(): void
    {
        Storage::fake('public');
        $request = $this->createDiagnosisRequest($this->farmUser, status: 'approved');

        $this->actingAs($this->farmUser, 'sanctum')
            ->postJson($this->nutrientDiagnosisUrl($this->farm, "{$request->id}/response"), [
                'description' => 'Should not work',
                'attachment' => UploadedFile::fake()->create('report.pdf', 100, 'application/pdf'),
            ])
            ->assertForbidden();
    }

    public function test_root_can_export_nutrient_samples(): void
    {
        $this->createDiagnosisRequest($this->farmUser);

        $response = $this->actingAs($this->rootUser, 'sanctum')
            ->get($this->nutrientDiagnosisUrl($this->farm, 'export'));

        $response->assertOk();
        $response->assertDownload(__('nutrient_samples_') . $this->farm->id . '.xlsx');
    }

    public function test_non_root_cannot_export_nutrient_samples(): void
    {
        $this->actingAs($this->farmUser, 'sanctum')
            ->getJson($this->nutrientDiagnosisUrl($this->farm, 'export'))
            ->assertForbidden();
    }

    public function test_resource_includes_correct_can_flags_for_farm_user(): void
    {
        $request = $this->createDiagnosisRequest($this->farmUser);

        $response = $this->actingAs($this->farmUser, 'sanctum')
            ->getJson($this->nutrientDiagnosisUrl($this->farm, (string) $request->id));

        $response->assertOk();
        $response->assertJsonPath('data.can.respond', false);
        $response->assertJsonPath('data.can.update', true);
        $response->assertJsonPath('data.can.delete', true);
    }

    public function test_resource_includes_correct_can_flags_for_root_user(): void
    {
        $request = $this->createDiagnosisRequest($this->farmUser);

        $response = $this->actingAs($this->rootUser, 'sanctum')
            ->getJson($this->nutrientDiagnosisUrl($this->farm, (string) $request->id));

        $response->assertOk();
        $response->assertJsonPath('data.can.respond', true);
        $response->assertJsonPath('data.can.update', true);
        $response->assertJsonPath('data.can.delete', true);
    }

    public function test_resource_shows_update_disabled_for_approved_request(): void
    {
        $request = $this->createDiagnosisRequest($this->farmUser, status: 'approved');

        $this->actingAs($this->farmUser, 'sanctum')
            ->getJson($this->nutrientDiagnosisUrl($this->farm, (string) $request->id))
            ->assertOk()
            ->assertJsonPath('data.can.update', false)
            ->assertJsonPath('data.can.delete', false);
    }
}
