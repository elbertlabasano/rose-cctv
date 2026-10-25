# RoseWatch application

Laravel 12 + Inertia 2 + Vue 3 + TypeScript + Vite + Pinia, backed by MySQL. The root index.html remains the earlier interactive design reference. Live application data is server-side; the app does not use browser storage for business records.

## Run locally

Requirements: PHP 8.2+ with PDO MySQL, GD, Fileinfo and the standard Laravel extensions; Composer; Node compatible with Vite 6; MySQL. Lockfiles are included.

```sh
cd web
composer install
npm ci
cp .env.example .env
php artisan key:generate
```

Set DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD in .env. The local workspace has already been configured with database rosewatch at 127.0.0.1:3306 and a dedicated account; its random credentials are stored only in the ignored .env file. Do not overwrite that file when using this workspace.

```sh
php artisan migrate
php artisan rosewatch:create-user your.email@example.com --name="Your Name" --role=admin
npm run build
php -d upload_max_filesize=8M -d post_max_size=64M -S 127.0.0.1:8002 -t public dev-server.php
```

Open http://127.0.0.1:8002/login. Port 8002 matches the registered local Rose account SSO callback. The account command privately prompts for a password and confirmation (8 characters minimum). For Vue development run npm run dev in another terminal.

The existing local server may be started with artisan serve. The explicit PHP command above ensures the image upload limits match the application. The public/.user.ini file provides the same limits for PHP-FPM; configure equivalent PHP settings for other servers.

## Starter seeders

Run these after the normal migrations, from `web/`:

```sh
php artisan db:seed
```

- `UserSeeder` creates Rynhart (`rynhart@rosebakeshop.net`) and Elbert (`elbert@rosebakeshop.net`) as active administrators with access to all branches. Both initial passwords are `password`, stored as hashes by the User model.
- `LocationSeeder` uses the existing branches table and adds the mockup locations: Makati Central, Quezon City, Cebu IT Park and Davao Downtown. Addresses are left blank.
- `DeviceSeeder` adds one 8-channel NVR and two IP cameras (Main Entrance and Cashier Area) per starter branch. These are sample inventory records; network and manufacturer details are left blank. Status starts as `unknown`, without daily observations or outages. Cameras connect to their recorder unless an existing asset or occupied channel prevents that connection.

The seeders only add missing records. Reruns preserve existing passwords, roles, activation, branch details and device settings. Locations match by code or name, users by email and devices by asset ID. Seeding does not clear tables or recreate the database. Change the initial passwords through User management after signing in.

You can also run them individually:

```sh
php artisan db:seed --class=UserSeeder
php artisan db:seed --class=LocationSeeder
php artisan db:seed --class=DeviceSeeder
```

`DeviceSeeder` ensures its starter locations exist when run alone. The seeder tests use only guarded SQLite `:memory:` and do not use `RefreshDatabase`.

## First-use flow

1. Create the first administrator using the interactive command and sign in.
2. Add a branch from Branch dashboard, then register recorders, cameras and other equipment in Device management.
3. Add users and assign their roles and branch access. Share initial passwords through your approved secure channel.
4. Use Today's checklist or Cameras to mark Online/Offline, upload/capture/paste screenshots, and request an external ticket.
5. Follow open outages, document incidents, verify resolutions and export daily reports.

There is no automatic camera health polling. States reflect saved observations. Existing public HTML mockup pages are references, not authenticated live views. Deploy only web/public as the web document root.

## Implemented workflows

