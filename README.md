# RoseWatch CCTV operations

The full Laravel + Inertia + Vue application is in [web](web/README.md), with MySQL, private evidence, branch/role access, operational workflows and server-side reports. The root index.html remains the earlier interactive design reference. Instructions below describe that reference; use the web README for the real application.

RoseWatch records camera observations and incident reports. Ticketing is an external integration: this application triggers ticket creation through an external adapter. The provider owns the ticket, assignment and lifecycle; RoseWatch stores the confirmed reference.

## Daily camera records

1. Open Cameras and select the cell for a camera and date.
2. Select one or more actions: Update status, Attach images, or Create ticket.
3. Mark Online or Offline, capture/upload/paste images, and/or enter a title for the external ticket.
4. Select Save selected actions to save the chosen changes together.

Up to six images can be attached to each daily record. Individual original images may be up to 8 MB. PNG, JPEG and WebP are supported; images are resized and stored as JPEG. Capture opens the browser's window-sharing picker and requires a supported browser and HTTPS or localhost. This application has no direct CCTV frame endpoint.

The register shows an image indicator with a count and a separate external-ticket-link indicator. An older day's status changes that observation; the last displayed day's status determines the camera's current state.

## Ticket integration boundary

Select Create ticket in the camera/day dialog and enter a title. Save selected actions submits camera ID/name, branch, date, selected status, notes, images and actor to the configured external adapter. The returned ticket ID and URL are saved with the record and shown in Tickets.

No provider is connected yet. Without a configured adapter, ticket creation displays a connection message and leaves the dialog open without claiming success. Deselect Create ticket to save status or images independently.

When connected, the daily record and a stable request key are saved before contacting the provider. If the provider fails, the daily record remains saved and the dialog offers retry through Save selected actions. Retries reuse the same idempotency key. A ticket indicator is shown only after the provider returns a valid ID and HTTP/HTTPS URL. A successful external operation cannot be rolled back by browser storage failure; the dialog retains its confirmed result for retrying the local save.

Configure `window.roseWatch.createTicket` using the contract in [camera workflow](assets/CAMERA-WORKFLOW.md). Connect it to a server-side provider adapter; keep credentials on the server. The provider integration must honor idempotency keys to prevent duplicates during retries. No endpoint, provider credentials, or status synchronization is configured in this repository.

Legacy tickets created by earlier versions are preserved in browser storage but are no longer used as an active local ticket workflow. They are not automatically imported into an external provider. Add the real external ticket link to the corresponding camera/day record.

## Incident reports and signed documents

Incidents are separate from tickets and retain their own assignment, action logs, evidence, resolution and verification workflow.

- When creating an incident, use Signed incident report document URL to attach a link if the signed document is already available.
- For an existing incident, select it in Incidents and use Signed document URL, then Save document link.
- Open signed incident report opens the stored document in a new tab.
- Clearing the field and saving removes the reference. Changes are recorded in the incident action history.

Only HTTP/HTTPS URLs without embedded credentials are accepted. The document remains in your document storage; this app stores a reference. Saving a link does not upload the document, verify its signature, or change its sharing permissions.

## Storage and verification

This is a browser-local prototype. Records, images and external references use localStorage; there is no server persistence, team synchronization, or connected authentication. Storage errors retain the previous camera records and document references and display an error.

Run the existing Node verification scripts under tmp. verify-camera-workflow.cjs uses installed Chrome on Windows to exercise camera cell clicks, multi-image uploads, combined saves, external ticket linking without local ticket creation, signed-document links, reload persistence and storage failure handling.

Additional technical details: [camera workflow](assets/CAMERA-WORKFLOW.md).

## Guided operations mockup

Start with Open today's checklist on Overview. The new pages use 25 October 2026 as the fixed demonstration date. No actual camera polling or messaging is connected.

- Today's checklist: filter by branch or checking progress, open a camera's daily record, and record Online/Offline. Image-only saves do not mark a camera checked. Previous observations remain visible as latest known status.
- Bulk updates: select cameras, choose Update selected cameras, review the list, choose Online/Offline, and enter the reason. All selected observations save together. Existing screenshots and ticket links remain intact.
- Screenshot history: filter by camera/date, enlarge images, download an image, or open its daily record. Try a sample image creates clearly labeled simulated evidence; it is not actual footage.
- Outage tracking: finds sequences of offline observations and the next online observation. Dates and durations are estimates from daily observations, not exact outage timestamps. Unchecked days do not imply restoration.
- Reminders: review offline cameras and active incidents in the follow-up queue. Remind tomorrow saves a local snooze until 26 October; Undo snooze reverses it. No push notifications or external messages are sent.
- Branch dashboard: compare latest known states, today's completion and active incidents. Check this branch opens the filtered checklist; Review incidents opens the branch incident register.
- Reports: choose a date/branch, preview, and download a standalone HTML report with embedded evidence, ticket links and incident summaries. Use the browser's Print action for PDF. CSV exports camera observations. Incident statuses are current saved states, not reconstructed historical states.

The camera register now includes unchecked cells through 25 October. Its current-status column uses the latest known observation. Uptime excludes unchecked days. The operations flows use the same camera records as the daily editor; changes refresh the related pages.

Browser verification: `node tmp/verify-operations.cjs` checks the checklist, bulk saves, outage restoration, gallery preview, branch filters, snooze persistence and exported report content.
