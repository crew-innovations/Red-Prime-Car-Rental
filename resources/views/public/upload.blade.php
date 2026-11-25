@extends('public.layout')
@section('title', 'Upload your documents')
@section('tag', 'Secure upload')

@section('content')
    <div class="card">
        <h1>Hi{{ $customerName ? ' '.$customerName : '' }},</h1>
        <p class="muted" style="margin:0">Please upload the documents below for your booking with {{ $company }}. A clear photo or PDF is fine. Your files are sent privately to our team.</p>
    </div>

    @if (session('uploaded'))
        <div class="alert ok">Thank you, we received your file. You can upload the next one below.</div>
    @endif
    @if ($errors->any())
        <div class="alert bad">{{ $errors->first() }}</div>
    @endif

    <div class="card">
        <h2>Documents requested</h2>
        <table>
            @foreach ($request->typeLabels() as $type => $label)
                @php($latest = ($uploaded[$type] ?? collect())->sortByDesc('id')->first())
                <tr>
                    <td>{{ $label }}</td>
                    <td class="r">
                        @if (! $latest)
                            <span class="pill warn">Needed</span>
                        @elseif ($latest->status === \App\Enums\DocumentStatus::Approved)
                            <span class="pill ok">Approved</span>
                        @elseif (in_array($latest->status, [\App\Enums\DocumentStatus::Rejected, \App\Enums\DocumentStatus::Replacement], true))
                            <span class="pill bad">Please upload again</span>
                        @else
                            <span class="pill info">Received</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    </div>

    <form class="card" method="post" action="{{ route('documents.upload.store', ['token' => $token]) }}" enctype="multipart/form-data">
        @csrf
        <h2>Upload a document</h2>
        <label for="type">Document</label>
        <select id="type" name="type" required>
            @foreach ($request->typeLabels() as $type => $label)
                <option value="{{ $type }}" @selected(old('type') === $type)>{{ $label }}</option>
            @endforeach
        </select>

        <label for="file">Photo or PDF (max 10 MB)</label>
        <input id="file" type="file" name="file" accept="image/jpeg,image/png,image/webp,application/pdf" data-rp-camera-input="upload-file" required>
        <div class="rp-camera-trigger" style="margin-top:10px">
            <button type="button" class="rp-camera-trigger-btn" data-rp-camera-open="upload-file">Scan with camera</button>
        </div>
        <p class="muted" style="margin:8px 0 0">Camera takes a photo. You can still choose a PDF or image from your files.</p>

        <div style="margin-top:18px">
            <button class="btn btn-primary" type="submit" style="width:100%">Upload</button>
        </div>
        <p class="muted" style="margin:12px 0 0">This link works until {{ $request->expires_at->timezone(config('app.timezone'))->format('d M Y, H:i') }}.</p>
    </form>
@endsection