- Authentication: session login/logout, normalization, validation, per-account/IP and overall login throttling, remember-me, CSRF, session rotation, inactive account rejection and password-change session protection. Sign-in help directs users to their administrator; email password reset is not configured. Rose account sign-in uses Keycloak with state and PKCE, linking existing active accounts by Employee ID code and stable issuer/subject. Email/password sign-in remains available. See [Rose account SSO setup](docs/rose-account-sso.md) for configuration and rollout. The bakery login image is an AI-generated illustration, not a photograph of an actual branch.
- Access: every branch/device/evidence/incident/report route enforces server-side permissions. Vue only offers actions permitted by the user's role. An unassigned user sees an empty access state, not other branches' data.
- Devices: create/edit, identity and branch, hardware, parent relationships, recorder capacity, channel uniqueness, IPv4 uniqueness per branch, circular-connection prevention, status, maintenance dates and audit reasons. Devices with operational history cannot change branch.
- Daily observations: device/date matrix, combined action selection, today's checklist and atomic bulk status saves. Image-only updates do not mark a camera checked. Existing images/ticket references remain intact when their actions are not selected. Past dates are read-only. Status updates, image changes, ticket creation, and manual ticket retries are allowed only for today in Philippine time.
- Evidence: six images per daily record; capture via browser picker, paste or multiple upload, private authenticated preview/download and removal through the daily editor. Incident action logs support three image/PDF proofs per entry. Original files are limited to 8 MB; images are validated and re-encoded to JPEG with a 1600px maximum dimension and 20-megapixel decode limit.
- Outages: a recorded current offline status starts one open interval; a current online observation restores it. Times represent observed state changes, not exact stream-failure telemetry. Non-camera device details can be reviewed from outage tracking too.
- Incidents: creation, assignment, priority and target times, work logs, evidence, cause/work/restoration/prevention, supervisor verification, reopen, and signed-document URLs with audited changes. Evidence/history is retained after reopening. Incident resolution does not automatically change camera status.
- Tickets: durable request, immutable context snapshot, queue processing, stable idempotency key, provider-confirmed reference, visible failure/unavailable states and retry. No local ticket IDs are invented. Provider setup is intentionally deferred.
- Reminders: each user's own in-app snoozes for offline cameras and incidents awaiting action/verification. No external messages or push notifications are sent.
- Dashboards/reports: branch health, overview, evidence history by selected month, full standalone HTML report with embedded screenshots and signed/ticket links, camera CSV with formula escaping. Print the downloaded HTML from the browser to obtain a PDF. Incident summaries show current saved state, not historical snapshots.
- Device dashboard: open Dashboard in Device inventory, select a device name in Cameras or Checklist, or visit `/devices/{id}`. Each device has its own overview, status history, ticket requests, incidents, evidence, outages and connected hardware. Administrators also get the device-change audit. Branch access is enforced before any dashboard data is returned.
- Users: role/team, branch assignments, activation/deactivation, password changes and admin protection. Account and operations audit displays the latest 200 entries.

## Device dashboard

Use **Search device** in the sidebar from any workspace page. Enter at least two characters from an asset ID, device name, location or branch, then select a result to open its dashboard. Arrow keys change the selected result, Enter opens it and Escape closes search. Exact asset IDs rank first. The authenticated search endpoint returns up to 12 results from branches the current user can access; results contain only device identity, branch, type, status and dashboard link. Search requests are debounced and cancelled when superseded or closed. `DeviceSearchTest` covers access, matching, ranking, result limits, literal wildcard handling and query validation using guarded SQLite `:memory:` without `RefreshDatabase`.

The overview charts default to the last 30 days. Quick choices select 7, 30 or 90 days; a custom chart period can cover up to 90 days, ending today or earlier in Philippine time. A day is marked checked only when its daily record has a saved status and check timestamp. Image-only records and missing dates remain unconfirmed. Chart cells open a read-only copy of the daily record and its images.

Recorded downtime comes from overlapping outage intervals clipped to the selected period and, for open outages, the current time. Overlapping intervals are merged before daily totals are calculated. This is recorded downtime, not measured stream uptime or an availability percentage. The chart includes an accessible data table with exact daily values.

History tabs cover **all saved dates**, independently of the chart period, with 12 items per page. Status history includes daily records and actual inventory status transitions, so NVRs and other non-camera equipment can have status history too. Only the status transition, actor and timestamp are exposed from these inventory audit entries; the full device audit remains admin-only. Daily records describe the latest saved observation for each date, rather than an immutable log of every edit.

Ticket requests remain distinct from confirmed external tickets. Incident links open the specific incident, including closed incidents, and its action history in Incident management. Evidence includes daily screenshots and incident proofs/PDFs, served through existing authenticated preview/download routes; storage paths and provider payloads are never included in dashboard page data. The dashboard does not modify records or backfill missing history. Use today's checklist or Device management to record new work.

The new route and view use the existing schema; no database migration or seeder is needed. `DeviceDashboardTest` verifies branch authorization, historical pagination, chart validation and outage math, sanitized evidence/audit data, and non-camera status changes using guarded SQLite `:memory:` without `RefreshDatabase`.

