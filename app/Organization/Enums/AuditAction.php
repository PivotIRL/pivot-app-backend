<?php

namespace App\Organization\Enums;

/**
 * Actions written to organization_audit_logs. Later phases add their own cases here.
 */
enum AuditAction: string
{
    case PIVOT_ROLE_GRANTED = 'pivot_role.granted';
    case PIVOT_ROLE_REVOKED = 'pivot_role.revoked';

    case ORGANIZATION_ROLE_ASSIGNED = 'organization_role.assigned';
    case ORGANIZATION_ROLE_REVOKED = 'organization_role.revoked';

    case ORGANIZATION_SUSPENDED = 'organization.suspended';
    case ORGANIZATION_REACTIVATED = 'organization.reactivated';
    case ORGANIZATION_REMOVED = 'organization.removed';
    case ORGANIZATION_CREATED = 'organization.created';
    case ORGANIZATION_UPDATED = 'organization.updated';
    case ORGANIZATION_CONTRACT_RENEWED = 'organization.contract_renewed';
    case ORGANIZATION_CONTRACT_ACTIVATED = 'organization.contract_activated';
    case ORGANIZATION_MEMBERSHIPS_RESTORED = 'organization.memberships_restored';
    case ORGANIZATION_CONTRACT_EXPIRED = 'organization.contract_expired';
    case ORGANIZATION_ENTITLEMENTS_UPDATED = 'organization.entitlements_updated';

    case ORGANIZATION_DOMAIN_ADDED = 'organization_domain.added';
    case ORGANIZATION_DOMAIN_VERIFIED = 'organization_domain.verified';
    case ORGANIZATION_DOMAIN_SET_PRIMARY = 'organization_domain.set_primary';
    case ORGANIZATION_DOMAIN_REMOVED = 'organization_domain.removed';

    case ORGANIZATION_CODE_CREATED = 'organization_code.created';
    case ORGANIZATION_CODE_DEACTIVATED = 'organization_code.deactivated';

    case MEMBERSHIP_ACTIVATED = 'organization_membership.activated';
    case MEMBERSHIP_SUSPENDED = 'organization_membership.suspended';
    case MEMBERSHIP_DEACTIVATED = 'organization_membership.deactivated';
    case MEMBERSHIPS_RELEASED = 'organization_membership.released';

    case INVITATION_CREATED = 'organization_invitation.created';
    case INVITATION_ACCEPTED = 'organization_invitation.accepted';
    case INVITATION_REVOKED = 'organization_invitation.revoked';

    case SEATS_RECONCILED = 'organization.seats_reconciled';

    case TENANT_ACCESS_DENIED = 'tenant.access_denied';
}
