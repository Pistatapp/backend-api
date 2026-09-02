<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('irrigations', function (Blueprint $table) {
            $table->timestamp('operator_confirmed_at')->nullable()->after('is_verified_by_admin');
            $table->foreignId('operator_confirmed_by')->nullable()->after('operator_confirmed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('admin_confirmed_at')->nullable()->after('operator_confirmed_by');
            $table->foreignId('admin_confirmed_by')->nullable()->after('admin_confirmed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable()->after('admin_confirmed_by');
        });
    }

    public function down(): void
    {
        Schema::table('irrigations', function (Blueprint $table) {
            $table->dropForeign(['operator_confirmed_by']);
            $table->dropForeign(['admin_confirmed_by']);
            $table->dropColumn([
                'operator_confirmed_at', 'operator_confirmed_by',
                'admin_confirmed_at', 'admin_confirmed_by', 'finalized_at',
            ]);
        });
    }
};
