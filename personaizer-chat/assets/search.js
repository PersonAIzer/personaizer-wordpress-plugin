/**
 * PERSONAIZER AI Search — frontend.
 *
 * Progressive enhancement over the site's own search: never intercepts plain Enter (the native
 * search form still submits exactly as it always did), only ever adds a dropdown of AI-ranked
 * results above it. Talks to PERSONAIZER's /v1/search directly from the browser using the public
 * Persona ID (the same embed-auth model chat.js already uses) — no WordPress round-trip per query.
 *
 * window.PersonaizerSearchConfig (set inline by PHP) carries:
 *   { apiBase, personaId, mode, selector }
 * `selector` is optional — an admin-supplied CSS selector for binding to the theme's own search
 * input, in addition to (or instead of) the [personaizer_search] shortcode's own input.
 */
( function () {
    var cfg = window.PersonaizerSearchConfig;
    if ( ! cfg || ! cfg.apiBase || ! cfg.personaId ) return;

    var MIN_CHARS = 2;
    var DEBOUNCE_MS = 300;
    var TOP_K = 6;
    var LINK_RE = /^https?:\/\//i;

    /**
     * Finds (or creates) the results panel that belongs to a given search input. The
     * [personaizer_search] shortcode already wraps its input in a positioned `.pz-search`
     * container, so a plain `position: absolute` panel lands correctly. A selector-bound input
     * (the owner's own theme markup) has no such wrapper — CSS `position: absolute` there would
     * anchor to whatever positioned ancestor the theme happens to have, landing anywhere on the
     * page. For that case the panel is marked `data-pz-floating` and positioned in JS instead
     * (see positionFloatingPanel), via `position: fixed` relative to the viewport.
     */
    function resultsPanelFor( input ) {
        var panel = input.nextElementSibling;
        if ( panel && panel.classList && panel.classList.contains( 'pz-search-results' ) ) {
            return panel;
        }
        panel = document.createElement( 'div' );
        panel.className = 'pz-search-results';
        panel.hidden = true;
        if ( ! input.closest( '.pz-search' ) ) {
            panel.dataset.pzFloating = '1';
            panel.style.position = 'fixed';
            document.body.appendChild( panel );
        } else {
            input.insertAdjacentElement( 'afterend', panel );
        }
        return panel;
    }

    function positionFloatingPanel( panel, input ) {
        if ( ! panel.dataset.pzFloating ) return;
        var rect = input.getBoundingClientRect();
        panel.style.top = ( rect.bottom + 6 ) + 'px';
        panel.style.left = rect.left + 'px';
        panel.style.right = 'auto';
        panel.style.width = rect.width + 'px';
    }

    function clearPanel( panel ) {
        while ( panel.firstChild ) panel.removeChild( panel.firstChild );
        panel.hidden = true;
    }

    function setMessage( panel, input, text ) {
        clearPanel( panel );
        var msg = document.createElement( 'div' );
        msg.className = 'pz-search-message';
        msg.textContent = text;
        panel.appendChild( msg );
        positionFloatingPanel( panel, input );
        panel.hidden = false;
    }

    function primaryLink( hit ) {
        var links = hit.links || [];
        var chosen = null;
        for ( var i = 0; i < links.length; i++ ) {
            if ( links[ i ] && links[ i ].is_primary ) { chosen = links[ i ]; break; }
        }
        if ( ! chosen && links.length ) chosen = links[ 0 ];
        var url = chosen && chosen.url;
        return ( typeof url === 'string' && LINK_RE.test( url ) ) ? url : null;
    }

    function renderResults( panel, input, hits ) {
        clearPanel( panel );
        if ( ! hits.length ) {
            setMessage( panel, input, 'No results.' );
            return;
        }

        var list = document.createElement( 'ul' );
        list.className = 'pz-search-list';
        list.setAttribute( 'role', 'listbox' );

        hits.forEach( function ( hit ) {
            var url = primaryLink( hit );
            var item = document.createElement( 'li' );
            item.className = 'pz-search-item';
            item.setAttribute( 'role', 'option' );

            var el = document.createElement( url ? 'a' : 'div' );
            el.className = 'pz-search-item-link';
            if ( url ) el.href = url;

            if ( hit.images && hit.images.length && typeof hit.images[ 0 ] === 'string' ) {
                var img = document.createElement( 'img' );
                img.className = 'pz-search-item-image';
                img.src = hit.images[ 0 ];
                img.alt = '';
                el.appendChild( img );
            }

            var text = document.createElement( 'div' );
            text.className = 'pz-search-item-text';

            var title = document.createElement( 'div' );
            title.className = 'pz-search-item-title';
            title.textContent = hit.title || '(untitled)';
            text.appendChild( title );

            var excerptSource = hit.content || hit.description || '';
            if ( excerptSource ) {
                var excerpt = document.createElement( 'div' );
                excerpt.className = 'pz-search-item-excerpt';
                excerpt.textContent = excerptSource.length > 140
                    ? excerptSource.slice( 0, 140 ) + '…'
                    : excerptSource;
                text.appendChild( excerpt );
            }

            if ( typeof hit.price === 'number' ) {
                var price = document.createElement( 'div' );
                price.className = 'pz-search-item-price';
                price.textContent = ( hit.currency ? hit.currency + ' ' : '' ) + hit.price;
                text.appendChild( price );
            }

            el.appendChild( text );
            item.appendChild( el );
            list.appendChild( item );
        } );

        panel.appendChild( list );
        positionFloatingPanel( panel, input );
        panel.hidden = false;
    }

    function wireInput( input ) {
        if ( input.dataset.pzSearchWired ) return;
        input.dataset.pzSearchWired = '1';

        var panel = resultsPanelFor( input );
        var debounceTimer = null;
        var controller = null;
        var highlighted = -1;

        function items() {
            return panel.querySelectorAll( '.pz-search-item-link' );
        }

        function setHighlight( index ) {
            var els = items();
            if ( ! els.length ) return;
            if ( highlighted >= 0 && els[ highlighted ] ) {
                els[ highlighted ].classList.remove( 'is-highlighted' );
            }
            highlighted = ( index + els.length ) % els.length;
            els[ highlighted ].classList.add( 'is-highlighted' );
        }

        function runSearch( query ) {
            if ( controller ) controller.abort();
            controller = ( 'AbortController' in window ) ? new AbortController() : null;

            fetch( cfg.apiBase + '/v1/search', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Persona-Id': cfg.personaId
                },
                body: JSON.stringify( { query: query, mode: cfg.mode || 'smart', top_k: TOP_K } ),
                signal: controller ? controller.signal : undefined
            } )
                .then( function ( res ) {
                    if ( ! res.ok ) throw new Error( 'search failed' );
                    return res.json();
                } )
                .then( function ( data ) {
                    highlighted = -1;
                    renderResults( panel, input, ( data && data.results ) || [] );
                } )
                .catch( function ( err ) {
                    if ( err && err.name === 'AbortError' ) return;
                    setMessage( panel, input, 'Search is temporarily unavailable.' );
                } );
        }

        input.addEventListener( 'input', function () {
            var query = input.value.trim();
            if ( debounceTimer ) clearTimeout( debounceTimer );
            if ( query.length < MIN_CHARS ) {
                if ( controller ) controller.abort();
                clearPanel( panel );
                return;
            }
            debounceTimer = setTimeout( function () { runSearch( query ); }, DEBOUNCE_MS );
        } );

        input.addEventListener( 'keydown', function ( e ) {
            if ( panel.hidden ) return;
            if ( e.key === 'ArrowDown' ) {
                e.preventDefault();
                setHighlight( highlighted + 1 );
            } else if ( e.key === 'ArrowUp' ) {
                e.preventDefault();
                setHighlight( highlighted - 1 );
            } else if ( e.key === 'Escape' ) {
                clearPanel( panel );
            } else if ( e.key === 'Enter' && highlighted >= 0 ) {
                // Only intercept Enter when a result is actually highlighted — otherwise the
                // native search form submits exactly as it always did.
                var els = items();
                var target = els[ highlighted ];
                if ( target && target.tagName === 'A' && target.href ) {
                    e.preventDefault();
                    window.location.href = target.href;
                }
            }
        } );

        document.addEventListener( 'click', function ( e ) {
            if ( e.target !== input && ! panel.contains( e.target ) ) {
                clearPanel( panel );
            }
        } );
    }

    var targets = document.querySelectorAll( '.pz-search-input' );
    targets.forEach( wireInput );

    if ( cfg.selector ) {
        try {
            document.querySelectorAll( cfg.selector ).forEach( wireInput );
        } catch ( e ) {
            // An invalid selector shouldn't break the rest of the page.
        }
    }
} )();
