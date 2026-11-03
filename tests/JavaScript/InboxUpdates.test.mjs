import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const source = readFileSync(new URL('../../resources/views/livewire/notificaciones/inbox-updates.blade.php', import.meta.url), 'utf8').match(/x-data="([\s\S]*?)"/)[1];
function setup(reply = async () => ({ active: true, changed: false })) {
    const document = { hidden: false, listeners: new Map(), addEventListener(k,v) { this.listeners.set(k,v) }, removeEventListener(k) { this.listeners.delete(k) } };
    const window = { ...document, listeners: new Map() };
    const navigator = { onLine: true };
    const timers = new Map(); let id = 0; let calls = 0;
    const view = new Function('$wire', 'document', 'window', 'navigator', 'setTimeout', 'clearTimeout', `return (${source})`)(
        { checkForUpdates: () => { calls++; return reply() } }, document, window, navigator,
        (callback, wait) => { timers.set(++id, { callback, wait }); return id }, key => timers.delete(key));
    view.init();
    return { view, document, window, navigator, timers, calls: () => calls };
}
test('spaces idle checks, pauses while hidden/offline and resumes on visibility', async () => {
    const s = setup();
    assert.equal([...s.timers.values()][0].wait, 15000);
    await s.view.check(); assert.equal(s.view.delay, 30000);
    await s.view.check(); assert.equal(s.view.delay, 60000);
    s.document.hidden = true; s.view.wake(); assert.equal(s.timers.size, 0);
    const before = s.calls(); await s.view.check(); assert.equal(s.calls(), before);
    s.document.hidden = false; s.navigator.onLine = false; s.view.wake(); assert.equal(s.timers.size, 0);
    s.navigator.onLine = true; s.view.wake(); assert.equal([...s.timers.values()][0].wait, 0);
    s.view.destroy(); assert.equal(s.timers.size, 0); assert.equal(s.window.listeners.size, 0); assert.equal(s.document.listeners.size, 0);
});
test('does not overlap requests and a late reply cannot restart a destroyed component', async () => {
    let resolve;
    const s = setup(() => new Promise(r => { resolve = r }));
    const pending = s.view.check(); await s.view.check(); assert.equal(s.calls(), 1);
    s.view.destroy(); resolve({ active: true, changed: true }); await pending;
    assert.equal(s.view.stopped, true); assert.equal(s.timers.size, 0);
});
test('backs off on connection errors and stops on revoked access', async () => {
    const failing = setup(async () => { throw Error('offline') });
    await failing.view.check(); assert.equal(failing.view.delay, 30000);
    await failing.view.check(); assert.equal(failing.view.delay, 60000);
    const revoked = setup(async () => ({ active: false, changed: true }));
    await revoked.view.check(); assert.equal(revoked.view.stopped, true); assert.equal(revoked.timers.size, 0);
});
