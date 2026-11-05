import { readFileSync } from 'fs';
import { join } from 'path';
import { parseJsonc } from '@/admin/content-model/jsonc';
import {
	addRule,
	applySettingsEdits,
	compact,
	moveRule,
	newGroupText,
	newRuleId,
	readRules,
	removeRule,
	RuleNotFoundError,
	setGroupField,
	setRuleEnabled,
	updateRule,
} from '@/admin/redirects/edits';

const fixture = readFileSync(join(__dirname, 'fixtures/legacy-site.jsonc'), 'utf8');
const COMMENTS = ['// proto-redirects/legacy-site/redirects.jsonc', '// shown in the UI', '// About page moved in 2024.', '// Keep the old blog URLs alive.', '// regex', '// Spread over lines on purpose.', '// End of rules.'];

function expectComments(text: string, except: string[] = []) {
	for (const comment of COMMENTS.filter((c) => !except.includes(c))) {
		expect(text).toContain(comment);
	}
	expect(parseJsonc(text).errors).toEqual([]);
}

const ids = (text: string) => readRules(text).map((rule) => rule.id ?? rule.from);

describe('setRuleEnabled', () => {
	test('disabling a one-line rule rewrites only its line and keeps every comment', () => {
		const out = setRuleEnabled(fixture, 'r_00000003', false);
		expect(out).toMatchSnapshot();
		expectComments(out);
		const changed = out.split('\n').filter((line, i) => line !== fixture.split('\n')[i]);
		expect(changed).toEqual(['    { "id": "r_00000003", "enabled": false, "from": "/promo", "to": "/sale", "code": 302, "query": "pass" },']);
	});

	test('enabling removes the key again, so the round trip is byte-identical', () => {
		expect(setRuleEnabled(setRuleEnabled(fixture, 'r_00000002', false), 'r_00000002', true)).toBe(fixture);
	});

	test('a multi-line rule is edited key by key and keeps the comment inside it', () => {
		const out = setRuleEnabled(fixture, 'r_00000004', false);
		expect(out).toContain('// Spread over lines on purpose.');
		expect(readRules(out)[3]).toMatchObject({ id: 'r_00000004', enabled: false, else: '/login' });
		expectComments(out);
	});

	test('a file rule without an id is found by index and gets the id written', () => {
		const out = setRuleEnabled(fixture, { id: 'r_deadbeef', index: 4 }, false);
		expect(readRules(out)[4]).toEqual({ id: 'r_deadbeef', enabled: false, from: '/gone-forever', action: 'error', code: 410 });
		expectComments(out);
	});

	test('an unknown id throws', () => {
		expect(() => setRuleEnabled(fixture, 'r_ffffffff', false)).toThrow(RuleNotFoundError);
		// The index fallback never picks a rule that has its own id.
		expect(() => setRuleEnabled(fixture, { id: 'r_ffffffff', index: 0 }, false)).toThrow(RuleNotFoundError);
	});
});

describe('addRule', () => {
	test('appends one line after the last rule, before the trailing comment', () => {
		const out = addRule(fixture, { to: '/b', from: '/a', id: 'r_0000000a' });
		expect(ids(out)).toEqual(['r_00000001', 'r_00000002', 'r_00000003', 'r_00000004', '/gone-forever', 'r_0000000a']);
		expect(out).toContain('    { "from": "/gone-forever", "action": "error", "code": 410 },\n    { "id": "r_0000000a", "from": "/a", "to": "/b" }\n    // End of rules.');
		expectComments(out);
	});

	test('inserts at a position', () => {
		const out = addRule(fixture, { id: 'r_0000000b', from: '/x', to: '/y' }, 1);
		expect(ids(out).slice(0, 3)).toEqual(['r_00000001', 'r_0000000b', 'r_00000002']);
		expectComments(out);
	});

	test('into an empty array, a missing array and a one-line array', () => {
		const empty = newGroupText('promo', 'Promo');
		const one = addRule(empty, { id: 'r_00000001', from: '/a', to: '/b' });
		expect(one).toContain('"rules": [\n    { "id": "r_00000001", "from": "/a", "to": "/b" }\n  ]');
		const two = addRule(one, { id: 'r_00000002', from: '/c', to: '/d' });
		expect(ids(two)).toEqual(['r_00000001', 'r_00000002']);
		expect(two).toContain('{ "id": "r_00000001", "from": "/a", "to": "/b" },\n    { "id": "r_00000002"');

		expect(readRules(addRule('{\n  "title": "X"\n}', { id: 'r_00000001', from: '/a', to: '/b' }))).toHaveLength(1);
		const inline = addRule('{ "rules": [{ "from": "/a", "to": "/b" }] }', { id: 'r_00000002', from: '/c', to: '/d' });
		expect(inline).toBe('{ "rules": [{ "from": "/a", "to": "/b" }, { "id": "r_00000002", "from": "/c", "to": "/d" }] }');
	});

	test('keeps a trailing comma style', () => {
		const out = addRule('{\n  "rules": [\n    { "from": "/a", "to": "/b" },\n  ]\n}', { id: 'r_00000002', from: '/c', to: '/d' });
		expect(out).toBe('{\n  "rules": [\n    { "from": "/a", "to": "/b" },\n    { "id": "r_00000002", "from": "/c", "to": "/d" },\n  ]\n}');
	});

	test('puts keys in the canonical order of spec §1', () => {
		expect(compact({ when: { type: 'login' }, to: '/b', from: '/a' })).toBe('{ "when": { "type": "login" }, "to": "/b", "from": "/a" }');
		const out = addRule(newGroupText('x', 'X'), { when: { type: 'ip', value: ['10.0.0.0/8'] }, code: 302, to: '/b', from: '/a', id: 'r_00000001' });
		expect(out).toContain('{ "id": "r_00000001", "from": "/a", "to": "/b", "code": 302, "when": { "type": "ip", "value": ["10.0.0.0/8"] } }');
	});
});

