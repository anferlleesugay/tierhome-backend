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
        Schema::create('amenities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('icon')->nullable();
            $table->timestamps();
        });

        // Pivot table for listing amenities relationship
        Schema::create('listing_amenities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')
                ->constrained('property_listings')
                ->onDelete('cascade');
            $table->foreignId('amenity_id')
                ->constrained('amenities')
                ->onDelete('cascade');
            $table->timestamps();
            
            // Prevent duplicate amenities for same listing
            $table->unique(['listing_id', 'amenity_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listing_amenities');
        Schema::dropIfExists('amenities');
    }
};