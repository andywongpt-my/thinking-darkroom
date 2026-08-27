<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('filename'); // stored file name on disk
            $table->string('original_name')->nullable();
            $table->string('path')->nullable(); // public relative path
            $table->string('mime', 64)->nullable();
            $table->unsignedInteger('size_bytes')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            // Creative authority state: only changes via an APPROVED proposal execution.
            $table->string('selection_state', 24)->default('unreviewed'); // unreviewed | selected | culled
            $table->string('retouch_state', 24)->default('none'); // none | proposed | approved | applied

            // Optional camera / exif metadata (nullable; not parsed in Sprint 1).
            $table->string('camera_make', 64)->nullable();
            $table->string('camera_model', 64)->nullable();
            $table->string('lens', 96)->nullable();
            $table->decimal('iso', 10, 1)->nullable();
            $table->decimal('aperture', 10, 2)->nullable();
            $table->decimal('shutter_speed', 12, 4)->nullable();
            $table->decimal('focal_length', 10, 2)->nullable();
            $table->timestamp('captured_at')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'selection_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photos');
    }
};
