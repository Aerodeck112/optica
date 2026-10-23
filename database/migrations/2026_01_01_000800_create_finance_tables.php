<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_series', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 20);           // invoice | proforma | credit_note | receipt_internal | goods_receipt
            $table->string('prefix', 20);                  // ex: AWCJ
            $table->unsignedInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(5);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['document_type', 'prefix']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 20);           // invoice | proforma | credit_note
            $table->string('kind', 20)->default('standard'); // standard | advance | final | correction
            $table->string('status', 12)->default('draft'); // draft | issued | void (doar ciorne/proforme)
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->string('series_prefix', 20)->nullable();
            $table->unsignedInteger('number')->nullable();
            $table->string('full_number', 40)->nullable()->unique();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('tax_point_date')->nullable();
            $table->string('currency', 3)->default('RON');
            $table->boolean('prices_include_vat')->default(true);
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->json('seller_snapshot')->nullable();
            $table->json('buyer_snapshot')->nullable();
            $table->bigInteger('net_total_minor')->default(0);
            $table->bigInteger('vat_total_minor')->default(0);
            $table->bigInteger('gross_total_minor')->default(0);
            $table->bigInteger('paid_minor')->default(0);
            $table->json('vat_breakdown')->nullable();     // [{category, rate, taxable_minor, tax_minor}]
            $table->foreignId('related_invoice_id')->nullable()->constrained('invoices')->nullOnDelete(); // storno/regularizare
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('payment_terms', 255)->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->string('content_hash', 64)->nullable(); // hash al conținutului emis — detectează modificări
            $table->string('efactura_status', 20)->default('not_applicable'); // not_applicable | pending | generated | validated | sent | accepted | rejected | error
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            $table->unique(['series_id', 'number']);
            $table->index(['document_type', 'issue_date']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('description', 255);
            $table->string('item_code', 60)->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->decimal('quantity', 14, 3);
            $table->string('unit_code', 10)->default('H87'); // UN/ECE Rec 20: H87 bucată, C62 unitate, HUR oră
            $table->decimal('unit_price', 16, 4);          // fără TVA (pentru UBL PriceAmount)
            $table->bigInteger('unit_price_gross_minor')->nullable();
            $table->decimal('vat_rate', 5, 2);
            $table->string('vat_category', 4)->default('S');
            $table->string('vat_exemption_reason', 255)->nullable();
            $table->bigInteger('line_net_minor');
            $table->bigInteger('line_vat_minor');
            $table->bigInteger('line_gross_minor');
            $table->unsignedBigInteger('advance_invoice_id')->nullable(); // linie de regularizare a unui avans
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 20);                  // cash | card_pos | bank_transfer | online | voucher
            $table->string('purpose', 20)->default('booking'); // deposit | balance | booking | voucher_sale | other
            $table->bigInteger('amount_minor');
            $table->string('currency', 3)->default('RON');
            $table->string('status', 20)->default('pending'); // pending | succeeded | failed | cancelled | refunded | partially_refunded
            $table->string('provider', 30)->nullable();
            $table->string('provider_reference', 190)->nullable();
            $table->string('checkout_url', 1000)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->bigInteger('refunded_minor')->default(0);
            $table->timestamp('succeeded_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_reference']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('status', 20)->default('requested'); // requested | approved | processing | succeeded | failed | rejected
            $table->string('reason', 255);
            $table->string('provider_reference', 190)->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_minor');
            $table->timestamps();
            $table->unique(['payment_id', 'invoice_id']);
        });

        Schema::create('efactura_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 10);             // test | prod
            $table->string('xml_path', 255);
            $table->string('xml_hash', 64);
            $table->string('status', 20)->default('generated'); // generated | validated | validation_failed | uploading | uploaded | in_processing | accepted | rejected | error
            $table->string('upload_index', 40)->nullable(); // index_incarcare
            $table->string('download_id', 40)->nullable();
            $table->string('response_path', 255)->nullable();
            $table->json('validation_errors')->nullable();
            $table->json('anaf_errors')->nullable();
            $table->unsignedSmallInteger('status_checks')->default(0);
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->boolean('is_current')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'last_checked_at']);
        });

        Schema::create('efactura_inbox', function (Blueprint $table) {
            $table->id();
            $table->string('anaf_message_id', 40)->unique();
            $table->string('message_type', 40);            // FACTURA PRIMITA | FACTURA TRIMISA | ERORI FACTURA | MESAJ CUMPARATOR
            $table->string('issuer_tax_id', 20)->nullable();
            $table->string('upload_index', 40)->nullable();
            $table->timestamp('anaf_created_at')->nullable();
            $table->string('zip_path', 255)->nullable();
            $table->json('parsed')->nullable();
            $table->string('status', 20)->default('new'); // new | downloaded | parsed | linked | ignored | error
            $table->unsignedBigInteger('purchase_invoice_id')->nullable();
            $table->timestamps();
        });

        Schema::create('fiscal_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('driver', 30)->default('simulator'); // simulator | agent
            $table->string('model', 120)->nullable();
            $table->string('serial_number', 60)->nullable();
            $table->string('agent_key_id', 40)->nullable()->unique();
            $table->text('agent_secret')->nullable();      // criptat
            $table->json('vat_mapping')->nullable();       // cota TVA → grupa fiscală a aparatului (A, B, C...)
            $table->json('payment_mapping')->nullable();   // metoda → cod plată al aparatului
            $table->string('status', 20)->default('unconfigured');
            $table->timestamp('last_seen_at')->nullable();
            $table->json('last_diagnostic')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('fiscal_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('command_id')->unique();          // comanda unică trimisă aparatului
            $table->foreignId('fiscal_device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->json('items');
            $table->json('payments');
            $table->bigInteger('total_minor');
            $table->boolean('is_simulated')->default(false);
            $table->string('status', 30)->default('queued'); // queued | dispatched | printed | failed | uncertain | reconciled_printed | reconciled_not_printed | cancelled
            $table->string('fiscal_number', 40)->nullable();
            $table->string('z_report_number', 20)->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->foreignId('retry_of_id')->nullable()->constrained('fiscal_receipts')->nullOnDelete();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reconciliation_note')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['fiscal_device_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['fiscal_receipts', 'fiscal_devices', 'efactura_inbox', 'efactura_submissions', 'payment_allocations', 'refunds', 'payments', 'invoice_lines', 'invoices', 'document_series'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
