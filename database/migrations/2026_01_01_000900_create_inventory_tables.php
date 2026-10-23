<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 190);
            $table->string('tax_id', 20)->nullable()->index();
            $table->string('reg_no', 40)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('country', 2)->default('RO');
            $table->string('iban', 40)->nullable();
            $table->string('bank', 120)->nullable();
            $table->string('contact_name', 120)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_vat_payer')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();          // UN/ECE Rec 20: H87, KGM, LTR, C62, SET
            $table->string('name', 60);
            $table->string('symbol', 10);
            $table->timestamps();
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 20)->unique();
            $table->string('type', 20)->default('consumables'); // equipment | consumables | merchandise
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 60)->unique();
            $table->string('name', 190);
            $table->string('type', 20);                    // equipment | consumable | merchandise | service
            $table->foreignId('unit_id')->constrained('units');
            $table->string('vat_group', 40)->default('standard');
            $table->boolean('track_stock')->default(true); // serviciile nu intră în stoc
            $table->unsignedBigInteger('sale_price_minor')->nullable();
            $table->decimal('min_stock', 14, 3)->nullable();
            $table->string('serial_number', 120)->nullable(); // pentru echipamente individuale
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained();
            $table->string('number', 60);
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->string('currency', 3)->default('RON');
            $table->bigInteger('net_total_minor')->default(0);
            $table->bigInteger('vat_total_minor')->default(0);
            $table->bigInteger('gross_total_minor')->default(0);
            $table->bigInteger('paid_minor')->default(0);
            $table->string('status', 20)->default('registered'); // registered | partially_received | received | paid | cancelled
            $table->string('source', 20)->default('manual'); // manual | efactura
            $table->unsignedBigInteger('efactura_inbox_id')->nullable();
            $table->string('file_path', 255)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['supplier_id', 'number']);
        });

        Schema::create('purchase_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 255);
            $table->string('line_type', 20)->default('goods'); // goods | service | equipment
            $table->decimal('quantity', 14, 3);
            $table->string('unit_code', 10)->default('H87');
            $table->decimal('unit_price', 16, 4);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->bigInteger('line_net_minor');
            $table->bigInteger('line_vat_minor');
            $table->decimal('received_quantity', 14, 3)->default(0);
            $table->timestamps();
        });

        // Recepție (NIR). O factură importată din e-Factura poate propune o recepție în stare draft;
        // confirmarea o face un om, după verificarea fizică a bunurilor.
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->nullable()->unique();
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->date('received_on')->nullable();
            $table->string('status', 20)->default('draft'); // draft | confirmed | cancelled
            $table->string('origin', 20)->default('manual'); // manual | efactura_proposal
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('purchase_invoice_line_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 16, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->decimal('quantity', 14, 3);            // + intrare, − ieșire
            $table->decimal('unit_cost', 16, 4)->nullable();
            $table->string('type', 20);                    // receipt | consumption | adjustment | inventory | sale | transfer_in | transfer_out | write_off
            $table->nullableMorphs('reference');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['item_id', 'warehouse_id', 'occurred_at']);
        });

        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->timestamps();
            $table->unique(['item_id', 'warehouse_id']);
        });

        Schema::create('inventory_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained();
            $table->date('counted_on');
            $table->string('status', 20)->default('draft'); // draft | confirmed
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained();
            $table->decimal('expected_quantity', 14, 3);
            $table->decimal('counted_quantity', 14, 3)->nullable();
            $table->timestamps();
            $table->unique(['inventory_count_id', 'item_id']);
        });
    }

    public function down(): void
    {
        foreach (['inventory_count_lines', 'inventory_counts', 'stock_levels', 'stock_movements', 'goods_receipt_lines', 'goods_receipts', 'purchase_invoice_lines', 'purchase_invoices', 'items', 'warehouses', 'units', 'suppliers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
