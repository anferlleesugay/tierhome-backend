<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('message_type')->default('text')->after('message'); // text | listing_card
            $table->unsignedBigInteger('listing_card_id')->nullable()->after('message_type');
            $table->foreign('listing_card_id')
                  ->references('id')
                  ->on('property_listings')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['listing_card_id']);
            $table->dropColumn(['message_type', 'listing_card_id']);
        });
    }
};