/**
 * Proto-Blocks Preview Capture -- admin UI orchestrator.
 *
 * Drives the "Capture missing / Capture selected / Recapture all"
 * buttons on the Preview Capture admin page:
 *
 *   1. Collects a list of block names from the table rows.
 *   2. For each block, swaps the off-screen iframe's src to the
 *      block's `renderUrl` (a server-rendered minimal HTML doc
 *      containing just that block + theme styles).
 *   3. Waits for iframe load + fonts + images, then runs html2canvas
 *      against the iframe body.
 *   4. POSTs the resulting base64 PNG to /wp-json/proto-blocks/v1/
 *      preview-capture.
 *   5. Updates the row's preview thumbnail + status badge in place.
 *
 * Sequential, one block at a time -- parallel iframe loads burn
 * memory and clash on the global font cache.
 */
( function () {
    'use strict';

    const root = document.querySelector( '.proto-blocks-capture' );
    if ( ! root ) return;

    const config = window.protoBlocksCapture || {};
    const iframe = root.querySelector( '.proto-blocks-capture__frame' );
    const status = root.querySelector( '.proto-blocks-capture__status' );
    const selectAll = root.querySelector( '[data-select-all]' );

    if ( ! iframe ) return;

    // Render width matches what the capture iframe document is sized
    // for (see PreviewCapture::renderBlockDocument inline CSS).
    iframe.width = String( config.width || 1280 );
    iframe.style.width = ( config.width || 1280 ) + 'px';

    function getRows( filter ) {
        const rows = Array.from( root.querySelectorAll( '[data-block-row]' ) );
        if ( filter === 'missing' ) {
            return rows.filter( ( r ) => r.dataset.hasPreview !== '1' );
        }
        if ( filter === 'selected' ) {
            return rows.filter( ( r ) => {
                const cb = r.querySelector( '[data-block-checkbox]' );
                return cb && cb.checked;
            } );
        }
        return rows; // all
    }

    function setRowStatus( row, message, level ) {
        const cell = row.querySelector( '.proto-blocks-capture__row-status' );
        if ( ! cell ) return;
        cell.textContent = message || '';
        cell.dataset.level = level || '';
    }

    function setGlobalStatus( message ) {
        if ( status ) status.textContent = message || '';
    }

    /** Resolve when the iframe document fires `load`. */
    function waitForLoad( frame ) {
        return new Promise( ( resolve ) => {
            frame.addEventListener( 'load', () => resolve(), { once: true } );
        } );
    }

    /** Wait for fonts + images inside the iframe to settle. */
    async function waitForReady( frame ) {
        const doc = frame.contentDocument;
        if ( ! doc ) return;

        try {
            if ( doc.fonts && doc.fonts.ready ) {
                await doc.fonts.ready;
            }
        } catch ( e ) {
            // Font loading API can throw inside cross-origin iframes;
            // we're same-origin but defensively swallow.
        }

        const images = Array.from( doc.images || [] );
        await Promise.all(
            images.map( ( img ) => {
                if ( img.complete && img.naturalWidth > 0 ) return null;
                return new Promise( ( resolve ) => {
                    img.addEventListener( 'load', resolve, { once: true } );
                    img.addEventListener( 'error', resolve, { once: true } );
                } );
            } )
        );

        // Extra settle frame -- gives GSAP/scroll-trigger animations
        // that auto-fire on load a chance to land at their final state
        // before we screenshot.
        await new Promise( ( resolve ) => setTimeout( resolve, 350 ) );
    }

    async function captureOne( row ) {
        const blockName = row.querySelector( '[data-block-checkbox]' ).value;
        const renderUrl = row.getAttribute( 'data-render-url' );

        if ( ! renderUrl ) {
            setRowStatus( row, 'Missing render URL.', 'error' );
            return false;
        }

        setRowStatus( row, 'Rendering…', 'pending' );
        setGlobalStatus( 'Capturing ' + blockName + '…' );

        iframe.src = renderUrl;
        await waitForLoad( iframe );
        await waitForReady( iframe );

        const doc = iframe.contentDocument;
        if ( ! doc || ! doc.body ) {
            setRowStatus( row, 'Iframe failed to load.', 'error' );
            return false;
        }

        // Force the body to its natural rendered height so html2canvas
        // doesn't clip at the iframe's default viewport height.
        const bodyHeight = Math.max(
            doc.body.scrollHeight,
            doc.documentElement.scrollHeight,
            doc.body.offsetHeight,
            doc.documentElement.offsetHeight
        );
        iframe.style.height = bodyHeight + 'px';

        setRowStatus( row, 'Rasterizing…', 'pending' );

        let canvas;
        try {
            canvas = await window.html2canvas( doc.body, {
                backgroundColor: '#ffffff',
                useCORS: true,
                allowTaint: true,
                logging: false,
                width: config.width || 1280,
                height: bodyHeight,
                windowWidth: config.width || 1280,
                windowHeight: bodyHeight,
                scale: 1,
            } );
        } catch ( err ) {
            console.error( 'html2canvas failed for ' + blockName, err );
            setRowStatus( row, 'Rasterize failed.', 'error' );
            return false;
        }

        const dataUrl = canvas.toDataURL( 'image/png' );

        setRowStatus( row, 'Uploading…', 'pending' );

        let response;
        try {
            response = await fetch( config.restUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce,
                },
                body: JSON.stringify( { block: blockName, image: dataUrl } ),
            } );
        } catch ( err ) {
            console.error( 'Upload failed for ' + blockName, err );
            setRowStatus( row, 'Upload failed.', 'error' );
            return false;
        }

        if ( ! response.ok ) {
            const text = await response.text();
            console.error( 'Upload rejected for ' + blockName, text );
            setRowStatus( row, 'Upload rejected.', 'error' );
            return false;
        }

        const payload = await response.json();

        // Refresh the row's thumbnail in place.
        const cell = row.querySelector( '.proto-blocks-capture__thumb-cell' );
        if ( cell && payload && payload.previewUrl ) {
            cell.innerHTML =
                '<img class="proto-blocks-capture__thumb" alt="" src="' +
                payload.previewUrl + '?v=' + ( payload.mtime || Date.now() ) + '">';
        }
        row.dataset.hasPreview = '1';
        const badge = row.querySelector( '.proto-blocks-capture__badge' );
        if ( badge ) {
            badge.classList.remove( 'proto-blocks-capture__badge--missing' );
            badge.classList.add( 'proto-blocks-capture__badge--ok' );
            badge.textContent = 'Present';
        }
        setRowStatus( row, 'Captured.', 'ok' );
        return true;
    }

    async function captureMany( rows ) {
        if ( rows.length === 0 ) {
            setGlobalStatus( 'Nothing to capture.' );
            return;
        }
        let okCount = 0;
        let failCount = 0;
        for ( let i = 0; i < rows.length; i++ ) {
            setGlobalStatus(
                'Capturing ' + ( i + 1 ) + ' of ' + rows.length + '…'
            );
            const ok = await captureOne( rows[ i ] );
            ok ? okCount++ : failCount++;
        }
        setGlobalStatus(
            'Done. ' + okCount + ' captured, ' + failCount + ' failed.'
        );
    }

    // --- Event wiring -----------------------------------------------

    if ( selectAll ) {
        selectAll.addEventListener( 'change', () => {
            root.querySelectorAll( '[data-block-checkbox]' ).forEach( ( cb ) => {
                cb.checked = selectAll.checked;
            } );
        } );
    }

    root.addEventListener( 'click', ( e ) => {
        const button = e.target.closest( '[data-action]' );
        if ( ! button ) return;

        const action = button.getAttribute( 'data-action' );

        if ( action === 'capture-one' ) {
            const blockName = button.getAttribute( 'data-block' );
            const row = root.querySelector( '[data-block-row="' + blockName + '"]' );
            if ( row ) captureMany( [ row ] );
            return;
        }

        if ( action === 'capture-missing' ) {
            captureMany( getRows( 'missing' ) );
            return;
        }
        if ( action === 'capture-selected' ) {
            captureMany( getRows( 'selected' ) );
            return;
        }
        if ( action === 'capture-all' ) {
            captureMany( getRows( 'all' ) );
            return;
        }
    } );
} )();
