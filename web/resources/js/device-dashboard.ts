import type { Device, Evidence, Observation, Ticket } from './types';

export interface HistoryPage<T> {
    data: T[];
    total: number;
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
}

export interface DeviceDay {
    date: string;
    status: string;
    downtime_minutes: number;
    record: Observation | null;
}

export interface DeviceTicket extends Ticket {
    created_at: string;
    observation: Observation;
}

export interface DeviceIncident {
    id: number;
    reference: string;
    title: string;
    description: string | null;
    status: string;
    priority: string;
    category: string;
    signed_document_url: string | null;
    resolution: { cause: string; work: string; prevention: string | null } | null;
    created_at: string;
    assignee: string | null;
    url: string;
}

export interface DeviceEvidence extends Evidence {
    source: string;
    record_date: string | null;
    incident_reference: string | null;
}

export interface DeviceOutage {
    id: number;
    started_at: string;
    restored_at: string | null;
    minutes: number;
    notes: string | null;
}

export interface DeviceActivity {
    id: number;
    action: string;
    reason: string;
    actor: string;
    created_at: string;
}

export interface DeviceStatusChange {
    id: number;
    from: string | null;
    to: string;
    actor: string;
    created_at: string;
}

export type RelatedDevice = Pick<Device, 'id' | 'asset_id' | 'name' | 'type' | 'status'>;

export interface DeviceDashboardData {
    device: Device;
    parent: RelatedDevice | null;
    children: RelatedDevice[];
    today: string;
    tab: string;
    period: { from: string; to: string };
    counts: {
        status: number;
        status_changes: number;
        tickets: number;
        confirmed_tickets: number;
        incidents: number;
        active_incidents: number;
        evidence: number;
        outages: number;
        open_outages: number;
        activity?: number;
    };
    days: DeviceDay[];
    history: {
        status?: HistoryPage<Observation>;
        status_changes?: HistoryPage<DeviceStatusChange>;
        tickets?: HistoryPage<DeviceTicket>;
        incidents?: HistoryPage<DeviceIncident>;
        evidence?: HistoryPage<DeviceEvidence>;
        outages?: HistoryPage<DeviceOutage>;
        activity?: HistoryPage<DeviceActivity>;
    };
    can_audit: boolean;
    can_check: boolean;
}
