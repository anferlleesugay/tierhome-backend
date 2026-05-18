<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // 🔥 NAME FIELDS
            $table->string('name'); // keep for compatibility
            $table->string('first_name');
            $table->string('last_name');
            $table->string('middle_initial')->nullable();

            $table->string('phone_number')->nullable();

            $table->string('email')->unique();
            $table->string('password');

            // 🔥 CONTROLLED FIELDS
            $table->string('role')->default('tenant');
            $table->string('verification_status')->default('unverified');

            // 🔥 PROFILE FIELDS (FIXED - WERE MISSING)
            $table->string('profile_picture')->nullable();
            $table->datetime('verified_at')->nullable();

            $table->timestamps();
        });

        // 🔥 DATABASE-LEVEL VALIDATION (SAFE)
        DB::statement("
            ALTER TABLE users 
            ADD CONSTRAINT chk_role 
            CHECK (role IN ('tenant', 'landlord', 'admin'))
        ");

        DB::statement("
            ALTER TABLE users 
            ADD CONSTRAINT chk_verification_status 
            CHECK (verification_status IN ('unverified', 'pending', 'verified'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
