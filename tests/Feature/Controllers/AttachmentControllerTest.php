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
use Illuminate\Support\Facades\Artisan;

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

    #[Test]
    public function attachment_url_should_be_accessible()
    {
        $this->actingAs($this->user);

        // Create and upload a file through the API
        $pdfContent = '%PDF-1.4
1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj
2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj
3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R>>endobj
xref
0 4
0000000000 65535 f
0000000009 00000 n
0000000052 00000 n
0000000101 00000 n
trailer<</Size 4/Root 1 0 R>>
startxref
178
%%EOF';

        $file = UploadedFile::fake()->createWithContent('test.pdf', $pdfContent);

        $response = $this->postJson('/api/attachments', [
            'name' => 'Test Document',
            'description' => 'This is a test document',
            'file' => $file,
            'attachable_type' => 'Plot',
            'attachable_id' => $this->plot->id
        ]);

        $response->assertStatus(201);

        // Get the attachment from database
        $attachment = Attachment::first();
        $this->assertNotNull($attachment, 'Attachment should be created');

        // Get the media
        $media = $attachment->getFirstMedia('attachments');
        $this->assertNotNull($media, 'Media should be attached');

        // Assert that file exists in storage
        $this->assertTrue($media->exists(), 'Media file should exist in storage');
        $this->assertEquals('application/pdf', $media->mime_type, 'File should be a PDF');
    }
}
