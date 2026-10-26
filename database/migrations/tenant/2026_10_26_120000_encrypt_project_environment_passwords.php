<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypt the project-environment passwords that were stored in plain text — security audit 2026-10-26,
 * finding 14. The model now casts `password` as `encrypted`; this converts the rows already on disk.
 *
 * Idempotent: a value that already decrypts is left alone, so a re-run (or a row written by the new cast
 * before this ran) is not double-encrypted. Reads the raw column through the query builder, not the cast,
 * so it sees the stored string whatever the model would do with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_environments')) {
            return;
        }

        DB::table('project_environments')
            ->whereNotNull('password')
            ->where('password', '!=', '')
            ->orderBy('id')
            ->each(function (object $row): void {
                try {
                    Crypt::decryptString($row->password);

                    return; // already encrypted
                } catch (DecryptException) {
                    // plain text — encrypt it below
                }

                DB::table('project_environments')
                    ->where('id', $row->id)
                    ->update(['password' => Crypt::encryptString($row->password)]);
            });
    }

    public function down(): void
    {
        // No down: decrypting back to plain text would re-introduce the finding.
    }
};
