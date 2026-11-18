<?php

namespace App\Http\Controllers;

use App\Models\LeadForm;
use App\Support\Leads\LeadIntake;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Public page of a shared lead form: /f/{token}. Makes a lead; never shows anything back from the CRM. */
class LeadFormController extends Controller
{
    protected function find(string $token): LeadForm
    {
        abort_unless(strlen($token) >= 32, 404);

        return LeadForm::query()->where('token', $token)->firstOrFail();
    }

    public function show(string $token)
    {
        $form = $this->find($token);

        return response()->view('public.lead-form', ['form' => $form, 'closed' => $form->closedReason(), 'sent' => (bool) session('sent')])
            ->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request, string $token)
    {
        $form = $this->find($token);
        if ($why = $form->closedReason()) {
            return redirect()->route('forms.show', $token)->withErrors(['form' => $why]);
        }
        if (filled($request->input('website'))) {
            return redirect()->route('forms.show', $token)->with('sent', true); // bot trap: pretend it worked
        }

        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{6,30}$/'],
            'email' => ['nullable', 'email', 'max:190'],
        ];
        $names = [];
        foreach ($form->questions() as $q) {
            $names['q.'.$q['key']] = $q['label'];
            $rules['q.'.$q['key']] = array_merge($q['required'] ? ['required'] : ['nullable'], match ($q['type']) {
                'email' => ['email', 'max:190'],
                'phone' => ['string', 'max:30', 'regex:/^[0-9+()\-\s]{6,30}$/'],
                'date' => ['date'],
                'number' => ['numeric', 'between:0,1000000'],
                'select' => [\Illuminate\Validation\Rule::in($q['options'])],
                'checkbox' => ['accepted'],
                'textarea' => ['string', 'max:2000'],
                default => ['string', 'max:255'],
            });
            if ($q['type'] === 'checkbox' && ! $q['required']) {
                $rules['q.'.$q['key']] = ['nullable'];
            }
        }
        $data = $request->validate($rules, [], $names);

        $answers = [];
        foreach ($form->questions() as $q) {
            $v = data_get($data, 'q.'.$q['key']);
            if ($q['type'] === 'checkbox') {
                $v = $v ? 'Yes' : null;
            }
            if (filled($v)) {
                $answers[$q['label']] = (string) $v;
            }
        }

        $full = DB::transaction(function () use ($form, $data, $answers) {
            $locked = LeadForm::query()->lockForUpdate()->find($form->id);
            if ($locked->closedReason()) {
                return true;
            }
            // 12 Nov: the agent sent this form in a chat (signed lead in the link): the answers
            // complete that lead and it stays with its agent. No second lead.
            if ($lead = \App\Support\Transfers\LimoLink::lead(request()->input('lead_ref'))) {
                self::complete($lead, $form, $data, $answers);
                $locked->increment('submissions');

                return false;
            }
            LeadIntake::receive([
                'name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'] ?? null,
                'service_type' => $form->service_type?->value, 'answers' => $answers,
                'channel' => 'form', 'source_slug' => 'shared-form', 'source_name' => 'Shared form',
                'platform' => ['form_id' => 'F-'.$form->id, 'form_name' => $form->name],
                'utm_source' => request()->query('utm_source'), 'utm_campaign' => request()->query('utm_campaign'),
                'assign_to' => $form->assign_to,
            ]);
            $locked->increment('submissions');

            return false;
        });
        if ($full) {
            return redirect()->route('forms.show', $token)->withErrors(['form' => 'This form is closed.']);
        }

        return redirect()->route('forms.show', $token)->with('sent', true);
    }

    /** The customer's form answers go onto the lead they were sent for. */
    protected static function complete(\App\Models\Lead $lead, LeadForm $form, array $data, array $answers): void
    {
        $lead->name = mb_substr($data['name'], 0, 255);
        $lead->phone = $lead->phone ?: mb_substr(trim($data['phone']), 0, 40);
        $lead->whatsapp = $lead->whatsapp ?: $lead->phone;
        if (blank($lead->email) && filter_var($data['email'] ?? null, FILTER_VALIDATE_EMAIL)) {
            $lead->email = mb_strtolower(trim($data['email']));
        }
        $lead->service_type = $lead->service_type ?: $form->service_type;
        $lead->answers = array_merge((array) $lead->answers, $answers) ?: null;
        $lead->save();
        $lead->activities()->create(['type' => \App\Enums\ActivityType::Note, 'user_id' => null,
            'body' => mb_substr('The customer filled in "'.$form->name.'":'."\n".'Name: '.$data['name']."\n".'Phone: '.$data['phone']
                .(filled($data['email'] ?? null) ? "\nEmail: ".$data['email'] : '').collect($answers)->map(fn ($v, $k) => "\n".$k.': '.$v)->implode(''), 0, 5000)]);
        if ($lead->assignee) {
            rescue(fn () => \App\Support\Drivers\Notify::person($lead->assignee, 'Form filled in: '.$lead->name, 'The details are on the lead.',
                \App\Filament\Resources\LeadResource::getUrl('edit', ['record' => $lead], panel: 'admin'), 'success', 'lead_returned'), report: false);
        }
    }
}
