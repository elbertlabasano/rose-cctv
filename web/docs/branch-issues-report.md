# Branch Issues Report

Open **Reports → Branch Issues Report**, select a date, cluster and branch, and
press **Generate report**. Exports use the applied report filters, not unsaved
changes in the filter form. **Download CSV** opens in Excel; **Print / Save PDF**
opens a standalone table formatted for landscape printing. CSV is unformatted
tabular data, not a styled XLSX workbook. No schema change or data migration is
needed.

The default report includes equipment with a recorded Offline, Degraded or
Maintenance status, plus active incident reports. An Online camera with an active
incident remains in the report. Healthy equipment without a recorded concern and
unchecked equipment without incidents are excluded. An optional checkbox adds
incidents resolved on the selected date. Resolving an incident does not hide an
offline device. One branch can have multiple device or incident rows; counts of
branches and devices are distinct.

To include an online camera with blurry footage, recording cuts, incorrect time,
or another concern, create an incident for that device and add work updates there.
Ordinary observation notes alone do not imply an active concern. Remarks use the
incident title and latest relevant action note available by the selected date,
falling back to its description. Status-only rows use the saved observation note
or a status explanation. This report does not create external tickets.

## Dates and missing data

- Status is the latest recorded observation on or before the date, or a later
  explicitly dated inventory update. Carried-forward observations show their date.
  Future inventory changes and incident actions are excluded.
- Imported observations can appear before the inventory record was created. An
  import date does not become an outage start or a precise observation timestamp.
- Days offline is an inclusive calendar-day count from an outage recorded as open
  at that time (same-day outage = 1). Unknown start dates remain blank in CSV and
  **Unknown** in the preview and printout; unchecked days are not fabricated.
- Date of concern is the incident's recorded creation date, or the outage start
  for a status-only row. It is not a separate, backdated business concern date.
  Historical spreadsheet concern dates not yet stored in the app are not inferred.
- Resolution dates and historical incident state are reconstructed from saved
  resolution timestamps and action logs. A reopen makes the issue active again.
  Current branch/device names are used; the report is not an immutable archived
  snapshot. Reimporting historical observations can affect regenerated reports.

All three formats enforce the user's branch access and cluster/branch intersection.
Responses are private/no-store. CSV formula prefixes are escaped and printable
HTML escapes user-entered remarks. Generation reads records without changing
camera status, incident state, or ticket state. Tests use guarded SQLite `:memory:`.

Verification: 16 targeted tests (259 assertions), TypeScript checking and the Vite
production build passed. Browser checks use synthetic records and block writes;
they cover the Reports entry, cluster/branch cascade, applied-filter exports,
generation, sidebar state and layouts from 320px to 1440px.
