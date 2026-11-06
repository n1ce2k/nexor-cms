import Alpine from 'alpinejs';
import { resolveTheme, setTheme } from './theme';

Alpine.store('theme', {
    current: resolveTheme(),

    set(theme) {
        setTheme(theme);
        this.current = resolveTheme();
    },

    toggle() {
        this.set(this.current === 'dark' ? 'light' : 'dark');
    },
});

Alpine.store('toasts', {
    items: [],
    nextId: 1,

    push(message, type = 'success', timeout = 5000) {
        const id = this.nextId++;

        this.items.push({ id, message, type });

        if (timeout) {
            setTimeout(() => this.dismiss(id), timeout);
        }
    },

    dismiss(id) {
        this.items = this.items.filter((toast) => toast.id !== id);
    },
});

window.notify = (message, type = 'success') => Alpine.store('toasts').push(message, type);
