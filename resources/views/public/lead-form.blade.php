@extends('public.layout')
@section('title', $form->name)
@section('tag', 'Enquiry')
@section('content')
    @php($input = 'width:100%;font:15px Poppins,sans-serif;padding:12px;border:1px solid var(--line);border-radius:12px;background:#fff')
    <div class="card">
        <h1>{{ $form->name }}</h1>
        @if ($form->intro)<p class="muted" style="white-space:pre-line;margin:6px 0 0">{{ $form->intro }}</p>@endif
    </div>

    @if ($sent)
        <div class="card" style="border-color:#86efac">
            <h2>Thank you</h2>
            <p class="muted" style="margin:0;white-space:pre-line">{{ $form->thank_you ?: 'We received your details. Our team will contact you shortly.' }}</p>
        </div>
    @elseif ($closed)
        <div class="card"><h2>{{ $closed }}</h2><p class="muted" style="margin:0">Please contact RED PRIME RENTALS directly.</p></div>
    @else
        <form class="card" method="post" action="{{ route('forms.store', $form->token) }}" novalidate>
            @csrf
            @if ($errors->any())<div class="alert bad">{{ $errors->first() }}</div>@endif
            <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>

            <label for="f_name">Full name *</label>
            <input id="f_name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name" style="{{ $input }}">
            <label for="f_phone">Mobile / WhatsApp *</label>
            <input id="f_phone" name="phone" value="{{ old('phone') }}" required maxlength="30" inputmode="tel" autocomplete="tel" placeholder="+971 50 123 4567" style="{{ $input }}">
            <label for="f_email">Email</label>
            <input id="f_email" name="email" type="email" value="{{ old('email') }}" maxlength="190" autocomplete="email" style="{{ $input }}">

            @foreach ($form->questions() as $q)
                @php($n = 'q['.$q['key'].']')
                @php($old = old('q.'.$q['key']))
                @if ($q['type'] === 'checkbox')
                    <label style="display:flex;gap:10px;align-items:flex-start;font-weight:400;margin-top:14px">
                        <input type="checkbox" name="{{ $n }}" value="1" @checked($old) style="margin-top:4px"> <span>{{ $q['label'] }}{{ $q['required'] ? ' *' : '' }}</span>
                    </label>
                @else
                    <label for="q_{{ $q['key'] }}">{{ $q['label'] }}{{ $q['required'] ? ' *' : '' }}</label>
                    @if ($q['type'] === 'textarea')
                        <textarea id="q_{{ $q['key'] }}" name="{{ $n }}" rows="4" maxlength="2000" style="{{ $input }}">{{ $old }}</textarea>
                    @elseif ($q['type'] === 'select')
                        <select id="q_{{ $q['key'] }}" name="{{ $n }}">
                            <option value="">Choose…</option>
                            @foreach ($q['options'] as $o)<option value="{{ $o }}" @selected($old === $o)>{{ $o }}</option>@endforeach
                        </select>
                    @else
                        <input id="q_{{ $q['key'] }}" name="{{ $n }}" value="{{ $old }}" type="{{ ['email' => 'email', 'date' => 'date', 'number' => 'number', 'phone' => 'tel'][$q['type']] ?? 'text' }}" style="{{ $input }}">
                    @endif
                @endif
            @endforeach

            <p class="muted" style="margin:16px 0 0">Your details are used only to answer this enquiry.</p>
            <button class="btn btn-primary" type="submit" style="margin-top:14px;width:100%">Send</button>
        </form>
    @endif
@endsection
