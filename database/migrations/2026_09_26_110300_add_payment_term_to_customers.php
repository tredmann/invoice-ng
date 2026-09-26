<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Nullable, and null means „use the company's". Not backfilled
            // from the company: changing a company's default must not silently
            // change what an existing customer is invoiced under.
            $table->string('payment_term')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('payment_term');
        });
    }
};
