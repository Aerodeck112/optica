<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vânzarea la recepție a produselor din stoc (băuturi, gustări, suveniruri): fiecare vânzare are liniile ei,
 * încasarea (numerar / card POS), mișcările de stoc și bonul fiscal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counter_sales', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('method', 20);
            $table->bigInteger('total_minor');
            $table->string('status', 20)->default('completed'); // completed | cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('counter_sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('counter_sale_id')->constrained('counter_sales')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items');
            $table->string('name', 190);
            $table->decimal('quantity', 14, 3);
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('amount_minor');
            $table->string('vat_group', 40);
            $table->decimal('vat_rate', 5, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counter_sale_lines');
        Schema::dropIfExists('counter_sales');
    }
};
