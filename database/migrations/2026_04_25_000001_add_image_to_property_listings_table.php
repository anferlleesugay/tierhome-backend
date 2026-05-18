<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('property_listings', function (Blueprint $table) {
            $table->string('image')->nullable()->after('barangay');
        });
    }

    public function down()
    {
        Schema::table('property_listings', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};