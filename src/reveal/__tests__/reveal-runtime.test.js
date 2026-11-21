/**
 * The reveal runtime's watchdog for `manual` blocks.
 *
 * The runtime is a plain IIFE in assets/js; it is read and evaluated here
 * against jsdom with a controllable IntersectionObserver and fake timers.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const SOURCE = fs.readFileSync(
	path.join( __dirname, '../../../assets/js/reveal-runtime.js' ),
	'utf8'
);

let observers;

class FakeObserver {
	constructor( callback ) {
		this.callback = callback;
		this.targets = new Set();
		observers.push( this );
	}
	observe( el ) {
		this.targets.add( el );
	}
	unobserve( el ) {
		this.targets.delete( el );
	}
	disconnect() {
		this.targets.clear();
	}
}

function enterView( el ) {
	observers.forEach( ( io ) => {
		if ( io.targets.has( el ) ) {
			io.callback( [ { target: el, isIntersecting: true } ] );
		}
	} );
}

function setReadyState( value ) {
	Object.defineProperty( document, 'readyState', {
		configurable: true,
		get: () => value,
	} );
}

function boot( markup ) {
	document.body.innerHTML = markup;
	// eslint-disable-next-line no-new-func
	new Function( SOURCE )();
}

describe( 'reveal runtime: manual watchdog', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		observers = [];
		window.IntersectionObserver = FakeObserver;
		window.matchMedia = () => ( { matches: false } );
		document.documentElement.className = '';
		setReadyState( 'interactive' );
	} );

	afterEach( () => {
		jest.useRealTimers();
		delete window.IntersectionObserver;
	} );

	it( 'does not reveal a manual block that entered view before the page has loaded', () => {
		boot( '<section data-proto-animate="manual"></section>' );
		const el = document.querySelector( 'section' );

		enterView( el );
		jest.advanceTimersByTime( 5000 );

		// Its view.js may still be downloading; revealing now would flash.
		expect( el.getAttribute( 'data-proto-animate' ) ).toBe( 'manual' );
	} );

	it( 'reveals it after the grace once the page has loaded', () => {
		boot( '<section data-proto-animate="manual"></section>' );
		const el = document.querySelector( 'section' );

		enterView( el );
		jest.advanceTimersByTime( 3000 );

		setReadyState( 'complete' );
		window.dispatchEvent( new Event( 'load' ) );

		jest.advanceTimersByTime( 1499 );
		expect( el.getAttribute( 'data-proto-animate' ) ).toBe( 'manual' );

		jest.advanceTimersByTime( 1 );
		expect( el.getAttribute( 'data-proto-animate' ) ).toBe( 'done' );
	} );

	it( 'still reveals it when load never fires', () => {
		boot( '<section data-proto-animate="manual"></section>' );
		const el = document.querySelector( 'section' );

		enterView( el );
		jest.advanceTimersByTime( 10000 + 1500 );

		expect( el.getAttribute( 'data-proto-animate' ) ).toBe( 'done' );
	} );

	it( 'starts the grace at once when the page has already loaded', () => {
		setReadyState( 'complete' );
		boot( '<section data-proto-animate="manual"></section>' );
		const el = document.querySelector( 'section' );

		enterView( el );
		jest.advanceTimersByTime( 1500 );

		expect( el.getAttribute( 'data-proto-animate' ) ).toBe( 'done' );
	} );

	it( 'leaves a block its own script finished alone', () => {
		setReadyState( 'complete' );
		boot( '<section data-proto-animate="manual"></section>' );
		const el = document.querySelector( 'section' );
		const reveals = jest.fn();
		el.addEventListener( 'proto-blocks:reveal', reveals );

		enterView( el );
		el.setAttribute( 'data-proto-animate', 'done' );
		jest.advanceTimersByTime( 5000 );

		expect( reveals ).not.toHaveBeenCalled();
	} );

	it( 'still reveals pending blocks as soon as they enter view', () => {
		boot( '<section data-proto-animate="pending"></section>' );
		const el = document.querySelector( 'section' );

		enterView( el );

		expect( el.getAttribute( 'data-proto-animate' ) ).toBe( 'done' );
	} );
} );
