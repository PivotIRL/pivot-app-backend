{{-- Delete confirmation: requires a reason and the exact organization name. Filled by openDeleteOrganization(). --}}
@php
    $deleteErrors = $errors->getBag('deleteOrganization');
    // Only reopen with a URL that points at an organization of this dashboard.
    $deleteAction = str_starts_with((string) old('delete_action'), route('admin.organizations.index').'/') ? old('delete_action') : '';
@endphp
<div class="modal-backdrop {{ $deleteErrors->any() ? 'active' : '' }}" id="delete-organization-modal">
    <div class="modal-dialog" style="max-width: 520px;">
        <div class="modal-header">
            <h3>Delete Organization</h3>
            <button type="button" class="modal-close-btn" onclick="closeModal('delete-organization-modal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="delete-organization-form" method="POST" action="{{ $deleteAction }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="delete_action" id="delete-organization-action" value="{{ $deleteAction }}">
            <input type="hidden" name="delete_name" id="delete-organization-name-input" value="{{ old('delete_name') }}">
            <input type="hidden" name="delete_members" id="delete-organization-members-input" value="{{ old('delete_members') }}">
            <div class="modal-body">
                <div class="org-alert org-alert-danger" style="margin-bottom: 1rem;">
                    <strong><i class="fa-solid fa-triangle-exclamation"></i> This will remove <span id="delete-organization-name-label">{{ old('delete_name') }}</span>.</strong>
                    <ul style="margin-top: .5rem;">
                        <li>All members may lose access to their corporate benefits.</li>
                        <li>Every member seat will be released (<span id="delete-organization-members-label">{{ old('delete_members', 0) }}</span> active member(s)).</li>
                        <li>The organization is hidden everywhere; its audit history is kept.</li>
                    </ul>
                </div>
                @if($deleteErrors->any())
                <div class="org-alert org-alert-danger">
                    <ul>
                        @foreach($deleteErrors->all() as $error)
                        <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
                @endif
                <div class="form-group">
                    <label class="form-label" for="delete-organization-reason">Deletion reason *</label>
                    <textarea id="delete-organization-reason" name="reason" class="form-control" rows="3" minlength="3" maxlength="500" required>{{ $deleteErrors->any() ? old('reason') : '' }}</textarea>
                </div>
                <div class="form-group">
                    <label class="form-label" for="delete-organization-confirm">Type <strong id="delete-organization-name-hint">{{ old('delete_name') }}</strong> to confirm *</label>
                    <input type="text" id="delete-organization-confirm" name="confirm_name" class="form-control" autocomplete="off" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('delete-organization-modal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" id="delete-organization-submit" class="btn btn-danger" disabled><i class="fa-solid fa-trash"></i> Delete Organization</button>
            </div>
        </form>
    </div>
</div>

<script>
    (function() {
        const confirmInput = document.getElementById('delete-organization-confirm');
        const submitButton = document.getElementById('delete-organization-submit');
        const reasonInput = document.getElementById('delete-organization-reason');

        function refreshSubmitState() {
            const expected = document.getElementById('delete-organization-name-input').value;
            submitButton.disabled = !(expected !== '' && confirmInput.value === expected && reasonInput.value.trim().length >= 3);
        }

        confirmInput.addEventListener('input', refreshSubmitState);
        reasonInput.addEventListener('input', refreshSubmitState);

        window.openDeleteOrganization = function(button) {
            const {
                action,
                name,
                members
            } = button.dataset;
            document.getElementById('delete-organization-form').action = action;
            document.getElementById('delete-organization-action').value = action;
            document.getElementById('delete-organization-name-input').value = name;
            document.getElementById('delete-organization-members-input').value = members;
            document.getElementById('delete-organization-name-label').textContent = name;
            document.getElementById('delete-organization-name-hint').textContent = name;
            document.getElementById('delete-organization-members-label').textContent = members;
            confirmInput.value = '';
            reasonInput.value = '';
            refreshSubmitState();
            openModal('delete-organization-modal');
        };
    })();
</script>
