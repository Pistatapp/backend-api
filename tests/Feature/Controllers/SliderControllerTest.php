<?php

namespace Tests\Feature\Controllers;

use Tests\TestCase;
use App\Models\User;
use App\Models\Slider;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class SliderControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $rootUser;
    private Slider $slider;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles and permissions
        $this->seed(RolePermissionSeeder::class);

        // Create regular user
        $this->user = User::factory()->create();
        $this->user->assignRole('admin');

        // Create root user
        $this->rootUser = User::factory()->create();
        $this->rootUser->assignRole('root');

        // Create a test slider
        $this->slider = Slider::factory()->create(['page' => 'home']);
    }

    #[Test]
    public function any_user_can_view_sliders_list()
    {
        $this->actingAs($this->user);
        // Create sliders for different pages
        Slider::factory()->count(2)->create(['page' => 'home']);
        Slider::factory()->count(3)->create(['page' => 'about']);

        $response = $this->getJson('/api/sliders');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'images',
                        'page',
                        'is_active',
                        'interval'
                    ]
                ]
            ]);

        $this->assertCount(6, $response->json('data')); // Including the one from setUp
    }

    #[Test]
    public function can_filter_sliders_by_page()
    {
        $this->actingAs($this->user);
        // Create sliders for different pages
        Slider::factory()->count(2)->create(['page' => 'home']);
        Slider::factory()->count(3)->create(['page' => 'about']);

        // Test filtering by home page
        $homeResponse = $this->getJson('/api/sliders?page=home');
        $homeResponse->assertOk();
        $this->assertCount(3, $homeResponse->json('data')); // 2 + 1 from setUp
        foreach ($homeResponse->json('data') as $slider) {
            $this->assertEquals('home', $slider['page']);
        }

        // Test filtering by about page
        $aboutResponse = $this->getJson('/api/sliders?page=about');
        $aboutResponse->assertOk();
        $this->assertCount(3, $aboutResponse->json('data'));
        foreach ($aboutResponse->json('data') as $slider) {
            $this->assertEquals('about', $slider['page']);
        }
    }

    #[Test]
    public function any_user_can_view_slider_details()
    {
        $this->actingAs($this->user);
        $response = $this->getJson("/api/sliders/{$this->slider->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'images',
                    'page',
                    'is_active',
                    'interval'
                ]
            ]);
    }

    #[Test]
    public function non_root_user_cannot_create_slider()
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('slider1.jpg', 100, 100);

        $data = [
            'name' => 'Test Slider',
            'page' => 'home',
            'is_active' => true,
            'interval' => 5,
            'images' => [
                [
                    'sort_order' => 1,
                    'file' => $file
                ]
            ]
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/sliders', $data);

        $response->assertForbidden();
    }

    #[Test]
    public function root_user_can_create_slider_with_multiple_images()
    {
        Storage::fake('public');

        $data = [
            'name' => 'Test Slider',
            'page' => 'home',
            'is_active' => true,
            'interval' => 5,
            'images' => [
                [
                    'sort_order' => 1,
                    'file' => UploadedFile::fake()->create('slider1.jpg')
                ],
                [
                    'sort_order' => 2,
                    'file' => UploadedFile::fake()->create('slider2.jpg')
                ],
                [
                    'sort_order' => 3,
                    'file' => UploadedFile::fake()->create('slider3.jpg')
                ]
            ]
        ];

        $response = $this->actingAs($this->rootUser)
            ->postJson('/api/sliders', $data);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'images',
                    'page',
                    'is_active',
                    'interval'
                ]
            ]);

        $slider = Slider::first();
        $this->assertCount(3, $slider->images);
    }

    #[Test]
    public function non_root_user_cannot_update_slider()
    {
        Storage::fake('public');

        $data = [
            'name' => 'Updated Slider',
            'page' => 'about',
            'is_active' => false,
            'interval' => 3,
            'images' => [
                [
                    'sort_order' => 1,
                    'file' => UploadedFile::fake()->image('updated1.jpg', 100, 100)
                ]
            ]
        ];

        $response = $this->actingAs($this->user)
            ->putJson("/api/sliders/{$this->slider->id}", $data);

        $response->assertForbidden();
    }

    #[Test]
    public function root_user_can_update_slider_with_multiple_images()
    {
        Storage::fake('public');

        $data = [
            'name' => 'Updated Slider',
            'page' => 'about',
            'is_active' => false,
            'interval' => 3,
            'images' => [
                [
                    'sort_order' => 1,
                    'file' => UploadedFile::fake()->image('updated1.jpg', 100, 100)
                ],
                [
                    'sort_order' => 2,
                    'file' => UploadedFile::fake()->image('updated2.jpg', 100, 100)
                ]
            ]
        ];

        $response = $this->actingAs($this->rootUser)
            ->putJson("/api/sliders/{$this->slider->id}", $data);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'images',
                    'page',
                    'is_active',
                    'interval'
                ]
            ]);

        $slider = $this->slider->fresh();
        $this->assertCount(2, $slider->images);
        foreach ($slider->images as $image) {
            $this->assertTrue(Storage::disk('public')->exists($image['path']));
        }
    }

    #[Test]
    public function non_root_user_cannot_delete_slider()
    {
        $this->actingAs($this->user);

        $response = $this->deleteJson("/api/sliders/{$this->slider->id}");
        $response->assertForbidden();
        $this->assertDatabaseHas('sliders', ['id' => $this->slider->id]);
    }

    #[Test]
    public function root_user_can_delete_slider()
    {
        $response = $this->actingAs($this->rootUser)
            ->deleteJson("/api/sliders/{$this->slider->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('sliders', ['id' => $this->slider->id]);
    }
}
