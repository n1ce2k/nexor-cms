<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import NBadge from '../../components/ui/NBadge.vue';
import NButton from '../../components/ui/NButton.vue';
import NCard from '../../components/ui/NCard.vue';
import NEmpty from '../../components/ui/NEmpty.vue';
import NIcon from '../../components/ui/NIcon.vue';
import NInput from '../../components/ui/NInput.vue';
import NPageHeader from '../../components/ui/NPageHeader.vue';
import NToggle from '../../components/ui/NToggle.vue';
import { ApiError, api } from '../../api';
import { useUi } from '../../stores/ui';

const ui = useUi();

const backups = ref([]);
const freeSpace = ref(null);
const loading = ref(true);
const starting = ref(false);
const errors = ref({});

/** Копия, над которой эта вкладка сейчас работает. */
const current = ref(null);
/** Вкладку закрывают или уходят со страницы — шаги прекращаются. */
let stopped = false;

const options = reactive({
    database: true,
    files: 'all',
    exclude_vendor: true,
    split: false,
    part_mb: 100,
});

const phases = {
    scan: 'Собираю список файлов',
    database: 'Выгружаю базу',
    prepare: 'Готовлю архив',
    archive: 'Пишу архив',
    done: 'Готово',
};

const fileScopes = [
    { value: 'all', label: 'Весь сайт', hint: 'Код, шаблоны, загруженные файлы, .env' },
    { value: 'uploads', label: 'Только загруженные файлы', hint: 'Папка storage/app: картинки, документы, файлы форм' },
    { value: 'none', label: 'Без файлов', hint: 'Только база' },
];

const busy = computed(() => current.value !== null);

