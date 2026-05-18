<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('listing_id');
            $table->string('image_path');
            $table->boolean('is_cover')->default(false);
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->foreign('listing_id')
                  ->references('id')
                  ->on('property_listings')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_images');
    }
};