<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affordability_rankings', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tenant_id');

            // Income & Expenses
            $table->decimal('gross_income', 12, 2)->nullable();
            $table->decimal('monthly_expenses', 12, 2)->nullable();
            $table->decimal('utility_costs', 12, 2)->nullable();
            $table->decimal('available_savings', 12, 2)->nullable();
            
            // Calculated
            $table->decimal('disposable_income', 12, 2)->nullable();
            $table->decimal('recommended_max_rent', 12, 2)->nullable();

            // Results JSON (tier, ranking, etc)
            $table->json('results')->nullable();

            $table->timestamps();

            // Foreign key
            $table->foreign('tenant_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
            
            // Indexes
            $table->index('tenant_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affordability_rankings');
    }
};
