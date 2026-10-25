# Camera workflow and integration notes

The user workflow and ticket integration boundary are documented in [README](../README.md).

Select a camera/day cell and choose Update status, Attach images, and/or Create ticket. Status and image changes are saved locally; selected ticket creation calls an external adapter and then saves its confirmed reference. Local records use the localStorage entry `rosewatch-camera-workflow-v1`.

Daily records contain `state`, `notes`, `screenshots`, `externalTicketUrl`, `externalTicketId`, `ticketRequestKey` and `history`. Existing single-image `screenshot` values remain readable and migrate when saved. Legacy `tickets` arrays remain preserved, but the app no longer generates TKT identifiers or edits local ticket status. The Tickets view derives external references from daily records.

## Screenshot integration hook

```js
await window.roseWatch.attachCameraScreenshot(
  'CAM-MKT-001',
  '2026-10-22',
  imageBlob // PNG, JPEG or WebP, up to 8 MB
);
```

The date must exist in the displayed October 2026 register. This hook appends and immediately saves an image, up to six per record. Images are resized to a maximum dimension of 1440px and converted to JPEG. The hook does not infer camera state, create a ticket, or contact a ticketing provider.

Screen capture uses getDisplayMedia and requires user selection of a screen, tab or window. No CCTV frame endpoint is configured. Capture, multiple upload and paste actions in the dialog remain drafts until Save selected actions succeeds.

## Signed incident documents

Incident records use the optional `signedDocumentUrl` property. New incident creation and existing incident detail views expose the field. Only HTTP/HTTPS URLs without embedded credentials are accepted; displayed URLs and attributes are escaped. Link updates use the existing incident persistence mechanism and append an action log. Storage failure leaves the previous link intact.

The linked signed document and its access controls remain in the external document system. This app does not verify signatures or fetch document contents.

## External ticket creation adapter

Register this adapter after camera-workflow.js loads and before using Create ticket:

```js
window.roseWatch.createTicket = async payload => {
  const response = await fetch('/api/ticket-integration/tickets', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Idempotency-Key': payload.idempotencyKey
    },
    body: JSON.stringify(payload)
  });
  if (!response.ok) throw new Error('Ticket service unavailable');
  return response.json(); // { id: 'PROVIDER-123', url: 'https://provider/.../123' }
};
```

This is an example contract, not an implemented endpoint. The server must authenticate/authorize users, keep provider credentials server-side, validate inputs, enforce attachment limits, and honor the idempotency key. Do not register an adapter returning invented success values in production.

Payload: `idempotencyKey`, `cameraId`, `cameraName`, `branch`, `date`, `title`, `status`, `notes`, `screenshots` (JPEG data URLs), and `requestedBy`. The adapter resolves with `{ id, url }` only after provider confirmation, or throws on failure. The selected record and request key are persisted before the call; a failed call preserves local observations and can be retried with the same key. Repeated saves on a record with a confirmed external URL do not create another ticket.

The dialog prevents duplicate submissions while awaiting the provider. No ticket ID is generated locally. Tickets remain separate from incident reports; assignment, resolution and closure remain in the external provider.
