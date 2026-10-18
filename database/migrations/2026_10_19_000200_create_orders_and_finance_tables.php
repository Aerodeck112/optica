<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Amounts are integer bani (1 leu = 100) so totals and balances never drift through rounding (FIN-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Glasses orders: tracking, not stock management or production (CMD-01…03).
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('location_id')->constrained();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('prescription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            // new | lab | in_progress | quality_check | ready | delivered | cancelled
            $table->string('status', 20)->default('new');
            $table->text('notes')->nullable();
            $table->bigInteger('total_cents')->default(0);
            $table->date('due_on')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['location_id', 'status']);
            $table->index(['status', 'due_on']);
            $table->index('patient_id');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // frame | lens | treatment | contact_lens | service | other
            $table->string('kind', 20);
            $table->string('description', 300);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->bigInteger('unit_price_cents');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // created | status | edited | note
            $table->string('type', 20);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at');
            $table->index(['order_id', 'created_at']);
        });

        // Value of services performed, separate from the money received (FIN-02).
        Schema::create('service_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('location_id')->constrained();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('specialist_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 300);
            $table->bigInteger('amount_cents');
            $table->date('performed_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 300)->nullable();
            $table->timestamps();
            $table->index(['location_id', 'performed_on']);
            $table->index('patient_id');
        });

        // Money movements. Never deleted: a mistake is voided with a reason and re-entered (FIN-01).
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('location_id')->constrained();
            // What is paid: an order or a service charge (explicit relation, FIN-02).
            $table->string('payable_type', 30);
            $table->unsignedBigInteger('payable_id');
            // payment | refund
            $table->string('type', 10);
            $table->boolean('is_advance')->default(false);
            $table->bigInteger('amount_cents');
            // cash | card | transfer | voucher | other
            $table->string('method', 20);
            $table->string('reference', 120)->nullable();
            $table->string('note', 300)->nullable();
            $table->dateTime('received_at');
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 300)->nullable();
            $table->timestamps();
            $table->index(['payable_type', 'payable_id']);
            $table->index(['location_id', 'received_at']);
            $table->index('patient_id');
        });
    }

    public function down(): void
    {
        foreach (['payments', 'service_charges', 'order_events', 'order_items', 'orders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
