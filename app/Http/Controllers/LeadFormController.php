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
}
