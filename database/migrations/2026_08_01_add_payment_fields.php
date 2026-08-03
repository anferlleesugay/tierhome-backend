<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_listings', function (Blueprint $table) {
            $table->decimal('reservation_fee', 10, 2)->nullable()->after('price');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->string('payment_status')->default('not_required')->after('status');
            // not_required | pending | paid | refund_processing | refunded | payment_failed
            $table->string('paymongo_payment_intent_id')->nullable()->after('payment_status');
            $table->string('paymongo_refund_id')->nullable()->after('paymongo_payment_intent_id');
            $table->timestamp('paid_at')->nullable()->after('paymongo_refund_id');
            $table->timestamp('refunded_at')->nullable()->after('paid_at');
            $table->boolean('disbursed_to_landlord')->default(false)->after('refunded_at');
        });
    }

    public function down(): void
    {
        Schema::table('property_listings', function (Blueprint $table) {
            $table->dropColumn('reservation_fee');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn([
                'payment_status',
                'paymongo_payment_intent_id',
                'paymongo_refund_id',
                'paid_at',
                'refunded_at',
                'disbursed_to_landlord',
            ]);
        });
    }
};
