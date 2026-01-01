<?php

namespace Tests\Unit\Models;

use App\Models\Labour;
use App\Models\Farm;
use App\Models\User;
use App\Models\LabourGpsData;
use App\Models\LabourAttendanceSession;
use App\Models\LabourDailyReport;
use App\Models\LabourMonthlyPayroll;
use App\Models\LabourShiftSchedule;
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
     * Test that labour has many GPS data records.
     */
    public function test_labour_has_many_gps_data(): void
    {
        $labour = Labour::factory()->create();
        LabourGpsData::factory()->count(3)->create(['labour_id' => $labour->id]);

        $this->assertCount(3, $labour->gpsData);
        $this->assertInstanceOf(LabourGpsData::class, $labour->gpsData->first());
    }

    /**
     * Test that labour has many attendance sessions.
     */
    public function test_labour_has_many_attendance_sessions(): void
    {
        $labour = Labour::factory()->create();
        LabourAttendanceSession::factory()->count(5)->create(['labour_id' => $labour->id]);

        $this->assertCount(5, $labour->attendanceSessions);
        $this->assertInstanceOf(LabourAttendanceSession::class, $labour->attendanceSessions->first());
    }

    /**
     * Test that labour has many daily reports.
     */
    public function test_labour_has_many_daily_reports(): void
    {
        $labour = Labour::factory()->create();
        LabourDailyReport::factory()->count(10)->create(['labour_id' => $labour->id]);

        $this->assertCount(10, $labour->dailyReports);
        $this->assertInstanceOf(LabourDailyReport::class, $labour->dailyReports->first());
    }

    /**
     * Test that labour has many monthly payrolls.
     */
    public function test_labour_has_many_monthly_payrolls(): void
    {
        $labour = Labour::factory()->create();
        // Create monthly payrolls with unique month/year combinations
        $dates = [];
        for ($i = 0; $i < 6; $i++) {
            $month = ($i % 12) + 1;
            $year = 2024 + intval($i / 12);
            $dates[] = ['month' => $month, 'year' => $year];
        }
        foreach ($dates as $date) {
            LabourMonthlyPayroll::factory()->create([
                'labour_id' => $labour->id,
                'month' => $date['month'],
                'year' => $date['year'],
            ]);
        }

        $this->assertCount(6, $labour->monthlyPayrolls);
        $this->assertInstanceOf(LabourMonthlyPayroll::class, $labour->monthlyPayrolls->first());
    }

    /**
     * Test that labour has many shift schedules.
     */
    public function test_labour_has_many_shift_schedules(): void
    {
        $labour = Labour::factory()->create();
        LabourShiftSchedule::factory()->count(7)->create(['labour_id' => $labour->id]);

        $this->assertCount(7, $labour->shiftSchedules);
        $this->assertInstanceOf(LabourShiftSchedule::class, $labour->shiftSchedules->first());
    }

    /**
     * Test full name accessor.
     */
    public function test_labour_has_full_name_accessor(): void
    {
        $labour = Labour::factory()->create([
            'fname' => 'John',
            'lname' => 'Doe',
        ]);

        $this->assertEquals('John Doe', $labour->full_name);
    }

    /**
     * Test working scope filters only working labours.
     */
    public function test_working_scope_filters_working_labours(): void
    {
        Labour::factory()->count(3)->create(['is_working' => false]);
        Labour::factory()->count(2)->create(['is_working' => true]);

        $workingLabours = Labour::working()->get();

        $this->assertCount(2, $workingLabours);
        $workingLabours->each(function ($labour) {
            $this->assertTrue($labour->is_working);
        });
    }

    /**
     * Test that work_days is cast to array.
     */
    public function test_work_days_is_cast_to_array(): void
    {
        $workDays = [0, 1, 2, 3, 4]; // Sunday to Thursday
        $labour = Labour::factory()->create(['work_days' => $workDays]);

        $this->assertIsArray($labour->work_days);
        $this->assertEquals($workDays, $labour->work_days);
    }

    /**
     * Test that is_working defaults to false.
     */
    public function test_is_working_defaults_to_false(): void
    {
        $labour = new Labour();
        $this->assertFalse($labour->is_working);
    }

    /**
     * Test that is_working is cast to boolean.
     */
    public function test_is_working_is_cast_to_boolean(): void
    {
        $labour = Labour::factory()->create(['is_working' => 1]);
        $this->assertIsBool($labour->is_working);
        $this->assertTrue($labour->is_working);
    }
}

