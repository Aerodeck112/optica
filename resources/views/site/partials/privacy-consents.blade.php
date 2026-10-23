{{-- Informare GDPR (nu consimțământ) + opțiuni de marketing separate, nebifate implicit. --}}
@php $privacy = \App\Modules\Cms\Models\LegalDocument::current('privacy'); @endphp
<p class="small muted mt-5">
    {!! __('site.booking.privacy_note', ['privacy' => $privacy ? '<a href="'.e(lroute('legal.show', ['slug' => $privacy->getTranslation('slug', app()->getLocale(), true)])).'">'.e(__('site.booking.privacy_link')).'</a>' : e(__('site.booking.privacy_link'))]) !!}
</p>
<fieldset class="mt-5" style="border:0;padding:0">
    <legend class="field__label">{{ __('site.booking.marketing_title') }}</legend>
    <label class="check mt-4"><input type="checkbox" name="marketing_email" value="1" @checked(old('marketing_email'))><span>{{ __('site.booking.marketing_email') }}</span></label>
    <label class="check"><input type="checkbox" name="marketing_sms" value="1" @checked(old('marketing_sms'))><span>{{ __('site.booking.marketing_sms') }}</span></label>
    <p class="field__hint mt-4">{{ __('site.booking.marketing_hint') }}</p>
</fieldset>
