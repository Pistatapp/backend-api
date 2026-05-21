<?php

namespace Tests\Feature;

use App\Models\AppRelease;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AppReleaseApiTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_URL = '/api/app-releases';

    private const LATEST_URL = '/api/app-releases/latest';

    private const UPLOAD_URL = '/api/upload';

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('root');
        Role::findOrCreate('operator');
    }

    public function test_unauthenticated_users_cannot_access_app_release_endpoints(): void
    {
        $release = $this->createRelease($this->createRootUser());

        $this->postJson(self::STORE_URL, $this->validStorePayload())->assertUnauthorized();
        $this->getJson(self::LATEST_URL)->assertUnauthorized();
        $this->getJson("/api/app-releases/{$release->id}/download")->assertUnauthorized();
        $this->post(self::UPLOAD_URL, [
            'file' => UploadedFile::fake()->create('pistat.apk', 1024),
        ], ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_user_without_username_cannot_access_app_release_endpoints(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'username' => null,
        ]);
        $user->assignRole('operator');

        $release = $this->createRelease($this->createRootUser());

        $this->actingAs($user)->postJson(self::STORE_URL, $this->validStorePayload())->assertForbidden();
        $this->actingAs($user)->getJson(self::LATEST_URL)->assertForbidden();
        $this->actingAs($user)->getJson("/api/app-releases/{$release->id}/download")->assertForbidden();
        $this->actingAs($user)
            ->post(self::UPLOAD_URL, ['file' => UploadedFile::fake()->create('pistat.apk', 1024)])
            ->assertForbidden();
    }

    public function test_authenticated_user_can_upload_file_via_upload_endpoint(): void
    {
        $root = $this->createRootUser();

        $response = $this->actingAs($root)->post(self::UPLOAD_URL, [
            'file' => UploadedFile::fake()->create('pistat-v1.0.0.apk', 1024, 'application/octet-stream'),
        ]);

        $response->assertOk()
            ->assertJsonStructure(['path', 'name', 'mime_type']);

        $path = $response->json('path');
        $name = $response->json('name');

        $this->assertFileExists(storage_path('app/'.$path.$name));
    }

    public function test_root_user_can_upload_a_new_application_release(): void
    {
        $root = $this->createRootUser();

        $response = $this->actingAs($root)->postJson(self::STORE_URL, $this->validStorePayload([
            'version' => 'v12.0.11',
            'release_notes' => 'Bug fixes and performance improvements.',
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.version', 'v12.0.11')
            ->assertJsonPath('data.release_notes', 'Bug fixes and performance improvements.')
            ->assertJsonPath('data.created_by', $root->id);

        $this->assertDatabaseHas('app_releases', [
            'version' => 'v12.0.11',
            'release_notes' => 'Bug fixes and performance improvements.',
            'created_by' => $root->id,
        ]);

        $release = AppRelease::query()->where('version', 'v12.0.11')->first();
        $this->assertNotNull($release->published_at);
        $this->assertNotNull($release->packageMedia());
    }

    public function test_store_returns_complete_resource_structure(): void
    {
        $root = $this->createRootUser();

        $response = $this->actingAs($root)->postJson(self::STORE_URL, $this->validStorePayload([
            'version' => 'v2.1.0',
        ]));

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'version',
                    'release_notes',
                    'published_at',
                    'download_url',
                    'file' => ['name', 'size', 'mime_type'],
                    'created_by',
                    'created_at',
                ],
            ]);

        $releaseId = $response->json('data.id');
        $response->assertJsonPath('data.download_url', route('app-releases.download', ['appRelease' => $releaseId]));
        $response->assertJsonPath('data.file.name', 'pistat-v2.1.0.apk');
    }

    public function test_store_accepts_version_with_prerelease_or_build_metadata(): void
    {
        $root = $this->createRootUser();

        $response = $this->actingAs($root)->postJson(self::STORE_URL, $this->validStorePayload([
            'version' => 'v3.0.0-beta.1',
            'file' => $this->stageUploadedPackageFile('pistat-v3.0.0-beta.1.apk'),
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.version', 'v3.0.0-beta.1');
    }

    public function test_store_accepts_nullable_release_notes(): void
    {
        $root = $this->createRootUser();

        $response = $this->actingAs($root)->postJson(self::STORE_URL, [
            'version' => 'v4.0.0',
            'file' => $this->stageUploadedPackageFile('pistat-v4.0.0.apk'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.release_notes', null);

        $this->assertDatabaseHas('app_releases', [
            'version' => 'v4.0.0',
            'release_notes' => null,
        ]);
    }

    public function test_non_root_user_cannot_upload_release(): void
    {
        $operator = $this->createOperatorUser();

        $this->actingAs($operator)
            ->postJson(self::STORE_URL, $this->validStorePayload())
            ->assertForbidden();

        $this->assertDatabaseCount('app_releases', 0);
    }

    public function test_store_requires_version(): void
    {
        $root = $this->createRootUser();

        $this->actingAs($root)
            ->postJson(self::STORE_URL, [
                'release_notes' => 'Notes',
                'file' => $this->stageUploadedPackageFile('pistat.apk'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['version']);
    }

    public function test_store_requires_file_metadata(): void
    {
        $root = $this->createRootUser();

        $this->actingAs($root)
            ->postJson(self::STORE_URL, [
                'version' => 'v5.0.0',
                'release_notes' => 'Notes',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        $this->actingAs($root)
            ->postJson(self::STORE_URL, [
                'version' => 'v5.0.1',
                'release_notes' => 'Notes',
                'file' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file.name', 'file.path', 'file.mime_type']);
    }

    public function test_store_rejects_invalid_version_format(): void
    {
        $root = $this->createRootUser();

        $this->actingAs($root)
            ->postJson(self::STORE_URL, $this->validStorePayload(['version' => '12.0.0']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['version']);

        $this->actingAs($root)
            ->postJson(self::STORE_URL, $this->validStorePayload(['version' => 'v1.0']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['version']);
    }

    public function test_store_rejects_duplicate_version(): void
    {
        $root = $this->createRootUser();
        $this->createRelease($root, ['version' => 'v6.0.0']);

        $this->actingAs($root)
            ->postJson(self::STORE_URL, $this->validStorePayload(['version' => 'v6.0.0']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['version']);
    }

    public function test_store_rejects_missing_staged_package_file(): void
    {
        $root = $this->createRootUser();

        $this->actingAs($root)
            ->postJson(self::STORE_URL, [
                'version' => 'v7.0.0',
                'release_notes' => 'Notes',
                'file' => [
                    'path' => 'upload/application-octet-stream/2099-01-01/',
                    'name' => 'missing.apk',
                    'mime_type' => 'application-octet-stream',
                ],
            ])
            ->assertStatus(500);
    }

    public function test_store_rejects_release_notes_longer_than_allowed(): void
    {
        $root = $this->createRootUser();

        $this->actingAs($root)
            ->postJson(self::STORE_URL, $this->validStorePayload([
                'version' => 'v8.0.0',
                'release_notes' => str_repeat('a', 20001),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['release_notes']);
    }

    public function test_latest_returns_newest_release_by_published_at_then_id(): void
    {
        $root = $this->createRootUser();
        $user = $this->createOperatorUser();

        $older = $this->createRelease($root, [
            'version' => 'v9.0.0',
            'published_at' => Carbon::parse('2026-01-01 10:00:00'),
        ]);
        $newerByDate = $this->createRelease($root, [
            'version' => 'v9.1.0',
            'published_at' => Carbon::parse('2026-02-01 10:00:00'),
        ]);
        $sameDateLowerId = $this->createRelease($root, [
            'version' => 'v9.0.1',
            'published_at' => Carbon::parse('2026-02-01 10:00:00'),
        ]);
        $sameDateHigherId = $this->createRelease($root, [
            'version' => 'v9.2.0',
            'published_at' => Carbon::parse('2026-02-01 10:00:00'),
        ]);

        $this->assertTrue($sameDateHigherId->id > $sameDateLowerId->id);
        $this->assertTrue($older->id < $newerByDate->id);

        $this->actingAs($user)
            ->getJson(self::LATEST_URL)
            ->assertOk()
            ->assertJsonPath('data.id', $sameDateHigherId->id)
            ->assertJsonPath('data.version', 'v9.2.0');
    }

    public function test_latest_returns_404_when_no_releases_exist(): void
    {
        $user = $this->createOperatorUser();

        $this->actingAs($user)
            ->getJson(self::LATEST_URL)
            ->assertNotFound();
    }

    public function test_latest_includes_download_url_and_file_metadata(): void
    {
        $root = $this->createRootUser();
        $user = $this->createOperatorUser();

        $release = $this->createRelease($root, [
            'version' => 'v10.0.0',
            'release_notes' => 'Latest release notes.',
        ]);

        $this->actingAs($user)
            ->getJson(self::LATEST_URL)
            ->assertOk()
            ->assertJsonPath('data.id', $release->id)
            ->assertJsonPath('data.version', 'v10.0.0')
            ->assertJsonPath('data.release_notes', 'Latest release notes.')
            ->assertJsonPath('data.download_url', route('app-releases.download', ['appRelease' => $release->id]))
            ->assertJsonPath('data.file.name', 'pistat-v10.0.0.apk')
            ->assertJsonStructure([
                'data' => [
                    'published_at',
                    'file' => ['size', 'mime_type'],
                ],
            ]);
    }

    public function test_authenticated_user_can_download_release_package(): void
    {
        $root = $this->createRootUser();
        $user = $this->createOperatorUser();
        $release = $this->createRelease($root, ['version' => 'v11.0.0']);

        $response = $this->actingAs($user)
            ->get("/api/app-releases/{$release->id}/download");

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('pistat-v11.0.0.apk', (string) $response->headers->get('content-disposition'));
    }

    public function test_download_returns_404_when_package_is_missing(): void
    {
        $root = $this->createRootUser();
        $user = $this->createOperatorUser();

        $release = AppRelease::create([
            'version' => 'v11.1.0',
            'release_notes' => 'No package attached.',
            'created_by' => $root->id,
            'published_at' => now(),
        ]);

        $this->actingAs($user)
            ->get("/api/app-releases/{$release->id}/download")
            ->assertNotFound();
    }

    public function test_download_returns_404_for_nonexistent_release(): void
    {
        $user = $this->createOperatorUser();

        $this->actingAs($user)
            ->get('/api/app-releases/99999/download')
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validStorePayload(array $overrides = []): array
    {
        $version = $overrides['version'] ?? 'v12.0.11';

        return array_merge([
            'version' => $version,
            'release_notes' => 'Release notes.',
            'file' => $this->stageUploadedPackageFile("pistat-{$version}.apk"),
        ], $overrides);
    }

    /**
     * @return array{path: string, name: string, mime_type: string}
     */
    private function stageUploadedPackageFile(string $fileName, string $contents = 'fake apk content'): array
    {
        $mime = 'application/octet-stream';
        $mimeFolder = str_replace('/', '-', $mime);
        $dateFolder = date('Y-m-W');
        $relativePath = "upload/{$mimeFolder}/{$dateFolder}/";

        Storage::disk('local')->makeDirectory($relativePath);
        Storage::disk('local')->put($relativePath.$fileName, $contents);

        return [
            'path' => $relativePath,
            'name' => $fileName,
            'mime_type' => $mime,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createRelease(User $creator, array $attributes = [], bool $withPackage = true): AppRelease
    {
        $release = AppRelease::create(array_merge([
            'version' => 'v1.0.0',
            'release_notes' => 'Test release.',
            'created_by' => $creator->id,
            'published_at' => now(),
        ], $attributes));

        if ($withPackage) {
            $fileName = 'pistat-'.($attributes['version'] ?? 'v1.0.0').'.apk';
            $release
                ->addMedia(UploadedFile::fake()->create($fileName, 1024, 'application/octet-stream'))
                ->toMediaCollection('package');
        }

        return $release->fresh();
    }

    private function createRootUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('root');

        return $user;
    }

    private function createOperatorUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('operator');

        return $user;
    }
}
