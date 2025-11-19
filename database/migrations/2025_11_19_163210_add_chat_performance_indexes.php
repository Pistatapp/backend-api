<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Composite index for chat_rooms - farm_id + type + last_message_at (common query pattern)
        Schema::table('chat_rooms', function (Blueprint $table) {
            $table->index(['farm_id', 'type', 'last_message_at'], 'idx_chat_rooms_farm_type_message');
        });

        // Composite index for chat_room_user - chat_room_id + user_id + left_at (most common query)
        Schema::table('chat_room_user', function (Blueprint $table) {
            $table->index(['chat_room_id', 'user_id', 'left_at'], 'idx_chat_room_user_room_user_left');
            $table->index(['user_id', 'left_at', 'last_read_at'], 'idx_chat_room_user_user_left_read');
        });

        // Composite index for messages - chat_room_id + created_at (pagination and ordering)
        Schema::table('messages', function (Blueprint $table) {
            $table->index(['chat_room_id', 'created_at'], 'idx_messages_room_created');
            $table->index(['chat_room_id', 'deleted_at', 'created_at'], 'idx_messages_room_deleted_created');
            $table->index(['user_id', 'created_at'], 'idx_messages_user_created');
        });

        // Composite index for message_deletions - message_id + deleted_by_user_id + deletion_type
        Schema::table('message_deletions', function (Blueprint $table) {
            $table->index(['message_id', 'deleted_by_user_id', 'deletion_type'], 'idx_message_deletions_message_user_type');
        });

        // Composite index for message_reads - message_id + user_id (already has unique, but add for read_at queries)
        Schema::table('message_reads', function (Blueprint $table) {
            $table->index(['message_id', 'read_at'], 'idx_message_reads_message_read');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_rooms', function (Blueprint $table) {
            $table->dropIndex('idx_chat_rooms_farm_type_message');
        });

        Schema::table('chat_room_user', function (Blueprint $table) {
            $table->dropIndex('idx_chat_room_user_room_user_left');
            $table->dropIndex('idx_chat_room_user_user_left_read');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('idx_messages_room_created');
            $table->dropIndex('idx_messages_room_deleted_created');
            $table->dropIndex('idx_messages_user_created');
        });

        Schema::table('message_deletions', function (Blueprint $table) {
            $table->dropIndex('idx_message_deletions_message_user_type');
        });

        Schema::table('message_reads', function (Blueprint $table) {
            $table->dropIndex('idx_message_reads_message_read');
        });
    }
};

