<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { onUnmounted, ref } from 'vue';
import AppLayout from '../../layouts/AppLayout.vue';

const props = defineProps<{
    csrf_token: string;
    backups: {
        available: boolean;
        attachments_available: boolean;
        attachment_count: number;
        attachment_bytes: number;
        drive_folder_url: string | null;
        recent_exports: {
            id: number;
            type: 'database' | 'attachments';
            actor: string;
            created_at: string;
            filename: string | null;
            bytes: number | null;
        }[];
    };
}>();

const zipBusy = ref(false);
const zipError = ref('');
const zipUrl = ref('');
const zipName = ref('RoseWatch-attachments.zip');
let zipRequest: AbortController | null = null;
let active = true;

async function downloadAttachments() {
    if (zipBusy.value || !props.backups.attachments_available) return;
    zipBusy.value = true;
    zipError.value = '';
    if (zipUrl.value) URL.revokeObjectURL(zipUrl.value);
    zipUrl.value = '';
    zipRequest = new AbortController();
    try {
        const response = await axios.post(
            '/backups/attachments/download',
            { _token: props.csrf_token },
            {
                responseType: 'blob',
                signal: zipRequest.signal,
                headers: { Accept: 'application/json' },
            },
        );
        if (!active) return;
        const blob = response.data as Blob;
        const signature = new Uint8Array(await blob.slice(0, 4).arrayBuffer());
        if (
            !String(response.headers['content-type']).includes('application/zip') ||
            signature[0] !== 80 ||
            signature[1] !== 75 ||
            signature[2] !== 3 ||
            signature[3] !== 4
        ) {
            throw new Error('No valid ZIP was received.');
        }
        if (!active) return;
        const disposition = String(response.headers['content-disposition'] || '');
        const filename = disposition.match(/filename="?([^";]+)"?/i)?.[1];
        zipName.value =
            filename && /^RoseWatch-attachments-[A-Za-z0-9_-]+\.zip$/.test(filename)
                ? filename
                : 'RoseWatch-attachments.zip';
        zipUrl.value = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = zipUrl.value;
        link.download = zipName.value;
        document.body.appendChild(link);
        link.click();
        link.remove();
        router.reload({ only: ['backups'] });
    } catch (failure) {
        if (!active || axios.isCancel(failure)) return;
        const response = axios.isAxiosError(failure) ? failure.response : null;
        if (response?.status === 401 || response?.status === 419) {
            zipError.value = 'Your session expired. Refresh the page and sign in again.';
        } else if (response?.status === 403) {
            zipError.value = 'Only active administrators can download backups.';
        } else if (response?.status === 429) {
            zipError.value = 'Too many backup requests. Wait a minute and try again.';
        } else {
            zipError.value = 'No ZIP was received. Please try again or contact your administrator.';
            if (
                response?.status === 503 &&
                response.data instanceof Blob &&
                response.data.size < 64000
            ) {
                try {
                    const result = JSON.parse(await response.data.text());
                    if (typeof result.message === 'string') zipError.value = result.message;
                } catch {
                    /* Retain the safe fallback when the response is not JSON. */
                }
            }
        }
    } finally {
        if (active) zipBusy.value = false;
    }
}

onUnmounted(() => {
    active = false;
    zipRequest?.abort();
    if (zipUrl.value) URL.revokeObjectURL(zipUrl.value);
});

function dateLabel(value: string) {
    return `${new Date(value).toLocaleString('en-PH', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Manila',
    })} PHT`;
}

