<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Drop old constraint and add new one that includes 'rejected'
        DB::statement('ALTER TABLE users DROP CONSTRAINT chk_verification_status');
        DB::statement("ALTER TABLE users ADD CONSTRAINT chk_verification_status 
            CHECK (verification_status IN ('unverified', 'pending', 'verified', 'rejected'))");
    }

    public function down()
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT chk_verification_status');
        DB::statement("ALTER TABLE users ADD CONSTRAINT chk_verification_status 
            CHECK (verification_status IN ('unverified', 'pending', 'verified'))");
    }
};