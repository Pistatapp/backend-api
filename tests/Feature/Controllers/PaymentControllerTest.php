<?php

namespace Tests\Feature\Controllers;

use App\Models\Payment;
use App\Models\User;
use App\Models\Farm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Farm $farm;
    protected bool $isLocalEnvironment;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test user and authenticate
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');

        // Create a farm for testing payable relationship
        $this->farm = Farm::factory()->create();

        // Determine if we're in a local environment
        $this->isLocalEnvironment = in_array(app()->environment(), ['local', 'testing']);
    }

    #[Test]
    public function request_validates_required_fields(): void
    {
        $response = $this->postJson('/api/payments/request', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount', 'description', 'payable_type', 'payable_id']);
    }

    #[Test]
    public function request_validates_minimum_amount(): void
    {
        $response = $this->postJson('/api/payments/request', [
            'amount' => 500,
            'description' => 'Test payment',
            'payable_type' => Farm::class,
            'payable_id' => $this->farm->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    #[Test]
    public function request_validates_mobile_format(): void
    {
        $response = $this->postJson('/api/payments/request', [
            'amount' => 1000,
            'description' => 'Test payment',
            'payable_type' => Farm::class,
            'payable_id' => $this->farm->id,
            'mobile' => '1234567890', // Invalid Iranian mobile format
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['mobile']);
    }

    #[Test]
    public function request_handles_ip_mismatch_in_local_environment(): void
    {
        if (!$this->isLocalEnvironment) {
            $this->markTestSkipped('This test only runs in local environment.');
        }

        $this->mock_zarinpal_ip_mismatch();

        $response = $this->postJson('/api/payments/request', [
            'amount' => 10000,
            'description' => 'Test payment',
            'payable_type' => Farm::class,
            'payable_id' => $this->farm->id,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'آی‌پی و یا مرچنت كد پذیرنده صحیح نیست.'
            ]);

        $this->assertDatabaseHas('payments', [
            'user_id' => $this->user->id,
            'status' => 'failed',
            'amount' => 10000,
        ]);
    }

    #[Test]
    public function request_creates_pending_payment_record_in_production(): void
    {
        if ($this->isLocalEnvironment) {
            $this->markTestSkipped('This test only runs in production environment.');
        }

        $this->mock_zarinpal_success();

        $response = $this->postJson('/api/payments/request', [
            'amount' => 10000,
            'description' => 'Test payment',
            'payable_type' => Farm::class,
            'payable_id' => $this->farm->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'status',
                'payment_id',
                'redirect_url',
                'authority'
            ]);

        $this->assertDatabaseHas('payments', [
            'user_id' => $this->user->id,
            'amount' => 10000,
            'description' => 'Test payment',
            'status' => 'pending',
            'payable_type' => Farm::class,
            'payable_id' => $this->farm->id,
        ]);
    }

    #[Test]
    public function request_handles_zarinpal_failure(): void
    {
        $this->mock_zarinpal_failure();

        $response = $this->postJson('/api/payments/request', [
            'amount' => 10000,
            'description' => 'Test payment',
            'payable_type' => Farm::class,
            'payable_id' => $this->farm->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'status',
                'message'
            ]);

        $this->assertDatabaseHas('payments', [
            'user_id' => $this->user->id,
            'status' => 'failed',
        ]);
    }

    #[Test]
    public function verify_handles_user_cancellation(): void
    {
        // Create a pending payment
        $payment = Payment::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'pending',
            'authority' => 'A00000-000-000-000-000000',
        ]);

        $response = $this->getJson("/api/payments/verify?Authority={$payment->authority}&Status=NOK");

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'Payment was canceled by user'
            ]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'canceled',
        ]);
    }

    #[Test]
    public function verify_handles_ip_mismatch_in_local_environment(): void
    {
        if (!$this->isLocalEnvironment) {
            $this->markTestSkipped('This test only runs in local environment.');
        }

        $payment = Payment::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'pending',
            'authority' => 'A00000-000-000-000-000000',
            'amount' => 10000,
        ]);

        $this->mock_zarinpal_verification_ip_mismatch();

        $response = $this->getJson("/api/payments/verify?Authority={$payment->authority}&Status=OK");

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'آی‌پی و یا مرچنت كد پذیرنده صحیح نیست.'
            ]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'failed',
        ]);
    }

    #[Test]
    public function verify_handles_successful_payment_in_production(): void
    {
        if ($this->isLocalEnvironment) {
            $this->markTestSkipped('This test only runs in production environment.');
        }

        // Create a pending payment
        $payment = Payment::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'pending',
            'authority' => 'A00000-000-000-000-000000',
            'amount' => 10000,
        ]);

        $this->mock_zarinpal_verification_success();

        $response = $this->getJson("/api/payments/verify?Authority={$payment->authority}&Status=OK");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'completed',
            'reference_id' => '100000000',
        ]);
    }

    #[Test]
    public function verify_handles_verification_failure(): void
    {
        // Create a pending payment
        $payment = Payment::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'pending',
            'authority' => 'A00000-000-000-000-000000',
            'amount' => 10000,
        ]);

        $this->mock_zarinpal_verification_failure();

        $response = $this->getJson("/api/payments/verify?Authority={$payment->authority}&Status=OK");

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
            ]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'failed',
        ]);
    }

    #[Test]
    public function verify_returns_404_for_invalid_authority(): void
    {
        $response = $this->getJson("/api/payments/verify?Authority=invalid-authority&Status=OK");

        $response->assertStatus(404)
            ->assertJson([
                'status' => 'error',
                'message' => 'Payment information not found'
            ]);
    }

    /**
     * Helper method to mock successful Zarinpal payment request
     */
    private function mock_zarinpal_success(): void
    {
        $response = Mockery::mock();
        $response->shouldReceive('success')->andReturn(true);
        $response->shouldReceive('authority')->andReturn('A00000-000-000-000-000000');
        $response->shouldReceive('redirect')->andReturn('https://sandbox.zarinpal.com/pg/StartPay/A00000-000-000-000-000000');

        $mockZarinpal = Mockery::mock();
        $mockZarinpal->shouldReceive('merchantId')->withAnyArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('amount')->withAnyArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('request')->withNoArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('description')->withAnyArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('callbackUrl')->withAnyArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('send')->withNoArgs()->andReturn($response);

        app()->bind('zarinpal', fn() => $mockZarinpal);
    }

    /**
     * Helper method to mock failed Zarinpal payment request
     */
    private function mock_zarinpal_failure(): void
    {
        $error = Mockery::mock();
        $error->shouldReceive('message')->andReturn('آی‌پی و یا مرچنت كد پذیرنده صحیح نیست.');

        $response = Mockery::mock();
        $response->shouldReceive('success')->andReturn(false);
        $response->shouldReceive('error')->andReturn($error);

        $mockZarinpal = Mockery::mock();
        $mockZarinpal->shouldReceive('merchantId')->withAnyArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('amount')->withAnyArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('request')->withNoArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('description')->withAnyArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('callbackUrl')->withAnyArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('send')->withNoArgs()->andReturn($response);

        app()->bind('zarinpal', fn() => $mockZarinpal);
    }

    /**
     * Helper method to mock failed Zarinpal payment request due to IP mismatch
     */
    private function mock_zarinpal_ip_mismatch(): void
    {
        $error = Mockery::mock();
        $error->shouldReceive('message')->andReturn('آی‌پی و یا مرچنت كد پذیرنده صحیح نیست.');

        $response = Mockery::mock();
        $response->shouldReceive('success')->andReturn(false);
        $response->shouldReceive('error')->andReturn($error);

        $mockZarinpal = Mockery::mock();
        $mockZarinpal->shouldReceive('merchantId')->withAnyArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('amount')->withAnyArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('request')->withNoArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('description')->withAnyArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('callbackUrl')->withAnyArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('send')->withNoArgs()->andReturn($response);

        app()->bind('zarinpal', fn() => $mockZarinpal);
    }

    /**
     * Helper method to mock successful Zarinpal payment verification
     */
    private function mock_zarinpal_verification_success(): void
    {
        $response = Mockery::mock();
        $response->shouldReceive('success')->andReturn(true);
        $response->shouldReceive('referenceId')->andReturn('100000000');
        $response->shouldReceive('cardPan')->andReturn('6037****1234');
        $response->shouldReceive('cardHash')->andReturn('1234567890ABCDEF');

        $mockZarinpal = Mockery::mock();
        $mockZarinpal->shouldReceive('merchantId')->withAnyArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('amount')->withAnyArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('authority')->withAnyArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('verification')->withNoArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('send')->withNoArgs()->andReturn($response);

        app()->bind('zarinpal', fn() => $mockZarinpal);
    }

    /**
     * Helper method to mock failed Zarinpal payment verification
     */
    private function mock_zarinpal_verification_failure(): void
    {
        $error = Mockery::mock();
        $error->shouldReceive('message')->andReturn('Verification failed');

        $response = Mockery::mock();
        $response->shouldReceive('success')->andReturn(false);
        $response->shouldReceive('error')->andReturn($error);

        $mockZarinpal = Mockery::mock();
        $mockZarinpal->shouldReceive('merchantId')->withAnyArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('amount')->withAnyArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('authority')->withAnyArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('verification')->withNoArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('send')->withNoArgs()->andReturn($response);

        app()->bind('zarinpal', fn() => $mockZarinpal);
    }

    /**
     * Helper method to mock failed Zarinpal payment verification due to IP mismatch
     */
    private function mock_zarinpal_verification_ip_mismatch(): void
    {
        $error = Mockery::mock();
        $error->shouldReceive('message')->andReturn('آی‌پی و یا مرچنت كد پذیرنده صحیح نیست.');

        $response = Mockery::mock();
        $response->shouldReceive('success')->andReturn(false);
        $response->shouldReceive('error')->andReturn($error);

        $mockZarinpal = Mockery::mock();
        $mockZarinpal->shouldReceive('merchantId')->withAnyArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('amount')->withAnyArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('authority')->withAnyArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('verification')->withNoArgs()->andReturnSelf();
        $mockZarinpal->shouldReceive('send')->withNoArgs()->andReturn($response);

        app()->bind('zarinpal', fn() => $mockZarinpal);
    }
}
