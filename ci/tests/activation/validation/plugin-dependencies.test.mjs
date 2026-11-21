import assert from 'node:assert/strict';
import test from 'node:test';

import {parseRequiredPluginNames, withRequiredPluginNames} from '../test-package/tests/plugin-dependencies.js';

const LIVE_CARTS = 'Live Carts for WooCommerce: Track Active, Abandoned, and Converted Carts & Wishlists in Real Time!';

test('splits a plain list of dependency names', () => {
    assert.deepEqual(
        parseRequiredPluginNames('Requires: WooCommerce, WooCommerce Subscriptions', ['WooCommerce', 'WooCommerce Subscriptions']),
        ['WooCommerce', 'WooCommerce Subscriptions'],
    );
});

// Live Carts for WooCommerce Pro was never activated because its dependency was split at these commas.
test('keeps an installed dependency whose name contains commas whole', () => {
    assert.deepEqual(
        parseRequiredPluginNames(`Requires: ${LIVE_CARTS}, WooCommerce`, ['WooCommerce', LIVE_CARTS, 'Live Carts for WooCommerce Pro']),
        [LIVE_CARTS, 'WooCommerce'],
    );
});

test('prefers the longest installed name when a shorter one is also a prefix', () => {
    assert.deepEqual(
        parseRequiredPluginNames('Requires: Alpha, Beta, Gamma', ['Alpha', 'Alpha, Beta', 'Gamma']),
        ['Alpha, Beta', 'Gamma'],
    );
});

test('falls back to comma-separated parts for dependencies that are not installed', () => {
    assert.deepEqual(
        parseRequiredPluginNames('Requires: Missing Plugin, WooCommerce', ['WooCommerce']),
        ['Missing Plugin', 'WooCommerce'],
    );
});

test('returns no dependencies for an empty list', () => {
    assert.deepEqual(parseRequiredPluginNames('Requires: ', ['WooCommerce']), []);
});

test('never resolves a dependency to the plugin that requires it', () => {
    const plugins = withRequiredPluginNames([
        {name: 'Alpha', requiresText: ''},
        {name: 'Beta', requiresText: ''},
        {name: 'Alpha, Beta', requiresText: 'Requires: Alpha, Beta'},
    ]);

    assert.deepEqual(plugins.map(plugin => plugin.dependencies), [[], [], ['Alpha', 'Beta']]);
    assert.ok(plugins.every(plugin => !('requiresText' in plugin)));
});

test('keeps a dependency that only shares its name with the plugin requiring it', () => {
    const plugins = withRequiredPluginNames([
        {name: 'Alpha, Beta', requiresText: ''},
        {name: 'Alpha, Beta', requiresText: 'Requires: Alpha, Beta'},
    ]);

    assert.deepEqual(plugins[1].dependencies, ['Alpha, Beta']);
});
