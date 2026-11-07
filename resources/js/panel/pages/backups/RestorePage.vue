<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import NButton from '../../components/ui/NButton.vue';
import NCard from '../../components/ui/NCard.vue';
import NEmpty from '../../components/ui/NEmpty.vue';
import NIcon from '../../components/ui/NIcon.vue';
import NPageHeader from '../../components/ui/NPageHeader.vue';
import NToggle from '../../components/ui/NToggle.vue';
import { api } from '../../api';
import { useUi } from '../../stores/ui';

const ui = useUi();

/** Токен шагов живёт в вкладке: после перезагрузки страницы шаги продолжатся. */
const TOKEN_KEY = 'nexor.restore.token';
const CHUNK = 1024 * 1024;

const loading = ref(true);
const backups = ref([]);
const restore = ref(null);
const stepUrl = ref(null);
const selected = ref(null);
const working = ref(false);
const upload = ref(null);
let stopped = false;

const options = reactive({ files: true, database: true, env: false, vendor: false });

const ready = computed(() => backups.value.filter((backup) => backup.status === 'done'));
const chosen = computed(() => ready.value.find((backup) => backup.id === selected.value) ?? null);
const active = computed(() => restore.value && ['running', 'stalled'].includes(restore.value.status));

const phases = {
    extract: 'Распаковываю файлы',
    database: 'Восстанавливаю базу',
    finish: 'Сбрасываю кеши',
    done: 'Готово',
};

const results = {
    done: { title: 'Сайт восстановлен', tone: 'green' },
    failed: { title: 'Восстановление не завершено', tone: 'red' },
    cancelled: { title: 'Восстановление остановлено', tone: 'amber' },
};

