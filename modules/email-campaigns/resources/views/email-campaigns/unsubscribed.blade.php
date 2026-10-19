@extends('home.main')
@section('title', 'Unsubscribed')

@section('content')
<div style="min-height:60vh; display:flex; align-items:center; justify-content:center; padding:60px 16px;">
    <div style="max-width:480px; width:100%; text-align:center;">
        <div style="width:72px; height:72px; border-radius:50%; background:linear-gradient(135deg,#0d9fb5,#6366f1); display:flex; align-items:center; justify-content:center; margin:0 auto 24px; font-size:2rem; color:#fff;">✓</div>
        <h1 style="font-size:1.75rem; font-weight:800; color:#0d1b2a; margin-bottom:12px;">You've been unsubscribed</h1>
        <p style="color:#64748b; font-size:1rem; line-height:1.7; margin-bottom:32px;">
            <strong>{{ $email }}</strong> will no longer receive marketing emails from us.
            Important account emails (receipts, security and password messages) will still reach you.
        </p>
        <a href="{{ url('/') }}" style="display:inline-block; padding:12px 32px; border-radius:50px; background:linear-gradient(135deg,#1a6b8a,#0d9fb5); color:#fff; text-decoration:none; font-weight:700; font-size:0.95rem;">Back to Homepage</a>
    </div>
</div>
@endsection
