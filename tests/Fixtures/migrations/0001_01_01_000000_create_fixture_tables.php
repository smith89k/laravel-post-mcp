<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        Schema::create('simple_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('status')->default('draft');
            $table->boolean('is_live')->default(false);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('summary', 100)->nullable();
            $table->json('links')->nullable();
            $table->json('aliases')->nullable();
            $table->string('logo')->nullable();
            $table->string('secret_code')->nullable();
            $table->timestamps();
        });

        Schema::create('rich_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('category_id')->nullable();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('body');
            $table->string('seo_title', 70)->nullable();
            $table->string('seo_description', 160)->nullable();
            $table->string('featured_image')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('api_token')->nullable();
            $table->timestamps();
        });

        Schema::create('rich_post_tag', function (Blueprint $table) {
            $table->foreignId('rich_post_id');
            $table->foreignId('tag_id');
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id('article_id');
            $table->string('headline');
            $table->string('permalink')->unique();
            $table->text('markdown_text');
            $table->string('state')->default('hidden');
            $table->dateTime('goes_live_at')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->string('secret_key')->nullable();
            $table->timestamp('created_on')->nullable();
            $table->timestamp('modified_on')->nullable();
        });

        (require __DIR__.'/../../../database/migrations/create_post_mcp_audit_logs_table.php.stub')->up();
        (require __DIR__.'/../../../database/migrations/create_post_mcp_settings_table.php.stub')->up();
    }
};
