@extends('layouts.admin')

@section('content')
<ul class="breadcrumb">
    <li><a href="{{ route('admin.dashboard') }}">Admin</a></li>
    <li><i class="fa-solid fa-chevron-right" style="font-size: 0.65rem;"></i></li>
    <li style="color: var(--text-heading); font-weight: 600;">Event Management</li>
</ul>

@if(session('success'))
<div style="background: var(--success-bg); color: var(--success); padding: 0.875rem 1.25rem; border-radius: var(--radius-md); margin-bottom: 1.25rem; border: 1px solid var(--success);">
    {{ session('success') }}
</div>
@endif

@if($errors->any())
<div style="background: var(--danger-bg); color: var(--danger); padding: 0.875rem 1.25rem; border-radius: var(--radius-md); margin-bottom: 1.25rem; border: 1px solid var(--danger);">
    <ul style="margin: 0; padding-left: 1.25rem;">
        @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
        @endforeach
    </ul>
</div>
@endif

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
        <h3 style="font-size: 1.125rem; font-weight: 700;">Events List</h3>
        <button onclick="openModal('create-event-modal')" class="btn btn-primary">
            <i class="fa-solid fa-plus"></i> Add Event
        </button>
    </div>

    <!-- Filter Form -->
    <form method="GET" action="{{ route('admin.events.index') }}" style="display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; align-items: center;">
        <div style="position: relative; flex: 1; max-width: 320px;">
            <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 0.875rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none;"></i>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search event title..." class="form-control" style="padding-left: 2.375rem;" oninput="debouncedSubmit(this, 450)">
        </div>
        @if(request('search'))
            <a href="{{ route('admin.events.index') }}" class="btn btn-secondary btn-sm" title="Clear Search" style="height: 38px; padding: 0 0.875rem; display: inline-flex; align-items: center;">
                <i class="fa-solid fa-xmark"></i> Clear
            </a>
        @endif
    </form>

    <!-- Data Table -->
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Image</th>
                    <th>Event Details</th>
                    <th>Date & Location</th>
                    <th>Status</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($events as $event)
                <tr>
                    <td>
                        @if($event->image)
                            <img src="{{ Storage::disk('public')->url($event->image) }}" alt="{{ $event->title }}" style="width: 60px; height: 60px; object-fit: cover; border-radius: 8px;">
                        @else
                            <div style="width: 60px; height: 60px; border-radius: 8px; background: var(--bg-main); display: flex; align-items: center; justify-content: center; color: var(--text-muted);">
                                <i class="fa-solid fa-calendar-days fa-2x"></i>
                            </div>
                        @endif
                    </td>
                    <td>
                        <div style="font-weight: 600; color: var(--text-heading);">{{ $event->title }}</div>
                        <div style="font-size: 0.75rem; color: var(--text-muted);">{{ Str::limit($event->description, 40) ?? 'No description' }}</div>
                        @if($event->guests && count($event->guests) > 0)
                            <div style="font-size: 0.75rem; color: var(--primary); margin-top: 4px;"><i class="fa-solid fa-users"></i> {{ count($event->guests) }} Guests</div>
                        @endif
                    </td>
                    <td>
                        <div style="font-size: 0.875rem; color: var(--text-heading);"><i class="fa-regular fa-clock"></i> {{ $event->date_and_time->format('M d, Y H:i') }}</div>
                        <div style="font-size: 0.75rem; color: var(--text-muted);"><i class="fa-solid fa-location-dot"></i> {{ $event->location }}</div>
                    </td>
                    <td>
                        @if($event->is_active)
                            <span class="badge badge-success">Active</span>
                        @else
                            <span class="badge badge-secondary">Inactive</span>
                        @endif
                    </td>
                    <td style="text-align: right;">
                        <div class="action-btn-group">
                            <button type="button" onclick='editEvent(@json($event))' class="btn btn-secondary btn-sm" title="Edit">
                                <i class="fa-solid fa-pen"></i>
                            </button>
                            <button type="button" onclick="confirmDelete('{{ route('admin.events.destroy', $event->id) }}')" class="btn btn-danger btn-sm" title="Delete">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" style="text-align: center; padding: 2rem; color: var(--text-muted);">No events found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top: 1.25rem;">
        {{ $events->links('partials.pagination') ?? '' }}
    </div>
</div>

