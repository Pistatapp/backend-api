<?php

namespace Tests\Feature;

use App\Models\Farm;
use App\Models\Field;
use App\Models\Irrigation;
use App\Models\Plot;
use App\Models\User;
use App\Models\Valve;
use App\Services\IrrigationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IrrigationMessageRecipientTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_messages_are_limited_to_creator_and_farm_admin(): void
    {
        $farm = Farm::factory()->create();
        $creator = User::factory()->create();
        $admin = User::factory()->create();
        $otherOperator = User::factory()->create();

        $farm->users()->attach([
            $creator->id => ['role' => 'operator', 'is_owner' => false],
            $admin->id => ['role' => 'admin', 'is_owner' => false],
            $otherOperator->id => ['role' => 'operator', 'is_owner' => false],
        ]);

        $field = Field::factory()->create(['farm_id' => $farm->id]);
        $plot = Plot::factory()->create(['field_id' => $field->id]);
        $valve = Valve::factory()->create(['plot_id' => $plot->id]);
        $irrigation = Irrigation::factory()->create([
            'farm_id' => $farm->id,
            'created_by' => $creator->id,
            'status' => 'finished',
            'is_verified_by_admin' => false,
            'start_time' => Carbon::parse('2026-08-10 10:00:00', 'Asia/Tehran'),
            'end_time' => Carbon::parse('2026-08-10 11:00:00', 'Asia/Tehran'),
        ]);
        $irrigation->plots()->attach($plot->id);
        $irrigation->valves()->attach($valve->id);

        $service = app(IrrigationService::class);

        $creatorMessages = $service->getIrrigationMessages($farm, false, $creator);
        $adminMessages = $service->getIrrigationMessages($farm, false, $admin);
        $otherMessages = $service->getIrrigationMessages($farm, false, $otherOperator);

        $this->assertSame([$irrigation->id], collect($creatorMessages['data'])->pluck('irrigation_id')->all());
        $this->assertSame([$irrigation->id], collect($adminMessages['data'])->pluck('irrigation_id')->all());
        $this->assertSame([], collect($otherMessages['data'])->pluck('irrigation_id')->all());
    }
}
