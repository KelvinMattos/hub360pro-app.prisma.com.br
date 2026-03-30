<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained()->onDelete('cascade');
                $table->string('invoice_number')->unique();
                $table->string('series')->default('1');
                $table->string('access_key')->nullable()->index();
                $table->decimal('total_amount', 12, 2);
                $table->decimal('tax_amount', 12, 2)->default(0);
                $table->enum('status', ['draft', 'pending', 'authorized', 'cancelled', 'error'])->default('draft');
                $table->text('xml_path')->nullable();
                $table->text('pdf_path')->nullable();
                $table->timestamp('issued_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('tax_rules')) {
            Schema::create('tax_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('cfop', 4);
                $table->decimal('icms_rate', 5, 2)->default(0);
                $table->decimal('pis_rate', 5, 2)->default(0);
                $table->decimal('cofins_rate', 5, 2)->default(0);
                $table->decimal('ipi_rate', 5, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('tax_rules');
    }
};