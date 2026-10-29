<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>RoseWatch Branch Issues Report {{ $report['date'] }}</title>
    <style>
        body { font: 13px/1.5 Arial, sans-serif; color: #452626; padding: 24px; margin: 0; }
        h1 { font-size: 22px; color: #5d1616; margin-bottom: 8px; }
        .instructions { background: #fff2d9; padding: 14px; border: 1px solid #e5c691; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 24px; }
        th, td { border: 1px solid #d8cec3; padding: 9px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        th { background: #f5e2c6; color: #5d1616; }
        .online { background: #e3f2e9; color: #215d47; }
        .offline { background: #fbe1de; color: #a12a22; }
        .degraded, .maintenance { background: #fff0cf; }
        small { display: block; margin-top: 5px; }
        .remarks { white-space: pre-wrap; }
        footer { margin-top: 20px; font-size: 11px; color: #69584f; }
        @page { size: A4 landscape; margin: 12mm; }
        @media print { body { padding: 0; font-size: 10px; } .instructions { display: none; } thead { display: table-header-group; } tr { break-inside: avoid; } th, td { padding: 6px; } * { print-color-adjust: exact; -webkit-print-color-adjust: exact; } }
    </style>
</head>
<body>
    <p class="instructions">Use your browser's Print command (Ctrl+P) and choose Save as PDF or a printer. This report is formatted for landscape pages.</p>
    <h1>CCTV Operation Monitoring · Branch Issues</h1>
    <p>Report date: <strong>{{ $report['date'] }}</strong> · Generated {{ $report['generated_at'] }}<br>{{ $report['scope'] }} · {{ $report['summary']['branches'] }} branches · {{ $report['summary']['devices'] }} devices · {{ $report['summary']['issues'] }} concerns<br>{{ $report['filters']['include_resolved'] ? 'Includes concerns resolved on this date.' : 'Active concerns and status issues only.' }}</p>
    <table>
        <colgroup><col style="width:17%"><col style="width:11%"><col style="width:11%"><col style="width:7%"><col style="width:34%"><col style="width:10%"><col style="width:10%"></colgroup>
        <thead><tr><th>Branch / device</th><th>Cluster</th><th>Status</th><th>Days offline</th><th>Remarks</th><th>Date of concern</th><th>Date resolved</th></tr></thead>
        <tbody>
        @forelse($report['rows'] as $row)
            <tr><td><strong>{{ $row['branch'] }}</strong><small>{{ $row['asset_id'] }} · {{ $row['device'] }}</small></td><td>{{ $row['cluster'] }}</td><td class="{{ in_array($row['status'], ['online', 'offline', 'degraded', 'maintenance']) ? $row['status'] : '' }}">{{ $row['status'] === 'unchecked' ? 'Not checked' : ucfirst($row['status']) }}@if($row['status_date'] && $row['status_date'] !== $report['date'])<small>Last recorded {{ $row['status_date'] }}</small>@endif</td><td>{{ $row['days_offline'] ?? ($row['status'] === 'offline' ? 'Unknown' : '—') }}</td><td class="remarks">{{ $row['remarks'] }}@if($row['reference'])<small>{{ $row['reference'] }} · {{ str_replace('_', ' ', $row['issue_state']) }}</small>@endif</td><td>{{ $row['concern_on'] ?? 'Unknown' }}</td><td>{{ $row['resolved_on'] ?? '—' }}</td></tr>
        @empty
            <tr><td colspan="7">No recorded issues in this scope. This does not mean every camera has been checked.</td></tr>
        @endforelse
        </tbody>
    </table>
    <footer><p>Status is the latest recorded state on or before the selected date. Days offline is an inclusive calendar-day count from a recorded outage start; missing starts remain Unknown.</p><p>Date of concern is the incident record date or recorded outage start. Incident state and action notes are read as of the selected day. Online devices can have active concerns. One branch can have several device or incident rows.</p></footer>
</body>
</html>
