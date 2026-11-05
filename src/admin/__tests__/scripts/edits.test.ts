import { parseJsonc } from '@/admin/content-model/jsonc';
import { formFromData, newScriptText, normalizeWhere, scriptText, setEnabledText } from '@/admin/scripts/edits';
import { buildTarget, scriptErrors, slugFromTitle, snippetError, targetError, utf8Length } from '@/admin/scripts/schema';
import { validateForm, savePayload } from '@/admin/scripts/ScriptEditor';
import { GTM_TEXT } from './fixtures';

const EMPTY = { head: '', bodyOpen: '', bodyClose: '' };

describe('script.jsonc edits', () => {
	test('an unchanged form leaves the text byte for byte', () => {
		const data = parseJsonc(GTM_TEXT).data;
		const form = formFromData(data, { head: 'x', bodyOpen: 'y', bodyClose: '' });
		expect(scriptText(GTM_TEXT, data, form)).toBe(GTM_TEXT);
	});

	test('changes keep comments and unknown keys', () => {
		const text = '{\n  // c1\n  "title": "A",\n  "$schema": "x",\n  "where": { /* c2 */ "include": ["all"] }\n}\n';
		const data = parseJsonc(text).data;
		const form = formFromData(data, EMPTY);
		const next = scriptText(text, data, { ...form, title: 'B', priority: 3, where: { ...form.where, exclude: ['404'] }, snippets: { ...EMPTY, head: '<meta>' } });
		expect(next).toContain('// c1');
		expect(next).toContain('/* c2 */');
		expect(next).toContain('"$schema": "x"');
		const parsed = parseJsonc(next).data;
		expect(parsed).toEqual({ title: 'B', $schema: 'x', priority: 3, head: { file: 'head.html' }, where: { include: ['all'], exclude: ['404'] } });
	});

	test('an inline snippet becomes the file form; an emptied location loses its key', () => {
		const text = '{ "title": "A", "head": "<meta>", "bodyClose": { "file": "body-close.html" } }';
		const data = parseJsonc(text).data;
		const next = parseJsonc(scriptText(text, data, formFromData(data, { head: '<meta>', bodyOpen: '', bodyClose: '' }))).data;
		expect(next.head).toEqual({ file: 'head.html' });
		expect('bodyClose' in next).toBe(false);
	});

	test('a where block is created when the file had none', () => {
		const text = '{ "title": "A" }';
		const data = parseJsonc(text).data;
		const form = formFromData(data, EMPTY);
		const next = parseJsonc(scriptText(text, data, { ...form, where: { ...form.where, loggedIn: true } })).data;
		expect(next.where).toEqual({ loggedIn: true });
	});

	test('setEnabledText', () => {
		expect(parseJsonc(setEnabledText(GTM_TEXT, false)).data.enabled).toBe(false);
		expect(setEnabledText(GTM_TEXT, false)).toContain('// keep first');
		expect(parseJsonc(setEnabledText('{ "title": "x" }', false)).data).toEqual({ title: 'x', enabled: false });
	});

	test('normalizeWhere defaults', () => {
		expect(normalizeWhere(undefined)).toEqual({ include: ['all'], exclude: [], loggedIn: null, roles: [], excludeRoles: [] });
		expect(normalizeWhere({ include: [] }).include).toEqual([]);
	});

	test('the new-file text is valid against the shared schema', () => {
		const data = parseJsonc(newScriptText('Chat <widget>')).data;
		expect(data.title).toBe('Chat widget');
		expect(scriptErrors(data)).toEqual([]);
	});
});

describe('schema mirror', () => {
	test('targets', () => {
		for (const ok of ['all', '404', 'singular:product', 'page:12', 'term:category:news', 'url:/landing/*', 'url:/']) {
			expect(targetError(ok)).toBeNull();
		}
		for (const bad of ['everywhere', 'page:', 'page:a b', 'url:x', 'taxonomy:Genre', 'term:genre']) {
			expect(targetError(bad)).not.toBeNull();
		}
		expect(buildTarget('page:', ' about ')).toBe('page:about');
		expect(buildTarget('404', 'ignored')).toBe('404');
	});

	test('script schema: unknown keys, bad file names, markup in titles, conflicting where', () => {
		expect(scriptErrors({ title: 'x', colour: 'red' }).map((e) => e.path)).toEqual(['/colour']);
		expect(scriptErrors({ title: 'a <b>' }).map((e) => e.path)).toEqual(['/title']);
		expect(scriptErrors({ title: 'x', head: { path: 'x' } }).length).toBeGreaterThan(0);
		expect(scriptErrors({ title: 'x', where: { include: ['nope'] } }).map((e) => e.path)).toEqual(['/where/include/0']);
		expect(scriptErrors({ title: 'x', where: { loggedIn: false, roles: ['editor'] } }).map((e) => e.path)).toEqual(['/where/roles']);
	});

	test('snippets: PHP tags and size', () => {
		expect(snippetError('<?php x')).not.toBeNull();
		expect(snippetError('<?= x')).not.toBeNull();
		expect(snippetError('<? x')).not.toBeNull();
		expect(snippetError('<?xml version="1.0"?>')).toBeNull();
		expect(snippetError('a'.repeat(262144))).toBeNull();
		expect(snippetError('é'.repeat(131073))).not.toBeNull();
		expect(utf8Length('é😀')).toBe(6);
	});

	test('slugFromTitle', () => {
		expect(slugFromTitle('Google Tag Manager')).toBe('google-tag-manager');
		expect(slugFromTitle('123 Café!')).toBe('cafe');
		expect(slugFromTitle('x'.repeat(40))).toHaveLength(32);
	});

	test('validateForm and savePayload', () => {
		const form = formFromData({ title: 'Chat' }, { ...EMPTY, bodyClose: '<script></script>' });
		const payload = savePayload(null, form);
		expect(payload.hash).toBeNull();
		expect(validateForm(form, payload.text, 'chat')).toEqual({});
		expect(Object.keys(validateForm({ ...form, title: '' }, payload.text, 'Chat'))).toEqual(['title', 'slug']);
	});
});
