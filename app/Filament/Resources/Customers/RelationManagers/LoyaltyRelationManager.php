<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Modules\Loyalty\Models\Reward;
use App\Modules\Loyalty\Services\LoyaltyException;
use App\Modules\Loyalty\Services\LoyaltyService;
use App\Modules\Loyalty\Services\RewardService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LoyaltyRelationManager extends RelationManager
{
    protected static string $relationship = 'loyaltyEntries';

    protected static ?string $title = 'Puncte fidelitate';

    public const TYPES = ['earn' => 'Câștigate', 'redeem' => 'Folosite', 'reversal' => 'Anulate (rambursare)', 'adjust' => 'Corecție', 'expire' => 'Expirate', 'referral' => 'Recomandare'];

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Data')->dateTime('d.m.Y H:i', config('aw.timezone')),
                TextColumn::make('type')->label('Tip')->formatStateUsing(fn ($state) => self::TYPES[$state] ?? $state),
                TextColumn::make('delta')->label('Puncte')->formatStateUsing(fn ($state) => ($state > 0 ? '+' : '').$state)->color(fn ($state) => $state > 0 ? 'success' : 'danger'),
                TextColumn::make('balance_after')->label('Sold'),
                TextColumn::make('note')->label('Detalii')->placeholder('—'),
                TextColumn::make('expires_at')->label('Expiră')->date('d.m.Y')->placeholder('—'),
            ])
            ->headerActions([
                Action::make('reward')->label('Schimbă puncte pe recompensă')->icon('heroicon-o-gift')
                    ->visible(fn () => auth()->user()?->can('vouchers.manage'))
                    ->schema([Select::make('reward_id')->label('Recompensă')->required()
                        ->options(fn () => Reward::query()->where('is_active', true)->get()->mapWithKeys(fn ($r) => [$r->id => $r->getTranslation('name', 'ro').' — '.$r->points_cost.' puncte']))])
                    ->action(function (array $data) {
                        try {
                            $voucher = app(RewardService::class)->redeem($this->getOwnerRecord(), Reward::query()->findOrFail($data['reward_id']), 'staff:'.$this->getOwnerRecord()->id.':'.now()->format('YmdHisv'), auth()->id());
                            Notification::make()->success()->title("Voucher emis: {$voucher->code}")->persistent()->send();
                        } catch (LoyaltyException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
                Action::make('adjust')->label('Corecție puncte')->icon('heroicon-o-adjustments-horizontal')
                    ->visible(fn () => auth()->user()?->can('loyalty.manage'))
                    ->schema([
                        TextInput::make('delta')->label('Puncte (+ adaugă / − scade)')->integer()->required()->notIn([0]),
                        TextInput::make('reason')->label('Motiv')->required()->maxLength(200),
                    ])
                    ->action(function (array $data) {
                        try {
                            app(LoyaltyService::class)->adjust($this->getOwnerRecord(), (int) $data['delta'], $data['reason'], auth()->id());
                            Notification::make()->success()->title('Corecția a fost înregistrată.')->send();
                        } catch (LoyaltyException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ]);
    }
}
