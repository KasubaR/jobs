<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('industries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('job_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('job_categories')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('job_titles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->foreignId('category_id')->nullable()->constrained('job_categories')->nullOnDelete();
            $table->json('aliases')->nullable();
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20); // province | district | town
            $table->foreignId('parent_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->timestamps();

            $table->unique(['name', 'type', 'parent_id']);
        });

        Schema::create('qualifications', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('level', 30); // certificate | diploma | degree | masters | phd | professional
            $table->json('aliases')->nullable();
            $table->timestamps();
        });

        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('type', 30); // technical | soft | software | certification
            $table->json('aliases')->nullable();
            $table->timestamps();
        });

        Schema::create('employers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('normalized_name')->unique();
            $table->foreignId('industry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employers');
        Schema::dropIfExists('skills');
        Schema::dropIfExists('qualifications');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('job_titles');
        Schema::dropIfExists('job_categories');
        Schema::dropIfExists('industries');
    }
};
