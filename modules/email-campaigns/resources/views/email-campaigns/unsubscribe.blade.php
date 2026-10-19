@extends('home.main')
@section('title', 'Unsubscribe')

@section('content')
<div style="min-height:60vh; display:flex; align-items:center; justify-content:center; padding:60px 16px;">
    <div style="max-width:480px; width:100%; text-align:center;">
        <h1 style="font-size:1.75rem; font-weight:800; color:#0d1b2a; margin-bottom:12px;">Unsubscribe from emails</h1>
        @if($already)
            <p style="color:#64748b; font-size:1rem; line-height:1.7; margin-bottom:32px;">
                <strong>{{ $email }}</strong> is already unsubscribed. You won't receive any more marketing emails from us.
            </p>
        @else
            <p style="color:#64748b; font-size:1rem; line-height:1.7; margin-bottom:24px;">
                Stop sending marketing emails to <strong>{{ $email }}</strong>?
                You will still receive important account emails (receipts, security and password messages).
            </p>
            <form method="POST" action="{{ request()->fullUrl() }}">
                <button type="submit" style="padding:12px 32px; border-radius:50px; border:0; background:linear-gradient(135deg,#1a6b8a,#0d9fb5); color:#fff; font-weight:700; font-size:0.95rem; cursor:pointer;">
                    Yes, unsubscribe me
                </button>
            </form>
        @endif
        <p style="margin-top:28px;"><a href="{{ url('/') }}" style="color:#64748b;">Back to homepage</a></p>
    </div>
</div>
@endsection
