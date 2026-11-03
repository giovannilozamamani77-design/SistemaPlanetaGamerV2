import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const blade = readFileSync(new URL('../../resources/views/filament/navigation/sidebar.blade.php', import.meta.url), 'utf8');
const source = blade.match(/data-sidebar-scroll[\s\S]*?x-data="([\s\S]*?)"/ )?.[1]?.replaceAll('&amp;', '&');
function setup(storage, key = 'pg-sidebar-scroll:inicio:1', height = 1400) {
    assert.ok(source, 'The scrollable sidebar must own its scroll persistence');
    const view = new Function('sessionStorage', `return (${source})`)(storage);
    const nav = { dataset: { scrollKey: key }, scrollTop: 0, scrollHeight: height, clientHeight: 400 };
    view.$el = nav;
    view.$nextTick = callback => callback();
    view.init();
    return { view, nav };
}
function memory() {
    const values = new Map();
    return { values, getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value) };
}

test('restores the lower menu on another page and persists an explicit return to the top', () => {
    const storage = memory();
    const first = setup(storage);
    first.nav.scrollTop = 830;
    first.view.remember();
    const next = setup(storage);
    assert.equal(next.nav.scrollTop, 830);
    next.nav.scrollTop = 0;
    next.view.remember();
    assert.equal(setup(storage).nav.scrollTop, 0);
    assert.equal(storage.values.size, 1);
});

test('scopes positions to user and panel and clamps after menu permissions or viewport change', () => {
    const storage = memory();
    storage.setItem('pg-sidebar-scroll:inicio:1', '830');
    assert.equal(setup(storage, 'pg-sidebar-scroll:inicio:2').nav.scrollTop, 0);
    assert.equal(setup(storage, 'pg-sidebar-scroll:other:1').nav.scrollTop, 0);
    assert.equal(setup(storage, 'pg-sidebar-scroll:inicio:1', 520).nav.scrollTop, 120);
});

test('ignores corrupt values and survives unavailable browser storage', () => {
    const storage = memory();
    for (const value of ['NaN', 'Infinity', '-20', 'text']) {
        storage.setItem('pg-sidebar-scroll:inicio:1', value);
        assert.equal(setup(storage).nav.scrollTop, 0);
    }
    const blocked = { getItem() { throw Error('blocked'); }, setItem() { throw Error('blocked'); } };
    const instance = setup(blocked);
    assert.equal(instance.nav.scrollTop, 0);
    assert.doesNotThrow(() => instance.view.remember());
});

test('saves before link navigation and page exit as well as scrolling without network requests', () => {
    assert.match(blade, /x-on:click\.capture="remember\(\)"/);
    assert.match(blade, /x-on:pagehide\.window="remember\(\)"/);
    assert.match(blade, /x-on:scroll\.debounce\.150ms="remember\(\)"/);
    assert.match(blade, /x-on:livewire:navigating\.document="remember\(\)"/);
    assert.doesNotMatch(source, /\$wire|fetch\(|setInterval/);
});
