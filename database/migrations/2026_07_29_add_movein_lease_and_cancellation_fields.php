<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->date('move_out_date')->nullable()->after('move_in_date');
            $table->text('cancellation_reason')->nullable()->after('terms_accepted_at');
            $table->string('cancelled_by')->nullable()->after('cancellation_reason'); // 'tenant' or 'landlord'
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['move_out_date', 'cancellation_reason', 'cancelled_by']);
        });
    }
};
