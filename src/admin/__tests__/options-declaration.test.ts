import { readFileSync } from 'fs';
import { join } from 'path';
import { applyDeclarationEdits, parseJsonc } from '@/admin/content-model/jsonc';
import { CAPABILITY_PATTERN, dataFromForm, formFromData } from '@/admin/options-pages/declaration';

const cadcoText = readFileSync(join(__dirname, '../../../tests/fixtures/options-pages/cadco-display-settings/page.jsonc'), 'utf8');
const cadcoData = { ...parseJsonc(cadcoText).data, acf_extra: { keep: true } };

test('formFromData fills defaults for absent keys', () => {
	const form = formFromData({ title: 'X' });
	expect(form.title).toBe('X');
	expect(form.menu).toBe('top');
	expect(form.capability).toBe('manage_options');
	expect(form.autoload).toBe(true);
	expect(form.storage).toEqual({ mode: 'fields' });
	expect(form.fields).toEqual([]);
	expect(form.tabs).toEqual([]);
	expect(form.children).toEqual([]);
});

test('formFromData reads the CADCO declaration', () => {
	const form = formFromData(cadcoData);
	expect(form.menu).toBe('edit.php?post_type=product');
	expect(form.capability).toBe('manage_woocommerce');
	expect(form.storage).toEqual({ mode: 'array', option: 'cadco_display_settings' });
	expect(form.fields).toHaveLength(3);
});

test('dataFromForm changes only what the form changed and keeps unknown keys', () => {
	const next = dataFromForm(cadcoData, { ...formFromData(cadcoData), capability: 'edit_products' });
	expect(next).toEqual({ ...cadcoData, capability: 'edit_products' });
	expect(next.acf_extra).toEqual({ keep: true });
});

test('dataFromForm writes no defaults the file did not have', () => {
	expect(dataFromForm({ title: 'X' }, formFromData({ title: 'X' }))).toEqual({ title: 'X' });
});

test('dataFromForm removes optional keys the form cleared and writes non-defaults', () => {
	const data = { title: 'X', icon: 'dashicons-admin-generic', position: 3 };
	const next = dataFromForm(data, {
		...formFromData(data),
		icon: undefined,
		position: undefined,
		autoload: false,
		storage: { mode: 'array', option: 'x_settings' },
	});
	expect(next).toEqual({ title: 'X', autoload: false, storage: { mode: 'array', option: 'x_settings' } });
});

test('the menu title goes under the key the file already uses, else menuTitle', () => {
	expect(dataFromForm({ title: 'X' }, { ...formFromData({ title: 'X' }), menu_title: 'Short' })).toEqual({ title: 'X', menuTitle: 'Short' });
	const old = { title: 'X', menu_title: 'Old' };
	expect(formFromData(old).menu_title).toBe('Old');
	expect(dataFromForm(old, { ...formFromData(old), menu_title: 'New' })).toEqual({ title: 'X', menu_title: 'New' });
});

test('the edited text keeps the file comment', () => {
	const next = dataFromForm(cadcoData, { ...formFromData(cadcoData), capability: 'edit_products' });
	const text = applyDeclarationEdits(cadcoText, parseJsonc(cadcoText).data, next);
	expect(text).toContain('// CADCO product display settings');
	expect(text).toContain('"capability": "edit_products"');
	expect(parseJsonc(text).data.capability).toBe('edit_products');
});

test('CAPABILITY_PATTERN follows the schema grammar', () => {
	expect(CAPABILITY_PATTERN.test('manage_woocommerce')).toBe(true);
	expect(CAPABILITY_PATTERN.test('Manage Options')).toBe(false);
	expect(CAPABILITY_PATTERN.test('9_lives')).toBe(false);
});
