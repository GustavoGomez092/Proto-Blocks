import { test } from 'node:test';
import assert from 'node:assert/strict';
import { nextVersion } from './next-version.mjs';

test('feat → minor bump', () => {
    assert.deepEqual(nextVersion('1.2.3', ['feat: add thing']), {
        version: '1.3.0',
        type: 'minor',
    });
});

test('fix → patch bump', () => {
    assert.deepEqual(nextVersion('1.2.3', ['fix: a bug']), {
        version: '1.2.4',
        type: 'patch',
    });
});

test('feat! subject → major bump', () => {
    assert.deepEqual(nextVersion('1.2.3', ['feat!: drop old API']), {
        version: '2.0.0',
        type: 'major',
    });
});

test('scoped type with ! → major', () => {
    assert.deepEqual(nextVersion('1.2.3', ['refactor(core)!: rework']), {
        version: '2.0.0',
        type: 'major',
    });
});

test('BREAKING CHANGE in body → major', () => {
    assert.deepEqual(
        nextVersion('1.2.3', ['feat: x\n\nBREAKING CHANGE: removes y']),
        { version: '2.0.0', type: 'major' }
    );
});

test('scoped feat → minor', () => {
    assert.deepEqual(nextVersion('1.2.3', ['feat(editor): add panel']), {
        version: '1.3.0',
        type: 'minor',
    });
});

test('highest precedence wins (feat + fix → minor)', () => {
    assert.deepEqual(nextVersion('1.2.3', ['fix: a', 'feat: b']), {
        version: '1.3.0',
        type: 'minor',
    });
});

test('breaking beats feat (→ major)', () => {
    assert.deepEqual(nextVersion('1.2.3', ['feat: a', 'feat!: b']), {
        version: '2.0.0',
        type: 'major',
    });
});

test('only chore/docs → null (no release)', () => {
    assert.equal(
        nextVersion('1.2.3', ['chore: deps', 'docs: readme', 'refactor: tidy']),
        null
    );
});

test('empty commit list → null', () => {
    assert.equal(nextVersion('1.2.3', []), null);
});

test('"BREAKING CHANGE" without a colon (prose) does not trigger major', () => {
    assert.equal(
        nextVersion('1.2.3', ['chore: note that this is not a BREAKING CHANGE at all']),
        null
    );
});

test('tolerates a leading v in the current version', () => {
    assert.deepEqual(nextVersion('v1.2.3', ['feat!: x']), {
        version: '2.0.0',
        type: 'major',
    });
});

test('tolerates a pre-release suffix in the current version', () => {
    assert.deepEqual(nextVersion('1.2.3-beta.1', ['feat: x']), {
        version: '1.3.0',
        type: 'minor',
    });
});
