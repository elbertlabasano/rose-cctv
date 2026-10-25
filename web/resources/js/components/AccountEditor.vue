<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import type { Account, Branch } from '../types';
import AppModal from './AppModal.vue';
import FormErrors from './FormErrors.vue';

const props = defineProps<{ open: boolean; account: Account | null; branches: Branch[] }>();

const emit = defineEmits<{ close: [] }>();

const form = useForm({
    name: '',
    email: '',
    idcode: '',
    password: '',
    role: 'viewer',
    active: true,
    all_branches: false,
    team: '',
    branch_ids: [] as number[],
    reason: '',
});

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.reset();
        form.clearErrors();
        if (props.account) {
            const a = props.account;
            form.name = a.name;
            form.email = a.email;
            form.idcode = a.idcode || '';
            form.role = a.role;
            form.active = a.active;
            form.all_branches = a.all_branches;
            form.team = a.team || '';
            form.branch_ids = a.branches.map((b) => b.id);
        }
    },
);

function save() {
    if (form.role === 'admin') form.all_branches = true;
    const options = {
        onSuccess: () => {
            form.reset('password');
            emit('close');
        },
    };
    props.account ? form.put(`/users/${props.account.id}`, options) : form.post('/users', options);
}
</script>
<template>
    <AppModal
        :open="open"
        :title="account ? 'Edit user' : 'Add user'"
        :busy="form.processing"
        @close="
            form.reset('password');
            emit('close');
        "
        ><form @submit.prevent="save">
            <FormErrors :errors="form.errors" />
            <div class="rw-form-grid">
                <label
                    >Full name *<input
                        v-model="form.name"
                        required
                        maxlength="255" /></label
                ><label
                    >Email *<input
                        v-model="form.email"
                        required
                        type="email"
                        maxlength="255" /></label
                ><label class="rw-full">
                    Employee ID code
                    <input
                        v-model="form.idcode"
                        maxlength="100"
                        autocomplete="off"
                    />
                    <small
                        >Use the exact code from the Rose account, including any leading
                        zeros.</small
                    > </label
                ><label class="rw-full"
                    >{{
                        account
                            ? 'New password (leave blank to keep current)'
                            : 'Initial password *'
                    }}<input
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                        :required="!account"
                        minlength="8"
                    /><small
                        >At least 8 characters. Share securely with the account holder.</small
                    ></label
                ><label
                    >Role<select v-model="form.role">
                        <option
                            v-for="role in [
                                'admin',
                                'supervisor',
                                'operator',
                                'technician',
                                'viewer',
                            ]"
                            :key="role"
                        >
                            {{ role }}
                        </option>
                    </select></label
                ><label
                    >Team<input
                        v-model="form.team"
                        maxlength="255"
                /></label>
            </div>
            <label class="rw-check"
                ><input
                    v-model="form.active"
                    type="checkbox"
                />Account active</label
            ><label
                v-if="form.role !== 'admin'"
                class="rw-check"
                ><input
                    v-model="form.all_branches"
                    type="checkbox"
                />Access to all branches</label
            >
            <fieldset v-if="!form.all_branches && form.role !== 'admin'">
                <legend>Assigned branches</legend>
                <label
                    v-for="b in branches"
                    :key="b.id"
                    class="rw-check"
                    ><input
                        v-model="form.branch_ids"
                        type="checkbox"
                        :value="b.id"
                    />{{ b.name }}</label
                >
            </fieldset>
            <p class="rw-note">
                Administrators manage all branches and users. Supervisors manage devices and verify
                incidents. Operators and technicians record checks and incident work. Viewers have
                read-only access.
            </p>
            <label
                >Reason for this change *<textarea
                    v-model="form.reason"
                    required
                    maxlength="2000"
                ></textarea>
            </label>
            <div class="rw-form-actions">
                <button
                    type="button"
                    class="rw-btn"
                    :disabled="form.processing"
                    @click="
                        form.reset('password');
                        emit('close');
                    "
                >
                    Cancel</button
                ><button
                    class="rw-btn primary"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Saving…' : 'Save account' }}
                </button>
            </div>
        </form></AppModal
    >
</template>
