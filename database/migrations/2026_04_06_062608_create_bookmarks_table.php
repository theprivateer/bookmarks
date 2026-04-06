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
        Schema::create('bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('domain')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('og_image_url', 2048)->nullable();
            $table->string('favicon_url', 2048)->nullable();
            $table->longText('extracted_text')->nullable();
            $table->text('ai_summary')->nullable();
            $table->vector('embedding', dimensions: 1536)->nullable()->index();
            $table->string('status')->default('pending');
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('domain');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookmarks');
    }
};
