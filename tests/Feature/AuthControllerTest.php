<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    public function test_login_tokens_can_be_sent_to_mobile()
    {
        $response = $this->postJson('/api/auth/send', [
            'mobile' => '9123456789',
        ]);

        $response->assertJson([
            'message' => 'Verification token sent successfully.',
        ]);
    }
}
