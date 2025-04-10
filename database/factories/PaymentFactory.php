<?php

namespace Database\Factories;

use App\Models\Farm;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'amount' => fake()->numberBetween(1000, 1000000),
            'description' => fake()->sentence(),
            'authority' => fake()->regexify('[A-Z0-9]{20}'),
            'reference_id' => fake()->regexify('[0-9]{8}'),
            'card_pan' => '6037' . fake()->regexify('[0-9]{12}'),
            'card_hash' => fake()->regexify('[A-F0-9]{16}'),
            'status' => fake()->randomElement(['pending', 'completed', 'failed', 'canceled']),
            'payable_type' => Farm::class,
            'payable_id' => Farm::factory(),
        ];
    }

    /**
     * Set the payment status to pending
     */
    public function pending(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    /**
     * Set the payment status to completed
     */
    public function completed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }

    /**
     * Set the payment status to failed
     */
    public function failed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
        ]);
    }

    /**
     * Set the payment status to canceled
     */
    public function canceled(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'canceled',
        ]);
    }
}
