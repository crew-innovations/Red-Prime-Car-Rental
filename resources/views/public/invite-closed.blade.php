@extends('public.layout')
@section('title', 'Link expired')
@section('tag', 'Your login')

@section('content')
    <div class="card">
        <h1>This link is no longer active</h1>
        <p class="muted" style="margin:0">Login links work once and expire after a while. If you already chose a password, just log in. Otherwise please ask our team for a new link.</p>
    </div>
@endsection
