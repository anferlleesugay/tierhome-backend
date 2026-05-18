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
        Schema::create('identity_verifications', function (Blueprint $table) {
    $table->id();

    // User Relationship
    $table->foreignId('user_id')
        ->constrained('users')
        ->onDelete('cascade');

    // Uploaded Documents
    $table->string('id_photo_path')->nullable();
    $table->string('selfie_path')->nullable();

    // Rekognition Data
    $table->decimal('rekognition_score', 5, 2)->nullable();
    $table->string('verification_method')->default('automatic');

    // Face Collections
    $table->string('face_id')->nullable();
    $table->string('collection_id')->default('tierhome-users');

    // 🔥 FIXED STATUS (NO ENUM)
    $table->string('status')->default('pending'); // pending, approved, rejected

    $table->text('rejection_reason')->nullable();
    $table->unsignedBigInteger('reviewed_by')->nullable();
    $table->timestamp('reviewed_at')->nullable();

    $table->timestamp('verified_at')->nullable();
    $table->timestamp('indexed_at')->nullable();

    $table->timestamps();

    // Indexes
    $table->index('status');
    $table->index('user_id');
    $table->index('created_at');
    $table->index('verified_at');
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('identity_verifications');
    }
};
