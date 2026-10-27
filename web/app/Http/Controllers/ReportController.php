<?php

namespace App\Http\Controllers;

use App\Models\Cluster;
use App\Models\Device;
use App\Models\IncidentReport;
use App\Models\Observation;
use App\Services\AccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function export(Request $request, AccessService $access)
    {
        $input = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'cluster_id' => ['bail', 'nullable', 'string', 'max:30', function ($attribute, $value, $fail) {
                if (! in_array($value, ['all', 'clustered', 'unassigned'], true)
                    && (! ctype_digit($value) || ! Cluster::whereKey($value)->exists())) {
                    $fail('Choose a valid cluster.');
                }
            }],
            'format' => ['required', 'in:html,csv'],
        ]);
        $branches = $access->branches($request->user());
        $cluster = $input['cluster_id'] ?? 'all';
        if ($cluster === 'clustered') {
            $branches->whereNotNull('cluster_id');
        } elseif ($cluster === 'unassigned') {
            $branches->whereNull('cluster_id');
        } elseif ($cluster !== 'all') {
            abort_unless($request->user()->role === 'admin' || (clone $branches)->where('cluster_id', $cluster)->exists(), 403);
            $branches->where('cluster_id', $cluster);
        }
        if (! empty($input['branch_id'])) {
            $access->branch($request->user(), $input['branch_id']);
            if (! (clone $branches)->whereKey($input['branch_id'])->exists()) {
                throw ValidationException::withMessages(['branch_id' => 'Choose a branch in the selected cluster.']);
            }
            $branches->whereKey($input['branch_id']);
        }
        $ids = $branches->pluck('id');
        $devices = Device::whereIn('branch_id', $ids)->whereIn('type', ['IP Camera', 'Analog Camera'])->with('branch')->get();
        $records = Observation::whereIn('device_id', $devices->pluck('id'))->whereDate('date', $input['date'])->with(['attachments', 'ticket'])->get()->keyBy('device_id');
        $incidents = IncidentReport::whereIn('branch_id', $ids)->whereDate('created_at', '<=', $input['date'])->with(['assignee:id,name', 'branch:id,name'])->get();
        if ($input['format'] === 'csv') {
            return response()->streamDownload(function () use ($devices, $records, $input) {
                $stream = fopen('php://output', 'w');
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, ['Date', 'Camera', 'Branch', 'Status', 'Notes', 'Images', 'Ticket URL']);
                foreach ($devices as $device) {
                    $r = $records->get($device->id);
                    $cells = [$input['date'], $device->asset_id, $device->branch->name, $r?->displayLabel() ?? 'Not checked', $r?->notes ?? '', $r?->attachments->count() ?? 0, $r?->ticket?->external_url ?? ''];
                    fputcsv($stream, array_map(fn ($v) => preg_match('/^\s*[=+@-]/', (string) $v) ? "'".$v : $v, $cells));
                }
                fclose($stream);
            }, 'RoseWatch-'.$input['date'].'.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'private, no-store']);
        }
        $image = function ($a) {
            if (! str_starts_with($a->mime, 'image/')) {
                return '';
            }

            return 'data:'.$a->mime.';base64,'.base64_encode(Storage::disk($a->disk)->get($a->path));
        };

        return response()->view('report', compact('devices', 'records', 'incidents', 'input', 'image'))->header('Content-Type', 'text/html; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="RoseWatch-'.$input['date'].'.html"')->header('Cache-Control', 'private, no-store');
    }
}
