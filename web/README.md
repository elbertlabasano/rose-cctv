# RoseWatch application

Laravel 12 + Inertia 2 + Vue 3 + TypeScript + Vite + Pinia, backed by MySQL. The root index.html remains the earlier interactive design reference. Live application data is server-side; the app does not use browser storage for business records.

The public homepage at `/` introduces RoseWatch and links to sign-in or the current
user's workspace. Camera and branch data remain behind authentication. See
[Homepage](docs/homepage.md) for navigation, image assets and validation details.

For an issue-only daily report, open **Reports → Branch Issues Report**. Filter by
date, cluster and branch, then download CSV or print/save PDF. See
[Branch Issues Report](docs/branch-issues-report.md) for inclusion rules and dates.

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

## Monitoring roster seeders

The October 2026 monitoring workbook supplies **10 clusters and 112 branches**.
Each workbook branch receives **one IP Camera** monitoring entry. The portable
roster is committed in `database/seeders/data/october-2026-monitoring.json`; the
original Excel file is not required when deploying or rerunning these seeders.
Names and cluster membership preserve branch rows 9-120 of `Sheet1 (2)`; summary
rows, notes, daily status values and offline-day calculations are excluded.

After applying the additive cluster migration, seed monitoring inventory only:

```sh
php artisan db:seed --class=MonitoringSeeder
```

This command calls `DeviceSeeder`, which includes `LocationSeeder` and
`ClusterSeeder` in a transaction. It does not run `UserSeeder` or change accounts.
A fresh database gets 112 branches, 112 IP cameras and 10 clusters. No recorder
or additional per-location cameras are generated. Existing unrelated branches,
recorders, cameras and history remain in place.

- Cluster and branch matching uses code or a case-insensitive trimmed name.
  Existing names, codes, addresses and activation flags are preserved. Missing
  cluster assignments are filled; a different existing assignment is preserved
  with a console warning.
- A workbook branch's existing IP camera is reused without changing its name,
  asset ID, status, network details, notes or history. If it already has multiple
  IP cameras, all are preserved and no additional camera is created.
- Newly created cameras have application asset IDs such as `CAM-AGDAO-1-001`,
  type `IP Camera` and status `unknown`. IP addresses, manufacturer, model, serial,
  channel and recorder details remain blank until verified. These asset IDs are
  generated application identifiers, not imported hardware serial numbers.
- The seed does not create historical observations or outages, or mark cameras
  checked. Old workbook statuses do not become current live status.
- Ambiguous existing branch/cluster identities or occupied generated asset IDs
  stop and roll back the inventory seed. No existing device is reassigned.

Clusters appear on the Branch dashboard and in the branch editor. Administrators
can change assignments there; other users receive cluster data only for branches
they can access. Cluster names can also be used in Branch dashboard search.

`php artisan db:seed` runs `UserSeeder` followed by `MonitoringSeeder`. UserSeeder
adds missing Rynhart/Elbert starter administrator accounts with initial password
`password`, hashed by the User model; reruns preserve existing
passwords, roles, activation and employee codes. For an existing installation,
use the monitoring-only command above when adding the workbook inventory.

`SeedersTest` uses guarded SQLite `:memory:` without `RefreshDatabase`, and covers
roster counts, one camera per branch on a fresh database, safe reruns, reuse,
conflict rollback, cluster assignment validation and branch-scoped cluster access.

## October 2026 history import

The reviewed workbook history is a separate snapshot in
`database/seeders/data/october-2026-history.json`, including source cells and the
workbook SHA-256. The inventory seeder above remains inventory-only. After the
observation import metadata migration, preview and import the dated records:

```sh
php artisan rosewatch:import-october-2026 --dry-run --initialize-status
php artisan rosewatch:import-october-2026 --initialize-status
```

This is an explicit server-side historical import, not a public backdating API.
Normal daily actions still allow only today in Philippine time. The import uses
one IP camera per workbook branch, rejects missing/ambiguous mappings, runs in a
transaction and preserves every existing device/date record, including evidence
and ticket links. Reruns skip already saved records. Blank cells are omitted and
future dated entries are skipped. The source has 2,909 nonblank cells through
October 26: 2,014 ON, 472 OFF and 423 DAYOFF entries.

