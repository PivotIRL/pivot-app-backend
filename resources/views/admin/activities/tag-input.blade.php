<div class="activity-tags" data-field="{{ $field }}" data-input="{{ $inputId }}">
    <input type="hidden" name="{{ $field }}" id="{{ $inputId }}" value="{{ json_encode(old($field, [])) }}">
    <div class="tag-selected" style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:8px;"></div>
    <div style="display:flex; gap:8px;">
        <select class="form-control tag-options" aria-label="Select {{ str_replace('_', ' ', $field) }}"></select>
        @if($field !== 'mood_match')
        <input type="text" class="form-control tag-custom" placeholder="Type tag + Enter" aria-label="New {{ str_replace('_', ' ', $field) }} tag" maxlength="100">
        @endif
    </div>
    <small style="color:var(--text-muted)">Select multiple tags. Click a selected tag to remove it.</small>
</div>
