/**
 * Entity Maps — Manage Entities screen.
 *
 * A dependency-free master–detail editor. State lives in `state.entities`
 * (a working copy of the server data); each entity carries a client `_key` and
 * a `_dirty` flag. Saving an entity POSTs it to admin-ajax, which upserts the
 * bl_entity post and regenerates the files, then returns the canonical entity.
 */
( function () {
	'use strict';

	var D = window.BL_EM;
	if ( ! D ) { return; }

	var keySeq = 1;

	var state = {
		entities: ( D.entities || [] ).map( function ( e ) { e._key = keySeq++; e._dirty = false; return e; } ),
		selKey: null,
		validation: D.validation || []
	};

	var refs = {}; // persistent DOM nodes

	/* ------------------------------------------------------------------ */
	/* Small DOM helper.                                                   */
	/* ------------------------------------------------------------------ */

	function el( tag, attrs, kids ) {
		var n = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				var v = attrs[ k ];
				if ( v == null ) { return; }
				if ( k === 'class' ) { n.className = v; }
				else if ( k === 'text' ) { n.textContent = v; }
				else if ( k.indexOf( 'on' ) === 0 && typeof v === 'function' ) { n.addEventListener( k.slice( 2 ), v ); }
				else { n.setAttribute( k, v ); }
			} );
		}
		( kids || [] ).forEach( function ( c ) {
			if ( c == null ) { return; }
			n.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
		} );
		return n;
	}

	/** Build a <select>. `opts` is an array of strings or [value,label] pairs. */
	function selectField( value, opts, onChange ) {
		var s = el( 'select', { class: 'widefat' } );
		opts.forEach( function ( o ) {
			var val = Array.isArray( o ) ? o[ 0 ] : o;
			var lbl = Array.isArray( o ) ? o[ 1 ] : o;
			var opt = el( 'option', { value: val, text: lbl } );
			if ( String( val ) === String( value == null ? '' : value ) ) { opt.selected = true; }
			s.appendChild( opt );
		} );
		s.addEventListener( 'change', function () { onChange( s.value ); } );
		return s;
	}

	function labelledLabel( v, none ) { return v === '' ? none : v; }

	/* ------------------------------------------------------------------ */
	/* AJAX.                                                               */
	/* ------------------------------------------------------------------ */

	function post( action, data ) {
		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( 'nonce', D.nonce );
		Object.keys( data ).forEach( function ( k ) { fd.append( k, data[ k ] ); } );
		return fetch( D.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd } )
			.then( function ( r ) { return r.json(); } );
	}

	/* ------------------------------------------------------------------ */
	/* Model helpers.                                                      */
	/* ------------------------------------------------------------------ */

	function getSelected() {
		return state.entities.filter( function ( e ) { return e._key === state.selKey; } )[ 0 ] || null;
	}

	function knownIds() {
		var set = {};
		state.entities.forEach( function ( e ) { if ( e.entityId ) { set[ e.entityId ] = e.name; } } );
		return set;
	}

	/** Cheap live per-entity issues (mirrors the server's structural checks). */
	function entityIssues( e ) {
		var out = [];
		if ( ! e.description || ! String( e.description ).trim() ) { out.push( 'No description' ); }
		var ids = knownIds();
		( e.relations || [] ).forEach( function ( r ) {
			if ( r.targetId && ! ids[ r.targetId ] ) { out.push( 'Relation → ' + r.targetId + ' is missing' ); }
		} );
		return out;
	}

	function markDirty() {
		var e = getSelected();
		if ( e ) { e._dirty = true; }
		setStatus( '', '' );
		renderListItems();
	}

	function setStatus( text, cls ) {
		if ( ! refs.status ) { return; }
		refs.status.textContent = text;
		refs.status.className = 'bl-em-status' + ( cls ? ' ' + cls : '' );
	}

	/** Full-panel overlay + spinner while a save/delete is in flight. */
	function showSaving( msg ) {
		hideSaving();
		refs._overlay = el( 'div', { class: 'bl-em-saving-overlay' }, [ el( 'span', { class: 'bl-em-spinner' } ), msg || D.i18n.saving ] );
		refs.detail.appendChild( refs._overlay );
	}

	function hideSaving() {
		if ( refs._overlay && refs._overlay.parentNode ) { refs._overlay.parentNode.removeChild( refs._overlay ); }
		refs._overlay = null;
	}

	/* ------------------------------------------------------------------ */
	/* Top-level render (skeleton built once).                             */
	/* ------------------------------------------------------------------ */

	function render() {
		var root = document.getElementById( 'bl-em-manager' );
		root.textContent = '';

		refs.banner = el( 'div', { class: 'bl-em-banner' } );

		refs.search = el( 'input', { type: 'search', class: 'bl-em-search', placeholder: 'Search entities…' } );
		refs.search.addEventListener( 'input', renderListItems );

		refs.filter = selectField( '', [ [ '', 'All types' ] ].concat( D.vocab.types.map( function ( t ) { return [ t, t ]; } ) ), function () { renderListItems(); } );
		refs.filter.classList.add( 'bl-em-filter' );

		refs.count = el( 'div', { class: 'bl-em-count' } );
		refs.listScroll = el( 'div', { class: 'bl-em-list-scroll' } );

		var addBtn = el( 'button', { class: 'button button-primary', type: 'button', onclick: addEntity }, [ '＋ Add entity' ] );

		var listPane = el( 'div', { class: 'bl-em-list' }, [
			el( 'div', { class: 'bl-em-list-head' }, [ refs.search, refs.filter ] ),
			el( 'div', { class: 'bl-em-list-actions' }, [ addBtn ] ),
			refs.count,
			refs.listScroll
		] );

		refs.detail = el( 'div', { class: 'bl-em-detail' } );

		root.appendChild( refs.banner );
		root.appendChild( el( 'div', { class: 'bl-em-layout' }, [ listPane, refs.detail ] ) );

		renderBanner();
		renderListItems();
		renderDetail();
	}

	/* ------------------------------------------------------------------ */
	/* Banner (validation summary + save status).                          */
	/* ------------------------------------------------------------------ */

	function renderBanner() {
		var b = refs.banner;
		b.textContent = '';

		var errors = state.validation.filter( function ( i ) { return i.level === 'error'; } );
		var warns = state.validation.filter( function ( i ) { return i.level === 'warn'; } );

		var cls = 'bl-em-banner ' + ( errors.length ? 'is-error' : ( warns.length ? 'is-warn' : 'is-ok' ) );
		b.className = cls;

		var summary = errors.length || warns.length
			? ( errors.length + ' error' + ( errors.length === 1 ? '' : 's' ) + ', ' + warns.length + ' warning' + ( warns.length === 1 ? '' : 's' ) )
			: 'All checks passed';

		var dot = el( 'span', { class: 'dashicons ' + ( errors.length ? 'dashicons-warning' : ( warns.length ? 'dashicons-flag' : 'dashicons-yes-alt' ) ) } );
		b.appendChild( dot );
		b.appendChild( el( 'strong', { text: summary } ) );

		if ( errors.length || warns.length ) {
			var open = false;
			var list = el( 'ul', { class: 'bl-em-issues' } );
			list.style.display = 'none';
			list.style.margin = '8px 0 0';
			list.style.width = '100%';
			list.style.listStyle = 'disc';
			list.style.paddingLeft = '1.4em';
			state.validation.forEach( function ( i ) {
				if ( i.level === 'ok' ) { return; }
				list.appendChild( el( 'li', { text: i.msg, style: 'color:' + ( i.level === 'error' ? '#b32d2e' : '#b26200' ) } ) );
			} );
			var toggle = el( 'button', { type: 'button', class: 'button-link', style: 'margin-left:8px;' }, [ 'details' ] );
			toggle.addEventListener( 'click', function () { open = ! open; list.style.display = open ? 'block' : 'none'; } );
			b.appendChild( toggle );
			b.appendChild( list );
		}

		refs.status = el( 'span', { class: 'bl-em-status' } );
		b.appendChild( refs.status );
	}

	/* ------------------------------------------------------------------ */
	/* List.                                                               */
	/* ------------------------------------------------------------------ */

	function filteredEntities() {
		var q = ( refs.search.value || '' ).toLowerCase().trim();
		var type = refs.filter.value;
		return state.entities.filter( function ( e ) {
			if ( type && e.type !== type ) { return false; }
			if ( q && ( e.name || '' ).toLowerCase().indexOf( q ) === -1 && ( e.entityId || '' ).toLowerCase().indexOf( q ) === -1 ) { return false; }
			return true;
		} );
	}

	function renderListItems() {
		var scroll = refs.listScroll;
		scroll.textContent = '';

		var list = filteredEntities();
		refs.count.textContent = list.length + ' of ' + state.entities.length + ' entities';

		list.forEach( function ( e ) {
			var issues = entityIssues( e );
			var cls = 'bl-em-item' + ( e._key === state.selKey ? ' is-active' : '' ) + ( e._dirty ? ' is-new' : '' );

			var kids = [
				el( 'span', { class: 'bl-em-item-name', text: e.name || '(untitled)' } ),
				e.type ? el( 'span', { class: 'bl-em-badge', text: e.type } ) : null,
				issues.length ? el( 'span', { class: 'bl-em-warn dashicons dashicons-warning', title: issues.join( ' · ' ) } ) : null,
				el( 'span', { class: 'bl-em-item-eid', text: e.entityId || 'new' } )
			];

			var item = el( 'button', { type: 'button', class: cls }, kids );
			item.addEventListener( 'click', function () { selectEntity( e._key ); } );
			scroll.appendChild( item );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Detail.                                                             */
	/* ------------------------------------------------------------------ */

	function field( labelText, control, desc ) {
		return el( 'div', { class: 'bl-em-field' }, [
			el( 'label', {}, [ labelText, desc ? el( 'span', { class: 'description', text: ' ' + desc } ) : null ] ),
			control
		] );
	}

	function textInput( value, onInput, type ) {
		var i = el( 'input', { type: type || 'text', value: value || '' } );
		i.addEventListener( 'input', function () { onInput( i.value ); markDirty(); } );
		return i;
	}

	function renderDetail() {
		var d = refs.detail;
		d.textContent = '';

		var e = getSelected();
		if ( ! e ) {
			d.appendChild( el( 'div', { class: 'bl-em-empty' }, [ 'Select an entity on the left, or ', el( 'strong', { text: '＋ Add entity' } ), ' to create one.' ] ) );
			return;
		}

		// Save bar — sticky at the TOP of the panel so Save/Delete are always
		// reachable. Appended first; the scrollable body sits beneath it.
		var saveBtn = el( 'button', { type: 'button', class: 'button button-primary' }, [ 'Save entity' ] );
		saveBtn.addEventListener( 'click', function () { save( e, saveBtn ); } );
		var delBtn = el( 'button', { type: 'button', class: 'button button-link-delete', style: 'color:#b32d2e;' }, [ 'Delete' ] );
		delBtn.addEventListener( 'click', function () { del( e ); } );
		var dirty = el( 'span', { class: 'bl-em-dirty', text: e._dirty ? 'Unsaved changes' : '' } );
		d.appendChild( el( 'div', { class: 'bl-em-savebar' }, [ saveBtn, delBtn, dirty ] ) );

		// Scrollable body (everything else). Its top padding guarantees clear
		// space below the sticky bar so nothing is hidden behind it.
		var body = el( 'div', { class: 'bl-em-detail-body' } );
		d.appendChild( body );

		// Header + ID.
		body.appendChild( el( 'h2', { text: e.name || '(untitled entity)' } ) );
		body.appendChild( field( 'Entity ID', el( 'div', {}, [
			el( 'span', { class: 'bl-em-eid-display', text: e.entityId || '(assigned on first save)' } )
		] ), 'Stable identifier, never changes or is reused.' ) );

		// Name + description.
		body.appendChild( field( 'Name', textInput( e.name, function ( v ) {
			e.name = v;
			// live-update header + active list item without a full re-render
			body.querySelector( 'h2' ).textContent = v || '(untitled entity)';
		} ) ) );

		var descTa = el( 'textarea', { rows: '3' }, [ e.description || '' ] );
		descTa.addEventListener( 'input', function () { e.description = descTa.value; markDirty(); } );
		body.appendChild( field( 'Description', descTa ) );

		// Type + maturity.
		body.appendChild( el( 'div', { class: 'bl-em-row' }, [
			field( 'Type', selectField( e.type, D.vocab.types, function ( v ) { e.type = v; markDirty(); } ) ),
			field( 'Maturity', selectField( e.maturity, D.vocab.maturity.map( function ( m ) { return [ m, labelledLabel( m, '— none —' ) ]; } ), function ( v ) { e.maturity = v; markDirty(); } ) )
		] ) );

		// Alternate + canonical.
		body.appendChild( el( 'div', { class: 'bl-em-row' }, [
			field( 'Alternate name', textInput( e.alternate_name, function ( v ) { e.alternate_name = v; } ) ),
			field( 'Canonical label', textInput( e.canonical_label, function ( v ) { e.canonical_label = v; } ), '(for proprietary terms)' )
		] ) );

		// sameAs (with Find on Wikidata).
		body.appendChild( field( 'sameAs URL', sameAsPicker( e ), '(verified Wikidata etc. — the single most useful field for AI)' ) );

		// Attach to page (picker).
		body.appendChild( field( 'Attach to page', pagePicker( e ), '(where per-page schema is injected)' ) );

		// Chunks.
		body.appendChild( el( 'h3', { text: 'Evidence chunks' } ) );
		body.appendChild( el( 'p', { class: 'description', text: 'Short evidence passages (1–5 sentences) with their source.' } ) );
		var chunksWrap = el( 'div', {} );
		( e.chunks || [] ).forEach( function ( c, i ) { chunksWrap.appendChild( chunkRow( e, i ) ); } );
		body.appendChild( chunksWrap );
		body.appendChild( el( 'button', { type: 'button', class: 'button bl-em-add' }, [ '＋ Add chunk' ] ) )
			.addEventListener( 'click', function () { e.chunks = e.chunks || []; e.chunks.push( {} ); markDirty(); renderDetail(); } );

		// Relations.
		body.appendChild( el( 'h3', { text: 'Relations' } ) );
		body.appendChild( el( 'p', { class: 'description', text: 'Typed edges to other saved entities.' } ) );
		var relWrap = el( 'div', {} );
		( e.relations || [] ).forEach( function ( r, i ) { relWrap.appendChild( relationRow( e, i ) ); } );
		body.appendChild( relWrap );
		body.appendChild( el( 'button', { type: 'button', class: 'button bl-em-add' }, [ '＋ Add relation' ] ) )
			.addEventListener( 'click', function () { e.relations = e.relations || []; e.relations.push( {} ); markDirty(); renderDetail(); } );
	}

	/* ------------------------------------------------------------------ */
	/* Chunk + relation rows.                                              */
	/* ------------------------------------------------------------------ */

	function chunkRow( e, i ) {
		var c = e.chunks[ i ];
		var row = el( 'div', { class: 'bl-em-repeater-row' } );

		var ta = el( 'textarea', { rows: '2', placeholder: 'Evidence text…' }, [ c.text || '' ] );
		ta.addEventListener( 'input', function () { c.text = ta.value; markDirty(); } );
		row.appendChild( field( 'Text', ta ) );

		row.appendChild( el( 'div', { class: 'bl-em-row' }, [
			field( 'Source URL', textInput( c.sourceUrl, function ( v ) { c.sourceUrl = v; }, 'url' ) ),
			field( 'Page title', textInput( c.pageTitle, function ( v ) { c.pageTitle = v; } ) )
		] ) );

		row.appendChild( el( 'div', { class: 'bl-em-row' }, [
			field( 'Content type', selectField( c.contentType, [ [ '', '—' ] ].concat( D.vocab.contentTypes.map( function ( t ) { return [ t, t ]; } ) ), function ( v ) { c.contentType = v; markDirty(); } ) ),
			field( 'Audience', selectField( c.audienceType, [ [ '', '—' ] ].concat( D.vocab.audience.map( function ( t ) { return [ t, t ]; } ) ), function ( v ) { c.audienceType = v; markDirty(); } ) ),
			field( ' ', el( 'button', { type: 'button', class: 'button-link bl-em-remove' }, [ 'Remove' ] ) )
		] ) );
		row.querySelector( '.bl-em-remove' ).addEventListener( 'click', function () { e.chunks.splice( i, 1 ); markDirty(); renderDetail(); } );

		return row;
	}

	function relationRow( e, i ) {
		var r = e.relations[ i ];
		var row = el( 'div', { class: 'bl-em-repeater-row' } );

		var targets = [ [ '', '—' ] ].concat(
			state.entities
				.filter( function ( t ) { return t.entityId && t._key !== e._key; } )
				.map( function ( t ) { return [ t.entityId, t.name + ' (' + t.entityId + ')' ]; } )
		);

		row.appendChild( el( 'div', { class: 'bl-em-row' }, [
			field( 'Predicate', selectField( r.predicate, [ [ '', '—' ] ].concat( D.vocab.predicates.map( function ( p ) { return [ p, p ]; } ) ), function ( v ) { r.predicate = v; markDirty(); } ) ),
			field( 'Target entity', selectField( r.targetId, targets, function ( v ) { r.targetId = v; markDirty(); } ) )
		] ) );

		row.appendChild( el( 'div', { class: 'bl-em-row' }, [
			field( 'Confidence', selectField( r.confidence, D.vocab.confidence.map( function ( c ) { return [ c, labelledLabel( c, '— stated —' ) ]; } ), function ( v ) { r.confidence = v; markDirty(); } ) ),
			field( 'Condition', textInput( r.condition, function ( v ) { r.condition = v; } ), '(if inferred)' ),
			field( ' ', el( 'button', { type: 'button', class: 'button-link bl-em-remove' }, [ 'Remove' ] ) )
		] ) );
		row.querySelector( '.bl-em-remove' ).addEventListener( 'click', function () { e.relations.splice( i, 1 ); markDirty(); renderDetail(); } );

		return row;
	}

	/* ------------------------------------------------------------------ */
	/* Page picker (title typeahead → stores the page URL).                */
	/* ------------------------------------------------------------------ */

	/** sameAs field with a "Find on Wikidata" typeahead that fills the URL. */
	function sameAsPicker( e ) {
		var wrap = el( 'div', { class: 'bl-em-pagepick' } );

		var urlInput = textInput( e.same_as, function ( v ) { e.same_as = v; }, 'url' );
		urlInput.placeholder = 'https://www.wikidata.org/wiki/Q…  (or search below)';
		wrap.appendChild( urlInput );

		var search = el( 'input', { type: 'search', placeholder: 'Find on Wikidata by name…', style: 'margin-top:6px;width:100%;' } );
		var results = el( 'div', { class: 'bl-em-pagepick-results' } );
		results.style.display = 'none';
		wrap.appendChild( search );
		wrap.appendChild( results );

		var timer = null;
		search.addEventListener( 'input', function () {
			clearTimeout( timer );
			var q = search.value.trim();
			if ( q.length < 2 ) { results.style.display = 'none'; return; }
			timer = setTimeout( function () {
				results.textContent = '';
				results.appendChild( el( 'div', { class: 'bl-em-pick-note', text: 'Searching Wikidata…' } ) );
				results.style.display = 'block';
				post( 'bl_em_search_wikidata', { q: q } ).then( function ( res ) {
					results.textContent = '';
					if ( ! res.success || ! res.data.results.length ) {
						results.appendChild( el( 'div', { class: 'bl-em-pick-note', text: res.success ? 'No matches on Wikidata.' : 'Could not reach Wikidata.' } ) );
						return;
					}
					res.data.results.forEach( function ( w ) {
						var b = el( 'button', { type: 'button' }, [
							el( 'span', { class: 't', text: w.label + '  ·  ' + w.id } ),
							el( 'span', { class: 'u', text: ' ' + ( w.description || w.url ) } )
						] );
						b.addEventListener( 'click', function () {
							e.same_as = w.url;
							urlInput.value = w.url;
							results.style.display = 'none';
							search.value = '';
							markDirty();
						} );
						results.appendChild( b );
					} );
				} ).catch( function () {
					results.textContent = '';
					results.appendChild( el( 'div', { class: 'bl-em-pick-note', text: 'Could not reach Wikidata.' } ) );
				} );
			}, 300 );
		} );

		return wrap;
	}

	function pagePicker( e ) {
		var wrap = el( 'div', { class: 'bl-em-pagepick' } );

		var urlInput = textInput( e.page_url, function ( v ) { e.page_url = v; } , 'url' );
		urlInput.placeholder = 'https://…  (or search below)';
		wrap.appendChild( urlInput );

		var search = el( 'input', { type: 'search', placeholder: 'Search pages by title…', style: 'margin-top:6px;width:100%;' } );
		var results = el( 'div', { class: 'bl-em-pagepick-results' } );
		results.style.display = 'none';
		wrap.appendChild( search );
		wrap.appendChild( results );

		var timer = null;
		search.addEventListener( 'input', function () {
			clearTimeout( timer );
			var q = search.value.trim();
			if ( q.length < 2 ) { results.style.display = 'none'; return; }
			timer = setTimeout( function () {
				post( 'bl_em_search_pages', { q: q } ).then( function ( res ) {
					results.textContent = '';
					if ( ! res.success || ! res.data.results.length ) {
						results.style.display = 'none';
						return;
					}
					res.data.results.forEach( function ( p ) {
						var b = el( 'button', { type: 'button' }, [
							el( 'span', { class: 't', text: p.title + ( p.type ? ' · ' + p.type : '' ) } ),
							el( 'span', { class: 'u', text: ' ' + p.url } )
						] );
						b.addEventListener( 'click', function () {
							e.page_url = p.url;
							urlInput.value = p.url;
							results.style.display = 'none';
							search.value = '';
							markDirty();
						} );
						results.appendChild( b );
					} );
					results.style.display = 'block';
				} );
			}, 250 );
		} );

		return wrap;
	}

	/* ------------------------------------------------------------------ */
	/* Actions.                                                            */
	/* ------------------------------------------------------------------ */

	function selectEntity( key ) {
		state.selKey = key;
		renderListItems();
		renderDetail();
		refs.detail.scrollTop = 0;
	}

	function addEntity() {
		var e = { _key: keySeq++, _dirty: true, postId: 0, entityId: '', name: '', description: '', type: 'Concept', alternate_name: '', canonical_label: '', same_as: '', maturity: '', page_url: '', chunks: [], relations: [] };
		state.entities.push( e );
		selectEntity( e._key );
		var nameInput = refs.detail.querySelector( 'input[type=text]' );
		if ( nameInput ) { nameInput.focus(); }
	}

	function payloadFor( e ) {
		return {
			postId: e.postId || 0,
			name: e.name || '',
			description: e.description || '',
			type: e.type || '',
			alternate_name: e.alternate_name || '',
			canonical_label: e.canonical_label || '',
			same_as: e.same_as || '',
			maturity: e.maturity || '',
			page_url: e.page_url || '',
			chunks: e.chunks || [],
			relations: e.relations || []
		};
	}

	function save( e, btn ) {
		if ( ! ( e.name || '' ).trim() ) { setStatus( 'An entity needs a name.', '' ); return; }
		if ( btn ) { btn.disabled = true; }
		setStatus( D.i18n.saving, 'is-saving' );
		showSaving( D.i18n.saving );

		post( 'bl_em_save_entity', { payload: JSON.stringify( payloadFor( e ) ) } ).then( function ( res ) {
			hideSaving();
			if ( btn ) { btn.disabled = false; }
			if ( ! res.success ) {
				setStatus( ( res.data && res.data.message ) || 'Save failed.', '' );
				return;
			}
			// Merge canonical server data back into the working copy, keep _key.
			var saved = res.data.entity;
			Object.keys( saved ).forEach( function ( k ) { e[ k ] = saved[ k ]; } );
			e._dirty = false;
			state.validation = res.data.validation || [];
			setStatus( res.data.staticOk === '0' ? D.i18n.savedDynamic : D.i18n.saved, 'is-saved' );
			renderBanner();
			renderListItems();
			renderDetail();
		} ).catch( function () {
			hideSaving();
			if ( btn ) { btn.disabled = false; }
			setStatus( 'Network error — try again.', '' );
		} );
	}

	function removeFromState( key ) {
		state.entities = state.entities.filter( function ( x ) { return x._key !== key; } );
		if ( state.selKey === key ) { state.selKey = state.entities.length ? state.entities[ 0 ]._key : null; }
	}

	function del( e ) {
		// Never-saved draft: just drop it locally.
		if ( ! e.postId ) {
			removeFromState( e._key );
			renderListItems();
			renderDetail();
			return;
		}
		if ( ! window.confirm( D.i18n.confirmDelete ) ) { return; }
		setStatus( 'Deleting…', 'is-saving' );
		showSaving( 'Deleting…' );
		post( 'bl_em_delete_entity', { postId: e.postId } ).then( function ( res ) {
			hideSaving();
			if ( ! res.success ) { setStatus( ( res.data && res.data.message ) || 'Delete failed.', '' ); return; }
			removeFromState( e._key );
			state.validation = res.data.validation || [];
			setStatus( res.data.staticOk === '0' ? D.i18n.savedDynamic : D.i18n.saved, 'is-saved' );
			renderBanner();
			renderListItems();
			renderDetail();
		} );
	}

	/* ------------------------------------------------------------------ */

	window.addEventListener( 'beforeunload', function ( ev ) {
		if ( state.entities.some( function ( e ) { return e._dirty; } ) ) {
			ev.preventDefault();
			ev.returnValue = D.i18n.unloadWarning;
			return D.i18n.unloadWarning;
		}
	} );

	if ( state.entities.length ) { state.selKey = state.entities[ 0 ]._key; }
	render();
} )();