## Roles

| Role | Read assigned branches | Camera checks / incident work | Devices | Verify incidents | Branches / users |
|---|---|---|---|---|---|
| Administrator | All | Yes | Yes | Yes | Yes |
| Supervisor | Assigned or all | Yes | Yes | Yes | No |
| Operator | Assigned or all | Yes | No | No | No |
| Technician | Assigned or all | Yes | No | No | No |
| Viewer | Assigned or all | No | No | No | No |

The CLI account command grants all-branch access to the specified role; use User management for branch-limited accounts. Your current administrator cannot deactivate or demote itself, and the last active admin is protected.

## Architecture

Form Request (input validation and authorization) -> Controller -> Service -> Eloquent model. Policies and AccessService enforce model/branch access. Services own transactions and business invariants. AttachmentService compensates newly written files on rollback and removes deleted evidence after commit. Pinia holds navigation state; Inertia owns page visits/forms. There is no general repository layer.

Main services: AccessService, AdministrationService, DeviceService, ObservationService, IncidentService, AttachmentService, WorkspaceService and AuditService. Private files are not included in page props; only authorized attachment routes are exposed.

## External ticket integration

Leave TICKET_ENDPOINT and TICKET_TOKEN blank until a provider is chosen. With no endpoint, selected requests are saved as unavailable; observations and evidence still save successfully. The UI never reports a ticket as created without provider confirmation.

Configure an HTTPS endpoint in TICKET_ENDPOINT and its server-side bearer token in TICKET_TOKEN. The HttpTicketProvider sends POST JSON with an Idempotency-Key header. Payload fields: camera_id, camera_name, branch, date, status, notes, title, requested_by and screenshots (array of name, mime and base64 data). The provider must return {"id":"EXTERNAL-123","url":"https://provider/tickets/123"} and honor the idempotency key for retries. Credentials never reach Vue.

Start a queue worker after configuration:

```sh
php artisan queue:work --tries=3 --timeout=60
```

The request snapshot and key persist before dispatch after the database transaction commits. The job tracks queued/processing/created/failed; retry preserves the key. A timeout may occur after external creation, so provider idempotency is required. External success cannot be rolled back by a later local failure. Endpoint setup and provider behavior are not represented as already connected.

## Storage and deployment

Use web/public as the only document root. Evidence stays in storage/app/private on Laravel's local disk, automatic local URL serving is disabled, and no public/storage symlink is needed. Files are streamed by authenticated routes after checking branch access, with private/no-store and nosniff headers. PDF previews are sandboxed. Generated filenames are UUIDs; MySQL stores metadata and ownership. Signed-document URLs are references only; this app does not fetch them, verify signatures or change document sharing.

Use HTTPS, APP_DEBUG=false, SESSION_SECURE_COOKIE=true and a configured APP_URL for deployment. Sessions are encrypted and stored locally. Set file-system permissions so only the app account can read evidence, sessions, logs and .env. Back up MySQL and private files together. A single local storage volume is required while running on one server; migrate the disk through Laravel's storage abstraction before scaling across servers. Large inventories or reports may need pagination/queued generation in a later scale-up.

## Frontend formatting

Vue components, TypeScript and styles use Prettier with four-space indentation, a 100-character line width, and one template attribute per line. Keep imports, state declarations and functions separated by blank lines.

```sh
npm run format
npm run format:check
```

The project configuration is in .prettierrc.json; generated assets, dependencies and runtime storage are excluded from formatting.

## Verification

```sh
php artisan test
npm run typecheck
npm run build
```

The 33 backend tests force isolated in-memory SQLite and refuse persistent databases before RefreshDatabase runs. Separate test cache paths ignore live configuration/route caches. Migrations have also been applied to the local MySQL database. Tests cover authentication, branch/role access, private files, bulk rollback, upload validation, outages, incident lifecycle, admin protection, reports, reminders and ticket confirmation/retries with a fake provider.

Browser UI review uses synthetic page props, not a privileged backend account. With the local server on port 8127, run node tmp/verify-workspace.cjs from the repository root. It exercises the compiled pages, matrix action dialog, device accordions, incident resolution form and responsive navigation. Login browser checks remain at tmp/verify-login.cjs. Real external ticket delivery is deferred until a provider is configured.
