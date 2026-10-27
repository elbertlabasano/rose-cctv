<!doctype html><html lang="en"><head><meta charset="utf-8"><title>RoseWatch daily report</title><style>body{font:13px Arial;color:#452626;max-width:1100px;margin:30px auto;padding:20px}h1,h2{color:#5d1616}article{border:1px solid #eaded3;border-radius:12px;padding:20px;margin:18px 0;break-inside:avoid}img{max-width:300px;max-height:230px;margin:8px}p{white-space:pre-wrap}a{overflow-wrap:anywhere}</style></head><body>
<h1>RoseWatch daily handover</h1><p>{{ $input['date'] }} · Generated {{ now()->format('d M Y H:i') }} Philippine time</p><p>Camera results are saved observations for the selected day. Incident states are current, not historical snapshots.</p>
@foreach($devices as $device) @php($r=$records->get($device->id))
<article><h2>{{ $device->asset_id }} · {{ $device->name }}</h2><p>{{ $device->branch->name }} · {{ $r?->displayLabel() ?? 'Not checked' }}</p><p>{{ $r?->notes ?? 'No saved observation.' }}</p>
@if($r?->ticket?->external_url)<a href="{{ $r->ticket->external_url }}" rel="noopener noreferrer">External ticket {{ $r->ticket->external_id }}</a>@endif
@foreach($r?->attachments ?? [] as $attachment)<img src="{{ $image($attachment) }}" alt="{{ $attachment->name }}">@endforeach</article>
@endforeach
<h2>Incident reports</h2>@forelse($incidents as $incident)<article><h3>{{ $incident->reference }} · {{ $incident->title }}</h3><p>{{ $incident->branch->name }} · {{ $incident->status }} · {{ $incident->assignee?->name ?? 'Unassigned' }}</p><p>{{ $incident->description }}</p>@if($incident->signed_document_url)<a href="{{ $incident->signed_document_url }}" rel="noopener noreferrer">Signed incident document</a>@endif</article>@empty<p>No matching incidents.</p>@endforelse
</body></html>
