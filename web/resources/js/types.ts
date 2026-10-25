export interface Branch {
    id: number;
    name: string;
    code: string;
    address: string | null;
    active: boolean;
}

export interface Device {
    id: number;
    asset_id: string;
    name: string;
    branch_id: number;
    branch: Branch;
    type: string;
    status: string;
    status_observed_at: string | null;
    parent_id: number | null;
    location: string | null;
    brand: string | null;
    model: string | null;
    serial: string | null;
    ip: string | null;
    owner: string | null;
    channel: number | null;
    channels: number | null;
    storage_tb: number | null;
    disk_health: string | null;
    installed_at: string | null;
    warranty_at: string | null;
    notes: string | null;
}

export interface Evidence {
    id: number;
    name: string;
    mime: string;
    url: string;
    download_url: string;
    created_at: string;
}

export interface Ticket {
    id: number;
    title: string;
    status: string;
    external_id: string | null;
    external_url: string | null;
    error: string | null;
}

export interface Observation {
    id: number;
    device_id: number;
    date: string;
    status: string | null;
    notes: string | null;
    checked_at: string | null;
    checker: string | null;
    attachments: Evidence[];
    ticket: Ticket | null;
}

export interface IncidentLog {
    id: number;
    action: string;
    notes: string;
    actor: string | null;
    created_at: string;
    attachments: Evidence[];
    details: {
        from?: string;
        to?: string;
        resolution?: { cause: string; work: string; prevention: string | null };
        signed_document_url?: string;
    };
}

export interface Incident {
    id: number;
    reference: string;
    device_id: number;
    device: Device;
    branch_id: number;
    branch: Branch;
    assigned_to: number | null;
    assignee: { id: number; name: string } | null;
    title: string;
    description: string | null;
    priority: string;
    category: string;
    status: string;
    signed_document_url: string | null;
    due_at: string;
    created_at: string;
    resolved_at: string | null;
    restored_at: string | null;
    verified_at: string | null;
    resolution: { cause: string; work: string; prevention: string | null } | null;
    logs: IncidentLog[];
}

export interface Account {
    id: number;
    name: string;
    email: string;
    idcode?: string | null;
    role: string;
    active: boolean;
    all_branches: boolean;
    team: string | null;
    branches: Branch[];
}

export interface Workspace {
    today: string;
    date: string;
    branches: Branch[];
    devices: Device[];
    observations: Observation[];
    incidents: Incident[];
    outages: {
        id: number;
        device_id: number;
        started_at: string;
        restored_at: string | null;
        notes: string | null;
    }[];
    people: {
        id: number;
        name: string;
        role: string;
        all_branches: boolean;
        branches: { id: number }[];
    }[];
    followups: { subject_type: string; subject_id: number; snoozed_until: string | null }[];
    accounts: Account[];
    audits: {
        id: number;
        subject_type: string;
        subject_id: number;
        action: string;
        reason: string;
        created_at: string;
        user: { name: string } | null;
    }[];
    permissions: { write: boolean; manage: boolean; verify: boolean; admin: boolean };
    ticket_configured: boolean;
}
