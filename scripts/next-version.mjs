/**
 * Conventional-Commits → next semver version.
 *
 * Pure logic (no git, no I/O) plus a thin CLI used by the release workflow.
 *
 * Rules, evaluated across all commit messages since the last release:
 *   - subject `type!:` (optionally scoped) OR `BREAKING CHANGE` anywhere → major
 *   - subject `feat:` (optionally scoped) → minor
 *   - subject `fix:`  (optionally scoped) → patch
 *   - none of the above → null (no release)
 * Highest-precedence match across all commits wins.
 */

import { readFileSync, realpathSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const RANK = { patch: 1, minor: 2, major: 3 };

/**
 * @param {string} current   Current semver, e.g. "1.2.3".
 * @param {string[]} messages Full commit messages (subject on the first line).
 * @returns {{version: string, type: 'major'|'minor'|'patch'} | null}
 */
export function nextVersion(current, messages) {
    let bump = null;

    for (const message of messages) {
        const subject = (message.split('\n')[0] || '').trim();
        let type = null;

        // Per the Conventional Commits spec the breaking footer is
        // `BREAKING CHANGE:` / `BREAKING-CHANGE:` (note the colon) — requiring it
        // avoids false positives on the phrase appearing in normal prose.
        if (/^\w+(\(.+\))?!:/.test(subject) || /BREAKING[ -]CHANGE:/.test(message)) {
            type = 'major';
        } else if (/^feat(\(.+\))?:/.test(subject)) {
            type = 'minor';
        } else if (/^fix(\(.+\))?:/.test(subject)) {
            type = 'patch';
        }

        if (type && (bump === null || RANK[type] > RANK[bump])) {
            bump = type;
        }
    }

    if (bump === null) {
        return null;
    }

    // Tolerate a leading `v` and any pre-release/build suffix (e.g. "v1.2.3",
    // "1.2.3-beta.1") so a slightly-off CURRENT_VERSION can't silently bump
    // from a wrong base.
    const core = current.replace(/^v/, '').split(/[-+]/)[0];
    const [major, minor, patch] = core
        .split('.')
        .map((n) => parseInt(n, 10) || 0);

    const version =
        bump === 'major'
            ? `${major + 1}.0.0`
            : bump === 'minor'
              ? `${major}.${minor + 1}.0`
              : `${major}.${minor}.${patch + 1}`;

    return { version, type: bump };
}

// CLI: only when executed directly (never during `import` in tests).
const invokedDirectly =
    process.argv[1] &&
    realpathSync(process.argv[1]) === fileURLToPath(import.meta.url);

if (invokedDirectly) {
    const current = process.env.CURRENT_VERSION || '0.0.0';
    let raw = '';
    try {
        raw = readFileSync(0, 'utf8'); // stdin
    } catch {
        raw = '';
    }
    // Commit messages are piped in, separated by the ASCII record separator
    // (0x1e) so multi-line bodies stay intact.
    const messages = raw
        .split('\x1e')
        .map((s) => s.trim())
        .filter(Boolean);

    const result = nextVersion(current, messages);
    if (result) {
        process.stdout.write(`version=${result.version}\ntype=${result.type}\n`);
    }
}
