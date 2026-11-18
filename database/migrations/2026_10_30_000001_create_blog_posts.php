<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Website blog (M3, 30 Oct: Bilal chose "Blog / guides"). New table only; nothing else changes.
 * Starter guides (database/data/blog-starter.php) are added once, for Bilal to check and edit in the CRM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('body')->nullable();
            $table->string('title_ar')->nullable();
            $table->text('excerpt_ar')->nullable();
            $table->longText('body_ar')->nullable();
            $table->string('cover_image')->nullable(); // a website photo used until a cover is uploaded
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['is_published', 'published_at']);
        });

        $file = database_path('data/blog-starter.php');
        if (is_file($file)) {
            $now = now();
            foreach (require $file as $i => $post) {
                DB::table('blog_posts')->insert($post + [
                    'is_published' => true,
                    'published_at' => $now->copy()->subDays(3 * $i),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
    }
};