ON/OFF become online/offline observations. DAYOFF, HOLIDAY, NO DEVICE and ALERT
are report markers, not confirmed camera connectivity. Imported records retain
the source filename/cell and date; no operator or exact check time is invented.
Date-only imported ON/OFF records count as reported checks, while day-off/holiday
markers do not count as completed checks. Device history, charts, readonly dialogs
and exports display the imported values.

`--initialize-status` initializes only an unknown camera without an observed status
timestamp or manual checks. It uses the latest eligible ON/OFF entry, stores its
`status_reported_on` date and leaves `status_observed_at` empty. Current checks and
existing known states are preserved. A later real check replaces import provenance
for that day's observation and records its actual operator/time. Importing does
not fabricate outage intervals, incidents, tickets or exact downtime. Opening
offline-day carry-over and worksheet-reported offline-day values remain in the
source snapshot; they are not interpreted as measured outage durations.

The Cameras register shows all days of the selected month, disabling future cells.
Its daily summary rows at the bottom of the table count online, offline, no device, day off/holiday, total
recorded and unrecorded cameras within the current filters. Total recorded includes
report markers, so day-off entries reconcile with the totals. Values are computed
from saved records instead of copied spreadsheet summary/formula cells. Existing
manual checks can therefore make app totals differ from the original worksheet.

When clusters are available, Cameras defaults to assigned clusters. Use its cluster
filter to select a single cluster, All clusters, or unassigned branches; existing
unassigned/sample cameras remain available. Summary totals follow the displayed
cameras.

`OctoberMonitoringImportTest` uses guarded SQLite `:memory:` to verify source totals,
safe reruns, future-date limits, manual-record preservation, report markers, status
initialization, readonly history and exports. `node tmp/verify-october.cjs` checks
the compiled calendar and exact daily counts with browser-only fixture data,
including corrected Sunday totals, cluster filtering and responsive layout.

## Workspace cluster filters

Cluster filters are available on Overview, Cameras, Today's checklist, Screenshot
history, Outage tracking, Branch dashboard, Incidents, Tickets, Reminders, Device
management, Reports and User management. They sit in the existing filter bars.
Other pages start with All clusters; Cameras retains its assigned-cluster default.

- Choose a cluster, Assigned clusters, or Unassigned branches. All clusters includes
  branches without a cluster assignment.
- Branch choices follow the selected cluster. Changing to an incompatible cluster
  resets the branch choice and clears checklist bulk selections.
- Records and summary counts follow the selected cluster and branch, alongside the
  page's existing search and status filters. Date/month navigation preserves the
  cluster and branch selection.
- Reports apply the same cluster and branch scope to both CSV and HTML downloads.
  Export requests validate cluster IDs and intersect the selection with the signed-in
  user's authorized branches; the filter never grants additional access.
- User management filters accounts by branch access. Administrators and all-branch
  accounts appear in every matching cluster. Its audit list follows the selected
  branches, including account events for matching users; global system events appear
  in the unfiltered view.

`ClusterReportFilterTest` checks export scopes, mismatched selections and branch
authorization using guarded SQLite `:memory:`. `node tmp/verify-clusters.cjs` checks
all 12 compiled workspace pages, cascading branch choices, checklist selections,
account/audit scoping, report links, date navigation and responsive layouts using
browser-only fixtures with backend writes blocked.

## First-use flow

On **Cameras**, use **Jump to today** to bring today's column into view. Use
the single checkbox beside each camera: **checked = Online, unchecked = Offline**.
Toggling it saves that day's status immediately,
without opening a dialog. The selected checkbox shows a checkmark after server
confirmation; saving and errors appear per camera. **Details** opens the existing
daily action dialog for notes, screenshots and ticket creation.

Quick marking is a status-only action: it preserves notes, evidence and ticket
requests and never dispatches a ticket job. It records the actual checker/time,
updates the current device status and follows the usual outage start/restoration
rules. A real check replaces imported status markers for today. Past/future dates
and read-only users have no quick controls; the backend independently enforces the
current Philippine date, role, branch and camera-type checks.

