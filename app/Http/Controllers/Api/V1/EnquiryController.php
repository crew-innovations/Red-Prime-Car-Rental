<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * General website enquiry (contact page, "call me back"), not tied to one car or yacht.
 * Creates a lead in the pipeline of the chosen service; never a booking. Website M3.
 */
class EnquiryController extends Controller
{
    public const SERVICES = ['car', 'yacht', 'trip', 'package', 'transfer', 'other'];

    public function store(Request $request): JsonResponse
    {
        if (filled($request->input('website'))) {
            return response()->json(['data' => ['status' => 'received']], 202);
        }

        $data = $request->validate([
            'service' => ['nullable', Rule::in(self::SERVICES)],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{6,30}$/'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'message' => ['nullable', 'string', 'max:2000'],
            'kind' => ['nullable', Rule::in(['contact', 'callback'])],
            'page' => ['nullable', 'string', 'max:255'],
            'utm_source' => ['nullable', 'string', 'max:100'],
            'utm_medium' => ['nullable', 'string', 'max:100'],
            'utm_campaign' => ['nullable', 'string', 'max:150'],
        ]);

        $service = ServiceType::tryFrom($data['service'] ?? 'other') ?? ServiceType::Other;
        $kind = $data['kind'] ?? 'contact';

        // The same phone asking again within 10 minutes: answer with the first lead, store nothing new.
        $recent = Phone::key($data['phone']) === null ? null : Lead::query()
            ->where('channel', 'website')
            ->whereNull('interested_in_id')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->where(fn ($q) => Phone::whereMatches($q, ['phone'], $data['phone']))
            ->latest()->first();
        if ($recent) {
            return $this->respond($recent, true, 200);
        }

        $lead = Lead::create([
            'name' => trim($data['name']),
            'phone' => trim($data['phone']),
            'email' => $data['email'] ?? null,
            'lead_source_id' => LeadSource::firstOrCreate(['slug' => 'website'], ['name' => 'Website', 'is_active' => true])->id,
            'channel' => 'website',
            'service_type' => $service,
            'preferred_date' => $data['date'] ?? null,
            'message' => trim(implode("\n", array_filter([
                $kind === 'callback' ? '[Website: call me back]' : '[Website contact form]',
                'Interested in: '.$service->getLabel(),
                ! empty($data['page']) ? 'Page: '.$data['page'] : null,
                filled($data['message'] ?? null) ? "\n".trim($data['message']) : null,
            ]))),
            'utm_source' => $data['utm_source'] ?? null,
            'utm_medium' => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'priority' => $kind === 'callback' ? 'hot' : 'warm',
        ]);

        \App\Support\Leads\Assignment::auto($lead);

        return $this->respond($lead, false, 201);
    }

    protected function respond(Lead $lead, bool $duplicate, int $status): JsonResponse
    {
        return response()->json(['data' => [
            'reference' => sprintf('LD-%05d', $lead->id),
            'status' => 'received',
            'duplicate' => $duplicate,
            'message' => 'Thank you. Our team will contact you shortly.',
        ]], $status);
    }
}
