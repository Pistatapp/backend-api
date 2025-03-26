<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_locale_is_english()
    {
        $response = $this->get('/');
        $this->assertEquals('en', app()->getLocale());
    }

    public function test_user_preferred_locale_is_applied()
    {
        $user = User::factory()->create([
            'preferences' => ['language' => 'fa']
        ]);

        $response = $this->actingAs($user)->get('/');
        $this->assertEquals('fa', app()->getLocale());
    }

    public function test_locale_falls_back_to_default_when_no_user_preference()
    {
        $user = User::factory()->create([
            'preferences' => []
        ]);

        $response = $this->actingAs($user)->get('/');
        $this->assertEquals('en', app()->getLocale());
    }
}