describe('removeRule', () => {
	test('removes the line and the trailing same-line comment, keeps comments above', () => {
		const out = removeRule(fixture, 'r_00000002');
		expect(ids(out)).toEqual(['r_00000001', 'r_00000003', 'r_00000004', '/gone-forever']);
		expectComments(out, ['// regex']);
		expect(out).not.toContain('Old blog');
	});

	test('removing the last rule drops the comma before it', () => {
		const out = removeRule(fixture, { id: 'r_x', index: 4 });
		expect(out).toContain('      "else": "/login"\n    }\n    // End of rules.');
		expectComments(out);
	});

	test('removes a multi-line rule whole', () => {
		const out = removeRule(fixture, 'r_00000004');
		expect(out).not.toContain('/members');
		expectComments(out, ['// Spread over lines on purpose.']);
	});

	test('one-line arrays', () => {
		const text = '{ "rules": [{ "id": "r_00000001", "from": "/a" }, { "id": "r_00000002", "from": "/b" }] }';
		expect(removeRule(text, 'r_00000001')).toBe('{ "rules": [{ "id": "r_00000002", "from": "/b" }] }');
		expect(removeRule(text, 'r_00000002')).toBe('{ "rules": [{ "id": "r_00000001", "from": "/a" }] }');
		expect(removeRule('{ "rules": [{ "id": "r_00000001" }] }', 'r_00000001')).toBe('{ "rules": [] }');
	});

	test('removing every rule leaves a valid file', () => {
		let out = fixture;
		for (const ref of ['r_00000001', 'r_00000002', 'r_00000003', 'r_00000004']) {
			out = removeRule(out, ref);
		}
		out = removeRule(out, { id: 'r_x', index: 0 });
		expect(readRules(out)).toEqual([]);
		expect(parseJsonc(out).errors).toEqual([]);
		expect(out).toContain('// End of rules.');
	});
});

describe('moveRule and updateRule', () => {
	test('moving swaps the rules and leaves the comments on their lines', () => {
		const down = moveRule(fixture, 'r_00000001', 1);
		expect(ids(down).slice(0, 2)).toEqual(['r_00000002', 'r_00000001']);
		expectComments(down);
		expect(moveRule(down, 'r_00000001', -1)).toBe(fixture);
		expect(moveRule(fixture, 'r_00000001', -1)).toBe(fixture);
		expect(ids(moveRule(fixture, 'r_00000001', 3)).slice(0, 4)).toEqual(['r_00000002', 'r_00000003', 'r_00000004', 'r_00000001']);
	});

	test('updating a one-line rule keeps its key order and its comment', () => {
		const out = updateRule(fixture, 'r_00000001', { id: 'r_00000001', from: '/about-us', to: '/about-new', code: 302 });
		expect(out).toContain('    { "id": "r_00000001", "from": "/about-us", "to": "/about-new", "code": 302 },');
		expectComments(out);
	});

	test('updating a multi-line rule removes keys no longer set', () => {
		const out = updateRule(fixture, 'r_00000004', { id: 'r_00000004', from: '/members', to: '/dashboard' });
		expect(readRules(out)[3]).toEqual({ id: 'r_00000004', from: '/members', to: '/dashboard' });
		expectComments(out);
	});
});

describe('group and settings edits', () => {
	test('setGroupField keeps the comment beside the title', () => {
		const out = setGroupField(fixture, 'title', 'Old site');
		expect(out).toContain('"title": "Old site", // shown in the UI');
		expect(parseJsonc(setGroupField(fixture, 'enabled', false)).data.enabled).toBe(false);
		expect(parseJsonc(setGroupField(fixture, 'position', 5)).data.position).toBe(5);
	});

	test('newGroupText is a valid empty group', () => {
		expect(parseJsonc(newGroupText('legacy', 'Legacy "old"')).data).toEqual({ title: 'Legacy "old"', enabled: true, position: 10, rules: [] });
	});

	test('settings edits write only what changed, key by key', () => {
		const text = '{\n  // Logs\n  "log": {\n    "redirects": 7, // a week\n    "ip": "none"\n  }\n}\n';
		const defaults = { log: { redirects: 7, notFound: 7, ip: 'none' }, trackHits: true, headers: { site: {}, redirect: {} } };
		expect(applySettingsEdits(text, defaults, { log: { redirects: 7, notFound: 7, ip: 'none' }, trackHits: true })).toBe(text);
		const out = applySettingsEdits(text, defaults, { log: { redirects: 30, notFound: 7, ip: 'masked' }, trackHits: false, headers: { site: { 'X-Frame-Options': 'DENY' }, redirect: {} } });
		expect(out).toContain('// a week');
		expect(out).toContain('// Logs');
		expect(parseJsonc(out).data).toEqual({ log: { redirects: 30, ip: 'masked' }, trackHits: false, headers: { site: { 'X-Frame-Options': 'DENY' } } });
	});

	test('newRuleId avoids taken ids', () => {
		const id = newRuleId();
		expect(id).toMatch(/^r_[a-f0-9]{8}$/);
		expect(newRuleId([id])).not.toBe(id);
	});
});
