import './public-path';
import './styles/admin.css';
import { createRoot } from 'react-dom/client';
import { AdminApp } from './app';
import { getBootstrap } from './bootstrap';

/** Mounts the admin into #proto-blocks-admin (printed by includes/Admin/AdminApp.php). */
function mount(): void {
	const node = document.getElementById('proto-blocks-admin');
	// Screens still rendered by PHP load this script too but have no mount node.
	if (!node) {
		return;
	}
	createRoot(node).render(<AdminApp bootstrap={getBootstrap()} />);
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', mount);
} else {
	mount();
}
