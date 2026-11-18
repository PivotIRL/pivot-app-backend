@extends('layouts.admin')
@section('title', 'Notifications | Pivot Admin')
@section('content')
<style>
.notifications-page{max-width:1120px;margin:auto}
.notifications-page .intro{margin:8px 0 28px;color:var(--text-muted);line-height:1.6}
.notification-layout{display:grid;grid-template-columns:minmax(0,2fr) minmax(240px,1fr);gap:24px;align-items:start}
.notification-panel{padding:28px;border:1px solid var(--border-color);border-radius:var(--radius-lg);background:var(--bg-surface)}
.notifications-page h2{font-size:20px;margin-bottom:8px}
.notifications-page .hint{color:var(--text-muted);font-size:13px;line-height:1.6;margin:6px 0 0}
.notifications-page textarea{resize:vertical;min-height:140px;font-family:inherit}
.notification-channels{border:0;padding:0;margin:0 0 24px;min-width:0}
.notification-channels .checkbox-card{align-items:flex-start;height:100%}
.notification-channels input{flex-shrink:0;margin-top:3px}
.notification-channels strong{display:block;font-size:14px}
.notification-channels .checkbox-card:has(input:checked){border-color:var(--primary-border);background:var(--primary-light)}
.notification-preview{background:var(--bg-main);border:1px solid var(--border-color);border-radius:14px;padding:20px;margin:20px 0;overflow-wrap:anywhere}
.notification-preview .brand{font-size:12px;letter-spacing:.08em;font-weight:700;color:var(--text-muted);margin-bottom:14px}
.notification-preview h3{font-size:16px;margin-bottom:8px}
.notification-preview p,.notification-body{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.6}
.notification-submit{border-top:1px solid var(--border-color);padding-top:20px}
.notification-history{margin-top:36px}
.notification-history article{margin-top:16px}
.notification-heading{display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
.notification-heading h3{font-size:17px;overflow-wrap:anywhere;min-width:0}
.notification-tags{display:flex;flex-wrap:wrap;gap:8px;margin:16px 0}
.notification-tag{padding:4px 10px;border-radius:20px;font-size:12px;font-weight:600;background:var(--bg-main);color:var(--text-muted)}
.notification-tag.active{background:var(--success-bg);color:var(--success)}
.notification-alert{padding:16px 20px;border-radius:10px;margin-bottom:20px;background:var(--success-bg);color:var(--success)}
.notification-alert.error{background:var(--danger-bg);color:var(--danger)}
.notification-alert ul{padding-left:20px}
.notification-empty{padding:40px 24px;text-align:center;margin-top:16px}
.notification-empty i{font-size:28px;color:var(--text-muted);margin-bottom:16px}
@media(max-width:1000px){.notification-layout{grid-template-columns:1fr}}
@media(max-width:600px){.notification-panel{padding:20px}
.notification-submit .btn{width:100%}}
</style>
<div class="notifications-page">
<h1>Send a notification</h1>
<p class="intro">Keep your members in the loop. Share announcements, helpful reminders, and updates with Android users, iOS users, or everyone.</p>
@if(session('success'))<div class="notification-alert" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="notification-alert error" role="alert"><strong>Please check your notification.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="notification-layout">
<form method="POST" action="{{ route('admin.announcements.store') }}" class="notification-panel">
@csrf
<input type="hidden" name="request_key" value="{{ old('request_key', (string) Illuminate\Support\Str::uuid()) }}">
<div class="form-group"><label class="form-label" for="notification-title">Title</label><input class="form-control" id="notification-title" name="title" required maxlength="100" value="{{ old('title') }}" placeholder="Give your message a clear headline" aria-describedby="title-hint"><p class="hint" id="title-hint">Keep it short and easy to recognise. Up to 100 characters.</p></div>
<div class="form-group"><label class="form-label" for="notification-body">Message</label><textarea class="form-control" id="notification-body" name="body" required maxlength="1000" rows="5" placeholder="What would you like your members to know?" aria-describedby="body-hint">{{ old('body') }}</textarea><p class="hint" id="body-hint">Up to 1,000 characters. A short message works best for push notifications.</p></div>
<div class="form-grid-2">
<div class="form-group"><label class="form-label" for="notification-platform">Audience</label><select class="form-control" id="notification-platform" name="platform">@foreach(['both' => 'Android & iOS', 'android' => 'Android only', 'ios' => 'iOS only'] as $value => $label)<option value="{{ $value }}" @selected(old('platform', 'both') === $value)>{{ $label }}</option>@endforeach</select></div>
<div class="form-group"><label class="form-label" for="notification-days">Available for (days)</label><input class="form-control" id="notification-days" type="number" name="days" min="1" max="90" required value="{{ old('days', 7) }}" aria-describedby="days-hint"><p class="hint" id="days-hint">Choose between 1 and 90 days.</p></div>
</div>
<fieldset class="notification-channels"><legend class="form-label">Delivery channels</legend>
<input type="hidden" name="in_app" value="0"><input type="hidden" name="push" value="0">
<div class="form-grid-2">
<label class="checkbox-card"><input type="checkbox" name="in_app" value="1" @checked(old('in_app', 1))><span><strong>In-app message</strong><span class="hint">Show when members open Pivot.</span></span></label>
<label class="checkbox-card"><input type="checkbox" name="push" value="1" @checked(old('push', 0))><span><strong>Push notification</strong><span class="hint">Reach devices with notifications enabled.</span></span></label>
</div><p class="hint">Select one or both channels.</p></fieldset>
<div class="notification-submit"><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane" aria-hidden="true"></i> Publish notification</button><p class="hint">Your message becomes available immediately. Selected pushes are queued for delivery.</p></div>
</form>
<aside class="notification-panel" aria-label="Notification preview"><h2>Message preview</h2><p class="hint">A quick look at what members will read.</p><div class="notification-preview"><div class="brand">PIVOT · NOTIFICATION</div><h3 id="preview-title">Your notification title</h3><p id="preview-body">Your message will appear here as you type.</p></div><p class="hint">Appearance may vary by device. Push notifications may shorten longer messages.</p></aside>
</div>
<section class="notification-history" aria-labelledby="history-title"><h2 id="history-title">Published notifications</h2><p class="hint">Review your messages and withdraw any that are no longer relevant.</p>
@forelse($messages as $message)
@php($expired = \Illuminate\Support\Carbon::parse($message->expires_at)->isPast())
<article class="notification-panel"><div class="notification-heading"><h3>{{ $message->title }}</h3><span class="notification-tag {{ !$message->withdrawn_at && !$expired ? 'active' : '' }}">{{ $message->withdrawn_at ? 'Withdrawn' : ($expired ? 'Expired' : 'Active') }}</span></div>
<p class="notification-body">{{ $message->body }}</p>
<div class="notification-tags"><span class="notification-tag">{{ ['android' => 'Android', 'ios' => 'iOS', 'both' => 'Android & iOS'][$message->platform] }}</span>@if($message->in_app)<span class="notification-tag">In-app</span>@endif @if($message->push)<span class="notification-tag">Push</span>@endif</div>
<p class="hint">Expires {{ $message->expires_at }} UTC</p>
@if($message->push)<p class="hint">@forelse($counts->get($message->id, collect()) as $count)<span>{{ ucfirst($count->status) }}: {{ $count->total }}{{ !$loop->last ? ' · ' : '' }}</span>@empty No registered devices matched this audience. @endforelse</p><details class="hint"><summary>About push delivery status</summary><p>Accepted means the push provider accepted the message, not that the member saw it. Processing deliveries may have been interrupted and are not automatically retried.</p></details>@endif
@if(!$message->withdrawn_at && !$expired)<form method="POST" action="{{ route('admin.announcements.withdraw', $message->id) }}" style="margin-top:16px">@csrf @method('DELETE')<button type="submit" class="btn btn-secondary btn-sm">Withdraw notification</button><p class="hint">Hides the in-app message and cancels pending pushes. Already sent pushes cannot be recalled.</p></form>@endif
</article>
@empty
<div class="notification-panel notification-empty"><i class="far fa-bell" aria-hidden="true"></i><h3>No notifications yet</h3><p class="hint">Publish your first message using the form above.</p></div>
@endforelse
{{ $messages->links() }}
</section></div>
@endsection
@push('scripts')
<script>
(() => {
    for (const [source, target, fallback] of [
        ['notification-title', 'preview-title', 'Your notification title'],
        ['notification-body', 'preview-body', 'Your message will appear here as you type.']
    ]) {
        const input = document.getElementById(source);
        const preview = document.getElementById(target);
        const update = () => { preview.textContent = input.value.trim() || fallback; };
        input.addEventListener('input', update);
        update();
    }
})();
</script>
@endpush
