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
            $table->json('coordinate')->after('imei')->nullable();
        });

        // Transfer existing latitude/longitude data to coordinate array
        DB::table('gps_reports')->orderBy('id')->chunk(1000, function ($reports) {
            foreach ($reports as $report) {
                DB::table('gps_reports')
                    ->where('id', $report->id)
                    ->update([
                        'coordinate' => json_encode([
                            (float) $report->latitude,
                            (float) $report->longitude
                        ])
                    ]);
            }
        });

        Schema::table('gps_reports', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gps_reports', function (Blueprint $table) {
            $table->string('latitude')->after('imei')->nullable();
            $table->string('longitude')->after('latitude')->nullable();
        });

        // Transfer coordinate array back to separate columns
        DB::table('gps_reports')->orderBy('id')->chunk(1000, function ($reports) {
            foreach ($reports as $report) {
                $coordinate = json_decode($report->coordinate, true);
                DB::table('gps_reports')
                    ->where('id', $report->id)
                    ->update([
                        (float) $coordinate[0],
                        (float) $coordinate[1]
                    ]);
            }
        });

        Schema::table('gps_reports', function (Blueprint $table) {
            $table->dropColumn('coordinate');
        });
    }
};
