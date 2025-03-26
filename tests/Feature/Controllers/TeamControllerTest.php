<?php

namespace Tests\Feature\Controllers;

use App\Models\Farm;
use App\Models\Labour;
use Tests\TestCase;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TeamControllerTest extends TestCase
{
    use RefreshDatabase;

    private $farm;
    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->farm = Farm::factory()->create();

        $this->farm->users()->attach($this->user->id, [
            'role' => 'admin',
            'is_owner' => true,
        ]);
    }

    /** @test */
    public function it_can_list_all_teams()
    {
        Team::factory(3)->create([
            'farm_id' => $this->farm->id,
        ]);

        $response = $this->getJson("/api/farms/{$this->farm->id}/teams");

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');
    }

    /** @test */
    public function it_can_create_a_team()
    {
        $supervisor = Labour::factory()->create(['farm_id' => $this->farm->id]);
        $labours = Labour::factory(2)->create(['farm_id' => $this->farm->id]);

        $data = [
            'name' => 'Test Team',
            'supervisor_id' => $supervisor->id,
            'labours' => $labours->pluck('id')->toArray()
        ];

        $response = $this->postJson("/api/farms/{$this->farm->id}/teams", $data);

        $response->assertStatus(201);
        $this->assertDatabaseHas('teams', [
            'name' => 'Test Team',
            'farm_id' => $this->farm->id,
            'supervisor_id' => $supervisor->id
        ]);

        $team = Team::where('name', 'Test Team')->first();
        $this->assertEquals($labours->pluck('id')->toArray(), $team->labours->pluck('id')->toArray());
    }

    /** @test */
    public function it_can_update_a_team()
    {
        $team = Team::factory()->create(['farm_id' => $this->farm->id]);
        $newSupervisor = Labour::factory()->create(['farm_id' => $this->farm->id]);
        $newLabours = Labour::factory(2)->create(['farm_id' => $this->farm->id]);

        $data = [
            'name' => 'Updated Team Name',
            'supervisor_id' => $newSupervisor->id,
            'labours' => $newLabours->pluck('id')->toArray()
        ];

        $response = $this->putJson("/api/teams/{$team->id}", $data);

        $response->assertStatus(200);
        $this->assertDatabaseHas('teams', [
            'id' => $team->id,
            'name' => 'Updated Team Name',
            'supervisor_id' => $newSupervisor->id
        ]);

        $team->refresh();
        $this->assertEquals($newLabours->pluck('id')->toArray(), $team->labours->pluck('id')->toArray());
    }

    /** @test */
    public function it_can_delete_a_team()
    {
        $team = Team::factory()->create(['farm_id' => $this->farm->id]);

        $response = $this->deleteJson("/api/teams/{$team->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
    }

    /** @test */
    public function it_can_search_teams()
    {
        Team::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Alpha Team'
        ]);
        Team::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Beta Team'
        ]);

        $response = $this->getJson("/api/farms/{$this->farm->id}/teams?search=Alpha");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Alpha Team');
    }
}
