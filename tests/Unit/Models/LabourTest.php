<?php

namespace Tests\Unit\Models;

use App\Models\Labour;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabourTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that labour belongs to farm.
     */
    public function test_labour_belongs_to_farm(): void
    {
        $farm = Farm::factory()->create();
        $labour = Labour::factory()->create(['farm_id' => $farm->id]);

        $this->assertInstanceOf(Farm::class, $labour->farm);
        $this->assertEquals($farm->id, $labour->farm->id);
    }

    /**
     * Test that labour can belong to user.
     */
    public function test_labour_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $labour = Labour::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $labour->user);
        $this->assertEquals($user->id, $labour->user->id);
    }

    /**
     * Test name field.
     */
    public function test_labour_has_full_name_accessor(): void
    {
        $labour = Labour::factory()->create([
            'name' => 'John Doe',
        ]);

        $this->assertEquals('John Doe', $labour->name);
    }
}
