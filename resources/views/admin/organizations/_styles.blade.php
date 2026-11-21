<style>
    .org-alert {
        padding: .875rem 1.25rem;
        border-radius: var(--radius-md);
        margin-bottom: 1.25rem;
        border: 1px solid
    }

    .org-alert ul {
        margin: 0;
        padding-left: 1.25rem
    }

    .org-alert-success {
        background: var(--success-bg);
        color: var(--success);
        border-color: var(--success)
    }

    .org-alert-danger {
        background: var(--danger-bg);
        color: var(--danger);
        border-color: var(--danger)
    }

    .org-alert-warning {
        background: var(--warning-bg);
        color: var(--warning);
        border-color: var(--warning)
    }

    .org-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        flex-wrap: wrap;
        margin-bottom: 1.25rem
    }

    .org-header h2,
    .org-header h3 {
        margin: 0;
        color: var(--text-heading);
        font-size: 1.125rem;
        font-weight: 700
    }

    .org-muted {
        margin: .35rem 0 0;
        color: var(--text-muted);
        font-size: .85rem
    }

    .org-section-title {
        margin: 1.5rem 0 .75rem;
        color: var(--text-heading);
        font-size: .95rem;
        font-weight: 700
    }

    .org-form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem
    }

    .org-form-grid .form-group {
        margin: 0
    }

    .org-form-grid .form-label span,
    .form-label span {
        color: var(--text-muted);
        font-weight: 400
    }

    .org-error {
        display: block;
        margin-top: .3rem;
        color: var(--danger);
        font-size: .8rem
    }

    .org-entitlement-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr) auto;
        gap: .75rem;
        align-items: start;
        margin-bottom: .75rem
    }

    .org-details {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 1rem 1.5rem
    }

    .org-details dt {
        color: var(--text-muted);
        font-size: .75rem;
        text-transform: uppercase;
        letter-spacing: .02em
    }

    .org-details dd {
        margin: .25rem 0 0;
        color: var(--text-heading);
        font-weight: 600;
        font-size: .9rem;
        word-break: break-word
    }

    .org-stats {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem
    }

    .org-stat {
        padding: 1rem 1.25rem
    }

    .org-stat-label {
        color: var(--text-muted);
        font-size: .8rem
    }

    .org-stat-value {
        color: var(--text-heading);
        font-size: 1.5rem;
        font-weight: 700;
        margin-top: .25rem
    }

    .org-cell-sub {
        display: block;
        margin-top: .2rem;
        color: var(--text-muted);
        font-size: .75rem
    }

    .org-empty {
        text-align: center;
        padding: 2rem;
        color: var(--text-muted)
    }

    .org-grid-2 {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1.5rem;
        margin-top: 1.5rem
    }

    .org-code {
        color: var(--primary);
        font-size: .8rem;
        word-break: break-all
    }

    @media(max-width: 900px) {

        .org-form-grid,
        .org-details,
        .org-grid-2 {
            grid-template-columns: 1fr
        }

        .org-stats {
            grid-template-columns: repeat(2, minmax(0, 1fr))
        }

        .org-entitlement-row {
            grid-template-columns: 1fr
        }
    }
</style>
