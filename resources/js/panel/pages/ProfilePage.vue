<script setup>
import { onMounted, ref } from 'vue';
import NButton from '../components/ui/NButton.vue';
import NCard from '../components/ui/NCard.vue';
import NField from '../components/ui/NField.vue';
import NIcon from '../components/ui/NIcon.vue';
import NInput from '../components/ui/NInput.vue';
import NPageHeader from '../components/ui/NPageHeader.vue';
import { api, toFormData } from '../api';
import { useForm } from '../composables/useForm';
import { useSession } from '../stores/session';
import { useUi } from '../stores/ui';

const session = useSession();
const ui = useUi();

const ready = ref(false);
const roles = ref([]);
const avatar = ref(null);
const avatarRemoved = ref(false);
const existingAvatar = ref(null);

const form = useForm({ name: '', login: '', email: '', phone: '' });
const passwordForm = useForm({ current_password: '', password: '', password_confirmation: '' });

function fill(user) {
    form.fill({
        name: user.name,
        login: user.login ?? '',
        email: user.email,
        phone: user.phone ?? '',
    });

    roles.value = user.roles ?? [];
    existingAvatar.value = user.avatar_url;
    avatar.value = null;
    avatarRemoved.value = false;
}

async function save() {
    const payload = toFormData({ ...form.fields });

    if (avatar.value) {
        payload.append('avatar', avatar.value);
    }

    if (avatarRemoved.value) {
        payload.append('avatar_remove', '1');
    }

    payload.append('_method', 'PUT');

    const data = await form.submit('post', 'profile', { body: payload, files: true });

    if (data) {
        fill(data.data);
        // Имя и аватар видны в шапке — обновляем их сразу.
        session.user = { ...session.user, ...data.data };
    }
}

async function changePassword() {
    const data = await passwordForm.submit('put', 'profile/password');

    if (data) {
        passwordForm.reset();
    }
}

function pickAvatar(event) {
    avatar.value = event.target.files?.[0] ?? null;
    avatarRemoved.value = false;
}

onMounted(async () => {
    try {
        fill((await api.get('profile')).data);
    } catch (error) {
        ui.notifyError(error);
    } finally {
        ready.value = true;
    }
});
</script>

<template>
    <div v-if="ready">
        <NPageHeader title="Профиль" description="Ваши данные для входа и связи." />

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <form @submit.prevent="save">
                    <NCard title="Основное">
                        <div class="grid gap-5 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <NField label="Имя" required :error="form.error('name')">
                                    <NInput v-model="form.fields.name" :invalid="Boolean(form.error('name'))" />
                                </NField>
                            </div>

                            <NField label="Логин" required hint="Им можно входить вместо e-mail"
                                    :error="form.error('login')">
                                <NInput v-model="form.fields.login" class="font-mono"
                                        :invalid="Boolean(form.error('login'))" />
                            </NField>

                            <NField label="E-mail" required :error="form.error('email')">
                                <NInput v-model="form.fields.email" type="email"
                                        :invalid="Boolean(form.error('email'))" />
                            </NField>

                            <NField label="Телефон" :error="form.error('phone')">
                                <NInput v-model="form.fields.phone" placeholder="+7 900 000-00-00" />
                            </NField>

                            <NField label="Аватар" :error="form.error('avatar')">
                                <div class="flex items-center gap-3">
                                    <img v-if="existingAvatar && !avatarRemoved && !avatar" :src="existingAvatar" alt=""
                                         class="size-10 rounded-full object-cover">

                                    <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-[var(--surface-border-strong)] px-3 py-2 text-sm font-medium text-[var(--text-base)] transition hover:bg-[var(--surface-muted)]">
                                        <NIcon name="upload" size="size-4" />
                                        Выбрать
                                        <input type="file" accept="image/*" class="sr-only" @change="pickAvatar">
                                    </label>

                                    <span class="truncate text-xs text-[var(--text-muted)]">{{ avatar?.name ?? '' }}</span>

                                    <button v-if="(existingAvatar && !avatarRemoved) || avatar" type="button"
                                            class="text-xs font-medium text-red-600 hover:underline dark:text-red-400"
                                            @click="avatar = null; avatarRemoved = true">
                                        убрать
                                    </button>
                                </div>
                            </NField>
                        </div>

                        <div class="mt-6">
                            <NButton type="submit" :loading="form.busy.value">Сохранить</NButton>
                        </div>
                    </NCard>
                </form>

                <form @submit.prevent="changePassword">
                    <NCard title="Пароль" description="Для смены нужен текущий пароль.">
                        <div class="grid gap-5 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <NField label="Текущий пароль" required :error="passwordForm.error('current_password')">
                                    <NInput v-model="passwordForm.fields.current_password" type="password"
                                            autocomplete="current-password"
                                            :invalid="Boolean(passwordForm.error('current_password'))" />
                                </NField>
                            </div>

                            <NField label="Новый пароль" required :error="passwordForm.error('password')">
                                <NInput v-model="passwordForm.fields.password" type="password" autocomplete="new-password"
                                        :invalid="Boolean(passwordForm.error('password'))" />
                            </NField>

                            <NField label="Повторите пароль" required>
                                <NInput v-model="passwordForm.fields.password_confirmation" type="password"
                                        autocomplete="new-password" />
                            </NField>
                        </div>

                        <div class="mt-6">
                            <NButton type="submit" :loading="passwordForm.busy.value">Сменить пароль</NButton>
                        </div>
                    </NCard>
                </form>
            </div>

            <div>
                <NCard title="Роли" description="Права меняет администратор в разделе «Пользователи».">
                    <p v-if="session.isSuperAdmin" class="text-sm text-[var(--text-base)]">Супер-администратор — доступно всё.</p>

                    <ul v-else-if="roles.length" class="space-y-2">
                        <li v-for="role in roles" :key="role.id" class="text-sm">
                            <span class="font-medium text-[var(--text-strong)]">{{ role.name }}</span>
                            <span v-if="role.description" class="mt-0.5 block text-xs text-[var(--text-muted)]">
                                {{ role.description }}
                            </span>
                        </li>
                    </ul>

                    <p v-else class="text-sm text-[var(--text-muted)]">Роли не назначены.</p>
                </NCard>
            </div>
        </div>
    </div>
</template>
