@extends('layouts.admin')
@section('title', 'Notifications | Pivot Admin')
@section('content')
<h1>Send a notification</h1>
<p>Share a message with Android users, iOS users, or both. In-app messages appear when members open Pivot. Push requires a registered device and notification permission.</p>
@if(session('success'))<p role="status">{{ session('success') }}</p>@endif
@if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
<form method="POST" action="{{ route('admin.announcements.store') }}" style="max-width:760px; padding:24px; background:white; border-radius:16px">
    @csrf
    <input type="hidden" name="request_key" value="{{ old('request_key', (string) Illuminate\Support\Str::uuid()) }}">
    <p><label>Title<br><input name="title" required maxlength="100" value="{{ old('title') }}" style="width:100%"></label></p>
    <p><label>Message<br><textarea name="body" required maxlength="1000" rows="5" style="width:100%">{{ old('body') }}</textarea></label></p>
    <p><label>Audience <select name="platform">@foreach(['android' => 'Android', 'ios' => 'iOS', 'both' => 'Both'] as $value => $label)<option value="{{ $value }}" @selected(old('platform', 'both') === $value)>{{ $label }}</option>@endforeach</select></label></p>
    <p><input type="hidden" name="in_app" value="0"><label><input type="checkbox" name="in_app" value="1" @checked(old('in_app', 1))> In-app message</label></p>
    <p><input type="hidden" name="push" value="0"><label><input type="checkbox" name="push" value="1" @checked(old('push', 0))> Push notification</label></p>
    <p><label>Available for <input type="number" name="days" min="1" max="90" required value="{{ old('days', 7) }}"> days</label></p>
    <button type="submit" class="btn btn-primary">Publish and queue delivery</button>
</form>
<h2>Published notifications</h2>
<p>Accepted means Firebase accepted the push, not that the user saw it. Processing may mean an interrupted send; it is not automatically retried to avoid duplicate pushes.</p>
@foreach($messages as $message)
<article style="background:white; padding:20px; margin:16px 0; border-radius:12px">
    <h3>{{ $message->title }}</h3><p style="white-space:pre-wrap">{{ $message->body }}</p>
    <p>{{ ucfirst($message->platform) }} · {{ $message->in_app ? 'In-app' : '' }} {{ $message->push ? 'Push' : '' }} · Expires {{ $message->expires_at }} UTC</p>
    <p>@forelse($counts->get($message->id, collect()) as $count) {{ ucfirst($count->status) }}: {{ $count->total }} @empty No push recipients. @endforelse</p>
    @if($message->withdrawn_at)<p>Withdrawn</p>@else
    <form method="POST" action="{{ route('admin.announcements.withdraw', $message->id) }}">@csrf @method('DELETE')<button type="submit">Withdraw message</button></form>
    @endif
</article>
@endforeach
{{ $messages->links() }}
@endsection
