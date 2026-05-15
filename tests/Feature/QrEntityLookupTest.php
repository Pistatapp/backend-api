<?php

namespace Tests\Feature;

use App\Models\Farm;
use App\Models\FarmPlan;
use App\Models\Field;
use App\Models\Plot;
use App\Models\Row;
use App\Models\Tree;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class QrEntityLookupTest extends TestCase
{
    use RefreshDatabase;

    private const UID_FIELD = 'flduid000000001';

    private const UID_ROW = 'rowuid000000002';

    private const UID_PLOT = 'pltuid000000003';

    private const UID_TREE = 'treuid000000004';

    private const UID_PLAN = 'plnuid000000005';

    private const UID_AMBIG = 'ambuid000000006';

    private const UID_FOREIGN = 'foruid000000007';

    private User $user;

    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'is_active' => true,
        ]);
        $this->farm = Farm::factory()->create();
        $this->farm->users()->attach($this->user->id, [
            'is_owner' => true,
            'role' => 'admin',
        ]);
    }

    #[Test]
    public function it_requires_authentication(): void
    {
        $this->getJson('/api/entities/by-unique-id?unique_id='.self::UID_FIELD)
            ->assertUnauthorized();

        $this->postJson('/api/entities/by-unique-id', ['unique_id' => self::UID_FIELD])
            ->assertUnauthorized();
    }

    #[Test]
    public function it_validates_unique_id_is_required(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/entities/by-unique-id')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['unique_id']);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/entities/by-unique-id', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['unique_id']);
    }

    #[Test]
    public function it_returns_404_when_no_entity_matches(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/entities/by-unique-id?unique_id=nonexistent0000')
            ->assertNotFound();
    }

    #[Test]
    public function it_resolves_a_field_via_get_query_string(): void
    {
        $field = Field::factory()->create([
            'farm_id' => $this->farm->id,
            'unique_id' => self::UID_FIELD,
            'qr_code' => 'test-qr-field',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/entities/by-unique-id?unique_id='.self::UID_FIELD);

        $response->assertOk()
            ->assertJsonPath('meta.entity_type', 'field')
            ->assertJsonPath('meta.unique_id', self::UID_FIELD)
            ->assertJsonPath('data.id', $field->id);
    }

    #[Test]
    public function it_resolves_a_field_via_post_json_body(): void
    {
        $field = Field::factory()->create([
            'farm_id' => $this->farm->id,
            'unique_id' => self::UID_FIELD,
            'qr_code' => 'test-qr-field',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/entities/by-unique-id', ['unique_id' => self::UID_FIELD]);

        $response->assertOk()
            ->assertJsonPath('meta.entity_type', 'field')
            ->assertJsonPath('data.id', $field->id);
    }

    #[Test]
    public function it_resolves_a_row(): void
    {
        $field = Field::factory()->create(['farm_id' => $this->farm->id]);
        $row = Row::factory()->create([
            'field_id' => $field->id,
            'unique_id' => self::UID_ROW,
            'qr_code' => 'test-qr-row',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/entities/by-unique-id?unique_id='.self::UID_ROW)
            ->assertOk()
            ->assertJsonPath('meta.entity_type', 'row')
            ->assertJsonPath('data.id', $row->id);
    }

    #[Test]
    public function it_resolves_a_plot(): void
    {
        $field = Field::factory()->create(['farm_id' => $this->farm->id]);
        $plot = Plot::factory()->create([
            'field_id' => $field->id,
            'unique_id' => self::UID_PLOT,
            'qr_code' => 'test-qr-plot',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/entities/by-unique-id?unique_id='.self::UID_PLOT)
            ->assertOk()
            ->assertJsonPath('meta.entity_type', 'plot')
            ->assertJsonPath('data.id', $plot->id);
    }

    #[Test]
    public function it_resolves_a_tree(): void
    {
        $field = Field::factory()->create(['farm_id' => $this->farm->id]);
        $row = Row::factory()->create(['field_id' => $field->id]);
        $tree = Tree::factory()->create([
            'row_id' => $row->id,
            'unique_id' => self::UID_TREE,
            'qr_code' => 'test-qr-tree',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/entities/by-unique-id?unique_id='.self::UID_TREE)
            ->assertOk()
            ->assertJsonPath('meta.entity_type', 'tree')
            ->assertJsonPath('data.id', $tree->id);
    }

    #[Test]
    public function it_resolves_a_farm_plan_when_user_has_view_permission(): void
    {
        Permission::firstOrCreate(
            ['name' => 'view-treatment-plan-details', 'guard_name' => 'web']
        );
        $this->user->givePermissionTo('view-treatment-plan-details');

        $plan = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'created_by' => $this->user->id,
            'unique_id' => self::UID_PLAN,
            'qr_code' => 'test-qr-plan',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/entities/by-unique-id?unique_id='.self::UID_PLAN)
            ->assertOk()
            ->assertJsonPath('meta.entity_type', 'farm_plan')
            ->assertJsonPath('data.id', $plan->id);
    }

    #[Test]
    public function it_returns_403_for_farm_plan_when_user_lacks_view_permission(): void
    {
        $plan = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'created_by' => $this->user->id,
            'unique_id' => self::UID_PLAN,
            'qr_code' => 'test-qr-plan',
        ]);

        $this->assertFalse($this->user->can('view-treatment-plan-details'));

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/entities/by-unique-id?unique_id='.self::UID_PLAN)
            ->assertForbidden();
    }

    #[Test]
    public function it_returns_403_when_user_is_not_on_the_entity_farm(): void
    {
        $otherFarm = Farm::factory()->create();
        $field = Field::factory()->create([
            'farm_id' => $otherFarm->id,
            'unique_id' => self::UID_FOREIGN,
            'qr_code' => 'test-qr-foreign',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/entities/by-unique-id?unique_id='.self::UID_FOREIGN)
            ->assertForbidden();

        $this->assertDatabaseHas('fields', ['id' => $field->id]);
    }

    #[Test]
    public function it_returns_409_when_unique_id_matches_more_than_one_table(): void
    {
        $field = Field::factory()->create([
            'farm_id' => $this->farm->id,
            'unique_id' => self::UID_AMBIG,
            'qr_code' => 'qr-a',
        ]);
        Plot::factory()->create([
            'field_id' => $field->id,
            'unique_id' => self::UID_AMBIG,
            'qr_code' => 'qr-b',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/entities/by-unique-id?unique_id='.self::UID_AMBIG)
            ->assertStatus(409);
    }
}