`POST /observations/quick-status` returns only the updated device state, safe
observation/evidence/ticket metadata and that device's outages. Vue updates the
Inertia props locally, so cell colors and summary totals change without reloading
the monthly dataset or resetting table scroll. A camera's checkbox is disabled
while saving; other cameras can be checked concurrently. Existing status filters
can remove a newly marked camera from the current view.

`QuickStatusTest` uses guarded SQLite `:memory:` and fake storage/queues to verify
permissions, dates, preservation, outage behavior, provenance and rollback.
`node tmp/verify-quick-status.cjs` checks the compiled UI with intercepted fixture
POSTs, including concurrent saves, totals, scroll, keyboard controls, errors, details
and mobile sizing. It does not write live application data.

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

RoseWatch connects to the [IT Support Hub ticket API documentation](https://api.rosebakeshop.net/?urls.primaryName=IT+Support+Hub+%E2%80%94+Tickets).
Only `POST /api/ticket` (create) and `GET /api/ticket` (view) are implemented.
No remote status changes, assignments, edits, comments or deletion actions are exposed.
Documentation Basic authentication is separate from runtime API bearer authentication;
only the API bearer token belongs in the app's ignored `.env`.

```dotenv
TICKET_ENDPOINT=https://itsupporthub.rosebakeshop.net/api/ticket
TICKET_TOKEN=<server-side bearer token>
TICKET_DEPARTMENT_ID=1
TICKET_CATEGORY_ID=33
TICKET_ASSIGNEE_ID=6
TICKET_BOARD_ID=10
```

The creation dialog offers board **10 ? CCTV Reports/Concerns** (default) and board
**9 ? CCTV Footage/Defiance Daily Reporting**. Allowed boards are configured in
`config/tickets.php` and validated server-side. An existing request retains its original
board and content snapshot. Department/category/assignee defaults stay on the server.
Without complete configuration, daily records still save and ticket requests remain
unavailable; the UI never claims creation before a valid provider confirmation.

The requester name/email come from the signed-in RoseWatch user. Support Hub may create
its own requester account when that email is unknown, as described by its API.
Creation sends subject, camera/branch/date/status/notes, department/category, assignee,
selected board, medium priority and request ticket type. `source_app=RoseWatch` and a
stable `external_reference` correlate the request. Saved screenshots are sent as binary
`attachments[]` multipart parts, preserving their stored MIME and generated filenames;
requests without files use JSON. Private evidence URLs, credentials and base64 image
payloads are not sent. The job rechecks requester access and attachment ownership before
sending. A confirmation must be HTTP 201 with `success=true`, a nested ticket identifier
and an HTTPS ticket URL on the configured provider origin.

Start or restart the queue worker after deployment/configuration changes:

```sh
php artisan queue:work --queue=tickets --tries=1 --timeout=60
```

The request snapshot and correlation key persist before dispatch after the transaction
commits. Jobs use a dedicated `tickets` queue. **The API does not guarantee idempotency.** Each queued request is sent once:
there are no HTTP retries or automatic POST retries by the job. Confirmed creation is
stored as `created`; known rejection/unsent failure becomes `failed` and can be explicitly
retried for today's record after correction. Timeouts, server errors, redirects,
malformed confirmations and interrupted processing become `unconfirmed`. These cannot be
requeued through retry endpoints or by saving daily actions again. Check Support Hub
using the request reference before any further action; an uncertain ticket may already
exist. Stale processing jobs are not reclaimed for another POST. Existing historical
records remain read-only. Files missing before sending fail without a remote POST.

On **Tickets**, choose **Load live tickets** to read current provider statuses, priorities,
board, assignees and descriptions; the local request state remains separate. The
server uses `assigned_id=6`, with explicit provider-page navigation (50 items per page).
API pagination URLs are ignored. Only confirmed tickets matching both the ID and URL of
local requests in the selected month and the current user's authorized branches are
returned to Vue. Other apps' tickets and inaccessible branches are excluded. Existing
cluster/branch/search filters also narrow the live panel. Provider pages can contain no
matching local tickets; tickets reassigned away from the configured assignee will be
absent. The API does not list attachments/checklists in GET responses. Use the confirmed
external link to view the complete ticket in Support Hub.

`GET /tickets/external` is authenticated, validated, rate limited and private/no-store;
callers cannot override the assigned-user ID. Token values, raw API errors and raw
provider payloads never appear in page props or logs. Redirects are disabled so bearer
credentials are not forwarded to another origin. Creation failures use safe messages
without chaining raw HTTP exceptions into failed-job storage.

`TicketIntegrationTest` and the affected operations tests use synthetic HTTP responses,
private fake storage and guarded SQLite `:memory:`. PHPUnit forces ticket credentials
blank and the shared test base blocks stray Laravel HTTP calls. Tests cover the exact
JSON/multipart contract, board validation, combined saves, single-send behavior,
uncertain outcomes, explicit retries, pagination, branch scoping and credential redaction.
No production POST is needed for verification. A read-only live GET verified the route,
authentication and response contract for the configured assignee.

## Storage and deployment

Use web/public as the only document root. Evidence stays in storage/app/private on Laravel's local disk, automatic local URL serving is disabled, and no public/storage symlink is needed. Files are streamed by authenticated routes after checking branch access, with private/no-store and nosniff headers. PDF previews are sandboxed. Generated filenames are UUIDs; MySQL stores metadata and ownership. Signed-document URLs are references only; this app does not fetch them, verify signatures or change document sharing.

Use HTTPS, APP_DEBUG=false, SESSION_SECURE_COOKIE=true and a configured APP_URL for deployment. Sessions are encrypted and stored locally. Set file-system permissions so only the app account can read evidence, sessions, logs and .env. Back up MySQL and private files together. A single local storage volume is required while running on one server; migrate the disk through Laravel's storage abstraction before scaling across servers. Large inventories or reports may need pagination/queued generation in a later scale-up.

## Manual database and attachment backups

Administrators can open **Management > Manual backup** (`/backups`):

1. Choose **Download SQL** and **Download attachments ZIP** to save both backup files.
2. Choose **Open Drive folder** to open the configured destination in a new tab.
3. In Google Drive, choose **New > File upload** and upload both downloaded files.
   Keep the SQL and ZIP together in the same dated backup folder.

The attachment button shows **Preparing ZIP…** while the export is requested. Once
the ZIP response arrives, the browser download is triggered explicitly and a
**Save ZIP file** link remains available if automatic downloading is blocked.
Missing files, expired sessions, access failures and rate limits appear inline;
HTML/sign-in responses are rejected rather than saved as broken ZIP files. AJAX
preparation failures return a safe JSON message; ordinary POSTs retain their
redirect-and-flash behavior. Refresh the page after deploying frontend changes.

This is a manual workflow. There is no Google API connection, automatic upload,
scheduled backup, or in-app restore. RoseWatch records when an export was prepared;
the recent-export list cannot confirm browser download completion or a Drive upload.
SQL includes all branches and user records. ZIP includes all registered screenshots
and uploaded incident files on the local evidence disk. Both exclude private
environment settings and unrelated filesystem data. Restrict access to the files
and their Drive folder. External signed-document URLs remain SQL references; their
remote documents are not fetched or included in the ZIP.

The ZIP includes `manifest.json` with attachment IDs, ownership references, original
local paths, archive paths, sizes and SHA-256 checksums, plus `README.txt` with recovery
instructions. Original filenames are metadata only; archive entries use the generated
evidence paths. Duplicate file references share a ZIP entry. Unregistered/orphan files
are excluded. An empty attachment library produces a valid manifest-only backup with
instructions. Missing, unreadable or size-mismatched registered files abort the whole
ZIP instead of producing an incomplete download. Unsupported disks, traversal paths
and symlinked evidence paths are rejected.

For recovery, restore the SQL separately, then copy `attachments/local/evidence/`
from the ZIP into the app's private `evidence/` directory, preserving generated
filenames and the existing local disk configuration. Verify files against manifest
checksums. There is no in-app restore. SQL and ZIP are separate snapshots: download
them close together and avoid uploads/deletions while preparing the pair.

Configure these server-side values in the ignored `.env`:

```dotenv
BACKUP_MYSQL_DUMP_BINARY=mysqldump
BACKUP_GOOGLE_DRIVE_FOLDER_ID=<the folder ID from the Drive URL>
```

Use a server-compatible MySQL/MariaDB dump executable. On Windows, set the absolute
path, for example `"C:/xampp/mysql/bin/mysqldump.exe"`. Run `php artisan config:clear`
after changes. The local workspace's utility and requested folder are configured.
The `/u/8/` segment in a Drive URL is an account selector, not part of the folder ID;
the app uses a canonical folder link. Sign in to a Google account with folder access.

Exports use a transaction snapshot for InnoDB tables, quick streaming and binary-safe
data output without locking application tables. Avoid schema changes during export.
Nontransactional tables need a separate consistency plan. Table schema/data and
default trigger output are included; routines, events, server users and privileges
are not exported. No database migration or reseeding is needed for this feature.

Attachment ZIP creation requires PHP's ZIP extension (`ZipArchive`) and a local
evidence disk. Availability is shown separately from SQL export, so one can remain
available when the other is not. The page displays registered attachment count and
uncompressed bytes. ZIP entries read files from disk without loading the entire
archive into PHP memory; metadata is processed in chunks. Allow temporary disk space
for the archive. The browser receives the ZIP as a Blob before starting its save;
the Blob URL is released on the next export or when leaving the page. Very large
libraries may require queued or offline exports.

The dump subprocess explicitly receives a small OS-variable allowlist, including
Windows `SystemRoot`, so exports also work through `php artisan serve` when the
web SAPI filters the inherited process environment. SQL failures log only the
exception class and available numeric exit/MySQL/socket error codes, never raw
native errors or connection values.

The authenticated, CSRF-protected POST routes (`/backups/download` and
`/backups/attachments/download`) are admin-only, rate limited to two requests per
minute, and each export type is guarded against overlapping preparation. SQL and connection option
files are temporary private files; credentials never appear in command arguments,
browser props or logs. Option files are deleted after dumping, SQL is deleted after
sending, and shutdown cleanup covers ordinary failed/aborted requests. Force-killing
PHP can bypass shutdown cleanup; keep storage restricted to the app account and
remove abandoned `export-*.sql`/`credentials-*.cnf` files only when no export is active.
On Windows, enforce the storage restriction with NTFS permissions; Unix modes alone
do not establish a Windows ACL. Error responses never include raw process output.
ZIP and manifest temporary files use `storage/app/private/attachment-backups`,
restrictive Unix modes and the same download/shutdown cleanup policy. Remove abandoned
`attachments-*.zip`/`manifest-*.json` only when no attachment backup is active. ZIP
failures log the exception class only. Audit/history entries for SQL and ZIP contain
filenames, byte counts and export type; ZIP audits also record attachment/file counts.
File contents, server paths and manifest details are not exposed in page props or logs.

`ManualBackupTest` uses fake processes and guarded SQLite `:memory:` only; no native
dump is run by tests. It covers access, SQL command construction, private-file cleanup,
locks, rate limits, audit metadata, safe errors and download headers.
`AttachmentBackupTest` exercises actual ZIP generation
with synthetic files and guarded SQLite `:memory:`, including all-branch evidence,
manifest/checksum recovery data, empty libraries, missing/changed files, invalid paths,
access control, safe errors and cleanup. No live database or private evidence is used.
Browser review for both export types is available with `node tmp/verify-backups.cjs`
from the repository root and the local
asset server on port 8002. It verifies that Chrome actually saves a synthetic ZIP
with matching bytes, plus progress, the fallback save link, invalid responses and
visible errors. ZIP requests are intercepted with fixture responses; other write
requests are blocked, so no live export or Drive upload occurs.

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

Browser UI review uses synthetic page props, not a privileged backend account. With the local server on port 8002, run node tmp/verify-workspace.cjs from the repository root. It exercises the compiled pages, matrix action dialog, device accordions, incident resolution form and responsive navigation. Login browser checks remain at tmp/verify-login.cjs. Real external ticket delivery is deferred until a provider is configured.
