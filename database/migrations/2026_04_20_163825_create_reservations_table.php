<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('listing_id');

            $table->string('status')->default('pending');

            $table->timestamps();

            // ✅ BOTH FOREIGN KEYS NOW
            $table->foreign('tenant_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('listing_id')
                ->references('id')
                ->on('property_listings')
                ->onDelete('cascade');

            // ✅ Add indexes
            $table->index('tenant_id');
            $table->index('listing_id');
            $table->index('status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('reservations');
    }
};
