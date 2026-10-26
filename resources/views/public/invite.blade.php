@extends('public.layout')
@section('title', 'Choose your password')
@section('tag', 'Your login')

@section('content')
    <div class="card">
        <h1>Hi {{ strtok($user->name, ' ') }},</h1>
        <p class="muted" style="margin:0">Choose a password for <strong>{{ $user->email }}</strong>. You will use this email and password to log in.</p>
    </div>

    @if ($errors->any())
        <div class="alert bad">{{ $errors->first() }}</div>
    @endif

    <form class="card" method="post" action="{{ $action }}">
        @csrf
        <label for="password">New password</label>
        <input id="password" type="password" name="password" autocomplete="new-password" minlength="8" required class="field">
        <label for="password_confirmation">Type it again</label>
        <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" minlength="8" required class="field">
        <p class="muted" style="margin:10px 0 0">At least 8 characters, with letters and numbers.</p>
        <div style="margin-top:18px">
            <button class="btn btn-primary" type="submit" style="width:100%">Save and log in</button>
        </div>
    </form>
    <style>.field{width:100%;font:15px Poppins,sans-serif;padding:12px;border:1px solid var(--line);border-radius:12px;background:#fff}</style>
@endsection
