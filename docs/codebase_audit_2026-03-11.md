# Codebase Audit (2026-03-11)

## Scope
- Role/module audit of: auth/RBAC, owner, HR, employee/staff, client, sys_admin, config helpers, cron scripts, SQL schema.
- Connectivity audit of UI ↔ backend wiring.
- Workflow audit for onboarding → support/disputes.

## Actual module map

### Authentication & RBAC
- Core auth/session helpers are centralized in `config/auth_functions.php` with role checks, status refresh, session timeout handling, and role-based redirects.
- Login flow includes explicit demo credentials in UI.
- `require_role()` is broadly used in role folders.

### Owner modules
- Operationally connected owner modules: quotation, payment verification, production/QC/delivery management, inventory/raw materials, support management, workforce scheduling.
- Compatibility/deprecated route: `owner/add_staff.php` (handoff page only).

### HR modules
- Working modules: `create_staff.php`, `hiring_management.php`, `attendance_management.php`, `payroll_compensation.php`, `staff_productivity_performance.php`, `dashboard.php`.
- Partially static module: `analytics_reporting.php` (real KPIs + hardcoded dashboard/report cards).

### Employee/staff modules
- Connected modules: dashboard, assigned jobs, status update, upload photos, attendance, support tasks, schedule, profile.
- Role checks include both `staff` and `employee` aliases; schema only defines `staff` role.

### Client modules
- Connected modules: place order, pricing quotation, design proofing, payment handling, order management, tracking, support, messaging, profile.
- `design_editor.php` is rich UI but persists drafts in browser localStorage, not backend APIs.

### Sys admin modules
- Connected modules: member approval, user access control, content moderation, system control, dss config, notifications/reminders, analytics/export, audit logs, backup.
- Placeholder/static-heavy modules: membership lifecycle page, portions of dashboard and settings recommendations.

### Config helpers
- High helper density in `/config` for auth, order workflow, quotes, payment lifecycle, scheduling, assignment, notification reminders, moderation, QC, inventory, exceptions.
- `config/db.php` performs extensive runtime schema patching (`ensure_*` functions), indicating schema drift mitigation at request time.

### Cron scripts
- `cron/cron_notification_reminders.php` runs reminder automation and logs summary.
- `cron/cron_recalculate_shop_metrics.php` refreshes DSS metrics and logs run info.

### SQL schema files
- Primary schema dump: `embroidery_platform.sql`.
- Contains both base table definitions and appended patch/migration blocks in one file.

## Working vs partial vs placeholder

### Fully working (high confidence)
- Auth login/logout/register/reset/OTP pages + RBAC/session checks.
- Core order + quote + payment verification + production tracking + QC + delivery modules (owner/client/employee side).
- Support ticket lifecycle (client creates; owner/staff process).
- Cron automation entrypoints.

### Partially working
- HR analytics reporting (real KPI fetch + static mock cards and alerts).
- Sys-admin dashboard (real stats + placeholder update button and mock chart commentary).
- Payment gateway abstraction exists but gateway session is manual-proof mode (not true hosted checkout).

### Static/demo/placeholder only
- `sys_admin/membership_lifecycle.php` (entirely static arrays/content cards).
- `owner/add_staff.php` compatibility-only handoff page.
- Demo credential box in login page.

### Duplicated/deprecated patterns
- Role aliasing: `staff` vs `employee` appears in code checks; DB role enum excludes `employee`.
- Parallel staff models: `staffs` table and `shop_staffs` used together in HR/payroll area.
- API surface overlap for design save/load (`design_api.php` vs `design_save.php`/`design_load.php`) with inconsistent schemas.

### Not connected to real backend logic
- Most API endpoints are not referenced by frontend pages (except payment methods + upload endpoints).
- `client/design_editor.php` does not call design APIs; stores state in localStorage only.

## Core business flow mapping

### 1) Onboarding
- Client self-registration + OTP/password reset flows exist.
- Owner registration/create shop + sys-admin member approval path exists.
- Owner creates HR; HR creates/hires staff.
- **Gap:** lifecycle orchestration docs page in sys_admin is non-executable/static.

### 2) Quoting
- Client proofing/quote request + owner quotation management + quote table/helpers present.
- Order workflow validators enforce quote approved before acceptance.

### 3) Proofing / design approval
- Client proofing flow is connected to order + design approvals.
- Separate design API stack has schema mismatch and weak/unknown integration with active UI.

### 4) Order creation
- Client place order flow supports service details, quantity, pricing estimate, payment method selection.

### 5) Payment
- Client payment handling + owner payment verification + payment lifecycle helpers + invoice/receipt generation exist.
- Gateway integration is currently manual-proof style (not full real-time gateway completion).

### 6) Production
- Owner production tracking + employee status updates + media uploads + scheduling/assignment helpers exist.

### 7) QC
- Owner QC module + `order_quality_checks` support.

### 8) Delivery / pickup
- Owner delivery management + fulfillment history/status transitions + client tracking view.

### 9) Completion
- Workflow statuses include delivered/completed and history logging.

### 10) Support/disputes
- Client support tickets + owner support assignment + sys-admin content moderation/dispute analytics.

## Gaps and disconnected UI/backend
- Design editor page is mostly client-side/localStorage and not wired to the design API endpoints.
- API endpoints exist with little/no caller usage (`design_api`, `design_save`, `design_load`, `order_api`, `analytics_api`).
- Sys-admin lifecycle module presents architecture but no operational backend actions.
- Several dashboard cards remain explanatory/mock rather than actionable.

## Placeholder/fake/hardcoded signals
- Login page shows explicit demo credentials.
- Sys-admin dashboard includes JS alert placeholder for updates.
- Owner add-staff page explicitly says compatibility route.
- HR analytics contains hardcoded insight cards/scheduled report rows/performance alerts.
- Membership lifecycle module is static narrative data.
- Design editor uses localStorage draft handoff alerts instead of backend persistence.

## Highest-risk schema issues
1. **Design API/schema mismatch:** `api/design_api.php` expects `design_versions.order_id/version_number/design_data` but SQL defines `project_id/version_no/design_json`.
2. **Role mismatch risk:** code checks for `employee` role while users table enum supports `sys_admin, staff, owner, hr, client`.
3. **Dual staff models:** `staffs` and `shop_staffs` both used in HR/payroll/attendance flows; risk of divergence.
4. **Runtime schema migration strategy:** many `ensure_*` ALTERs in request bootstrap increase environment skew and startup side effects.
5. **Single monolithic SQL file with base + patches:** difficult reproducibility/ordering across environments.

## Recommended implementation order
1. **Stabilize schema contract first** (canonical migrations + remove request-time DDL side-effects).
2. **Unify roles/staff model** (`staff` only, remove `employee` alias where inappropriate, reconcile `staffs` vs `shop_staffs`).
3. **Consolidate design subsystem** (pick project-based or order-based model; align UI + APIs + schema).
4. **Wire disconnected APIs/UI** (design editor persistence, order API consumers, admin analytics API consumers).
5. **Replace placeholder admin/HR cards with query-backed widgets + actions**.
6. **Harden payment gateway path** (true checkout/webhook verification or clearly scoped manual-proof mode).
