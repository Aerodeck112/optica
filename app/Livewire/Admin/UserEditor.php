<?php

namespace App\Livewire\Admin;

use App\Models\Location;
use App\Models\Role;
use App\Models\Specialist;
use App\Models\User;
use App\Notifications\StaffInvitation;
use App\Support\Access\AccessControl;
use App\Support\Access\SessionRevoker;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Staff accounts: roles, locations, link to a specialist profile (ACC-01). Every change of rights
 * is audited (ACC-02, SEC-03). The platform can never be left without an active superadministrator.
 */
class UserEditor extends Component
{
    public ?User $user = null;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $locale = 'ro';

    public bool $is_active = true;

    public bool $all_locations = false;

    /** @var list<int> */
    public array $roleIds = [];

    /** @var list<int> */
    public array $locationIds = [];

    public ?int $specialistId = null;

    public function boot(): void
    {
        $this->authorize('users.manage');
    }

    public function mount(?User $user = null): void
    {
        $this->user = $user?->exists ? User::withTrashed()->with(['roles', 'locations', 'specialist'])->find($user->id) : null;
        if ($this->user) {
            $this->name = $this->user->name;
            $this->email = $this->user->email;
            $this->phone = (string) $this->user->phone;
            $this->locale = $this->user->preferredLocale();
            $this->is_active = (bool) $this->user->is_active;
            $this->all_locations = (bool) $this->user->all_locations;
            $this->roleIds = $this->user->roles->pluck('id')->map(fn ($id) => (int) $id)->all();
            $this->locationIds = $this->user->locations->pluck('id')->map(fn ($id) => (int) $id)->all();
            $this->specialistId = $this->user->specialist?->id;
        }
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($this->user?->id)],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9 +().-]+$/'],
            'locale' => ['required', Rule::in(config('optica.locales'))],
            'is_active' => ['boolean'],
            'all_locations' => ['boolean'],
            'roleIds' => ['array'],
            'roleIds.*' => ['integer', 'exists:roles,id'],
            'locationIds' => ['array'],
            'locationIds.*' => ['integer', 'exists:locations,id'],
            'specialistId' => ['nullable', 'integer', Rule::exists('specialists', 'id')->whereNull('deleted_at')],
        ];
    }

    public function save(): void
    {
        $data = $this->validate();
        $data['email'] = Str::lower($data['email']);
        $roleIds = array_values(array_unique(array_map('intval', $data['roleIds'])));
        $locationIds = $data['all_locations'] ? [] : array_values(array_unique(array_map('intval', $data['locationIds'])));

        if ($this->user && $this->user->is(auth()->user()) && ! $data['is_active']) {
            $this->addError('is_active', __('users.errors.self_deactivate'));

            return;
        }
        if ($this->wouldRemoveLastSuperadmin($roleIds, $data['is_active'])) {
            $this->addError('roleIds', __('users.errors.last_superadmin'));

            return;
        }
        if ($data['specialistId'] && Specialist::whereKey($data['specialistId'])->whereNotNull('user_id')->where('user_id', '!=', $this->user?->id)->exists()) {
            $this->addError('specialistId', __('users.errors.specialist_taken'));

            return;
        }

        $created = $this->user === null;
        $before = $this->user ? $this->snapshot(User::withTrashed()->with(['roles', 'locations'])->find($this->user->id)) : null;

        $user = DB::transaction(function () use ($data, $roleIds, $locationIds) {
            $user = $this->user ?? new User(['password' => Str::password(40)]);
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?: null,
                'locale' => $data['locale'],
                'is_active' => $data['is_active'],
                'all_locations' => $data['all_locations'],
            ])->save();
            $user->roles()->sync($roleIds);
            $user->locations()->sync($locationIds);

            Specialist::where('user_id', $user->id)->when($data['specialistId'], fn ($q) => $q->whereKeyNot($data['specialistId']))->update(['user_id' => null]);
            if ($data['specialistId']) {
                Specialist::whereKey($data['specialistId'])->update(['user_id' => $user->id]);
            }

            return $user;
        });

        app(AccessControl::class)->flush($user);
        $after = $this->snapshot($user->fresh(['roles', 'locations']));
        $audit = app(AuditLogger::class);
        $audit->log($created ? 'users.created' : 'users.updated', $user);
        if ($before === null || $before['roles'] !== $after['roles'] || $before['locations'] !== $after['locations'] || $before['all_locations'] !== $after['all_locations']) {
            $audit->log('users.rights_changed', $user, ['before' => $before, 'after' => $after]);
        }
        if ($before && $before['is_active'] && ! $data['is_active']) {
            app(SessionRevoker::class)->revoke($user);
        }
        if ($created) {
            $this->sendInvitation($user);
        }

        session()->flash('status', $created ? __('users.created_invited', ['email' => $user->email]) : __('users.saved'));
        $this->redirectRoute('admin.users.edit', $user, navigate: true);
    }

    public function invite(): void
    {
        abort_unless($this->user && ! $this->user->trashed(), 404);
        $this->sendInvitation($this->user);
        session()->flash('status', __('users.invitation_sent', ['email' => $this->user->email]));
        $this->redirectRoute('admin.users.edit', $this->user, navigate: true);
    }

    public function resetTwoFactor(): void
    {
        abort_unless($this->user, 404);
        $this->user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        app(SessionRevoker::class)->revoke($this->user);
        app(AuditLogger::class)->log('users.two_factor_reset', $this->user);
        session()->flash('status', __('users.two_factor_reset_done'));
        $this->redirectRoute('admin.users.edit', $this->user, navigate: true);
    }

    public function revokeSessions(): void
    {
        abort_unless($this->user, 404);
        $count = app(SessionRevoker::class)->revoke($this->user, $this->user->is(auth()->user()) ? session()->getId() : null);
        app(AuditLogger::class)->log('users.sessions_revoked', $this->user, ['count' => $count]);
        session()->flash('status', __('users.sessions_revoked', ['count' => $count]));
        $this->redirectRoute('admin.users.edit', $this->user, navigate: true);
    }

    private function sendInvitation(User $user): void
    {
        $token = Password::broker('invites')->createToken($user);
        $user->notify(new StaffInvitation($token));
        app(AuditLogger::class)->log('users.invited', $user);
    }

    private function wouldRemoveLastSuperadmin(array $roleIds, bool $active): bool
    {
        $superadminRoleId = (int) Role::where('key', 'superadmin')->value('id');
        if (! $this->user || ($active && in_array($superadminRoleId, $roleIds, true))) {
            return false;
        }

        $others = User::query()
            ->whereKeyNot($this->user->id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereKey($superadminRoleId))
            ->exists();
        $isSuperadminNow = $this->user->roles()->whereKey($superadminRoleId)->exists() && $this->user->is_active;

        return $isSuperadminNow && ! $others;
    }

    private function snapshot(User $user): array
    {
        return [
            'roles' => $user->roles->pluck('key')->sort()->values()->all(),
            'locations' => $user->locations->pluck('id')->sort()->values()->all(),
            'all_locations' => (bool) $user->all_locations,
            'is_active' => (bool) $user->is_active,
        ];
    }

    public function render()
    {
        return view('livewire.admin.user-editor', [
            'roles' => Role::orderBy('id')->get(),
            'locations' => Location::orderBy('sort_order')->get(),
            'specialists' => Specialist::query()
                ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $this->user?->id))
                ->orderBy('display_name')->get(),
        ])->layout('components.layouts.crm')->title($this->user?->name ?? __('users.create'));
    }
}
