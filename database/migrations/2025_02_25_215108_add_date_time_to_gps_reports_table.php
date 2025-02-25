<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('gps_reports', function (Blueprint $table) {
            $table->date('date')->nullable();
            $table->time('time')->nullable();
        });

        DB::table('gps_reports')->select('id', 'date_time')->chunkById(100, function ($reports) {
            foreach ($reports as $report) {
                $dateTime = new DateTime($report->date_time);
                DB::table('gps_reports')
                    ->where('id', $report->id)
                    ->update([
                        'date' => $dateTime->format('Y-m-d'),
                        'time' => $dateTime->format('H:i:s'),
                    ]);
            }
        });

        Schema::table('gps_reports', function (Blueprint $table) {
            $table->dropColumn('date_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gps_reports', function (Blueprint $table) {
            $table->dateTime('date_time')->nullable();
        });

        DB::table('gps_reports')->select('id', 'date', 'time')->chunkById(100, function ($reports) {
            foreach ($reports as $report) {
                $date = Carbon\Carbon::parse($report->date)->format('Y-m-d');
                $time = trim($report->time);
                $dateTime = new DateTime($date . ' ' . $time);
                DB::table('gps_reports')
                    ->where('id', $report->id)
                    ->update([
                        'date_time' => $dateTime->format('Y-m-d H:i:s'),
                    ]);
            }
        });

        Schema::table('gps_reports', function (Blueprint $table) {
            $table->dropColumn(['date', 'time']);
        });
    }
};
