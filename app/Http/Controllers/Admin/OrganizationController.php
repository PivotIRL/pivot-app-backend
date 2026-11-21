<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminOrganizationRequest;
use App\Http\Requests\Admin\UpdateAdminOrganizationRequest;
use App\Organization\Enums\MembershipStatus;
use App\Organization\Enums\OrganizationStatus;
use App\Organization\Enums\OrganizationType;
use App\Organization\Enums\Package;
use App\Organization\Exceptions\OrganizationActionException;
use App\Organization\Models\Organization;
use App\Organization\Services\OrganizationControlService;
use App\Organization\Services\OrganizationService;
use BackedEnum;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin Dashboard management of Corporate organizations.
 * All business rules (contracts, entitlements, seats, audit) stay in the Organization services.
 * Access is limited by the manage-organizations gate on the route group.
 */
class OrganizationController extends Controller
{
    public function __construct(
        protected OrganizationService $organizations,
        protected OrganizationControlService $control,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(OrganizationStatus::class)],
        ]);

        $organizations = Organization::query()
            ->withCount(['memberships as active_members_count' => fn ($query) => $query->where('status', MembershipStatus::ACTIVE)])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(fn ($query) => $query
                    ->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(slug) LIKE ?', [$term]));
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.organizations.index', [
            'organizations' => $organizations,
            'statuses' => OrganizationStatus::cases(),
        ]);
    }

    public function create(): View
    {
        return view('admin.organizations.create', [
            'organization' => new Organization(['timezone' => 'UTC']),
            ...$this->formOptions(),
        ]);
    }

    public function store(StoreAdminOrganizationRequest $request): RedirectResponse
    {
        // No User actor: the audit log records the signed-in admin (actor_type "admin").
        $organization = $this->organizations->create($request->validated());

        return redirect()
            ->route('admin.organizations.show', $organization)
            ->with('success', "Organization \"{$organization->name}\" created successfully.");
    }

    public function show(Organization $organization): View
    {
        $organization->loadCount([
            'memberships as active_members_count' => fn ($query) => $query->where('status', MembershipStatus::ACTIVE),
            'memberships as total_members_count',
            'redemptionCodes',
            'invitations as pending_invitations_count' => fn ($query) => $query->where('status', 'pending'),
            'roleAssignments as active_role_assignments_count' => fn ($query) => $query->whereNull('revoked_at'),
        ]);
        $organization->load([
            'entitlements' => fn ($query) => $query->orderBy('feature_key'),
            'domains' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('domain'),
            'creator:id,name,email',
        ]);

        return view('admin.organizations.show', [
            'organization' => $organization,
            'contracts' => $this->organizations->contractsFor($organization),
            'auditLogs' => $organization->auditLogs()->with('actor:id,name,email')->latest('id')->limit(10)->get(),
        ]);
    }

    public function edit(Organization $organization): View
    {
        $organization->load(['entitlements' => fn ($query) => $query->orderBy('feature_key')]);

        return view('admin.organizations.edit', [
            'organization' => $organization,
            ...$this->formOptions(),
        ]);
    }

    public function update(UpdateAdminOrganizationRequest $request, Organization $organization): RedirectResponse
    {
        $changes = $this->changedFields($organization, $request->validated());

        if ($changes === []) {
            return redirect()
                ->route('admin.organizations.show', $organization)
                ->with('success', 'No changes were made.');
        }

        try {
            $organization = $this->organizations->update($organization, $changes);
        } catch (OrganizationActionException $e) {
            return back()->withInput()->withErrors(['organization' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.organizations.show', $organization)
            ->with('success', "Organization \"{$organization->name}\" updated successfully.");
    }

    public function destroy(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validateWithBag('deleteOrganization', [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirm_name' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($organization) {
                if ($value !== $organization->name) {
                    $fail('Type the organization name exactly as shown to confirm.');
                }
            }],
        ], [], [
            'reason' => 'deletion reason',
            'confirm_name' => 'organization name',
        ]);

        $name = $organization->name;
        // Soft delete, release every seat and write the audit entries, all in one transaction.
        $this->control->remove($organization, null, trim($validated['reason']));

        return redirect()
            ->route('admin.organizations.index')
            ->with('success', "Organization \"{$name}\" was deleted and its member seats were released.");
    }

    private function formOptions(): array
    {
        return [
            'types' => OrganizationType::cases(),
            'packages' => Package::cases(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ];
    }

    /**
     * Keeps only the fields whose values really differ, so saving an unchanged form
     * does not touch the contract or recalculate the organization status.
     */
    private function changedFields(Organization $organization, array $validated): array
    {
        $changes = [];

        foreach ($validated as $field => $value) {
            $current = match ($field) {
                'entitlements' => $organization->entitlements()->orderBy('feature_key')->get()
                    ->map(fn ($entitlement) => ['feature_key' => $entitlement->feature_key, 'value_json' => $entitlement->value_json])
                    ->all(),
                default => $organization->getAttribute($field),
            };

            if (! $this->sameValue($field, $current, $value)) {
                $changes[$field] = $value;
            }
        }

        return $changes;
    }

    private function sameValue(string $field, mixed $current, mixed $new): bool
    {
        if (in_array($field, ['contract_start_at', 'contract_end_at'], true)) {
            return $current !== null && $new !== null && Carbon::parse($new)->equalTo($current);
        }

        if ($field === 'entitlements') {
            $sort = fn (array $rows) => collect($rows)
                ->map(fn ($row) => ['feature_key' => $row['feature_key'], 'value_json' => $this->normalize($row['value_json'] ?? null)])
                ->sortBy('feature_key')->values()->all();

            return $sort($current) === $sort($new);
        }

        return $this->normalize($current) === $this->normalize($new);
    }

    /** Compares numbers, enums and nested arrays independent of type and key order. */
    private function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => (string) $value->value,
            is_array($value) => $this->normalizeArray($value),
            is_int($value), is_float($value) => (string) (0 + $value),
            is_string($value) && is_numeric($value) => (string) (0 + $value),
            default => $value,
        };
    }

    private function normalizeArray(array $value): array
    {
        $normalized = array_map(fn ($item) => $this->normalize($item), $value);
        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }
}
