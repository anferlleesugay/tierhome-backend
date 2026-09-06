<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Adds a failed-attempt counter to otp_codes so verifyOtp()/register() can
// lock out a code after repeated wrong guesses, instead of allowing
// unlimited attempts against a 6-digit code for its full 10-minute window.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempts')->default(0)->after('used');
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });
    }
};
