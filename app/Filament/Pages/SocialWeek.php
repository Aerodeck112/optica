<?php

namespace App\Filament\Pages;

use App\Modules\Marketing\Models\SocialPost;
use App\Modules\Marketing\Services\Social\SocialImageRenderer;
use App\Modules\Marketing\Services\Social\SocialPlanGenerator;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Planul de postări pe o săptămână: pentru fiecare zi, imaginile gata de descărcat în formatul rețelei,
 * textul de copiat și butonul „Publicată”. Publicarea propriu-zisă se face din aplicația rețelei.
 */
class SocialWeek extends Page
{
    protected string $view = 'filament.pages.social-week';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    protected static string|\UnitEnum|null $navigationGroup = 'Social media';

    protected static ?string $navigationLabel = 'Planul săptămânii';

    protected static ?string $title = 'Planul săptămânii';

    protected static ?int $navigationSort = 1;

    public string $week = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('social.manage');
    }

    public function mount(): void
    {
        $this->week = LocalTime::now()->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    }

    public function shift(int $weeks): void
    {
        $this->week = $this->monday()->addWeeks($weeks)->toDateString();
    }

    public function thisWeek(): void
    {
        $this->mount();
    }

    public function monday(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->week ?: LocalTime::now()->toDateString(), LocalTime::zone())->startOfWeek(CarbonImmutable::MONDAY);
    }

    /** @return array<string, Collection<int, SocialPost>> postările pe zile (Y-m-d), luni → duminică */
    public function days(): array
    {
        $monday = $this->monday();
        $posts = SocialPost::query()->whereBetween('publish_on', [$monday->toDateString(), $monday->addDays(6)->toDateString()])
            ->orderBy('publish_on')->orderBy('publish_time')->get()->groupBy(fn (SocialPost $p) => $p->publish_on->toDateString());
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $monday->addDays($i)->toDateString();
            $days[$date] = $posts[$date] ?? collect();
        }

        return $days;
    }

    public function formats(): array
    {
        return SocialImageRenderer::FORMATS;
    }

    public function markPublished(int $id): void
    {
        $post = SocialPost::query()->findOrFail($id);
        $post->update(['status' => 'published', 'published_at' => now(), 'published_by' => auth()->id()]);
        Notification::make()->success()->title('Marcată publicată: '.$post->title)->send();
    }

    public function markReady(int $id): void
    {
        SocialPost::query()->whereKey($id)->where('status', 'planned')->update(['status' => 'ready']);
    }

    public function reopen(int $id): void
    {
        SocialPost::query()->whereKey($id)->update(['status' => 'planned', 'published_at' => null, 'published_by' => null]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')->label('Propune postări pentru zilele libere')->icon('heroicon-o-sparkles')
                ->requiresConfirmation()
                ->modalHeading('Propune postări pentru această săptămână')
                ->modalDescription('Pentru fiecare zi fără postare se creează o propunere: temă, imagini nefolosite recent și un text construit din informațiile din panou. Propunerile rămân „Planificate” până le verificați; le puteți modifica sau șterge.')
                ->action(function () {
                    $created = app(SocialPlanGenerator::class)->generateWeek($this->monday(), auth()->user());
                    Notification::make()->success()->title($created->isEmpty() ? 'Toate zilele au deja o postare.' : $created->count().' propuneri adăugate.')->send();
                }),
            Action::make('new')->label('Postare nouă')->icon('heroicon-o-plus')->color('gray')
                ->url(fn () => route('filament.admin.resources.social-posts.create')),
        ];
    }
}
