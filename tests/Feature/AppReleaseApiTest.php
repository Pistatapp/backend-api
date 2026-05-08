<?php

namespace Tests\Feature;

use App\Models\AppRelease;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AppReleaseApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('root');
        Role::findOrCreate('operator');
    }

    public function test_root_user_can_upload_a_new_application_release(): void
    {
        /** @var User $root */
        $root = User::factory()->create(['is_active' => true]);
        $root->assignRole('root');

        $response = $this->actingAs($root)->postJson('/api/app-releases', [
            'version' => 'v12.0.11',
            'release_notes' => 'Bug fixes and performance improvements.',
            'file' => UploadedFile::fake()->create('pistat-v12.0.11.apk', 1024, 'application/octet-stream'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.version', 'v12.0.11');

        $this->assertDatabaseHas('app_releases', [
            'version' => 'v12.0.11',
            'created_by' => $root->id,
        ]);
    }

    public function test_non_root_user_cannot_upload_release(): void
    {
        /** @var User $operator */
        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole('operator');

        $response = $this->actingAs($operator)->postJson('/api/app-releases', [
            'version' => 'v12.0.11',
            'release_notes' => 'Notes',
            'file' => UploadedFile::fake()->create('pistat-v12.0.11.apk', 1024, 'application/octet-stream'),
        ]);

        $response->assertForbidden();
    }

    public function test_user_can_get_latest_release_and_download_it(): void
    {
        /** @var User $root */
        $root = User::factory()->create(['is_active' => true]);
        $root->assignRole('root');

        $release = AppRelease::create([
            'version' => 'v12.0.11',
            'release_notes' => 'Latest release notes.',
            'created_by' => $root->id,
            'published_at' => now(),
        ]);

        $release
            ->addMedia(UploadedFile::fake()->create('pistat-v12.0.11.apk', 1024, 'application/octet-stream'))
            ->toMediaCollection('package');

        /** @var User $user */
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('operator');

        $latestResponse = $this->actingAs($user)->getJson('/api/app-releases/latest');
        $latestResponse->assertOk()
            ->assertJsonPath('data.id', $release->id)
            ->assertJsonPath('data.version', 'v12.0.11');

        $downloadResponse = $this->actingAs($user)
            ->get("/api/app-releases/{$release->id}/download");

        $downloadResponse->assertOk();
        $downloadResponse->assertHeader('content-disposition');
    }
}
