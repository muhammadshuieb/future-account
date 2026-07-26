<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->string('payment_type', 16)->default('credit')->after('status');
            $table->foreignId('cash_box_id')->nullable()->after('warehouse_id')->constrained('cash_boxes')->nullOnDelete();
        });

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->string('payment_type', 16)->default('credit')->after('status');
            $table->foreignId('cash_box_id')->nullable()->after('warehouse_id')->constrained('cash_boxes')->nullOnDelete();
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->string('disk', 32)->default('attachments');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Input VAT (ضريبة مشتريات) — asset; keep 2102 as output VAT payable.
        $parentId = DB::table('accounts')->where('code', '11')->value('id');
        if ($parentId && ! DB::table('accounts')->where('code', '1106')->exists()) {
            DB::table('accounts')->insert([
                'code' => '1106',
                'name' => 'ضريبة مشتريات (مدخلات)',
                'name_en' => 'VAT Input',
                'parent_id' => $parentId,
                'type' => 'asset',
                'nature' => 'debit',
                'level' => 3,
                'is_group' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_box_id');
            $table->dropColumn('payment_type');
        });

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_box_id');
            $table->dropColumn('payment_type');
        });

        DB::table('accounts')->where('code', '1106')->delete();
    }
};
