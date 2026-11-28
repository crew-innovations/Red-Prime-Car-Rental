<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Site\WebsiteAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public website chat. Replies are written in the app. Car facts come from the catalogue. */
class ChatController extends Controller
{
    public function store(Request $request, WebsiteAssistant $assistant): JsonResponse
    {
        if (filled($request->input('website'))) {
            return response()->json(['data' => ['reply' => '']], 202);
        }

        $data = $request->validate([
            'visitor_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'message' => ['required', 'string', 'max:2000'],
            'locale' => ['nullable', 'in:en,ar'],
            'product_slug' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', 'in:book,enquiry'],
            'start_at' => ['nullable', 'date_format:Y-m-d H:i'],
            'end_at' => ['nullable', 'date_format:Y-m-d H:i'],
            'drive_mode' => ['nullable', 'in:self_drive,with_driver'],
            'name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'pay_now' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $assistant->reply($data)]);
    }
}
