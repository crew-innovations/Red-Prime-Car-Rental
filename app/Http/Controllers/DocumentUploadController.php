<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Models\CustomerDocument;
use App\Models\DocumentRequest;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mobile upload page for a customer's documents. No customer account: the
 * secure link itself is the key, and it expires or can be revoked.
 */
class DocumentUploadController extends Controller
{
    /** Allowed file types and size (KB). */
    public const MIMES = 'jpg,jpeg,png,webp,pdf';

    public const MAX_KB = 10240;

    public function show(string $token)
    {
        $request = DocumentRequest::findByToken($token);

        if (! $request || ! $request->isUsable()) {
            return $this->page('public.upload-closed', [], 410);
        }

        $uploaded = CustomerDocument::where('document_request_id', $request->id)
            ->get()
            ->groupBy(fn (CustomerDocument $d) => $d->type->value);

        return $this->page('public.upload', [
            'request' => $request,
            'token' => $token,
            'customerName' => str($request->customer?->name)->before(' ')->toString(),
            'uploaded' => $uploaded,
            'company' => Setting::read('company_name', 'RED PRIME RENTALS'),
        ]);
    }

    public function store(Request $http, string $token)
    {
        $request = DocumentRequest::findByToken($token);

        if (! $request || ! $request->isUsable()) {
            return $this->page('public.upload-closed', [], 410);
        }

        $data = $http->validate([
            'type' => ['required', 'string', 'in:'.implode(',', $request->types ?? [])],
            'file' => ['required', 'file', 'mimes:'.self::MIMES, 'max:'.self::MAX_KB],
        ], [
            'file.mimes' => 'Please upload a photo (JPG, PNG) or a PDF.',
            'file.max' => 'The file is too large. The maximum is 10 MB.',
        ]);

        DB::transaction(function () use ($request, $data, $http) {
            $file = $http->file('file');
            $path = $file->store('customers/'.$request->customer_id, 'private');

            CustomerDocument::create([
                'customer_id' => $request->customer_id,
                'booking_id' => $request->booking_id,
                'document_request_id' => $request->id,
                'type' => $data['type'],
                'status' => DocumentStatus::Received,
                'path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
                'mime' => $file->getMimeType(),
                'size' => (int) ceil($file->getSize() / 1024),
                'uploaded_via' => 'link',
            ]);

            $request->increment('upload_count');
            $request->forceFill(['last_used_at' => now()])->save();
        });

        return redirect()->route('documents.upload', ['token' => $token])->with('uploaded', true);
    }

    protected function page(string $view, array $data, int $status = 200)
    {
        return response()->view($view, $data, $status)->withHeaders([
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
        ]);
    }
}
