<?php

namespace Tests\Feature\Controllers;

use App\Models\Pest;
use App\Models\PhonologyGuideFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;

#[Group('controller')]
#[Group('phonology')]
class PhonologyGuideFileControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $rootUser;
    private Pest $pest;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media-library');

        // Create root role
        Role::create(['name' => 'root']);

        $this->rootUser = User::factory()->create();
        $this->rootUser->assignRole('root');

        $this->pest = Pest::factory()->create();
    }

    #[Test]
    public function it_lists_phonology_guide_files_for_a_model()
    {
        PhonologyGuideFile::factory()->count(3)->create([
            'phonologyable_type' => Pest::class,
            'phonologyable_id' => $this->pest->id,
            'created_by' => $this->rootUser->id
        ]);

        $response = $this->actingAs($this->rootUser)
            ->getJson("/api/phonology_guide_files?" . http_build_query([
                'model_type' => 'pest',
                'model_id' => $this->pest->id
            ]));

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'name',
                    'created_by',
                    'file',
                    'created_at'
                ]]
            ]);
    }

    #[Test]
    public function it_stores_phonology_guide_file()
    {
        $file = UploadedFile::fake()->create('guide.pdf', 100);

        $response = $this->actingAs($this->rootUser)
            ->postJson("/api/phonology_guide_files", [
                'name' => 'test-guide',
                'file' => $file,
                'model_type' => 'pest',
                'model_id' => $this->pest->id
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'created_by',
                    'file',
                    'created_at'
                ]
            ]);

        $this->assertDatabaseHas('phonology_guide_files', [
            'name' => 'test-guide',
            'phonologyable_type' => Pest::class,
            'phonologyable_id' => $this->pest->id,
            'created_by' => $this->rootUser->id
        ]);

        $phonologyGuideFile = PhonologyGuideFile::first();
        $this->assertTrue($phonologyGuideFile->hasMedia('phonology_guide_files'));
    }

    #[Test]
    public function it_validates_file_upload_requirements()
    {
        $response = $this->actingAs($this->rootUser)
            ->postJson("/api/phonology_guide_files", [
                'name' => 'test-guide',
                'model_type' => 'pest',
                'model_id' => $this->pest->id
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    #[Test]
    public function it_prevents_duplicate_names()
    {
        PhonologyGuideFile::factory()->create([
            'name' => 'test-guide',
            'phonologyable_type' => Pest::class,
            'phonologyable_id' => $this->pest->id,
            'created_by' => $this->rootUser->id
        ]);

        $response = $this->actingAs($this->rootUser)
            ->postJson("/api/phonology_guide_files", [
                'name' => 'test-guide',
                'file' => UploadedFile::fake()->create('guide.pdf', 100),
                'model_type' => 'pest',
                'model_id' => $this->pest->id
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    #[Test]
    public function it_allows_deletion_of_phonology_guide_file()
    {
        $file = PhonologyGuideFile::factory()->create([
            'phonologyable_type' => Pest::class,
            'phonologyable_id' => $this->pest->id,
            'created_by' => $this->rootUser->id
        ]);

        $response = $this->actingAs($this->rootUser)
            ->deleteJson("/api/phonology_guide_files/{$file->id}?" . http_build_query([
                'model_type' => 'pest',
                'model_id' => $this->pest->id
            ]));

        $response->assertNoContent();

        $this->assertDatabaseMissing('phonology_guide_files', ['id' => $file->id]);
    }
}