function formatSize(bytes) {
    if (bytes === null || bytes === undefined) {
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

const statuses = {
    running: { label: 'Создаётся', color: 'blue' },
    stalled: { label: 'Прервана', color: 'amber' },
    done: { label: 'Готова', color: 'green' },
    failed: { label: 'Ошибка', color: 'red' },
};

async function load() {
    try {
        const data = await api.get('backups');

        backups.value = data.data;
        freeSpace.value = data.free_space;
    } catch (error) {
        ui.notifyError(error);
    } finally {
        loading.value = false;
    }
}

async function start() {
    starting.value = true;
    errors.value = {};

    try {
        const data = await api.post('backups', {
            database: options.database,
            files: options.files,
            exclude_vendor: options.exclude_vendor,
            part_mb: options.split ? Number(options.part_mb) || null : null,
        });

        await run(data.data);
    } catch (error) {
        if (error instanceof ApiError && error.status === 422 && Object.keys(error.errors).length) {
            errors.value = error.errors;
        } else {
            ui.notifyError(error);
        }
    } finally {
        starting.value = false;
    }
}

/**
 * Зовёт шаги, пока копия не будет готова. Каждый шаг — несколько секунд работы
 * на сервере, поэтому лимит хостинга на время запроса не мешает.
 */
async function run(backup) {
    current.value = backup;
    stopped = false;

    try {
        while (!stopped && ['running', 'stalled'].includes(current.value.status)) {
            const data = await api.post(`backups/${current.value.id}/step`);

            current.value = data.data;

            // Шаг уже идёт в другой вкладке — подождём и спросим снова.
            if (data.busy) {
                await new Promise((resolve) => setTimeout(resolve, 2000));
            }
        }

        if (current.value.status === 'done') {
            ui.notify('Резервная копия готова.');
        } else if (current.value.status === 'failed') {
            ui.notify(`Копия не создана: ${current.value.error}`, 'error');
        }
    } catch (error) {
        ui.notifyError(error);
    } finally {
        current.value = null;
        await load();
    }
}

async function remove(backup) {
    const confirmed = await ui.confirm({
        title: 'Удалить копию?',
        message: `Копия от ${formatDate(backup.created_at)} будет удалена с сервера вместе со всеми томами.`,
    });

    if (!confirmed) {
        return;
    }

    try {
        const data = await api.delete(`backups/${backup.id}`);

        ui.notify(data.message);
        await load();
    } catch (error) {
        ui.notifyError(error);
    }
}

onMounted(load);

onBeforeUnmount(() => {
    stopped = true;
});
</script>

<template>
    <div>
        <NPageHeader title="Резервное копирование"
                     description="Архив файлов сайта и дамп базы. Копии хранятся на сервере в storage/app/backups и скачиваются отсюда." />

        <div class="grid gap-6 xl:grid-cols-5">
            <div class="space-y-6 xl:col-span-2">
                <NCard title="Новая копия">
                    <form class="space-y-5" @submit.prevent="start">
                        <NToggle v-model="options.database" label="База данных"
                                 hint="Дамп всех таблиц; у сессий и кеша — только структура." :disabled="busy" />

                        <fieldset class="space-y-2" :disabled="busy">
                            <legend class="mb-2 text-sm font-medium text-[var(--text-strong)]">Файлы</legend>

                            <label v-for="scope in fileScopes" :key="scope.value"
                                   class="flex cursor-pointer items-start gap-2.5 select-none">
                                <input v-model="options.files" type="radio" :value="scope.value"
                                       class="mt-0.5 size-4 shrink-0 border-[var(--surface-border-strong)] text-brand-600 focus:ring-2 focus:ring-brand-500/40">
                                <span class="text-sm">
                                    <span class="font-medium text-[var(--text-strong)]">{{ scope.label }}</span>
                                    <span class="mt-0.5 block text-xs text-[var(--text-muted)]">{{ scope.hint }}</span>
                                </span>
                            </label>
                        </fieldset>

                        <NToggle v-if="options.files === 'all'" v-model="options.exclude_vendor"
                                 label="Без vendor и node_modules"
                                 hint="Они восстанавливаются через composer install и занимают больше всего места."
                                 :disabled="busy" />

                        <fieldset class="space-y-2" :disabled="busy">
                            <legend class="mb-2 text-sm font-medium text-[var(--text-strong)]">Архив</legend>

                            <label class="flex cursor-pointer items-center gap-2.5 select-none">
                                <input v-model="options.split" type="radio" :value="false"
                                       class="size-4 shrink-0 border-[var(--surface-border-strong)] text-brand-600 focus:ring-2 focus:ring-brand-500/40">
                                <span class="text-sm font-medium text-[var(--text-strong)]">Одним файлом</span>
                            </label>

                            <label class="flex cursor-pointer items-center gap-2.5 select-none">
                                <input v-model="options.split" type="radio" :value="true"
                                       class="size-4 shrink-0 border-[var(--surface-border-strong)] text-brand-600 focus:ring-2 focus:ring-brand-500/40">
                                <span class="text-sm font-medium text-[var(--text-strong)]">Частями по</span>
                                <NInput v-model="options.part_mb" type="number" min="10" max="4096" class="w-24"
                                        :disabled="!options.split || busy"
                                        :invalid="Boolean(errors.part_mb)" />
                                <span class="text-sm text-[var(--text-muted)]">МБ</span>
                            </label>

                            <p v-if="errors.part_mb" class="text-xs text-red-600 dark:text-red-400">{{ errors.part_mb[0] }}</p>
                            <p v-else-if="options.split" class="text-xs text-[var(--text-muted)]">
                                Тома .tar.gz.001, .002… — 7-Zip открывает первый и собирает остальные сам.
                            </p>
                        </fieldset>

                        <p v-if="errors.files" class="text-xs text-red-600 dark:text-red-400">{{ errors.files[0] }}</p>

                        <div class="flex items-center justify-between gap-3">
                            <NButton type="submit" icon="save" :loading="starting" :disabled="busy">Создать копию</NButton>
                            <span v-if="freeSpace !== null" class="text-xs text-[var(--text-muted)]">
                                Свободно: {{ formatSize(freeSpace) }}
                            </span>
                        </div>
                    </form>
                </NCard>
            </div>

            <div class="space-y-6 xl:col-span-3">
                <NCard v-if="current" title="Создаётся копия">
                    <div class="space-y-3">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium text-[var(--text-strong)]">{{ phases[current.phase] ?? current.phase }}…</span>
                            <span class="tabular-nums text-[var(--text-muted)]">{{ current.percent }}%</span>
                        </div>

                        <div class="h-2 overflow-hidden rounded-full bg-[var(--surface-muted)]">
                            <div class="h-full rounded-full bg-brand-600 transition-[width] duration-500"
                                 :style="{ width: `${current.percent}%` }"></div>
                        </div>

                        <p class="text-xs text-[var(--text-muted)]">
                            <template v-if="current.progress.tables_total">
                                Таблиц: {{ current.progress.tables_done }} из {{ current.progress.tables_total }}.
                            </template>
                            <template v-if="current.progress.files_total">
                                Файлов: {{ current.progress.files_done }} из {{ current.progress.files_total }},
                                {{ formatSize(current.progress.bytes_done) }} из {{ formatSize(current.progress.bytes_total) }}.
                            </template>
                            Не закрывайте страницу, пока копия не будет готова.
                        </p>
                    </div>
                </NCard>

                <NCard title="Копии" :padding="false">
                    <div v-if="loading" class="p-6 text-sm text-[var(--text-muted)]">Загрузка…</div>

                    <NEmpty v-else-if="backups.length === 0" icon="database" title="Копий пока нет"
                            description="Создайте первую — слева выберите, что в неё положить." />

                    <ul v-else class="divide-y divide-[var(--surface-border)]">
                        <li v-for="backup in backups" :key="backup.id" class="flex flex-wrap items-start gap-x-4 gap-y-2 px-5 py-4">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium text-[var(--text-strong)]">{{ formatDate(backup.created_at) }}</span>
                                    <NBadge :color="statuses[backup.status]?.color">{{ statuses[backup.status]?.label ?? backup.status }}</NBadge>
                                    <span class="text-xs text-[var(--text-muted)]">{{ contents(backup) }}</span>
                                </div>

                                <p v-if="backup.status === 'failed'" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ backup.error }}</p>
                                <p v-else-if="backup.status === 'stalled'" class="mt-1 text-xs text-[var(--text-muted)]">
                                    Работа прервалась на этапе «{{ phases[backup.phase] ?? backup.phase }}» — её можно продолжить.
                                </p>

                                <div v-if="backup.status === 'done'" class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                                    <a v-for="part in backup.parts" :key="part.name" :href="part.url"
                                       class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                                        <NIcon name="upload" size="size-3.5 rotate-180" />
                                        {{ part.name }}
                                        <span class="font-normal text-[var(--text-muted)]">{{ formatSize(part.size) }}</span>
                                    </a>
                                </div>

                                <p v-if="backup.author" class="mt-1 text-xs text-[var(--text-faint)]">Создал: {{ backup.author }}</p>
                            </div>

                            <div class="flex items-center gap-2">
                                <span v-if="backup.status === 'done'" class="text-sm tabular-nums text-[var(--text-muted)]">
                                    {{ formatSize(backup.size) }}
                                </span>

                                <NButton v-if="['running', 'stalled'].includes(backup.status) && !busy"
                                         size="sm" variant="secondary" @click="run(backup)">
                                    Продолжить
                                </NButton>

                                <NButton size="sm" variant="ghost" icon="trash"
                                         :disabled="busy && current?.id === backup.id"
                                         @click="remove(backup)" />
                            </div>
                        </li>
                    </ul>
                </NCard>
            </div>
        </div>
    </div>
</template>
