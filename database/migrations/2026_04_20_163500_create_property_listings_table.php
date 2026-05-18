<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('property_listings', function (Blueprint $table) {
        $table->id();

        $table->unsignedBigInteger('landlord_id')->nullable();

        $table->string('title');
        $table->text('description')->nullable();
        $table->decimal('price', 10, 2);

        $table->string('property_type')->nullable();

        $table->string('address')->nullable();
        $table->string('barangay')->nullable();

        $table->decimal('latitude', 10, 7)->nullable();
        $table->decimal('longitude', 10, 7)->nullable();

        $table->boolean('is_verified')->default(true);
        $table->string('status')->default('available');

        $table->timestamps();
    });
    }

    public function down()
    {
        Schema::dropIfExists('property_listings');
    }
};
