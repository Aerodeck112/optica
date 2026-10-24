<?php

namespace App\Filament\Resources\Payments;

use App\Filament\Support\Fields;
use App\Modules\Booking\Models\Booking;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\Refund;
use App\Modules\Finance\Services\Payments\PaymentException;
use App\Modules\Finance\Services\Payments\PaymentService;
use App\Modules\Finance\Services\Payments\RefundService;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

/** Încasări și rambursări din panou. Toate trec prin PaymentService / RefundService. */
final class PaymentActions
{
    private static function run(Closure $callback, string $success): void
    {
        try {
            $callback();
            Notification::make()->success()->title($success)->send();
        } catch (PaymentException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
        }
    }

    public static function record(): Action
    {
        return Action::make('record_payment')->label('Încasează')->icon('heroicon-o-banknotes')->color('success')->modalSubmitActionLabel('Înregistrează încasarea')
            ->visible(fn (Booking $record) => auth()->user()?->can('finance.issue') && ! in_array($record->status, ['cancelled', 'expired'], true) && ($record->balanceMinor() ?? 1) > 0)
            ->modalDescription(fn (Booking $record) => $record->balanceMinor() !== null ? 'Rest de plată: '.Money::format($record->balanceMinor()).'.' : 'Prețul rezervării nu este stabilit; înregistrați suma încasată.')
            ->schema(fn (Booking $record) => [
                Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
                Select::make('method')->label('Metodă')->required()->native()
                    ->options(array_intersect_key(Payment::METHODS, array_flip(PaymentService::MANUAL_METHODS)))->default('card_pos'),
                Fields::money('amount', 'Sumă')->required()->default(self::suggested($record)),
                TextInput::make('note')->label('Notă')->maxLength(255)->placeholder('ex. nr. chitanță, referință transfer'),
            ])
            ->action(fn (Booking $record, array $data) => self::run(
                fn () => app(PaymentService::class)->recordManual($record, $data['method'], (int) $data['amount'], auth()->user(), $data['note'] ?? null, $data['idempotency_key'] ?? null),
                'Încasarea a fost înregistrată.'
            ));
    }

    private static function suggested(Booking $booking): ?int
    {
        $balance = $booking->balanceMinor();
        $paid = $booking->paid_minor - $booking->refunded_minor;

        return $booking->deposit_minor > 0 && $paid < $booking->deposit_minor && $booking->status === 'pending'
            ? $booking->deposit_minor - $paid
            : $balance;
    }

    public static function requestRefund(): Action
    {
        return Action::make('request_refund')->label('Rambursare')->icon('heroicon-o-arrow-uturn-left')->color('warning')->modalSubmitActionLabel('Cere rambursarea')
            ->visible(fn (Payment $record) => auth()->user()?->can('finance.issue') && app(RefundService::class)->refundable($record) > 0)
            ->modalDescription(fn (Payment $record) => 'Se poate rambursa cel mult '.Money::format(app(RefundService::class)->refundable($record)).'. '
                .($record->method === 'online' ? 'Banii se returnează prin procesator, pe cardul folosit.' : 'Returnarea se face manual (numerar/transfer); aplicația doar o consemnează.')
                .' Cererea trebuie aprobată de cineva cu permisiunea „Aprobă rambursări”.')
            ->schema(fn (Payment $record) => [
                Fields::money('amount', 'Sumă')->required()->default(app(RefundService::class)->refundable($record)),
                TextInput::make('reason')->label('Motiv')->required()->maxLength(255),
            ])
            ->action(function (Payment $record, array $data) {
                self::run(function () use ($record, $data) {
                    $refund = app(RefundService::class)->request($record, (int) $data['amount'], $data['reason'], auth()->user());
                    if (auth()->user()->can('finance.refund')) {
                        app(RefundService::class)->approve($refund, auth()->user());
                    }
                }, auth()->user()->can('finance.refund') ? 'Rambursarea a fost procesată. Verificați starea în lista de rambursări.' : 'Cererea de rambursare a fost trimisă spre aprobare.');
            });
    }

    public static function approveRefund(): Action
    {
        return Action::make('approve_refund')->label(fn (Refund $record) => $record->status === 'requested' ? 'Aprobă' : 'Reîncearcă')
            ->icon('heroicon-o-check')->color('success')->requiresConfirmation()
            ->visible(fn (Refund $record) => auth()->user()?->can('finance.refund') && in_array($record->status, ['requested', 'processing', 'failed'], true))
            ->modalDescription(fn (Refund $record) => $record->status === 'processing'
                ? 'Răspunsul anterior al procesatorului a fost neclar. Reîncercarea folosește aceeași cheie de idempotență, deci banii nu se returnează de două ori.'
                : 'Se returnează '.Money::format($record->amount_minor).'.')
            ->action(fn (Refund $record) => self::run(fn () => app(RefundService::class)->approve($record, auth()->user()), 'Rambursarea a fost procesată.'));
    }

    public static function rejectRefund(): Action
    {
        return Action::make('reject_refund')->label('Respinge')->icon('heroicon-o-x-mark')->color('danger')
            ->visible(fn (Refund $record) => auth()->user()?->can('finance.refund') && $record->status === 'requested')
            ->schema([TextInput::make('reason')->label('Motiv')->required()->maxLength(255)])
            ->action(fn (Refund $record, array $data) => self::run(fn () => app(RefundService::class)->reject($record, auth()->user(), $data['reason']), 'Cererea a fost respinsă.'));
    }
}
