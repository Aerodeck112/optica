<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Resources\Leads\LeadResource;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\LeadService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

/** Pipeline Kanban: Cerere → Contactat → Ofertă trimisă → În negociere → Confirmat → Finalizat / Pierdut. */
class LeadPipeline extends Page
{
    protected static string $resource = LeadResource::class;

    protected string $view = 'filament.pages.lead-pipeline';

    protected static ?string $title = 'Pipeline cereri evenimente';

    public function move(int $leadId, string $stage): void
    {
        abort_unless(LeadResource::canViewAny(), 403);
        if (! array_key_exists($stage, Lead::STAGES) || $stage === 'lost') {
            return; // „Pierdut” cere un motiv — se face din fișa cererii
        }
        $lead = Lead::query()->findOrFail($leadId);
        if ($lead->stage !== $stage) {
            app(LeadService::class)->moveTo($lead, $stage, auth()->id());
            Notification::make()->success()->title("{$lead->contact_name}: ".Lead::STAGES[$stage])->send();
        }
    }

    protected function getViewData(): array
    {
        $leads = Lead::query()->where(fn ($q) => $q->whereNotIn('stage', ['completed', 'lost'])->orWhere('stage_changed_at', '>=', now()->subDays(30)))
            ->orderBy('preferred_date')->get()->groupBy('stage');

        return ['stages' => Lead::STAGES, 'leads' => $leads, 'url' => fn (Lead $lead) => LeadResource::getUrl('view', ['record' => $lead])];
    }
}
