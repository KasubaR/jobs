<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Four provenance changes to the qualification directory:
 *
 *  1. organization_roles: one organization can be a university AND an awarding body, without a duplicate record.
 *     organizations.type stays as the primary role; every role, that one included, is a row here.
 *  2. qualification_relationships.basis: an official determination (needs a source) is kept apart from an unverified
 *     comparison. Nothing is "official" by default.
 *  3. data_change_logs: who changed which field of which row, from what to what, and through which door.
 *  4. job_qualifications.original_text: the short phrase the employer wrote ("Degree in Accounting"), kept for good.
 *     The surrounding sentence (`evidence`) still goes with the raw ad text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_roles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->string('role', 20);   // awarding_body | professional_body | university | college | regulator | exam_board
            $t->timestamps();

            $t->unique(['organization_id', 'role']);
        });

        $now = now();
        DB::table('organizations')->orderBy('id')->each(fn ($o) => DB::table('organization_roles')->insertOrIgnore(
            ['organization_id' => $o->id, 'role' => $o->type, 'created_at' => $now, 'updated_at' => $now],
        ));

        Schema::table('qualification_relationships', function (Blueprint $t) {
            $t->string('basis', 12)->default('comparison')->after('type');   // official | comparison
            $t->timestamp('verified_at')->nullable()->after('note');         // set only when a source backs it
        });

        Schema::create('data_change_logs', function (Blueprint $t) {
            $t->id();
            $t->string('table_name', 40);
            $t->unsignedBigInteger('row_id');
            $t->string('action', 10);            // created | updated
            $t->string('field', 40)->nullable();
            $t->text('old_value')->nullable();
            $t->text('new_value')->nullable();
            $t->string('actor', 120)->nullable(); // the signed-in person's email, or "command" for the terminal
            $t->string('via', 30);               // review_screen | employers_screen | csv_import ...
            $t->timestamp('created_at');

            $t->index(['table_name', 'row_id']);
            $t->index('created_at');
        });

        Schema::table('job_qualifications', function (Blueprint $t) {
            $t->string('original_text', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('job_qualifications', fn (Blueprint $t) => $t->dropColumn('original_text'));
        Schema::dropIfExists('data_change_logs');
        Schema::table('qualification_relationships', fn (Blueprint $t) => $t->dropColumn(['basis', 'verified_at']));
        Schema::dropIfExists('organization_roles');
    }
};
