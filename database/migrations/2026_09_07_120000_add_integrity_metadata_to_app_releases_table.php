<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_releases', function (Blueprint $table) {
            $table->string('package_name', 255)
                ->default('com.pistat.android')
                ->after('version');
            $table->unsignedInteger('version_code')->nullable()->after('package_name');
            $table->char('sha256', 64)->nullable()->after('file_url');
            $table->unsignedBigInteger('file_size')->nullable()->after('sha256');
            $table->string('mime_type', 100)->nullable()->after('file_size');
        });
    }

    public function down(): void
    {
        Schema::table('app_releases', function (Blueprint $table) {
            $table->dropColumn([
                'package_name',
                'version_code',
                'sha256',
                'file_size',
                'mime_type',
            ]);
        });
    }
};
