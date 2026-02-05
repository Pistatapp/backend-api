<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Profile;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->string('name')->after('user_id')->nullable();
        });

        Profile::chunk(100, function ($profiles) {
            foreach($profiles as $profile) {
                $profile->name = $profile->first_name . ' ' . $profile->last_name;
                $profile->save();
            }
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('first_name');
            $table->dropColumn('last_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->string('first_name')->after('user_id')->nullable();
            $table->string('last_name')->after('first_name')->nullable();
        });
    }
};
