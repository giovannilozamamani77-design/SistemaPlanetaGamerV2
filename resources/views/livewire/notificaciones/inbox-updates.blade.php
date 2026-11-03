<div aria-hidden="true" x-data="{
    timer: null, delay: 15000, busy: false, stopped: false, wake: null,
    init() {
        this.wake = () => { this.delay = 15000; this.schedule(0) };
        document.addEventListener('visibilitychange', this.wake);
        window.addEventListener('online', this.wake);
        window.addEventListener('offline', this.wake);
        window.addEventListener('panel-inbox-check', this.wake);
        this.schedule(this.delay);
    },
    schedule(wait) {
        clearTimeout(this.timer);
        if (this.stopped || document.hidden || !navigator.onLine) return;
        this.timer = setTimeout(() => this.check(), wait);
    },
    async check() {
        if (this.busy || this.stopped || document.hidden || !navigator.onLine) return;
        this.busy = true;
        try {
            const result = await $wire.checkForUpdates();
            if (this.stopped) return;
            this.stopped = !result.active;
            this.delay = result.changed ? 15000 : Math.min(this.delay * 2, 60000);
        } catch (error) {
            this.delay = Math.min(this.delay * 2, 60000);
        } finally {
            this.busy = false;
            this.schedule(this.delay);
        }
    },
    destroy() {
        this.stopped = true;
        clearTimeout(this.timer);
        document.removeEventListener('visibilitychange', this.wake);
        window.removeEventListener('online', this.wake);
        window.removeEventListener('offline', this.wake);
        window.removeEventListener('panel-inbox-check', this.wake);
    }
}"></div>
