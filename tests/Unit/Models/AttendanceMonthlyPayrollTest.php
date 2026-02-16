<?php

namespace Tests\Unit\Models;

use App\Models\AttendanceMonthlyPayroll;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceMonthlyPayrollTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that monthly payroll belongs to user.
     */
    public function test_monthly_payroll_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $payroll = AttendanceMonthlyPayroll::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $payroll->user);
        $this->assertEquals($user->id, $payroll->user->id);
    }

    /**
     * Test that numeric fields are cast to decimal.
     */
    public function test_numeric_fields_are_cast_to_decimal(): void
    {
        $payroll = AttendanceMonthlyPayroll::factory()->create([
            'total_work_hours' => 176.5,
            'total_required_hours' => 160.0,
            'total_overtime_hours' => 16.5,
        ]);

        $this->assertIsNumeric($payroll->total_work_hours);
        $this->assertIsNumeric($payroll->total_required_hours);
        $this->assertIsNumeric($payroll->total_overtime_hours);
    }

    /**
     * Test that generated_at is cast to datetime.
     */
    public function test_generated_at_is_cast_to_datetime(): void
    {
        $generatedAt = Carbon::now();
        $payroll = AttendanceMonthlyPayroll::factory()->create(['generated_at' => $generatedAt]);

        $this->assertInstanceOf(Carbon::class, $payroll->generated_at);
    }

    /**
     * Test monthly payroll can be created with all fields.
     */
    public function test_monthly_payroll_can_be_created(): void
    {
        $user = User::factory()->create();

        $payroll = AttendanceMonthlyPayroll::factory()->create([
            'user_id' => $user->id,
            'month' => 11,
            'year' => 2024,
            'total_work_hours' => 176.5,
            'total_required_hours' => 160.0,
            'total_overtime_hours' => 16.5,
            'base_wage_total' => 16000000,
            'overtime_wage_total' => 2475000,
            'additions' => 500000,
            'deductions' => 200000,
            'final_total' => 18875000,
            'generated_at' => Carbon::now(),
        ]);

        $this->assertDatabaseHas('attendance_monthly_payrolls', [
            'id' => $payroll->id,
            'user_id' => $user->id,
            'month' => 11,
            'year' => 2024,
        ]);

        $this->assertEquals(18875000, $payroll->final_total);
    }
}
