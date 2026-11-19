<?php

namespace App\Http\Requests;

use App\Modules\Arena\Models\Player;
use App\Modules\Arena\Services\AvatarCatalog;
use App\Modules\Arena\Services\NicknamePolicy;
use App\Modules\Arena\Services\PlayerService;
use Illuminate\Validation\Rule;

class SignWaiverRequest extends PublicFormRequest
{
    public const MAX_CHILDREN = 10;

    /** Formularul pentru familie: un adult semnează o dată, pentru el și/sau pentru copiii lui. */
    public function isFamily(): bool
    {
        return $this->input('mode') === 'family';
    }

    public function rules(): array
    {
        $date = ['date_format:Y-m-d', 'before:today', 'after:1900-01-01'];
        if ($this->isFamily()) {
            return [
                'mode' => ['required', 'in:family'],
                'signer_name' => ['required', 'string', 'min:3', 'max:120'],
                'self' => ['nullable', 'boolean'],
                'signer_birth_date' => ['nullable', 'required_if:self,1', ...$date],
                'self_nickname' => ['nullable', 'string', 'max:40'],
                'children' => ['nullable', 'array', 'max:'.self::MAX_CHILDREN],
                'children.*.name' => ['required', 'string', 'min:3', 'max:120'],
                'children.*.birth_date' => ['required', ...$date],
                'children.*.nickname' => ['nullable', 'string', 'max:40'],
                'guardian_relationship' => ['nullable', 'string', 'max:60'],
                'guardian_phone' => ['nullable', 'string', 'min:6', 'max:40', 'regex:/^[0-9+()\s.\-]+$/'],
                'booking_code' => ['nullable', 'string', 'max:20'],
                'email' => ['nullable', 'email:rfc', 'max:190'],
                'signature' => ['required', 'string', 'starts_with:data:image/png;base64,', 'max:400000'],
                'accept' => ['accepted'],
                'marketing_email' => ['nullable', 'boolean'],
                'photo_consent' => ['nullable', 'boolean'],
                'screen_display' => ['nullable', 'boolean'],
                'locale' => ['nullable', 'string', 'max:5'],
            ];
        }

        return [
            'participant_name' => ['required', 'string', 'min:3', 'max:120'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            'booking_code' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'guardian_name' => ['nullable', 'string', 'min:3', 'max:120'],
            'guardian_relationship' => ['nullable', 'string', 'max:60'],
            'guardian_phone' => ['nullable', 'string', 'min:6', 'max:40', 'regex:/^[0-9+()\s.\-]+$/'],
            'signer_name' => ['required', 'string', 'min:3', 'max:120'],
            'signature' => ['required', 'string', 'starts_with:data:image/png;base64,', 'max:400000'],
            'accept' => ['accepted'],
            'marketing_email' => ['nullable', 'boolean'],
            'photo_consent' => ['nullable', 'boolean'],
            'locale' => ['nullable', 'string', 'max:5'],
            'nickname' => ['nullable', 'string', 'max:40', function (string $attribute, $value, \Closure $fail) {
                // același jucător (recunoscut după nume + data nașterii) își poate păstra nickname-ul
                $existing = Player::query()->where('identity_hash', PlayerService::identityHash((string) $this->input('participant_name'), (string) $this->input('birth_date')))->first();
                if ($problem = app(NicknamePolicy::class)->problem($value, $existing)) {
                    $fail($problem);
                }
            }],
            'avatar_key' => ['nullable', 'string', Rule::in(array_keys(AvatarCatalog::AVATARS))],
            'screen_display' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Participanții formularului, fiecare cu datele pentru o declarație separată (aceeași semnătură, același semnatar).
     *
     * @return array<int, array{participant_name: string, birth_date: string, nickname: ?string, avatar_key: ?string, is_self: bool}>
     */
    public function participants(): array
    {
        if (! $this->isFamily()) {
            return [[
                'participant_name' => trim((string) $this->input('participant_name')),
                'birth_date' => (string) $this->input('birth_date'),
                'nickname' => $this->input('nickname'),
                'avatar_key' => $this->input('avatar_key'),
                'is_self' => trim((string) $this->input('participant_name')) === trim((string) $this->input('signer_name')),
            ]];
        }
        $list = [];
        if ($this->boolean('self')) {
            $list[] = ['participant_name' => trim((string) $this->input('signer_name')), 'birth_date' => (string) $this->input('signer_birth_date'), 'nickname' => $this->input('self_nickname'), 'avatar_key' => null, 'is_self' => true];
        }
        foreach ((array) $this->input('children', []) as $child) {
            $list[] = ['participant_name' => trim((string) ($child['name'] ?? '')), 'birth_date' => (string) ($child['birth_date'] ?? ''), 'nickname' => $child['nickname'] ?? null, 'avatar_key' => null, 'is_self' => false];
        }

        return $list;
    }

    /** Nickname-urile: reguli comune și unicitate și între membrii aceleiași familii. */
    public function after(): array
    {
        if (! $this->isFamily()) {
            return [];
        }

        return [function ($validator) {
            if (! $this->boolean('self') && empty($this->input('children'))) {
                $validator->errors()->add('self', __('site.waiver.family.nobody'));

                return;
            }
            $seen = [];
            $fields = $this->boolean('self') ? ['self_nickname' => [$this->input('signer_name'), $this->input('signer_birth_date'), $this->input('self_nickname')]] : [];
            foreach ((array) $this->input('children', []) as $i => $child) {
                $fields["children.{$i}.nickname"] = [$child['name'] ?? '', $child['birth_date'] ?? '', $child['nickname'] ?? null];
            }
            foreach ($fields as $field => [$name, $birth, $nickname]) {
                if (blank($nickname)) {
                    continue;
                }
                $existing = Player::query()->where('identity_hash', PlayerService::identityHash((string) $name, (string) $birth))->first();
                if ($problem = app(NicknamePolicy::class)->problem($nickname, $existing)) {
                    $validator->errors()->add($field, $problem);
                }
                $key = mb_strtolower(trim((string) $nickname));
                if (isset($seen[$key])) {
                    $validator->errors()->add($field, __('site.waiver.family.nickname_twice'));
                }
                $seen[$key] = true;
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'signer_birth_date' => __('site.waiver.birth_date'),
            'children.*.name' => __('site.waiver.family.child_name'),
            'children.*.birth_date' => __('site.waiver.birth_date'),
            'participant_name' => __('site.waiver.participant_name'),
            'birth_date' => __('site.waiver.birth_date'),
            'guardian_name' => __('site.waiver.guardian_name'),
            'guardian_phone' => __('site.waiver.guardian_phone'),
            'signer_name' => __('site.waiver.signer_name'),
            'signature' => __('site.waiver.signature'),
            'accept' => __('site.waiver.accept'),
            'nickname' => __('site.waiver.nickname'),
        ];
    }

    public function messages(): array
    {
        return ['signature.required' => __('site.waiver.signature_missing')];
    }

    /** O semnătură reală: imagine PNG validă, nu un chenar gol. */
    public function signatureIsValid(): bool
    {
        $binary = base64_decode(substr((string) $this->input('signature'), strlen('data:image/png;base64,')), true);
        if ($binary === false || ! str_starts_with($binary, "\x89PNG") || strlen($binary) < 800) {
            return false;
        }
        $size = @getimagesizefromstring($binary);

        return is_array($size) && $size[0] >= 100 && $size[1] >= 50;
    }
}
