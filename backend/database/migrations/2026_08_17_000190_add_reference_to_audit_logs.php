<?php

use App\Models\AuditLog;
use App\Support\AuditCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        if (! Schema::hasColumn('audit_logs', 'reference')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->string('reference', 160)->nullable()->after('auditable_id');
                $table->index('reference');
            });
        }

        AuditLog::query()->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                if (filled($row->reference)) {
                    continue;
                }
                $reference = AuditCatalog::referenceFromArrays(
                    is_array($row->new_values) ? $row->new_values : null,
                    is_array($row->old_values) ? $row->old_values : null,
                );
                if ($reference) {
                    $row->timestamps = false;
                    $row->update(['reference' => $reference]);
                }
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('audit_logs') && Schema::hasColumn('audit_logs', 'reference')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropIndex(['reference']);
                $table->dropColumn('reference');
            });
        }
    }
};
