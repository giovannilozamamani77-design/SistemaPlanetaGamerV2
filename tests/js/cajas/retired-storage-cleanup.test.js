import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const template = readFileSync(new URL('../../../resources/views/cajas/retired-storage-cleanup.blade.php', import.meta.url), 'utf8');
const expression = template.match(/x-init="([^"]+)"/)[1];

test('Alpine cleanup is a valid expression and removes only obsolete closing keys', () => {
    const values = new Map([['pg-close-draft:1', 'private-old'], ['theme', 'dark'], ['pg-close-draft:2', 'private-old']]);
    const storage = { get length() { return values.size; }, key(i) { return [...values.keys()][i]; }, removeItem(key) { values.delete(key); } };
    new vm.Script(`(${expression})`).runInNewContext({ localStorage: storage });
    assert.deepEqual([...values.entries()], [['theme', 'dark']]);
});

test('Unavailable storage does not interrupt the page', () => {
    new vm.Script(`(${expression})`).runInNewContext({ get localStorage() { throw new Error('Storage blocked'); } });
});
