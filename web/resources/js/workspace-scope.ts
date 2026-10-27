import type { Branch } from './types';

export function branchMatchesCluster(branch: Branch, selection: string): boolean {
    if (selection === 'all') return true;
    if (selection === 'clustered') return !!branch.cluster_id;
    if (selection === 'unassigned') return !branch.cluster_id;
    return branch.cluster_id === Number(selection);
}
