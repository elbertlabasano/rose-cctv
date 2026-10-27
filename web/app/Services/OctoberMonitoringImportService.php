<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Observation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OctoberMonitoringImportService
{
    public function snapshot(): array
    {
        return json_decode(file_get_contents(database_path('seeders/data/october-2026-history.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function run(bool $dryRun = false, bool $initializeStatus = false): array
    {
        return DB::transaction(function () use ($dryRun, $initializeStatus) {
            $source = $this->snapshot();
            $today = now('Asia/Manila')->toDateString();
            $result = ['branches' => 0, 'imported' => 0, 'preserved' => 0, 'future_skipped' => 0, 'statuses_initialized' => 0, 'dry_run' => $dryRun];
            $firstImported = null;
            foreach ($source['branches'] as $entry) {
                $branches = Branch::where('code', $entry['branch_code'])->orWhereRaw('UPPER(TRIM(name)) = ?', [$entry['branch_name']])->limit(2)->get();
                if ($branches->count() !== 1) {
                    throw new RuntimeException('Expected exactly one seeded branch for '.$entry['branch_name'].'. No history was imported.');
                }
                $cameras = Device::where('branch_id', $branches->first()->id)->where('type', 'IP Camera')->lockForUpdate()->limit(2)->get();
                if ($cameras->count() !== 1) {
                    throw new RuntimeException('Expected one IP Camera for '.$entry['branch_name'].'. Resolve the inventory before importing history.');
                }
                $device = $cameras->first();
                $result['branches']++;
                $existing = $device->observations()->whereBetween('date', ['2026-10-01', '2026-10-31'])->get()->keyBy(fn ($row) => $row->date->toDateString());
                $latest = null;
                $days = $entry['days'];
                ksort($days);
                foreach ($days as $date => $cell) {
                    if (! preg_match('/\A2026-10-(0[1-9]|[12][0-9]|3[01])\z/D', $date)) {
                        throw new RuntimeException('The import snapshot contains an invalid October date.');
                    }
                    if ($date > $today) {
                        $result['future_skipped']++;

                        continue;
                    }
                    $value = $cell['value'];
                    $status = match ($value) {
                        'ON' => 'online', 'OFF' => 'offline', default => null
                    };
                    $checkResult = match ($value) {
                        'ON', 'OFF' => null, 'DAYOFF' => 'day_off', 'HOLIDAY' => 'holiday',
                        'NO DEVICE' => 'no_device', 'ALERT' => 'alert',
                        default => throw new RuntimeException('Unrecognized status in the import snapshot.'),
                    };
                    if ($existing->has($date)) {
                        $result['preserved']++;
                        // Never let a conflicting worksheet entry override a manual record.
                        if ($status && $existing[$date]->import_source === $source['source'] && $existing[$date]->status === $status) {
                            $latest = ['date' => $date, 'status' => $status];
                        }

                        continue;
                    }
                    $result['imported']++;
                    if ($status) {
                        $latest = ['date' => $date, 'status' => $status];
                    }
                    if (! $dryRun) {
                        $record = Observation::create([
                            'device_id' => $device->id, 'date' => $date, 'status' => $status,
                            'check_result' => $checkResult, 'import_source' => $source['source'],
                            'import_reference' => $source['sheet'].'!'.$cell['cell'],
                            'notes' => 'Imported from '.$source['source'].' ('.$source['sheet'].'!'.$cell['cell'].'). Reported value: '.$value.'. Check time and operator were not supplied.',
                            'checked_at' => null, 'checked_by' => null,
                        ]);
                        $firstImported ??= $record->id;
                    }
                }
                if ($initializeStatus && $latest && $device->status === 'unknown' && $device->status_observed_at === null
                    && ! $device->observations()->whereNotNull('checked_at')->exists()) {
                    $result['statuses_initialized']++;
                    if (! $dryRun) {
                        $device->update(['status' => $latest['status'], 'status_reported_on' => $latest['date']]);
                    }
                }
            }
            if (! $dryRun && ($result['imported'] > 0 || $result['statuses_initialized'] > 0)) {
                AuditLog::create([
                    'subject_type' => 'Observation', 'subject_id' => $firstImported ?? 0,
                    'action' => 'Historical monitoring imported',
                    'reason' => 'Authorized one-time October 2026 worksheet import; existing records preserved.',
                    'changes' => ['source' => $source['source'], 'sha256' => $source['sha256'], ...$result],
                ]);
            }

            return $result;
        });
    }
}
