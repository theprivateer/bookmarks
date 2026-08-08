<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Postgres does not create an index for a foreign key constraint, so
     * bookmarks.user_id was unindexed while every query in the application starts
     * from $user->bookmarks(). The composite covers the default listing's
     * "where user_id = ? order by created_at desc" in one index.
     */
    public function up(): void
    {
        Schema::table('bookmarks', function (Blueprint $table) {
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('bookmarks', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
        });
    }
};
