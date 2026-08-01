<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id'); // recipient
            $table->string('type'); // e.g. 'reservation_approved', 'reservation_rejected', 'reservation_cancelled', 'new_message', 'listing_approved', 'listing_rejected'
            $table->string('title');
            $table->text('message');
            $table->string('link')->nullable(); // frontend route to navigate to on click
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