function formatSize(bytes) {
    if (!bytes) {
        return '—';
    }

    if (bytes >= 1073741824) {
        return `${(bytes / 1073741824).toFixed(1)} ГБ`;
    }

    return bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} МБ` : `${Math.max(1, Math.round(bytes / 1024))} КБ`;
}

function formatDate(value) {
    return value ? new Date(value).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' }) : '—';
}

function contents(backup) {
    if (backup.origin === 'upload') {
        return 'загружена с компьютера';
    }

    const parts = [];

    if (backup.options.database) {
        parts.push('база');
    }

    if (backup.options.files === 'all') {
        parts.push(backup.options.exclude_vendor ? 'весь сайт без vendor' : 'весь сайт');
    } else if (backup.options.files === 'uploads') {
        parts.push('загруженные файлы');
    }

    return parts.join(' + ');
}

/** Чего в копии точно нет — переключатель выключаем и прячем подсказкой. */
const lacks = computed(() => ({
    database: chosen.value?.origin !== 'upload' && chosen.value?.options.database === false,
    files: chosen.value?.origin !== 'upload' && chosen.value?.options.files === 'none',
    // .env и зависимости есть только в копии «весь сайт», vendor — если его не исключали.
    env: chosen.value?.origin !== 'upload' && chosen.value?.options.files !== 'all',
    vendor: chosen.value?.origin !== 'upload'
        && (chosen.value?.options.files !== 'all' || chosen.value?.options.exclude_vendor),
}));

function choose(backup) {
    selected.value = backup.id;
    options.database = !(backup.origin !== 'upload' && backup.options.database === false);
    options.files = !(backup.origin !== 'upload' && backup.options.files === 'none');
    options.vendor = false;
    options.env = false;
}

async function load() {
    try {
        const [list, state] = await Promise.all([api.get('backups'), api.get('restore')]);

        backups.value = list.data;
        restore.value = state.data;
        stepUrl.value = state.step_url;

        if (!selected.value && ready.value.length) {
            choose(ready.value[0]);
        }
    } catch (error) {
        ui.notifyError(error);
    } finally {
        loading.value = false;
    }
}

async function start() {
    const what = [options.files ? 'файлы' : null, options.database ? 'база' : null].filter(Boolean).join(' и ');

    const confirmed = await ui.confirm({
        title: 'Восстановить сайт из копии?',
        message: `Будут заменены ${what} сайта копией от ${formatDate(chosen.value.created_at)}. `
            + 'Отменить это нельзя — если сомневаетесь, сначала создайте свежую копию.',
        confirmLabel: 'Восстановить',
    });

    if (!confirmed) {
        return;
    }

    try {
        const data = await api.post('restore', { backup: selected.value, ...options });

        sessionStorage.setItem(TOKEN_KEY, data.token);
        stepUrl.value = data.step_url;
        await run(data.data);
    } catch (error) {
        ui.notifyError(error);
    }
}

async function resume() {
    try {
        const data = await api.post('restore/resume');

        sessionStorage.setItem(TOKEN_KEY, data.token);
        await run(data.data);
    } catch (error) {
        ui.notifyError(error);
    }
}

/**
 * Шаги идут мимо API панели: без сессии и CSRF, по токену. База по ходу
 * восстановления заменяется, и сессия, на которой держится панель, исчезает.
 */
async function step(token) {
    for (let attempt = 1; ; attempt++) {
        try {
            const response = await fetch(stepUrl.value, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ token }),
            });
            const data = await response.json().catch(() => null);

            if (!response.ok) {
                throw new Error(data?.message ?? `Шаг завершился ошибкой ${response.status}`);
            }

            return data;
        } catch (error) {
            // Хостинг иногда рвёт соединение — пробуем ещё пару раз.
            if (attempt >= 3) {
                throw error;
            }

            await new Promise((resolve) => setTimeout(resolve, 3000));
        }
    }
}

async function run(state) {
    restore.value = state;
    working.value = true;
    stopped = false;

    const token = sessionStorage.getItem(TOKEN_KEY);

    try {
        while (!stopped && ['running', 'stalled'].includes(restore.value.status)) {
            const data = await step(token);

            restore.value = data.data;

            if (data.busy) {
                await new Promise((resolve) => setTimeout(resolve, 2000));
            }
        }
    } catch (error) {
        ui.notify(error.message, 'error');
    } finally {
        working.value = false;

        if (restore.value?.status !== 'running') {
            sessionStorage.removeItem(TOKEN_KEY);
        }
    }
}

async function cancel() {
    const confirmed = await ui.confirm({
        title: 'Остановить восстановление?',
        message: 'Уже восстановленные файлы и таблицы останутся как есть — сайт может оказаться наполовину старым.',
        confirmLabel: 'Остановить',
    });

    if (!confirmed) {
        return;
    }

    stopped = true;

    try {
        const data = await api.delete('restore');

        ui.notify(data.message);
        sessionStorage.removeItem(TOKEN_KEY);
        await load();
    } catch (error) {
        ui.notifyError(error);
    }
}

function reloadPage() {
    window.location.reload();
}

async function dismiss() {
    try {
        await api.delete('restore');
        restore.value = null;
    } catch {
        // После восстановления базы сессия могла уйти — тогда просто перезагрузим.
        window.location.reload();
    }
}

/**
 * Загружает тома кусками по мегабайту — так проходит лимит хостинга на размер
 * загрузки. Сервер отвечает, сколько уже получил, с этого места и продолжаем.
 */
async function uploadFiles(event) {
    const files = [...(event.target.files ?? [])].sort((a, b) => a.name.localeCompare(b.name));
    event.target.value = '';

    if (!files.length) {
        return;
    }

    const total = files.reduce((sum, file) => sum + file.size, 0);
    upload.value = { name: files.map((file) => file.name).join(', '), done: 0, total };

    try {
        const created = await api.post('backups/uploads', {
            files: files.map((file) => ({ name: file.name, size: file.size })),
        });
        const id = created.data.id;
        let before = 0;

        for (const [index, file] of files.entries()) {
            let offset = 0;

            while (offset < file.size) {
                const body = new FormData();
                body.append('part', index);
                body.append('offset', offset);
                body.append('chunk', file.slice(offset, offset + CHUNK), 'chunk');

                const data = await api.post(`backups/uploads/${id}/chunk`, body, true);

                offset = data.received;
                upload.value.done = before + offset;
            }

            before += file.size;
        }

        ui.notify('Архив загружен.');
        selected.value = id;
        await load();
        choose(ready.value.find((backup) => backup.id === id) ?? ready.value[0]);
    } catch (error) {
        ui.notifyError(error);
    } finally {
        upload.value = null;
    }
}

onMounted(async () => {
    await load();

    // Вкладка перезагрузилась посреди восстановления — продолжаем своим токеном.
    if (active.value && sessionStorage.getItem(TOKEN_KEY) && restore.value.status === 'running') {
        run(restore.value);
    }
});

onBeforeUnmount(() => {
    stopped = true;
});
</script>

<template>
    <div>
        <NPageHeader title="Восстановление"
                     description="Возврат сайта к резервной копии: файлы и база заменяются тем, что в копии." />

        <div v-if="loading" class="text-sm text-[var(--text-muted)]">Загрузка…</div>

        <template v-else>
            <!-- Идёт восстановление -->
            <NCard v-if="active" title="Идёт восстановление" class="mb-6">
                <div class="space-y-3">
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-medium text-[var(--text-strong)]">
                            {{ restore.status === 'stalled' && !working ? 'Прервано' : (phases[restore.phase] ?? restore.phase) + '…' }}
                        </span>
                        <span class="tabular-nums text-[var(--text-muted)]">{{ restore.percent }}%</span>
                    </div>

                    <div class="h-2 overflow-hidden rounded-full bg-[var(--surface-muted)]">
                        <div class="h-full rounded-full bg-brand-600 transition-[width] duration-500"
                             :style="{ width: `${restore.percent}%` }"></div>
                    </div>

                    <p class="text-xs text-[var(--text-muted)]">
                        Копия от {{ formatDate(restore.backup_created_at) }}.
                        Файлов записано: {{ restore.progress.files_written }}.
                        <template v-if="restore.progress.statements">Запросов к базе: {{ restore.progress.statements }}.</template>
                        <template v-if="working">Не закрывайте страницу до конца.</template>
                    </p>

                    <div v-if="!working" class="flex gap-2">
                        <NButton size="sm" @click="resume">Продолжить</NButton>
                        <NButton size="sm" variant="secondary" @click="cancel">Остановить</NButton>
                    </div>
                </div>
            </NCard>

            <!-- Итог прошлого восстановления -->
            <NCard v-else-if="restore" :title="results[restore.status]?.title ?? 'Восстановление'" class="mb-6">
                <div class="space-y-3 text-sm">
                    <p class="text-[var(--text-base)]">
                        Копия от {{ formatDate(restore.backup_created_at) }}, закончено {{ formatDate(restore.finished_at) }}.
                        Файлов записано: {{ restore.progress.files_written }}<template v-if="restore.progress.statements">,
                        запросов к базе: {{ restore.progress.statements }}</template>.
                    </p>

                    <p v-if="restore.error" class="text-red-600 dark:text-red-400">{{ restore.error }}</p>

                    <ul v-if="restore.notes.length" class="list-inside list-disc space-y-1 text-xs text-[var(--text-muted)]">
                        <li v-for="note in restore.notes" :key="note">{{ note }}</li>
                    </ul>

                    <p v-if="restore.status === 'done' && restore.options.database"
                       class="flex items-start gap-2 rounded-lg bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                        <NIcon name="info" size="size-4 shrink-0" />
                        База заменена копией: пользователи и пароли теперь те, что были в копии. Если панель
                        перестанет отвечать — войдите заново. Копия с другого домена: на сервере выполните
                        php artisan nexor:license:bind с ключом на этот домен.
                    </p>

                    <div class="flex gap-2">
                        <NButton v-if="restore.status === 'done' && restore.options.database" size="sm"
                                 @click="reloadPage">
                            Обновить страницу
                        </NButton>
                        <NButton size="sm" variant="secondary" @click="dismiss">Скрыть</NButton>
                    </div>
                </div>
            </NCard>

            <div class="grid gap-6 xl:grid-cols-5">
                <div class="space-y-6 xl:col-span-3">
                    <NCard title="Копия" :padding="false">
                        <NEmpty v-if="ready.length === 0" icon="database" title="Готовых копий нет"
                                description="Создайте копию в разделе «Резервное копирование» или загрузите архив с компьютера." />

                        <ul v-else class="divide-y divide-[var(--surface-border)]">
                            <li v-for="backup in ready" :key="backup.id">
                                <label class="flex cursor-pointer items-start gap-3 px-5 py-3.5 transition hover:bg-[var(--surface-muted)]">
                                    <input type="radio" :checked="selected === backup.id" :disabled="active"
                                           class="mt-1 size-4 shrink-0 border-[var(--surface-border-strong)] text-brand-600 focus:ring-2 focus:ring-brand-500/40"
                                           @change="choose(backup)">
                                    <span class="min-w-0 flex-1 text-sm">
                                        <span class="font-medium text-[var(--text-strong)]">{{ formatDate(backup.created_at) }}</span>
                                        <span class="ml-2 text-xs text-[var(--text-muted)]">{{ contents(backup) }}</span>
                                        <span class="mt-0.5 block truncate text-xs text-[var(--text-faint)]">
                                            {{ backup.uploads ? backup.uploads.map((part) => part.original).join(', ') : backup.id }}
                                        </span>
                                    </span>
                                    <span class="text-sm tabular-nums text-[var(--text-muted)]">{{ formatSize(backup.size) }}</span>
                                </label>
                            </li>
                        </ul>

                        <div class="border-t border-[var(--surface-border)] px-5 py-4">
                            <div v-if="upload" class="space-y-2">
                                <div class="flex items-center justify-between text-xs text-[var(--text-muted)]">
                                    <span class="truncate">Загружаю {{ upload.name }}</span>
                                    <span class="tabular-nums">{{ formatSize(upload.done) }} из {{ formatSize(upload.total) }}</span>
                                </div>
                                <div class="h-1.5 overflow-hidden rounded-full bg-[var(--surface-muted)]">
                                    <div class="h-full rounded-full bg-brand-600 transition-[width]"
                                         :style="{ width: `${Math.floor((upload.done / upload.total) * 100)}%` }"></div>
                                </div>
                            </div>

                            <label v-else class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-[var(--surface-border-strong)] px-3 py-2 text-sm font-medium text-[var(--text-base)] transition hover:bg-[var(--surface-muted)]"
                                   :class="active && 'pointer-events-none opacity-60'">
                                <NIcon name="upload" size="size-4" />
                                Загрузить архив с компьютера
                                <input type="file" multiple accept=".gz,.tgz,.001,.002,.003" class="sr-only" @change="uploadFiles">
                            </label>
                            <p v-if="!upload" class="mt-2 text-xs text-[var(--text-muted)]">
                                Архив .tar.gz, снятый NEXOR. Если он частями — выберите все тома сразу.
                            </p>
                        </div>
                    </NCard>
                </div>

                <div class="space-y-6 xl:col-span-2">
                    <NCard title="Что восстановить">
                        <div class="space-y-4">
                            <NToggle v-model="options.files" label="Файлы сайта"
                                     :hint="lacks.files ? 'В этой копии файлов нет.' : 'Файлы из копии заменят текущие. Лишние файлы не удаляются.'"
                                     :disabled="active || lacks.files" />

                            <NToggle v-model="options.database" label="База данных"
                                     :hint="lacks.database ? 'В этой копии базы нет.' : 'Все таблицы заменятся таблицами из копии.'"
                                     :disabled="active || lacks.database" />

                            <NToggle v-model="options.env" label="Файл .env из копии"
                                     :hint="lacks.env ? 'В этой копии его нет.' : 'Только если переносите сайт целиком: в .env адрес базы и ключи старого сайта.'"
                                     :disabled="active || !options.files || lacks.env" />

                            <NToggle v-model="options.vendor" label="vendor и node_modules"
                                     :hint="lacks.vendor ? 'В этой копии их нет — после восстановления выполните composer install.' : 'Обычно лучше composer install, чем старые файлы зависимостей.'"
                                     :disabled="active || !options.files || lacks.vendor" />
                        </div>
                    </NCard>

                    <div class="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
                        <NIcon name="alert" size="size-5 shrink-0" />
                        <div class="space-y-1">
                            <p class="font-medium">Текущие файлы и база будут заменены.</p>
                            <p class="text-xs">
                                Пока идёт восстановление, сайт может отвечать с ошибками. После замены базы
                                входить нужно паролем из копии. Большой сайт надёжнее восстанавливать из консоли:
                                <code class="font-mono">php artisan nexor:restore</code>
                            </p>
                        </div>
                    </div>

                    <NButton variant="danger" size="lg" class="w-full" icon="clock"
                             :disabled="!chosen || active || working || (!options.files && !options.database)"
                             @click="start">
                        Восстановить
                    </NButton>
                </div>
            </div>
        </template>
    </div>
</template>
