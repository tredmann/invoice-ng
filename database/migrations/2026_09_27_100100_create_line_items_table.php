<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('line_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Cascade, uniquely in this schema: a Position has no life without
            // its document, and deleting a draft is the only deletion this
            // system performs.
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->integer('position');
            $table->string('title');
            $table->text('description')->nullable();
            // Decimal, never a float: CalculateTotals takes a BigDecimal and
            // brick/math refuses a float outright.
            $table->decimal('quantity', 12, 3);
            // The UN/ECE code and the Steuersatz in basis points, as values.
            // No foreign key to tax_rates or to a units table: §3.6 says a
            // Position references no master data, and §4 makes an issued
            // document immutable — a rate renamed next year must not be able
            // to reach backwards into the document it was computed with.
            $table->string('unit');
            $table->bigInteger('unit_price');
            $table->integer('tax_rate');
            $table->timestamps();

            $table->unique(['document_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('line_items');
    }
};