<!-- CREATE EVENT MODAL -->
<div class="modal-backdrop" id="create-event-modal">
    <div class="modal-dialog" style="max-width: 600px;">
        <div class="modal-header">
            <h3>Add New Event</h3>
            <button type="button" class="modal-close-btn" onclick="closeModal('create-event-modal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form action="{{ route('admin.events.store') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Title *</label>
                    <input type="text" name="title" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Description *</label>
                    <textarea name="description" class="form-control" rows="3" required></textarea>
                </div>
                <div style="display: flex; gap: 1rem;">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Date and Time *</label>
                        <input type="datetime-local" name="date_and_time" class="form-control" required>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Location *</label>
                        <input type="text" name="location" class="form-control" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Image (Optional)</label>
                    <input type="file" name="image" class="form-control" accept="image/*">
                    <small class="text-muted">Maximum image size: 10 MB.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Guests (Comma separated, Optional)</label>
                    <input type="text" name="guests" class="form-control" placeholder="John Doe, Jane Smith">
                </div>
                <div class="form-group">
                    <label class="form-label">Redirect Link (Optional)</label>
                    <input type="url" name="redirect_link" class="form-control" placeholder="https://example.com/attend">
                </div>
                <div class="form-group" style="display: flex; align-items: center; gap: 0.5rem; margin-top: 1rem;">
                    <input type="checkbox" name="is_active" id="create-is-active" value="1" checked>
                    <label for="create-is-active" style="margin: 0;">Is Active</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('create-event-modal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Create Event</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT EVENT MODAL -->
<div class="modal-backdrop" id="edit-event-modal">
    <div class="modal-dialog" style="max-width: 600px;">
        <div class="modal-header">
            <h3>Edit Event</h3>
            <button type="button" class="modal-close-btn" onclick="closeModal('edit-event-modal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="edit-event-form" method="POST" enctype="multipart/form-data">
            @csrf
            @method('PUT')
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Title *</label>
                    <input type="text" name="title" id="edit-title" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Description *</label>
                    <textarea name="description" id="edit-description" class="form-control" rows="3" required></textarea>
                </div>
                <div style="display: flex; gap: 1rem;">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Date and Time *</label>
                        <input type="datetime-local" name="date_and_time" id="edit-date-and-time" class="form-control" required>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Location *</label>
                        <input type="text" name="location" id="edit-location" class="form-control" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Image (Optional - Leave blank to keep current)</label>
                    <input type="file" name="image" class="form-control" accept="image/*">
                    <small class="text-muted">Maximum image size: 10 MB.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Guests (Comma separated, Optional)</label>
                    <input type="text" name="guests" id="edit-guests" class="form-control" placeholder="John Doe, Jane Smith">
                </div>
                <div class="form-group">
                    <label class="form-label">Redirect Link (Optional)</label>
                    <input type="url" name="redirect_link" id="edit-redirect-link" class="form-control" placeholder="https://example.com/attend">
                </div>
                <div class="form-group" style="display: flex; align-items: center; gap: 0.5rem; margin-top: 1rem;">
                    <input type="checkbox" name="is_active" id="edit-is-active" value="1">
                    <label for="edit-is-active" style="margin: 0;">Is Active</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('edit-event-modal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Update Event</button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE MODAL -->
<div class="modal-backdrop" id="delete-modal">
    <div class="modal-dialog" style="max-width: 460px;">
        <div class="modal-header">
            <h3>Confirm Delete</h3>
            <button type="button" class="modal-close-btn" onclick="closeModal('delete-modal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="delete-form" method="POST">
            @csrf
            @method('DELETE')
            <div class="modal-body">
                <p style="font-size: 0.9375rem; color: var(--text-body); margin: 0;">Are you sure you want to delete this event? This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('delete-modal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="fa-solid fa-trash"></i> Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
    function editEvent(event) {
        const updateUrl = "{{ route('admin.events.update', ':id') }}".replace(':id', event.id);
        document.getElementById('edit-event-form').action = updateUrl;

        document.getElementById('edit-title').value = event.title || '';
        document.getElementById('edit-description').value = event.description || '';
        
        // Format datetime for datetime-local input (YYYY-MM-DDTHH:mm)
        if(event.date_and_time) {
            const dt = new Date(event.date_and_time);
            dt.setMinutes(dt.getMinutes() - dt.getTimezoneOffset());
            document.getElementById('edit-date-and-time').value = dt.toISOString().slice(0,16);
        }

        document.getElementById('edit-location').value = event.location || '';
        
        let guestsStr = '';
        if(event.guests && Array.isArray(event.guests)) {
            guestsStr = event.guests.join(', ');
        }
        document.getElementById('edit-guests').value = guestsStr;
        
        document.getElementById('edit-redirect-link').value = event.redirect_link || '';
        
        document.getElementById('edit-is-active').checked = !!event.is_active;

        openModal('edit-event-modal');
    }

    function confirmDelete(actionUrl) {
        document.getElementById('delete-form').action = actionUrl;
        openModal('delete-modal');
    }
</script>
@endsection
