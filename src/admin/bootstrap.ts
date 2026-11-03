/**
 * Bootstrap data printed by includes/Admin/AdminApp.php as
 * window.protoBlocksAdmin before the admin script runs.
 */

export type Screen = 'dashboard' | 'tailwind' | 'system' | 'previews' | 'wizard' | 'post-types' | 'taxonomies' | 'fields' | 'options-page';

export type FileModsConstant = 'DISALLOW_FILE_MODS' | 'DISALLOW_FILE_EDIT';

export type WriteReason = 'file_mods' | 'multisite' | null;

export interface AdminBootstrap {
	screen: Screen;
	/** REST root for this plugin, ends with `/proto-blocks/v1/`. */
	restRoot: string;
	restNonce: string;
	/** wp-admin/admin.php, for links to other admin screens. */
	adminUrl: string;
	version: string;
	/** False when DISALLOW_FILE_MODS / DISALLOW_FILE_EDIT is set. */
	fileModsAllowed: boolean;
	/**
	 * Why the current user cannot write declaration files: file mods are off,
	 * or a multisite sub-site administrator; null when writes are allowed.
	 */
	writeReason?: WriteReason;
	/** The constant that disables file writes; null when they are allowed. */
	fileModsConstant: FileModsConstant | null;
	assetBase: string;
	tailwind: { ajaxUrl: string; actionPrefix: string; nonce: string } | null;
}

declare global {
	interface Window {
		protoBlocksAdmin?: AdminBootstrap;
	}
}

export function getBootstrap(): AdminBootstrap {
	const data = typeof window === 'undefined' ? undefined : window.protoBlocksAdmin;
	if (!data) {
		throw new Error('protoBlocksAdmin bootstrap missing');
	}
	return data;
}

/** Why declaration files can't be written (see AdminBootstrap.writeReason); derived from fileModsAllowed when the server sent none. */
export function getWriteReason(): WriteReason {
	const data = getBootstrap();
	return data.writeReason ?? (data.fileModsAllowed ? null : 'file_mods');
}
