<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Restrict, not cascade: companies are deactivated, never deleted,
            // and a customer an issued document refers to must never vanish
            // with one.
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            // An integer, displayed as K-0004. A string would sort K-10000
            // before K-9999 and make max() + 1 wrong past 9999.
            $table->integer('number');
            $table->string('type');
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('vat_id')->nullable();
            $table->string('street');
            $table->string('postal_code');
            $table->string('city');
            $table->string('email')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // The backstop for numbering: were the lock ever bypassed, a race
            // fails the save instead of producing two customers with one
            // number. Its leading column also serves every tenant-scoped query.
            $table->unique(['company_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
