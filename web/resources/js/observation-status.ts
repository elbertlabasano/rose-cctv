import type { Observation } from './types';

export function hasStatusCheck(record: Observation | null | undefined): boolean {
    return !!record && (record.is_checked ?? !!record.checked_at);
}

export function observationStatus(record: Observation | null | undefined): string {
    return record && hasStatusCheck(record) && record.status
        ? record.status
        : record?.check_result || 'unchecked';
}

export function observationLabel(record: Observation | null | undefined): string {
    const status = observationStatus(record);
    return status === 'unchecked' ? 'Not checked' : status.replaceAll('_', ' ');
}
