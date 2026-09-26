<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Restrict everywhere: companies and customers are deactivated,
            // never deleted, and a Beleg must never lose the parties it names.
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            // Parental's discriminator. `invoice` is the only value anything
            // can write today; Storno, Teilstorno and Gutschrift join it as
            // classes rather than as tables (tech stack §6.5).
            $table->string('type');
            $table->string('status');
            // Null until the document is issued. A unique index over a
            // nullable column still admits every draft: PostgreSQL treats
            // nulls as distinct, so the backstop numbering will rest on can be
            // built before the numbering exists.
            $table->integer('number')->nullable();
            // The Ausstellungsdatum. Editable while a draft, where it is a
            // proposal; it becomes a statement of fact at issue.
            $table->date('issued_on');
            // Exactly one of these forms is ever set. EN16931 maps them
            // differently — BT-72 for the day, BG-14 for the period — so a
            // single field holding both would map to neither.
            $table->date('performed_on')->nullable();
            $table->date('performed_from')->nullable();
            $table->date('performed_to')->nullable();
            // Copied onto the document, not read through the customer: the
            // Zahlungsziel is part of what issuing freezes.
            $table->string('payment_term');
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'issued_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
