<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Register form has always collected a full middle name (label "Middle
// Name", placeholder "Santos" — not an abbreviation like "S."), but the
// column and all the code around it were named/written as if it stored a
// single-letter initial, appending a period after whatever was typed
// (e.g. producing "Juan Santos. Dela Cruz" instead of a correctly
// formatted full name). Renaming the column to match what it actually is.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('middle_initial', 'middle_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('middle_name', 'middle_initial');
        });
    }
};
