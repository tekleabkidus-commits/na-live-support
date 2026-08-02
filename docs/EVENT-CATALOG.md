# Event catalog

> **GENERATED — do not edit.** Source of truth is
> `app/Support/Events/EventCatalog.php`. Regenerate with
> `php ci/event-catalog.php --write`; the `event-catalog` CI gate fails on drift.

This is the integration contract between the module that publishes an event and every
module that consumes it (Doc 01 §21). A producer changing a payload can see here who
will notice; a consumer can see what it is entitled to receive.

**Payload keys are exhaustive and enforced.** `EventPublisher` rejects a payload with a
missing key AND one with an extra key — the second is how PII leaks into an event stream
that is retained for 13 months (SEC-07, NFR-07).

12 event(s).

## `auth.2fa.challenge_failed`

A 2FA challenge attempt failed; the fifth voids the login attempt (FR-07).

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`, `attempt`
- **Producers:** Doc02/TwoFactor
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `auth.2fa.disabled`

A user turned their own 2FA off, proving a current code first (§4.8).

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`
- **Producers:** Doc02/TwoFactor
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `auth.2fa.enabled`

A user confirmed 2FA enrolment with a valid code (FR-06).

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`
- **Producers:** Doc02/TwoFactor
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `auth.2fa.reset_by_admin`

An admin cleared a user's 2FA; the user re-enrols at next login (FR-08).

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`, `admin_id`
- **Producers:** Doc02/TwoFactor
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `auth.login.failed`

A staff authentication attempt failed, with FR-04's reason category.

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`, `reason`, `ip_hash`
- **Producers:** Doc02/LoginPipeline
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `auth.login.succeeded`

A staff user completed authentication and a session was established (FR-04).

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`, `device_id`, `new_device`
- **Producers:** Doc02/LoginPipeline
- **Consumers:** Doc17/Audit, Doc18/SecurityMonitoring

## `auth.logout`

A staff user ended their session deliberately (§4.8).

- **Schema version:** 1
- **Payload:** `tenant_id`, `user_id`
- **Producers:** Doc02/LoginPipeline
- **Consumers:** Doc17/Audit

## `tenant.provisioned`

A tenant completed every provisioning step and became active.

- **Schema version:** 1
- **Payload:** `tenant_id`
- **Producers:** Doc01/ProvisionTenant
- **Consumers:** Doc03/RoleSeeder, Doc10/KnowledgeBaseSeeder, Doc17/Audit

## `tenant.quota_exceeded`

A tenant hit a quota ceiling and a request was refused with 429.

- **Schema version:** 1
- **Payload:** `tenant_id`, `quota`
- **Producers:** Doc01/QuotaManager
- **Consumers:** Doc16/Backoffice, Doc18/Alerting

## `tenant.quota_threshold_reached`

A tenant crossed a quota warning threshold (NFR-06: alert at 80%).

- **Schema version:** 1
- **Payload:** `tenant_id`, `quota`, `threshold_percent`
- **Producers:** Doc01/QuotaManager
- **Consumers:** Doc16/Backoffice, Doc18/Alerting

## `tenant.setting_changed`

A tenant setting override was written; consumers should invalidate caches.

- **Schema version:** 1
- **Payload:** `tenant_id`, `key`
- **Producers:** Doc01/SettingsRepository
- **Consumers:** Doc01/SettingsCache, Doc16/Backoffice, Doc17/Audit

## `tenant.state_changed`

A tenant moved between lifecycle states (Doc 01 FR-05).

- **Schema version:** 1
- **Payload:** `tenant_id`, `old_state`, `new_state`
- **Producers:** Doc01/TenantLifecycle
- **Consumers:** Doc02/SessionInvalidation, Doc16/Backoffice, Doc17/Audit