function sizeLabel(bytes: number | null) {
    if (bytes === null) return '—';
    return bytes < 1024 * 1024
        ? `${(bytes / 1024).toFixed(1)} KB`
        : `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
</script>

<template>
    <Head title="Manual backup" />
    <AppLayout
        section="backups"
        title="Manual backup"
    >
        <div class="rw-page-heading">
            <div>
                <span class="form-eyebrow">ADMINISTRATION</span>
                <h1>Keep a copy, just in case.</h1>
                <p>Download your database and attachments, then save both files in Google Drive.</p>
            </div>
        </div>

        <section
            class="backup-card"
            aria-labelledby="backup-steps-title"
        >
            <header class="backup-card-head">
                <div>
                    <h2 id="backup-steps-title">Manual backup</h2>
                    <p>Three steps. You choose when to make a copy.</p>
                </div>
                <span class="backup-badge">SQL + attachment ZIP</span>
            </header>

            <ol class="backup-steps">
                <li>
                    <span
                        class="backup-step-number"
                        aria-hidden="true"
                        >1</span
                    >
                    <div class="backup-step-copy">
                        <h3>Download both backup files</h3>
                        <p>
                            Save the database as SQL and your screenshots and uploaded files as ZIP.
                        </p>
                    </div>
                    <div class="backup-downloads">
                        <!-- A normal POST lets the browser stream the attachment without holding it in Vue. -->
                        <form
                            method="post"
                            action="/backups/download"
                        >
                            <input
                                type="hidden"
                                name="_token"
                                :value="csrf_token"
                            />
                            <button
                                class="rw-btn primary"
                                :disabled="!backups.available"
                            >
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.7"
                                    aria-hidden="true"
                                >
                                    <path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5" />
                                </svg>
                                Download SQL
                            </button>
                        </form>
                        <small v-if="backups.available"
                            >Your download starts when the export is ready.</small
                        >
                        <small
                            v-else
                            role="status"
                            >SQL export is unavailable. Ask your administrator to check backup
                            setup.</small
                        >
                        <form
                            method="post"
                            action="/backups/attachments/download"
                            :aria-busy="zipBusy"
                            @submit.prevent="downloadAttachments"
                        >
                            <input
                                type="hidden"
                                name="_token"
                                :value="csrf_token"
                            />
                            <button
                                class="rw-btn"
                                :disabled="!backups.attachments_available || zipBusy"
                            >
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.7"
                                    aria-hidden="true"
                                >
                                    <path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5" />
                                </svg>
                                {{ zipBusy ? 'Preparing ZIP…' : 'Download attachments ZIP' }}
                            </button>
                        </form>
                        <small
                            v-if="zipBusy"
                            role="status"
                            >Preparing your attachments. Please keep this page open.</small
                        >
                        <small
                            v-if="zipError"
                            class="backup-download-error"
                            role="alert"
                            >{{ zipError }}</small
                        >
                        <div
                            v-if="zipUrl"
                            class="backup-ready"
                            role="status"
                        >
                            <small
                                >ZIP ready. Check your browser’s downloads, or save it here:</small
                            >
                            <a
                                class="rw-btn"
                                :href="zipUrl"
                                :download="zipName"
                                >Save ZIP file</a
                            >
                        </div>
                        <small v-if="backups.attachments_available">
                            {{ backups.attachment_count }} registered
                            {{ backups.attachment_count === 1 ? 'attachment' : 'attachments' }} ·
                            {{ sizeLabel(backups.attachment_bytes) }} before compression.
                            Preparation can take a moment.
                        </small>
                        <small
                            v-else
                            role="status"
                        >
                            Attachment backup is unavailable. Ask your administrator to check ZIP
                            and local storage setup.
                        </small>
                    </div>
                </li>
                <li>
                    <span
                        class="backup-step-number"
                        aria-hidden="true"
                        >2</span
                    >
                    <div class="backup-step-copy">
                        <h3>Open the backup folder</h3>
                        <p>Open Google Drive using an account that can access this folder.</p>
                    </div>
                    <a
                        v-if="backups.drive_folder_url"
                        class="rw-btn"
                        :href="backups.drive_folder_url"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        Open Drive folder
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                            aria-hidden="true"
                        >
                            <path d="M14 4h6v6m0-6L10 14M10 4H4v16h16v-6" />
                        </svg>
                    </a>
                    <small v-else>No backup folder has been set yet.</small>
                    <small v-if="backups.drive_folder_url">Opens in a new tab.</small>
                </li>
                <li>
                    <span
                        class="backup-step-number"
                        aria-hidden="true"
                        >3</span
                    >
                    <div class="backup-step-copy">
                        <h3>Upload the SQL and ZIP files</h3>
                        <p>
                            In the Drive folder, choose <strong>New → File upload</strong> and
                            select both files you just downloaded. Keep the pair together in the
                            same backup folder.
                        </p>
                    </div>
                    <div class="backup-upload-tip">
                        Wait for Google Drive to show that the upload has finished.
                    </div>
                </li>
            </ol>

            <div class="backup-scope">
                <strong>What is included?</strong>
                <p>
                    <strong>SQL:</strong> all database records, including users, branches, devices,
                    checks, incidents and ticket references.
                </p>
                <p>
                    <strong>ZIP:</strong> registered screenshots and uploaded incident files from
                    all branches, with a recovery manifest and checksums. External signed-document
                    links are saved in SQL; the documents behind those links are not downloaded.
                </p>
                <p>
                    Download both files close together and avoid changing attachments while backups
                    are being prepared. Keep them in a restricted Drive folder. A ZIP with missing
                    files will fail instead of downloading an incomplete backup.
                </p>
                <p>
                    Google Drive uploads are manual. RoseWatch does not upload, verify uploads or
                    run scheduled backups.
                </p>
            </div>
        </section>

        <section
            class="backup-card backup-history"
            aria-labelledby="backup-history-title"
        >
            <header class="backup-card-head">
                <div>
                    <h2 id="backup-history-title">Recent backup exports</h2>
                    <p>
                        Prepared downloads only. This list does not confirm that a file was saved to
                        Drive.
                    </p>
                </div>
                <button
                    type="button"
                    class="rw-btn"
                    @click="router.reload({ only: ['backups'] })"
                >
                    Refresh list
                </button>
            </header>
            <div
                v-if="backups.recent_exports.length"
                class="backup-table-wrap"
            >
                <table class="backup-table">
                    <thead>
                        <tr>
                            <th scope="col">Prepared</th>
                            <th scope="col">Backup file</th>
                            <th scope="col">Type</th>
                            <th scope="col">Size</th>
                            <th scope="col">Administrator</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="entry in backups.recent_exports"
                            :key="entry.id"
                        >
                            <td>{{ dateLabel(entry.created_at) }}</td>
                            <td class="backup-filename">
                                {{ entry.filename || 'Backup export' }}
                            </td>
                            <td>
                                {{
                                    entry.type === 'attachments'
                                        ? 'Attachments ZIP'
                                        : 'Database SQL'
                                }}
                            </td>
                            <td>{{ sizeLabel(entry.bytes) }}</td>
                            <td>{{ entry.actor }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                v-else
                class="backup-empty"
            >
                <strong>No backup exports yet</strong>
                <p>Use the download buttons above to prepare your first backup.</p>
            </div>
        </section>
    </AppLayout>
</template>

<style scoped>
.backup-card {
    overflow: hidden;
    background: #fff;
    border: 1px solid #ecddcf;
    border-radius: 22px;
}
.backup-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 22px 24px;
    border-bottom: 1px solid #ecddcf;
}
.backup-card h2 {
    margin: 0;
    font-size: 17px;
    color: #591f1b;
}
.backup-card p {
    margin: 7px 0 0;
    line-height: 1.7;
    color: #806b65;
}
.backup-badge {
    flex: none;
    padding: 7px 12px;
    border-radius: 18px;
    background: #fbefdf;
    color: #794421;
    font-size: 12px;
    font-weight: 600;
}
.backup-steps {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 28px;
    margin: 0;
    padding: 28px 24px;
    list-style: none;
}
.backup-steps li {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 14px;
    min-width: 0;
}
.backup-step-number {
    display: grid;
    place-items: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #fbefdf;
    color: #632820;
    font-weight: 700;
}
.backup-step-copy {
    flex: 1;
}
.backup-steps h3 {
    margin: 0;
    font-size: 15px;
    color: #591f1b;
}
.backup-steps form {
    margin: 0;
}
.backup-downloads {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
    width: 100%;
}
.backup-downloads form,
.backup-downloads .rw-btn {
    max-width: 100%;
}
.backup-download-error {
    color: #ad2f2a !important;
}
.backup-ready {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
}
.backup-downloads .rw-btn {
    text-align: left;
    white-space: normal;
}
.backup-steps svg {
    width: 19px;
    height: 19px;
    flex: none;
}
.backup-steps small {
    color: #806b65;
    font-size: 12px;
    line-height: 1.6;
}
.backup-upload-tip {
    padding: 12px 14px;
    border: 1px dashed #dac5b5;
    border-radius: 12px;
    color: #765e52;
    font-size: 13px;
    line-height: 1.6;
}
.backup-scope {
    padding: 20px 24px;
    background: #fdf8f2;
    border-top: 1px solid #ecddcf;
}
.backup-scope > strong {
    font-size: 14px;
    color: #591f1b;
}
.backup-history {
    margin-top: 24px;
}
.backup-table-wrap {
    overflow-x: auto;
}
.backup-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.backup-table th,
.backup-table td {
    padding: 15px 24px;
    text-align: left;
    border-bottom: 1px solid #f0e5dc;
}
.backup-table th {
    background: #fdf8f2;
    color: #806b65;
    font-size: 12px;
}
.backup-table tbody tr:last-child td {
    border-bottom: none;
}
.backup-filename {
    overflow-wrap: anywhere;
    min-width: 190px;
}
.backup-empty {
    padding: 30px 24px;
}
.backup-empty > strong {
    font-size: 14px;
}
@media (max-width: 1000px) {
    .backup-steps {
        grid-template-columns: 1fr;
        gap: 24px;
    }
    .backup-steps li + li {
        padding-top: 24px;
        border-top: 1px solid #ecddcf;
    }
}
@media (max-width: 550px) {
    .backup-card-head {
        align-items: flex-start;
        flex-wrap: wrap;
        padding: 20px;
    }
    .backup-steps,
    .backup-scope {
        padding: 22px 20px;
    }
}
</style>
