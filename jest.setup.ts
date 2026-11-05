import '@testing-library/jest-dom';

// jsdom lacks the pointer-capture and scroll APIs Radix Select calls.
if (typeof Element !== 'undefined') {
	const proto = Element.prototype as unknown as Record<string, unknown>;
	proto.hasPointerCapture ??= () => false;
	proto.setPointerCapture ??= () => undefined;
	proto.releasePointerCapture ??= () => undefined;
	proto.scrollIntoView ??= () => undefined;
}

// Admin screens are React.lazy modules. The first test in a file otherwise pays
// the cold transform of a whole screen graph inside a findBy* window, which is
// what made list tests time out on starved CI runners. Preload them once per
// admin test file so lazy() resolves from the module cache.
beforeAll(async () => {
	const testPath = expect.getState().testPath ?? '';
	if (!/[\\/]src[\\/]admin[\\/]__tests__[\\/]/.test(testPath)) {
		return;
	}
	await Promise.all([
		import('@/admin/screens/SystemScreen'),
		import('@/admin/screens/WizardScreen'),
		import('@/admin/screens/DashboardScreen'),
		import('@/admin/screens/TailwindScreen'),
		import('@/admin/screens/PreviewsScreen'),
		import('@/admin/screens/PostTypesScreen'),
		import('@/admin/screens/TaxonomiesScreen'),
		import('@/admin/screens/FieldsScreen'),
		import('@/admin/screens/OptionsPagesScreen'),
		import('@/admin/screens/OptionsPageScreen'),
		import('@/admin/screens/RedirectsScreen'),
		import('@/admin/screens/ScriptsScreen'),
	]);
}, 120000);
