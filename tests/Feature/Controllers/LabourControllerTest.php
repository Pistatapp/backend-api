<?php

namespace Tests\Feature\Controllers;

use App\Models\Farm;
use Tests\TestCase;
use App\Models\Labour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LabourControllerTest extends TestCase
{
    use RefreshDatabase;

    private $farm;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->actingAs($user);

        $this->farm = Farm::factory()->create();

        $this->farm->users()->attach($user->id, [
            'role' => 'admin',
            'is_owner' => true,
        ]);
    }

    /** @test */
    public function it_can_list_all_labours()
    {
        Labour::factory(3)->create([
            'farm_id' => $this->farm->id,
        ]);

        $response = $this->getJson("/api/farms/{$this->farm->id}/labours");

        $response->assertStatus(200);
        $response->assertJsonCount(3);
    }

    /** @test */
    public function it_can_create_a_labour()
    {
        $data = [
            'type' => 'permanent_labourer',
            'fname' => 'John',
            'lname' => 'Doe',
            'national_id' => '5380108717',
            'mobile' => '09123456789',
            'position' => 'Worker',
            'work_type' => 'Full-time',
            'work_days' => 5,
            'work_hours' => 8,
            'start_work_time' => '08:00',
            'end_work_time' => '16:00',
            'monthly_salary' => 4000,
        ];

        $response = $this->postJson("/api/farms/{$this->farm->id}/labours", $data);

        $response->assertStatus(201);
        $this->assertDatabaseHas('labours', array_merge($data, ['farm_id' => $this->farm->id]));
    }

    /** @test */
    public function it_can_update_a_labour()
    {
        $labour = Labour::factory()->create([
            'farm_id' => $this->farm->id,
            'national_id' => '5380108717',
            'mobile' => '09123456789',
            'type' => 'permanent_labourer',
        ]);

        $data = [
            'type' => 'permanent_labourer',
            'fname' => 'Updated Name',
            'lname' => $labour->lname,
            'national_id' => $labour->national_id,
            'mobile' => $labour->mobile,
            'position' => $labour->position,
            'work_type' => $labour->work_type,
            'work_days' => $labour->work_days,
            'work_hours' => $labour->work_hours,
            'start_work_time' => $labour->start_work_time,
            'end_work_time' => $labour->end_work_time,
            'monthly_salary' => $labour->monthly_salary,
        ];

        $response = $this->putJson("/api/labours/{$labour->id}", $data);

        $response->assertStatus(200);
        $this->assertDatabaseHas('labours', ['id' => $labour->id, 'fname' => 'Updated Name']);
    }

    /** @test */
    public function it_can_delete_a_labour()
    {
        $labour = Labour::factory()->create(['farm_id' => $this->farm->id]);

        $response = $this->delete("/api/labours/{$labour->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('labours', ['id' => $labour->id]);
    }

    /** @test */
    public function it_can_search_labours()
    {
        Labour::factory()->create([
            'farm_id' => $this->farm->id,
            'fname' => 'John',
            'lname' => 'Doe',
        ]);

        $response = $this->getJson("/api/farms/{$this->farm->id}/labours?search=John");

        $response->assertStatus(200);
        $response->assertJsonCount(1);
    }
}
