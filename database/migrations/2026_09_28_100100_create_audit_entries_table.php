<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Restrict rather than cascade, and that is a claim rather than
            // caution: only an Entwurf can be deleted (§3.4) and only issuing
            // writes an entry, so no entry can ever belong to a deletable
            // document. The database says so instead of a comment promising it.
            $table->foreignUuid('document_id')->constrained()->restrictOnDelete();
            // Backed by AuditEvent.
            $table->string('event');
            // When the work happened, not when the row was written. They are
            // the same today and would not be if an entry were ever backfilled.
            $table->timestamp('occurred_at');
            // Null for work performed by a job or a console command. Nothing
            // does that yet; the column admits it rather than inventing a
            // system user.
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('details')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
    }
};
