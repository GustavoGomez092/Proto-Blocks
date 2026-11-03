/**
 * Field box entry (assets/js/fields-box.js). Mounts a FieldsForm into every
 * [data-proto-fields-box] element printed by the meta box, reading the
 * element's <script type="application/json"> payload.
 *
 * Marks `proto-fields:start` before mounting and `proto-fields:mounted` once
 * the last box has committed, for the box performance budget.
 */
import './styles.css';
import { useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { PortalProvider } from '@/admin/components/shell/PortalProvider';
import type { BoxPayload } from '../model/types';
import { FieldsForm } from './FieldsForm';

const PORTAL_ID = 'proto-blocks-fields-portal';

function Committed( { onCommit }: { onCommit: () => void } ) {
	useEffect( onCommit, [] ); // eslint-disable-line react-hooks/exhaustive-deps
	return null;
}

function readPayload( el: HTMLElement ): BoxPayload | null {
	const script = el.querySelector( 'script[type="application/json"]' );
	try {
		const data = JSON.parse( script?.textContent ?? '' );
		return data && typeof data === 'object' && Array.isArray( data.fields ) ? ( data as BoxPayload ) : null;
	} catch {
		return null;
	}
}

/** Popovers from later inputs portal here; the class gives them the box tokens. */
function portal(): HTMLElement {
	let node = document.getElementById( PORTAL_ID );
	if ( ! node ) {
		node = document.createElement( 'div' );
		node.id = PORTAL_ID;
		node.className = 'proto-blocks-fields';
		document.body.appendChild( node );
	}
	return node;
}

export function mountFieldBoxes( root: ParentNode = document ): number {
	performance.mark?.( 'proto-fields:start' );
	const boxes = Array.from( root.querySelectorAll< HTMLElement >( '[data-proto-fields-box]' ) ).filter(
		( el ) => el.dataset.protoFieldsMounted !== '1'
	);
	const mounts = boxes
		.map( ( el ) => ( { el, payload: readPayload( el ) } ) )
		.filter( ( m ): m is { el: HTMLElement; payload: BoxPayload } => {
			if ( ! m.payload ) {
				// eslint-disable-next-line no-console
				console.error( 'Proto-Blocks: a field box has no readable payload.', m.el );
			}
			return m.payload !== null;
		} );

	let pending = mounts.length;
	const committed = () => {
		pending -= 1;
		if ( pending === 0 ) {
			performance.mark?.( 'proto-fields:mounted' );
		}
	};
	if ( pending === 0 ) {
		performance.mark?.( 'proto-fields:mounted' );
		return 0;
	}

	const portalNode = portal();
	for ( const { el, payload } of mounts ) {
		el.dataset.protoFieldsMounted = '1';
		const host = document.createElement( 'div' );
		host.className = 'proto-blocks-fields';
		el.appendChild( host );
		createRoot( host ).render(
			<PortalProvider container={ portalNode }>
				<FieldsForm payload={ payload } />
				<Committed onCommit={ committed } />
			</PortalProvider>
		);
	}
	return mounts.length;
}

if ( typeof document !== 'undefined' ) {
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', () => mountFieldBoxes() );
	} else {
		mountFieldBoxes();
	}
}
