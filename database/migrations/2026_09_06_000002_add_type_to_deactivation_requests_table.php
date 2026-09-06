<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reuses the existing deactivation_requests table/admin-review queue for
// account DELETION requests too, distinguished by this new `type` column,
// instead of building a fully separate parallel system.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deactivation_requests', function (Blueprint $table) {
            $table->string('type')->default('deactivate')->after('user_id'); // deactivate | delete
        });
    }

    public function down(): void
    {
        Schema::table('deactivation_requests', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
