<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('comments_enabled')->default(true)->after('noindex');
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('post_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('parent_id')->nullable()->constrained('comments')->nullOnDelete();
            $table->string('wordpress_id')->nullable();
            $table->string('author_name', 100);
            $table->string('author_email', 254)->nullable();
            $table->string('author_url', 2048)->nullable();
            $table->text('body');
            $table->enum('status', ['pending', 'approved', 'spam'])->default('pending')->index();
            $table->string('author_ip_hash', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
            $table->unique(['post_id', 'wordpress_id']);
            $table->index(['post_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('comments_enabled');
        });
    }
};
