import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

// Exercise the actual Alpine object from the view, with a local fake clock.
const view = readFileSync(new URL('../../../resources/views/cajas/workspace.blade.php', import.meta.url), 'utf8');
const expression = view.match(/x-data="([\s\S]*?)"/)[1];

test('one local clock advances both displays, catches up after hiding and cleans up', () => {
    let now = 100000;
    let callback;
    const timers = new Set();
    const document = { hidden: false };
    const clock = vm.runInNewContext('(' + expression + ')', {
        Date: { now: () => now }, document,
        setInterval(fn) { callback = fn; const id = {}; timers.add(id); return id; },
        clearInterval(id) { timers.delete(id); },
    });
    clock.$el = { dataset: { elapsedSeconds: '3599' } };
    clock.init();
    assert.equal(clock.elapsed, '0 h 59 min');
    now += 1000; callback();
    assert.equal(clock.elapsed, '1 h 0 min');
    document.hidden = true; clock.pause();
    assert.equal(timers.size, 0);
    now += 125000;
    document.hidden = false; clock.resume();
    assert.equal(clock.elapsed, '1 h 2 min');
    clock.resume();
    assert.equal(timers.size, 1);
    clock.destroy();
    assert.equal(timers.size, 0);
    assert.equal((view.match(/x-text="elapsed"/g) || []).length, 2);
});

test('a new session gets its own baseline and future opening never displays negative time', () => {
    const clock = vm.runInNewContext('(' + expression + ')', {
        Date: { now: () => 5000 }, document: { hidden: true },
        clearInterval() {}, setInterval() { throw new Error('hidden clock must stay paused'); },
    });
    clock.$el = { dataset: { elapsedSeconds: '-10' } };
    clock.init();
    assert.equal(clock.elapsed, '0 h 0 min');
    clock.destroy();
});
