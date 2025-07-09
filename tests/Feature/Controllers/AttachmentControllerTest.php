<?php

namespace Tests\Feature\Controllers;

use App\Models\Farm;
use App\Models\Field;
use App\Models\Plot;
use App\Models\User;
use App\Models\Attachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @method void actingAs(\Illuminate\Contracts\Auth\Authenticatable $user, string|null $guard = null)
 */
class AttachmentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Farm $farm;
    protected Field $field;
    protected Plot $plot;

    public function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        /** @var User $user */
        $this->user = User::factory()->create();
        $this->farm = Farm::factory()->create();
        $this->field = Field::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        // Associate user with farm
        $this->farm->users()->attach($this->user->id, ['is_owner' => true]);

        $this->plot = Plot::factory()->create([
            'field_id' => $this->field->id,
        ]);
    }

    #[Test]
    public function user_can_upload_attachment()
    {
        $this->actingAs($this->user);

        $file = UploadedFile::fake()->create('document.pdf', 100);

        $response = $this->postJson('/api/attachments', [
            'name' => 'Test Document',
            'description' => 'This is a test document',
            'file' => $file,
            'attachable_type' => 'Plot',
            'attachable_id' => $this->plot->id
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'description',
                    'url',
                    'size',
                    'extension'
                ]
            ]);

        $this->assertDatabaseHas('attachments', [
            'name' => 'Test Document',
            'description' => 'This is a test document',
            'user_id' => $this->user->id,
            'attachable_type' => 'App\\Models\\Plot',
            'attachable_id' => $this->plot->id
        ]);
    }

    #[Test]
    public function user_can_update_attachment()
    {
        $this->actingAs($this->user);

        $attachment = Attachment::factory()->create([
            'user_id' => $this->user->id,
            'attachable_type' => 'App\\Models\\Plot',
            'attachable_id' => $this->plot->id
        ]);
        $attachment->addMedia(UploadedFile::fake()->create('old.pdf'))->toMediaCollection('attachments');

        $newFile = UploadedFile::fake()->create('new.pdf', 100);

        $response = $this->putJson("/api/attachments/{$attachment->id}", [
            'name' => 'Updated Document',
            'description' => 'This is an updated document',
            'file' => $newFile
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'description',
                    'url',
                    'size',
                    'extension'
                ]
            ]);

        $this->assertDatabaseHas('attachments', [
            'id' => $attachment->id,
            'name' => 'Updated Document',
            'description' => 'This is an updated document'
        ]);
    }

    #[Test]
    public function user_can_delete_attachment()
    {
        $this->actingAs($this->user);

        $attachment = Attachment::factory()->create([
            'user_id' => $this->user->id,
            'attachable_type' => 'App\\Models\\Plot',
            'attachable_id' => $this->plot->id
        ]);
        $attachment->addMedia(UploadedFile::fake()->create('document.pdf'))->toMediaCollection('attachments');

        $response = $this->deleteJson("/api/attachments/{$attachment->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        $this->assertDatabaseMissing('media', ['model_id' => $attachment->id]);
    }

    #[Test]
    public function unauthorized_user_cannot_modify_others_attachment()
    {
        $otherUser = User::factory()->create();
        $this->actingAs($otherUser);

        $attachment = Attachment::factory()->create([
            'user_id' => $this->user->id,
            'attachable_type' => 'App\\Models\\Plot',
            'attachable_id' => $this->plot->id
        ]);

        $response = $this->putJson("/api/attachments/{$attachment->id}", [
            'name' => 'Updated Document',
            'description' => 'This is an updated document'
        ]);

        $response->assertStatus(403);
    }
}
