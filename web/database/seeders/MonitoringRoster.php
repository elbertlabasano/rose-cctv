<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Cluster;
use RuntimeException;

class MonitoringRoster
{
    public static function clusters(): array
    {
        return json_decode(file_get_contents(__DIR__.'/data/october-2026-monitoring.json'), true, flags: JSON_THROW_ON_ERROR)['clusters'];
    }

    public static function branch(array $entry): ?Branch
    {
        $matches = Branch::where('code', $entry['code'])
            ->orWhereRaw('UPPER(TRIM(name)) = ?', [$entry['name']])->limit(2)->get();
        if ($matches->count() > 1) {
            throw new RuntimeException('Ambiguous existing branch for '.$entry['name'].'. Seeding was rolled back; resolve its name/code conflict first.');
        }

        return $matches->first();
    }

    public static function cluster(array $entry): ?Cluster
    {
        $matches = Cluster::where('code', $entry['code'])
            ->orWhereRaw('UPPER(TRIM(name)) = ?', [$entry['name']])->limit(2)->get();
        if ($matches->count() > 1) {
            throw new RuntimeException('Ambiguous existing cluster for '.$entry['name'].'. Seeding was rolled back; resolve its name/code conflict first.');
        }

        return $matches->first();
    }
}
