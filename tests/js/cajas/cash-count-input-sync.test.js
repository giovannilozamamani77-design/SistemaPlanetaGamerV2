import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

// Exercise the installed Livewire reconciliation, without modifying vendor code.
const source = readFileSync(new URL('../../../vendor/livewire/livewire/dist/livewire.js', import.meta.url), 'utf8');
const helpers = source.slice(source.indexOf('  function isObjecty('), source.indexOf('  function extractData('));
const runtime = vm.createContext({});
vm.runInContext(helpers, runtime);

function reconcile(sent, received, current) {
    runtime.diffAndPatchRecursive(sent, received, current);
    return current;
}

test('reproduces the lost trailing zero when a sparse PHP array returns as an object', () => {
    const sent = { quantities: [] };
    sent.quantities.m010 = '500';
    const typing = { quantities: [] };
    typing.quantities.m010 = '5000';
    const result = reconcile(sent, { quantities: { m010: '500' } }, typing);
    assert.equal(result.quantities.m010, '500');
});

test('stable denomination maps preserve newer zeros and sibling edits across stale responses', () => {
    const empty = { b100: '', m1: '', m010: '' };
    const sent = { quantities: { ...empty, m010: '500' }, packed: { ...empty, m1: '50' } };
    const received = JSON.parse(JSON.stringify(sent));
    const typing = { quantities: { ...empty, m010: '5000', b100: '1000' }, packed: { ...empty, m1: '5000.03' } };
    const result = reconcile(sent, received, typing);
    assert.equal(result.quantities.m010, '5000');
    assert.equal(result.quantities.b100, '1000');
    assert.equal(result.packed.m1, '5000.03');
});
