<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->integer('user_reviews_count')->nullable();
            $table->integer('rating');
            $table->string('title')->nullable();
            $table->text('review_text')->nullable();
            $table->date('review_date')->nullable();
            $table->date('experience_date')->nullable();
            $table->string('country')->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('source_url')->nullable();
            $table->timestamps();
            $table->unique(['username', 'title', 'review_date'], 'unique_review'); // уникаємо дублювання
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
