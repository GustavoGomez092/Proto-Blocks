import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { INCLUDE_PATTERNS } = require('./build-zip.js');

test('the plugin zip ships the schemas folder (field-type aliases, JSON Schemas)', () => {
    assert.ok(INCLUDE_PATTERNS.includes('schemas/**/*'));
});

test('the plugin zip ships the PHP sources', () => {
    assert.ok(INCLUDE_PATTERNS.includes('includes/**/*'));
});
