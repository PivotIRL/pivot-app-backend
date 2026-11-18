@extends('layouts.admin')

@section('content')
<style>
    .form-error { display: block; margin-top: 0.25rem; color: #dc2626; font-size: 0.8rem; }
</style>

<ul class="breadcrumb">
    <li><a href="{{ route('admin.dashboard') }}">Admin</a></li>
    <li><i class="fa-solid fa-chevron-right" style="font-size: 0.65rem;"></i></li>
    <li style="color: var(--text-heading); font-weight: 600;">Activities Management</li>
</ul>

@if(session('success'))
<div style="background: var(--success-bg); color: var(--success); padding: 0.875rem 1.25rem; border-radius: var(--radius-md); margin-bottom: 1.25rem; border: 1px solid var(--success);">
    {{ session('success') }}
</div>
@endif

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
        <h3 style="font-size: 1.125rem; font-weight: 700;">Activities List</h3>
        <div style="display: flex; gap: 0.75rem;">
            <button onclick="openModal('create-activity-modal')" class="btn btn-primary">
                <i class="fa-solid fa-plus"></i> Add Activity
            </button>
        </div>
    </div>

    <!-- Filter Form -->
    <form method="GET" action="{{ route('admin.activities.index') }}" style="display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; align-items: center;">
        <div style="position: relative; flex: 1; max-width: 320px;">
            <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 0.875rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none;"></i>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search title, description..." class="form-control" style="padding-left: 2.375rem;" oninput="debouncedSubmit(this, 450)">
        </div>
        <input type="text" name="tier" value="{{ request('tier') }}" placeholder="Tier (e.g. Free, Premium)" class="form-control" style="max-width: 180px;" oninput="debouncedSubmit(this, 450)">
        @if(request('search') || request('tier'))
            <a href="{{ route('admin.activities.index') }}" class="btn btn-secondary btn-sm" title="Clear Filters" style="height: 38px; padding: 0 0.875rem; display: inline-flex; align-items: center;">
                <i class="fa-solid fa-xmark"></i> Clear
            </a>
        @endif
    </form>

    <!-- Data Table -->
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Type</th>
                    <th>Duration</th>
                    <th>Tier</th>
                    <th>Energy Level</th>
                    <th>Neurodivergent Friendly</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($activities as $activity)
                <tr>
                    <td>
                        <div style="font-weight: 600; color: var(--text-heading);">{{ $activity->activity_title }}</div>
                        <div style="font-size: 0.75rem; color: var(--text-muted);">{{ $activity->subcategory ?? 'General' }}</div>
                    </td>
                    <td>{{ $activity->activity_type ?? 'N/A' }}</td>
                    <td>{{ $activity->duration_minutes ? $activity->duration_minutes . ' mins' : 'N/A' }}</td>
                    <td><span class="badge badge-info">{{ $activity->tier ?? 'Standard' }}</span></td>
                    <td>{{ $activity->energy_level ?? 'N/A' }}</td>
                    <td>
                        <span class="badge {{ ($activity->neurodivergent_friendly === 'Yes' || $activity->neurodivergent_friendly == 1) ? 'badge-success' : 'badge-warning' }}">
                            {{ ($activity->neurodivergent_friendly === 'Yes' || $activity->neurodivergent_friendly == 1) ? 'Yes' : 'No' }}
                        </span>
                    </td>
                    <td style="text-align: right;">
                        <div class="action-btn-group">
                            <a href="{{ route('admin.activities.show', $activity->id) }}" class="btn btn-secondary btn-sm" title="View Details">
                                <i class="fa-solid fa-eye"></i>
                            </a>
                            <button type="button" onclick="editActivity({{ json_encode($activity) }})" class="btn btn-secondary btn-sm" title="Edit">
                                <i class="fa-solid fa-pen"></i>
                            </button>
                            <button type="button" onclick="confirmDelete('{{ route('admin.activities.destroy', $activity->id) }}')" class="btn btn-danger btn-sm" title="Delete">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" style="text-align: center; padding: 2rem; color: var(--text-muted);">No activities found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top: 1.25rem;">
        {{ $activities->links('partials.pagination') }}
    </div>
</div>

<!-- CREATE ACTIVITY MODAL -->
<div class="modal-backdrop" id="create-activity-modal">
    <div class="modal-dialog" style="max-width: 680px;">
        <div class="modal-header">
            <h3>Add New Activity</h3>
            <button type="button" onclick="closeModal('create-activity-modal')" style="background: none; border: none; cursor: pointer;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form action="{{ route('admin.activities.store') }}" method="POST">
            @csrf
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <div class="form-grid-2">
                    <div class="form-group col-span-2">
                        <label class="form-label">Category *</label>
                        <select name="hobby_id" class="form-control" required>
                            <option value="">Select a category</option>
                            @foreach($hobbies as $hobby)
                            <option value="{{ $hobby->id }}" @selected((string) old('hobby_id') === (string) $hobby->id)>{{ $hobby->name }}</option>
                            @endforeach
                        </select>
                        @error('hobby_id') <small class="form-error">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group col-span-2">
                        <label class="form-label">Activity Title *</label>
                        <input type="text" name="activity_title" class="form-control" value="{{ old('activity_title') }}" required>
                        @error('activity_title') <small class="form-error">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label">Activity Type</label>
                        <select name="activity_type" class="form-control"> <option value="">None</option><option value="Time-Bound" @selected(old('activity_type', 'Time-Bound') === 'Time-Bound')>Time-Bound</option><option value="Micro" @selected(old('activity_type', 'Time-Bound') === 'Micro')>Micro</option></select>
                        @error('activity_type') <small class="form-error">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Subcategory</label>
                        <input type="text" name="subcategory" class="form-control" value="{{ old('subcategory') }}">
                        @error('subcategory') <small class="form-error">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label">Duration (Minutes) *</label>
                        <input type="number" name="duration_minutes" class="form-control" value="{{ old('duration_minutes') }}" min="1" step="1" required>
                        @error('duration_minutes') <small class="form-error">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tier *</label>
                        <select name="tier" class="form-control" required>
                            <option value="">Select a tier</option>
                            <option value="1" @selected(old('tier') === '1')>Tier 1</option>
                            <option value="2" @selected(old('tier') === '2')>Tier 2</option>
                            <option value="3" @selected(old('tier') === '3')>Tier 3</option>
                        </select>
                        @error('tier') <small class="form-error">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group col-span-2">
                        <label class="form-label">Cost *</label>
                        <input type="text" name="cost" class="form-control" value="{{ old('cost', 'Free') }}" placeholder="e.g. Free, $10" required>
                        @error('cost') <small class="form-error">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Location *</label>
                        <select name="location" class="form-control" required><option value="Indoor" @selected(old('location', 'Indoor') === 'Indoor')>Indoor</option><option value="Outdoor" @selected(old('location', 'Indoor') === 'Outdoor')>Outdoor</option><option value="Indoor/Outdoor" @selected(old('location', 'Indoor') === 'Indoor/Outdoor')>Indoor/Outdoor</option></select>
                        @error('location') <small class="form-error">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Time</label>
                        <select name="time" id="create-time" class="form-control">
                            <option value="">Select time</option>
                            <option value="daytime" @selected(old('time') === 'daytime')>Daytime</option>
                            <option value="evening" @selected(old('time') === 'evening')>Evening</option>
                        </select>
                        @error('time') <small class="form-error">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group col-span-2">
                        <label class="form-label">Mood Match *</label>
                        @include('admin.activities.tag-input', ['field' => 'mood_match', 'inputId' => 'create-mood'])
                        @error('mood_match') <small class="form-error">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label">Energy Level *</label>
                        <select name="energy_level" class="form-control" required>
                            <option value="">Select an energy level</option>
                            <option value="Low" @selected(old('energy_level') === 'Low')>Low</option>
                            <option value="Medium" @selected(old('energy_level') === 'Medium')>Medium</option>
                            <option value="High" @selected(old('energy_level') === 'High')>High</option>
                        </select>
                        @error('energy_level') <small class="form-error">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Social Type *</label>
                        <select name="social_type" class="form-control" required><option value="Solo" @selected(old('social_type', 'Solo') === 'Solo')>Solo</option><option value="Group" @selected(old('social_type', 'Solo') === 'Group')>Group</option><option value="Solo/Group" @selected(old('social_type', 'Solo') === 'Solo/Group')>Solo/Group</option></select>
                        @error('social_type') <small class="form-error">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label">Min Age *</label>
                        <input type="number" name="min_age" class="form-control" value="{{ old('min_age', 10) }}" min="0" max="120" step="1" required>
                        @error('min_age') <small class="form-error">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Max Age *</label>
                        <input type="number" name="max_age" class="form-control" value="{{ old('max_age', 90) }}" min="0" max="120" step="1" required>
                        @error('max_age') <small class="form-error">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label">Sensory Tags</label>
                        @include('admin.activities.tag-input', ['field' => 'sensory_tags', 'inputId' => 'create-sensory'])
                        @error('sensory_tags') <small class="form-error">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Outcome Tag</label>
                        @include('admin.activities.tag-input', ['field' => 'outcome_tag', 'inputId' => 'create-outcome'])
                        @error('outcome_tag') <small class="form-error">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group col-span-2">
                        <label class="form-label">Description *</label>
                        <textarea name="description" class="form-control" rows="2" required>{{ old('description') }}</textarea>
                        @error('description') <small class="form-error">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group col-span-2">
                        <label class="form-label">Instructions *</label>
                        <textarea name="instruction" class="form-control" rows="2" required>{{ old('instruction') }}</textarea>
                        @error('instruction') <small class="form-error">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group col-span-2">
                        <label class="form-label">Neurodivergent Notes</label>
                        <textarea name="neurodivergent_notes" class="form-control" rows="2">{{ old('neurodivergent_notes') }}</textarea>
                        @error('neurodivergent_notes') <small class="form-error">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group col-span-2">
                        <label class="form-label">Neurodivergent Friendly *</label>
                        <select name="neurodivergent_friendly" class="form-control" required>
                            <option value="">Select an option</option>
                            <option value="Yes" @selected(old('neurodivergent_friendly') === 'Yes')>Yes</option>
                            <option value="No" @selected(old('neurodivergent_friendly') === 'No')>No</option>
                            <option value="Partial" @selected(old('neurodivergent_friendly') === 'Partial')>Partial</option>
                        </select>
                        @error('neurodivergent_friendly') <small class="form-error">{{ $message }}</small> @enderror
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('create-activity-modal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Activity</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT ACTIVITY MODAL -->
<div class="modal-backdrop" id="edit-activity-modal">
    <div class="modal-dialog" style="max-width: 680px;">
        <div class="modal-header">
            <h3>Edit Activity</h3>
            <button type="button" onclick="closeModal('edit-activity-modal')" style="background: none; border: none; cursor: pointer;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="edit-activity-form" method="POST" action="{{ old('_method') === 'PUT' && old('_activity_id') ? route('admin.activities.update', old('_activity_id')) : '' }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="_activity_id" id="edit-activity-id" value="{{ old('_activity_id') }}">
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <div class="form-grid-2">
                    <div class="form-group col-span-2">
                        <label class="form-label">Activity Title *</label>
                        <input type="text" name="activity_title" id="edit-title" class="form-control" value="{{ old('activity_title') }}" required>
                        @error('activity_title') <small class="form-error">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group col-span-2">
                        <label class="form-label">Category *</label>
                        <select name="hobby_id" id="edit-hobby" class="form-control" required>
                            <option value="">Select a category</option>
                            @foreach($hobbies as $hobby)
                            <option value="{{ $hobby->id }}" @selected((string) old('hobby_id') === (string) $hobby->id)>{{ $hobby->name }}</option>
                            @endforeach
                        </select>
                        @error('hobby_id') <small class="form-error">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label">Activity Type</label>
                        <select name="activity_type" id="edit-type" class="form-control"> <option value="">None</option><option value="Time-Bound" @selected(old('activity_type', 'Time-Bound') === 'Time-Bound')>Time-Bound</option><option value="Micro" @selected(old('activity_type', 'Time-Bound') === 'Micro')>Micro</option></select>
                        @error('activity_type') <small class="form-error">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Subcategory</label>
                        <input type="text" name="subcategory" id="edit-subcategory" class="form-control" value="{{ old('subcategory') }}">
                        @error('subcategory') <small class="form-error">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label">Duration (Minutes) *</label>
                        <input type="number" name="duration_minutes" id="edit-duration" class="form-control" value="{{ old('duration_minutes') }}" min="1" step="1" required>
                        @error('duration_minutes') <small class="form-error">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tier *</label>
                        <select name="tier" id="edit-tier" class="form-control" required>
                            <option value="">Select a tier</option>
                            <option value="1" @selected(old('tier') === '1')>Tier 1</option>
                            <option value="2" @selected(old('tier') === '2')>Tier 2</option>
                            <option value="3" @selected(old('tier') === '3')>Tier 3</option>
                        </select>
                        @error('tier') <small class="form-error">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group col-span-2">
                        <label class="form-label">Cost *</label>
                        <input type="text" name="cost" id="edit-cost" class="form-control" value="{{ old('cost', 'Free') }}" required>
                        @error('cost') <small class="form-error">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Location *</label>
                        <select name="location" id="edit-location" class="form-control" required><option value="Indoor" @selected(old('location', 'Indoor') === 'Indoor')>Indoor</option><option value="Outdoor" @selected(old('location', 'Indoor') === 'Outdoor')>Outdoor</option><option value="Indoor/Outdoor" @selected(old('location', 'Indoor') === 'Indoor/Outdoor')>Indoor/Outdoor</option></select>
                        @error('location') <small class="form-error">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Time</label>
                        <select name="time" id="edit-time" class="form-control">
                            <option value="">Select time</option>
                            <option value="daytime" @selected(old('time') === 'daytime')>Daytime</option>
                            <option value="evening" @selected(old('time') === 'evening')>Evening</option>
                        </select>
                        @error('time') <small class="form-error">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group col-span-2">
                        <label class="form-label">Mood Match</label>
                        @include('admin.activities.tag-input', ['field' => 'mood_match', 'inputId' => 'edit-mood'])
                        @error('mood_match') <small class="form-error">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label">Energy Level *</label>
                        <select name="energy_level" id="edit-energy" class="form-control" required>
                            <option value="">Select an energy level</option>
                            <option value="Low" @selected(old('energy_level') === 'Low')>Low</option>
                            <option value="Medium" @selected(old('energy_level') === 'Medium')>Medium</option>
                            <option value="High" @selected(old('energy_level') === 'High')>High</option>
                        </select>
                        @error('energy_level') <small class="form-error">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Social Type *</label>
                        <select name="social_type" id="edit-social" class="form-control" required><option value="Solo" @selected(old('social_type', 'Solo') === 'Solo')>Solo</option><option value="Group" @selected(old('social_type', 'Solo') === 'Group')>Group</option><option value="Solo/Group" @selected(old('social_type', 'Solo') === 'Solo/Group')>Solo/Group</option></select>
                        @error('social_type') <small class="form-error">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label">Min Age *</label>
                        <input type="number" name="min_age" id="edit-min-age" class="form-control" value="{{ old('min_age', 10) }}" min="0" max="120" step="1" required>
                        @error('min_age') <small class="form-error">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Max Age *</label>
                        <input type="number" name="max_age" id="edit-max-age" class="form-control" value="{{ old('max_age', 90) }}" min="0" max="120" step="1" required>
                        @error('max_age') <small class="form-error">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label">Sensory Tags</label>
                        @include('admin.activities.tag-input', ['field' => 'sensory_tags', 'inputId' => 'edit-sensory'])
                        @error('sensory_tags') <small class="form-error">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Outcome Tag</label>
                        @include('admin.activities.tag-input', ['field' => 'outcome_tag', 'inputId' => 'edit-outcome'])
                        @error('outcome_tag') <small class="form-error">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group col-span-2">
                        <label class="form-label">Description *</label>
                        <textarea name="description" id="edit-description" class="form-control" rows="2" required>{{ old('description') }}</textarea>
                        @error('description') <small class="form-error">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group col-span-2">
                        <label class="form-label">Instructions *</label>
                        <textarea name="instruction" id="edit-instruction" class="form-control" rows="2" required>{{ old('instruction') }}</textarea>
                        @error('instruction') <small class="form-error">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group col-span-2">
                        <label class="form-label">Neurodivergent Notes</label>
                        <textarea name="neurodivergent_notes" id="edit-nd-notes" class="form-control" rows="2">{{ old('neurodivergent_notes') }}</textarea>
                        @error('neurodivergent_notes') <small class="form-error">{{ $message }}</small> @enderror
                    </div>

                    <div class="form-group col-span-2">
                        <label class="form-label">Neurodivergent Friendly *</label>
                        <select name="neurodivergent_friendly" id="edit-nd" class="form-control" required>
                            <option value="">Select an option</option>
                            <option value="Yes" @selected(old('neurodivergent_friendly') === 'Yes')>Yes</option>
                            <option value="No" @selected(old('neurodivergent_friendly') === 'No')>No</option>
                            <option value="Partial" @selected(old('neurodivergent_friendly') === 'Partial')>Partial</option>
                        </select>
                        @error('neurodivergent_friendly') <small class="form-error">{{ $message }}</small> @enderror
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('edit-activity-modal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Activity</button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE MODAL -->
<div class="modal-backdrop" id="delete-modal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3>Confirm Delete</h3>
            <button type="button" onclick="closeModal('delete-modal')" style="background: none; border: none; cursor: pointer;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="delete-form" method="POST">
            @csrf
            @method('DELETE')
            <div class="modal-body">
                <p>Are you sure you want to delete this activity?</p>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('delete-modal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete</button>
            </div>
        </form>
    </div>
</div>

@include('admin.activities.tag-script')
<script>
    @if ($errors->any())
        window.addEventListener('DOMContentLoaded', () => {
            openModal(@json(old('_method') === 'PUT' ? 'edit-activity-modal' : 'create-activity-modal'));
        });
    @endif

    function editActivity(activity) {
        const updateUrl = "{{ route('admin.activities.update', ':id') }}".replace(':id', activity.id);
        document.getElementById('edit-activity-form').action = updateUrl;
        document.getElementById('edit-activity-id').value = activity.id;

        document.getElementById('edit-title').value = activity.activity_title ?? '';
        document.getElementById('edit-hobby').value = activity.hobby_id ?? ''; // Populates category select
        setActivitySelectOrInput('edit-type', activity.activity_type ?? 'Time-Bound');
        document.getElementById('edit-subcategory').value = activity.subcategory ?? '';
        document.getElementById('edit-duration').value = activity.duration_minutes ?? '';
        document.getElementById('edit-tier').value = activity.tier ?? '';
        setActivitySelectOrInput('edit-cost', activity.cost ?? 'Free');
        setActivitySelectOrInput('edit-location', activity.location ?? 'Indoor');
        document.getElementById('edit-energy').value = activity.energy_level ?? '';
        setActivitySelectOrInput('edit-social', activity.social_type ?? 'Solo');
        setActivitySelectOrInput('edit-min-age', activity.min_age ?? 10);
        setActivitySelectOrInput('edit-max-age', activity.max_age ?? 90);
        setActivityTags('edit-sensory', activity.sensory_tags);
        setActivityTags('edit-outcome', activity.outcome_tag);
        document.getElementById('edit-description').value = activity.description ?? '';
        document.getElementById('edit-instruction').value = activity.instruction ?? '';
        document.getElementById('edit-nd-notes').value = activity.neurodivergent_notes ?? '';

        document.getElementById('edit-nd').value = activity.neurodivergent_friendly ?? '';

        setActivitySelectOrInput('edit-time', activity.time ?? '');
        setActivityTags('edit-mood', activity.mood_match);
        openModal('edit-activity-modal');
    }

    function confirmDelete(actionUrl) {
        document.getElementById('delete-form').action = actionUrl;
        openModal('delete-modal');
    }
</script>
@endsection
