<?php

namespace App\Services;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class DeviceSearchService
{
    public function __construct(private AccessService $access) {}

    public function search(User $user, string $term): array
    {
        abort_unless($user->active, 403);
        $normalized = Str::lower($term);
        // Use an explicit SQL escape character so % and _ remain literal on MySQL and SQLite.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $normalized);
        $contains = '%'.$escaped.'%';

        $devices = Device::whereIn('branch_id', $this->access->branches($user)->select('branches.id'))
            ->where(function (Builder $query) use ($contains) {
                $query->whereRaw("LOWER(asset_id) LIKE ? ESCAPE '!'", [$contains])
                    ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$contains])
                    ->orWhereRaw("LOWER(location) LIKE ? ESCAPE '!'", [$contains])
                    ->orWhereHas('branch', fn (Builder $branch) => $branch->where(fn (Builder $fields) => $fields
                        ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$contains])
                        ->orWhereRaw("LOWER(code) LIKE ? ESCAPE '!'", [$contains])));
            })
            ->with('branch:id,name')
            ->orderByRaw("CASE WHEN LOWER(asset_id) = ? THEN 0 WHEN LOWER(asset_id) LIKE ? ESCAPE '!' THEN 1 WHEN LOWER(name) = ? THEN 2 ELSE 3 END", [$normalized, $escaped.'%', $normalized])
            ->orderBy('asset_id')->limit(13)
            ->get(['id', 'asset_id', 'name', 'type', 'status', 'branch_id']);

        return [
            'devices' => $devices->take(12)->map(fn (Device $device) => [
                ...$device->only('id', 'asset_id', 'name', 'type', 'status'),
                'branch' => $device->branch->name,
                'url' => route('devices.show', $device, false),
            ])->values(),
            'has_more' => $devices->count() > 12,
        ];
    }
}
