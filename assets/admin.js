( function () {
	'use strict';

	if ( typeof forwpDriveAdmin === 'undefined' ) {
		return;
	}

	const getRestBase = () => {
		if ( forwpDriveAdmin.restPath ) {
			return (
				window.location.origin.replace( /\/$/, '' ) +
				forwpDriveAdmin.restPath
			);
		}
		return forwpDriveAdmin.restUrl;
	};

	const api = ( path, options = {} ) => {
		const headers = {
			'Content-Type': 'application/json',
			'X-WP-Nonce': forwpDriveAdmin.nonce,
		};
		const url =
			getRestBase().replace( /\/$/, '' ) +
			'/' +
			String( path ).replace( /^\//, '' );

		return fetch( url, {
			...options,
			credentials: 'same-origin',
			headers: { ...headers, ...( options.headers || {} ) },
		} ).then( async ( res ) => {
			const text = await res.text();
			let data = {};
			if ( text ) {
				try {
					data = JSON.parse( text );
				} catch ( err ) {
					data = { message: text.substring( 0, 200 ) };
				}
			}
			return { ok: res.ok, status: res.status, data };
		} );
	};

	let dialogResolver = null;
	let dialogKeyHandler = null;

	/**
	 * Ensure a single in-page confirm dialog exists in the DOM.
	 *
	 * @return {HTMLElement} Dialog root.
	 */
	function ensureConfirmDialog() {
		let root = document.getElementById( 'forwp-drive-confirm-dialog' );
		if ( root ) {
			return root;
		}

		root = document.createElement( 'div' );
		root.id = 'forwp-drive-confirm-dialog';
		root.className = 'forwp-drive-dialog forwp-drive-admin-chrome';
		root.hidden = true;
		root.setAttribute( 'role', 'dialog' );
		root.setAttribute( 'aria-modal', 'true' );
		root.setAttribute( 'aria-labelledby', 'forwp-drive-dialog-title' );
		root.innerHTML = `
			<div class="forwp-drive-dialog__backdrop" data-dialog-dismiss="1"></div>
			<div class="forwp-drive-dialog__panel">
				<div class="forwp-drive-dialog__header">
					<h2 id="forwp-drive-dialog-title" class="forwp-drive-dialog__title"></h2>
				</div>
				<p id="forwp-drive-dialog-message" class="forwp-drive-dialog__body"></p>
				<div class="forwp-drive-dialog__actions">
					<button type="button" class="button forwp-drive-dialog__cancel" data-dialog-dismiss="1"></button>
					<button type="button" class="button button-primary forwp-drive-dialog__confirm"></button>
				</div>
			</div>`;
		document.body.appendChild( root );

		root.addEventListener( 'click', ( event ) => {
			const target = event.target;
			if ( ! ( target instanceof HTMLElement ) ) {
				return;
			}
			if ( target.closest( '[data-dialog-dismiss="1"]' ) ) {
				closeConfirmDialog( false );
				return;
			}
			if ( target.closest( '.forwp-drive-dialog__confirm' ) ) {
				closeConfirmDialog( true );
			}
		} );

		return root;
	}

	/**
	 * @param {boolean} confirmed Whether the user confirmed.
	 * @return {void}
	 */
	function closeConfirmDialog( confirmed ) {
		const root = document.getElementById( 'forwp-drive-confirm-dialog' );
		if ( root ) {
			root.hidden = true;
		}
		if ( dialogKeyHandler ) {
			document.removeEventListener( 'keydown', dialogKeyHandler );
			dialogKeyHandler = null;
		}
		const resolve = dialogResolver;
		dialogResolver = null;
		if ( typeof resolve === 'function' ) {
			resolve( !! confirmed );
		}
	}

	/**
	 * In-admin confirm dialog (replaces native window.confirm).
	 *
	 * @param {Object}  options               Options.
	 * @param {string}  options.message       Body text.
	 * @param {string}  [options.title]       Title.
	 * @param {string}  [options.confirmLabel] Confirm button.
	 * @param {string}  [options.cancelLabel] Cancel button.
	 * @param {boolean} [options.danger]      Destructive confirm style.
	 * @return {Promise<boolean>}
	 */
	function confirmDialog( options ) {
		const strings = forwpDriveAdmin.strings || {};
		const root = ensureConfirmDialog();
		const titleEl = root.querySelector( '#forwp-drive-dialog-title' );
		const messageEl = root.querySelector( '#forwp-drive-dialog-message' );
		const confirmBtn = root.querySelector( '.forwp-drive-dialog__confirm' );
		const cancelBtn = root.querySelector( '.forwp-drive-dialog__cancel' );

		if ( dialogResolver ) {
			closeConfirmDialog( false );
		}

		if ( titleEl ) {
			titleEl.textContent =
				options.title || strings.dialogConfirm || 'Confirm';
		}
		if ( messageEl ) {
			messageEl.textContent = options.message || '';
		}
		if ( confirmBtn ) {
			confirmBtn.textContent =
				options.confirmLabel || strings.dialogConfirm || 'Confirm';
			confirmBtn.classList.toggle(
				'forwp-drive-dialog__confirm--danger',
				!! options.danger
			);
		}
		if ( cancelBtn ) {
			cancelBtn.textContent =
				options.cancelLabel || strings.dialogCancel || 'Cancel';
		}

		root.hidden = false;

		return new Promise( ( resolve ) => {
			dialogResolver = resolve;
			dialogKeyHandler = ( event ) => {
				if ( event.key === 'Escape' ) {
					event.preventDefault();
					closeConfirmDialog( false );
				} else if ( event.key === 'Enter' ) {
					event.preventDefault();
					closeConfirmDialog( true );
				}
			};
			document.addEventListener( 'keydown', dialogKeyHandler );
			if ( confirmBtn && typeof confirmBtn.focus === 'function' ) {
				confirmBtn.focus();
			}
		} );
	}

	let previewId = null;
	let previewDoc = null;
	let pinSelectedName = '';
	let multilingualConfig = forwpDriveAdmin.multilingual || null;
	const INBOX_SOURCE_STORAGE_KEY = 'forwp_drive_inbox_active_source';

	function readStoredInboxSource() {
		try {
			const stored = window.localStorage.getItem( INBOX_SOURCE_STORAGE_KEY );
			return stored ? String( stored ) : '';
		} catch ( err ) {
			return '';
		}
	}

	function persistInboxSource( slug ) {
		try {
			window.localStorage.setItem( INBOX_SOURCE_STORAGE_KEY, String( slug || '' ) );
		} catch ( err ) {
			// Ignore quota / private mode.
		}
	}

	function resolveInitialInboxSource() {
		const stored = readStoredInboxSource();
		if ( stored && getSourceBySlug( stored ) ) {
			return stored;
		}
		return forwpDriveAdmin.activeSource || 'google_drive';
	}

	let activeSourceSlug = resolveInitialInboxSource();
	let inboxCache = {
		documents: [],
		browseTrees: {},
		lastSync: null,
		connection: null,
		incomingId: '',
		sourceStatus: {},
	};

	function browseTreeForActiveSource() {
		const map = inboxCache.browseTrees || {};
		return map[ activeSourceSlug ] || null;
	}

	function treeHasEntries( node ) {
		if ( ! node ) {
			return false;
		}
		if ( ( node.files || [] ).length ) {
			return true;
		}
		const folders = node.folders || {};
		return Object.keys( folders ).some( ( key ) => treeHasEntries( folders[ key ] ) );
	}

	function documentsForSource( slug ) {
		const docs = inboxCache.documents || [];
		return docs.filter(
			( doc ) => ! doc.source || doc.source === slug
		);
	}

	function documentsForActiveSource() {
		return documentsForSource( activeSourceSlug );
	}

	function incomingCountForSource( slug ) {
		return documentsForSource( slug ).length;
	}

	function updateAdminMenuIncomingCount( total ) {
		const n = Math.max( 0, Number( total ) || 0 );
		const badgeHtml =
			n > 0
				? `<span class="update-plugins count-${ n }"><span class="plugin-count">${ n }</span></span>`
				: '';
		const topName = document.querySelector(
			'#toplevel_page_forwp-drive-inbox > a.menu-top .wp-menu-name'
		);
		const subLink = document.querySelector(
			'#toplevel_page_forwp-drive-inbox .wp-submenu a[href*="page=forwp-drive-inbox"]'
		);

		if ( topName ) {
			const label =
				( forwpDriveAdmin.strings && forwpDriveAdmin.strings.menuPlugin ) || '4WP Drive';
			topName.innerHTML = label + ( badgeHtml ? ' ' + badgeHtml : '' );
		}

		if ( subLink ) {
			subLink.textContent =
				( forwpDriveAdmin.strings && forwpDriveAdmin.strings.menuIncoming ) || 'Incoming';
		}
	}

	function getInboxSources() {
		const sources = forwpDriveAdmin.sources;
		return Array.isArray( sources ) ? sources : [];
	}

	function getSourceBySlug( slug ) {
		return getInboxSources().find( ( source ) => source.slug === slug ) || null;
	}

	function isSourceImplemented( slug ) {
		const source = getSourceBySlug( slug );
		return !!( source && source.implemented );
	}

	/**
	 * Compact brand marks for source tabs / status (inline SVG, currentColor).
	 *
	 * @param {string} slug Source slug.
	 * @return {string} SVG markup (trusted static strings only).
	 */
	function sourceIconMarkup( slug ) {
		const icons = {
			google_drive:
				'<svg class="forwp-drive-source-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8.15 3.5h7.7L22 14.5h-7.7L8.15 3.5zm-1.9 1.1 3.85 6.65H2.4L6.25 4.6zM2 16h7.55l3.85 6.65H5.85L2 16zm11.2 0H22l-3.85 6.65h-8.8L13.2 16z"/></svg>',
			github:
				'<svg class="forwp-drive-source-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2a10 10 0 0 0-3.16 19.49c.5.09.68-.22.68-.48v-1.7c-2.78.6-3.37-1.34-3.37-1.34-.45-1.16-1.11-1.47-1.11-1.47-.91-.62.07-.61.07-.61 1 .07 1.53 1.03 1.53 1.03.89 1.53 2.34 1.09 2.91.83.09-.65.35-1.09.63-1.34-2.22-.25-4.55-1.11-4.55-4.94 0-1.09.39-1.98 1.03-2.68-.1-.25-.45-1.27.1-2.64 0 0 .84-.27 2.75 1.02A9.56 9.56 0 0 1 12 6.8c.85 0 1.71.11 2.51.33 1.91-1.29 2.75-1.02 2.75-1.02.55 1.37.2 2.39.1 2.64.64.7 1.03 1.59 1.03 2.68 0 3.84-2.34 4.68-4.57 4.93.36.31.68.92.68 1.85v2.74c0 .27.18.58.69.48A10 10 0 0 0 12 2z"/></svg>',
			onedrive:
				'<svg class="forwp-drive-source-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M10.5 7.2c1.4-1.7 3.5-2.7 5.8-2.7 2.9 0 5.4 1.7 6.5 4.2-.3 0-.6-.1-.9-.1-2.6 0-4.9 1.6-5.9 3.9l-.2.5H8.6c-.5-1.9.1-3.9 1.9-5.8zm-6.8 5.1c.7-1.7 2.1-3 3.8-3.6-.2.7-.3 1.4-.3 2.1 0 1.1.3 2.2.8 3.2H3.5c-.8 0-1.5-.7-1.5-1.5 0-.1 0-.2.1-.3.3-.7.8-1.3 1.6-1.9zm8.6 1.4h9.2c.9 0 1.7.8 1.7 1.7 0 .9-.8 1.7-1.7 1.7H6.8c-1.2 0-2.1-1-2.1-2.2 0-.9.6-1.7 1.4-2 .3 1 .9 1.8 1.8 2.3.5.3 1.1.5 1.7.5h4.7z"/></svg>',
			dropbox:
				'<svg class="forwp-drive-source-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="m12 6.1 4.6 2.9L12 12 7.4 9 12 6.1zm0 7.8 4.6-2.9 4.6 2.9L16.6 17 12 13.9zm0 0L7.4 17 2.8 13.9l4.6-2.9L12 13.9zM7.4 5.2 12 8.1 7.4 11 2.8 8.1 7.4 5.2zm9.2 0 4.6 2.9-4.6 2.9L12 8.1l4.6-2.9zM12 15.2l4.6 3-1.5.9L12 17.4l-3.1 1.7-1.5-.9 4.6-3z"/></svg>',
		};
		return icons[ slug ] || '';
	}

	function setChip( name, text, tone, iconHtml ) {
		const chip = document.querySelector(
			`#forwp-drive-inbox-chips [data-chip="${ name }"]`
		);
		if ( ! chip ) {
			return;
		}
		chip.innerHTML = ( iconHtml || '' ) + `<span class="forwp-drive-chip__text">${ escapeHtml( text ) }</span>`;
		chip.className = 'forwp-drive-chip';
		if ( tone ) {
			chip.classList.add( 'forwp-drive-chip--' + tone );
		}
		chip.hidden = false;
	}

	function renderInboxSourceTabs() {
		const tabs = document.getElementById( 'forwp-drive-inbox-source-tabs' );
		if ( ! tabs ) {
			return;
		}

		const sources = getInboxSources();
		if ( ! sources.length ) {
			tabs.innerHTML = '';
			return;
		}

		tabs.innerHTML = sources
			.map( ( source ) => {
				const slug = escapeHtml( source.slug );
				const label = escapeHtml( source.label || source.slug );
				const active = source.slug === activeSourceSlug;
				const soon = ! source.implemented;
				const icon = sourceIconMarkup( source.slug );
				const incoming = soon ? 0 : incomingCountForSource( source.slug );
				let badge = '';
				if ( soon ) {
					badge = '<span class="forwp-drive-source-tab__badge">Soon</span>';
				} else if ( incoming > 0 ) {
					badge = `<span class="forwp-drive-source-tab__count" aria-label="${ incoming } incoming">${ incoming }</span>`;
				}
				const classes = [
					'forwp-drive-source-tab',
					active ? 'is-active' : '',
					soon ? 'is-disabled' : '',
				]
					.filter( Boolean )
					.join( ' ' );
				return `<button
					type="button"
					role="tab"
					class="${ classes }"
					data-action="source-tab"
					data-source="${ slug }"
					aria-selected="${ active ? 'true' : 'false' }"
					title="${ escapeHtml( source.status || '' ) }"
				><span class="forwp-drive-source-tab__inner">${ icon }<span class="forwp-drive-source-tab__label">${ label }</span>${ badge }</span></button>`;
			} )
			.join( '' );
	}

	function getActiveSourceStatus() {
		const map = inboxCache.sourceStatus || {};
		return map[ activeSourceSlug ] || null;
	}

	function applyActiveSourceChrome() {
		const implemented = isSourceImplemented( activeSourceSlug );
		const syncBtn = document.getElementById( 'forwp-drive-inbox-sync' );
		const openIncoming = document.getElementById(
			'forwp-drive-inbox-open-incoming'
		);
		const strings = forwpDriveAdmin.strings || {};

		if ( syncBtn ) {
			syncBtn.disabled = ! implemented;
			syncBtn.textContent =
				activeSourceSlug === 'google_drive'
					? strings.syncFromDrive || 'Sync from Drive'
					: strings.syncLabel || 'Sync';
		}

		if ( openIncoming ) {
			if ( ! implemented ) {
				openIncoming.hidden = true;
			} else {
				const srcStatus = getActiveSourceStatus();
				const url =
					( srcStatus && srcStatus.incoming_url ) ||
					( activeSourceSlug === 'google_drive'
						? driveFolderUrl(
								( srcStatus && srcStatus.incoming_id ) ||
									inboxCache.incomingId
						  )
						: '' );
				openIncoming.textContent =
					activeSourceSlug === 'github'
						? strings.openOnGitHub || 'Open on GitHub'
						: strings.openFolder || 'Open folder';
				if ( url ) {
					openIncoming.href = url;
					openIncoming.hidden = false;
				} else {
					openIncoming.hidden = true;
					openIncoming.removeAttribute( 'href' );
				}
			}
		}

		document
			.querySelectorAll( '#forwp-drive-inbox-source-tabs .forwp-drive-source-tab' )
			.forEach( ( tab ) => {
				const isActive = tab.getAttribute( 'data-source' ) === activeSourceSlug;
				tab.classList.toggle( 'is-active', isActive );
				tab.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
			} );
	}

	function setActiveInboxSource( slug ) {
		const source = getSourceBySlug( slug );
		if ( ! source ) {
			return;
		}
		const sourceChanged = activeSourceSlug !== slug;
		activeSourceSlug = slug;
		persistInboxSource( slug );
		if ( sourceChanged ) {
			treeExpandedPaths = new Set();
			treeExpandInitialized = false;
			showWorkspacePlaceholder();
		}
		applyActiveSourceChrome();

		if ( ! source.implemented ) {
			const list = document.getElementById( 'forwp-drive-inbox-list' );
			if ( list ) {
				list.innerHTML = `
					<div class="forwp-drive-empty-panel forwp-drive-admin-chrome">
						<p class="forwp-drive-empty-panel__lead"><strong>${ escapeHtml(
							source.label
						) }</strong></p>
						<p>${ escapeHtml( source.status || '' ) }</p>
					</div>`;
			}
			setChip(
				'connection',
				source.label + ' — soon',
				'muted',
				sourceIconMarkup( source.slug )
			);
			setChip( 'sync', 'Last sync: —', 'muted' );
			setChip( 'ready', 'Ready: —', 'muted' );
			const errorsChip = document.querySelector(
				'#forwp-drive-inbox-chips [data-chip="errors"]'
			);
			if ( errorsChip ) {
				errorsChip.hidden = true;
			}
			const queueCount = document.getElementById(
				'forwp-drive-inbox-queue-count'
			);
			if ( queueCount ) {
				queueCount.hidden = true;
			}
			return;
		}

		updateInboxStatusBar(
			inboxCache.connection,
			inboxCache.lastSync,
			documentsForActiveSource().length,
			inboxCache.incomingId
		);
		renderInbox( documentsForActiveSource(), inboxCache.lastSync );
	}

	function getMultilingualConfig() {
		return multilingualConfig || forwpDriveAdmin.multilingual || null;
	}

	function getConfiguredLanguages( configOverride ) {
		const config = configOverride || getMultilingualConfig();
		return config && Array.isArray( config.languages ) ? config.languages : [];
	}

	/**
	 * Show the language picker only when the site has more than one language.
	 *
	 * @return {boolean}
	 */
	function requiresImportLanguage() {
		const languages = getConfiguredLanguages();
		if ( languages.length <= 1 ) {
			return false;
		}
		const config = getMultilingualConfig();
		return !!( config && config.requires_selection );
	}

	/**
	 * Sole / default language when the picker is hidden.
	 *
	 * @return {string}
	 */
	function getImplicitImportLanguage() {
		const config = getMultilingualConfig();
		const languages = getConfiguredLanguages();
		if ( languages.length === 1 && languages[0].code ) {
			return String( languages[0].code );
		}
		if ( config && config.default_language ) {
			return String( config.default_language );
		}
		return '';
	}

	function getSelectedImportLanguage() {
		if ( ! requiresImportLanguage() ) {
			return getImplicitImportLanguage();
		}
		const select = document.getElementById( 'forwp-drive-import-language' );
		if ( ! select ) {
			return getImplicitImportLanguage();
		}
		return select.value || '';
	}

	/**
	 * Mark a required import field as valid/invalid (red chrome when missing).
	 *
	 * @param {string}  wrapId   Wrapper element id.
	 * @param {string}  selectId Select element id.
	 * @param {string}  errorId  Inline error element id.
	 * @param {boolean} invalid  Whether the field is invalid.
	 * @param {string}  message  Error message.
	 * @param {boolean} [shake]  Brief shake attention.
	 * @return {void}
	 */
	function setImportFieldInvalid( wrapId, selectId, errorId, invalid, message, shake ) {
		const wrap = document.getElementById( wrapId );
		const select = document.getElementById( selectId );
		const errorEl = document.getElementById( errorId );
		if ( wrap ) {
			wrap.classList.toggle( 'is-invalid', !! invalid );
			if ( shake && invalid ) {
				wrap.classList.remove( 'is-shake' );
				// Force reflow so the animation can replay.
				void wrap.offsetWidth;
				wrap.classList.add( 'is-shake' );
				window.setTimeout( () => {
					wrap.classList.remove( 'is-shake' );
				}, 450 );
			}
		}
		if ( select ) {
			select.setAttribute( 'aria-invalid', invalid ? 'true' : 'false' );
		}
		if ( errorEl ) {
			errorEl.textContent = invalid ? message || '' : '';
			errorEl.hidden = ! invalid;
		}
	}

	/**
	 * Disable Import only when update-mode target is missing.
	 * Language gaps stay clickable so validation can paint the field red.
	 *
	 * @return {void}
	 */
	function syncImportButtonState() {
		const button = document.getElementById( 'forwp-drive-preview-import' );
		const strings = forwpDriveAdmin.strings || {};
		const langMissing =
			requiresImportLanguage() && ! getSelectedImportLanguage();
		const langMessage =
			strings.languageRequired ||
			'Select a content language for this import.';

		setImportFieldInvalid(
			'forwp-drive-import-language-wrap',
			'forwp-drive-import-language',
			'forwp-drive-import-language-error',
			langMissing,
			langMessage,
			false
		);

		let targetMissing = false;
		const targetMessage =
			strings.updateTargetRequired ||
			'Select an existing post to update.';
		if ( getImportMode() === 'update' ) {
			const select = document.getElementById( 'forwp-drive-import-target' );
			const targetId = select ? parseInt( select.value, 10 ) : 0;
			targetMissing = ! targetId;
		}
		setImportFieldInvalid(
			'forwp-drive-import-target-wrap',
			'forwp-drive-import-target',
			'forwp-drive-import-target-error',
			targetMissing,
			targetMessage,
			false
		);

		if ( ! button ) {
			return;
		}

		// Keep Import clickable when only language is missing — red field is the cue.
		const blocked = targetMissing;
		button.disabled = blocked;
		if ( blocked ) {
			button.setAttribute( 'title', targetMessage );
			button.setAttribute( 'aria-disabled', 'true' );
		} else {
			button.removeAttribute( 'title' );
			button.removeAttribute( 'aria-disabled' );
		}
	}

	/**
	 * Surface validation failures in the status bar + red field highlight.
	 *
	 * @param {string} message Message.
	 * @param {string} [focusId] Element id to focus.
	 * @return {void}
	 */
	function showImportValidationError( message, focusId ) {
		const status = document.getElementById( 'forwp-drive-inbox-status' );
		setStatus( status, message, true );

		if ( focusId === 'forwp-drive-import-language' ) {
			setImportFieldInvalid(
				'forwp-drive-import-language-wrap',
				'forwp-drive-import-language',
				'forwp-drive-import-language-error',
				true,
				message,
				true
			);
		}
		if ( focusId === 'forwp-drive-import-target' ) {
			setImportFieldInvalid(
				'forwp-drive-import-target-wrap',
				'forwp-drive-import-target',
				'forwp-drive-import-target-error',
				true,
				message,
				true
			);
		}

		if ( focusId ) {
			const el = document.getElementById( focusId );
			if ( el && typeof el.focus === 'function' ) {
				el.focus();
			}
			const wrap =
				focusId === 'forwp-drive-import-language'
					? document.getElementById( 'forwp-drive-import-language-wrap' )
					: focusId === 'forwp-drive-import-target'
					? document.getElementById( 'forwp-drive-import-target-wrap' )
					: el;
			if ( wrap && typeof wrap.scrollIntoView === 'function' ) {
				wrap.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
			}
		}
	}

	function setupImportLanguageUi( configOverride ) {
		const wrap = document.getElementById( 'forwp-drive-import-language-wrap' );
		const select = document.getElementById( 'forwp-drive-import-language' );
		if ( ! wrap || ! select ) {
			return;
		}

		const config = configOverride || getMultilingualConfig();
		const languages = getConfiguredLanguages( config );
		// One language (or none): never show a pointless picker.
		if ( ! config || languages.length <= 1 || ! config.requires_selection ) {
			wrap.hidden = true;
			select.innerHTML = '';
			syncImportButtonState();
			return;
		}

		wrap.hidden = false;
		const strings = forwpDriveAdmin.strings || {};
		const defaultCode =
			( config.default_language &&
				languages.some( ( language ) => language.code === config.default_language ) &&
				String( config.default_language ) ) ||
			( languages[0] && languages[0].code ) ||
			'';
		select.innerHTML = languages
			.map(
				( language ) =>
					`<option value="${ escapeHtml( language.code ) }">${ escapeHtml(
						language.name
					) }</option>`
			)
			.join( '' );
		select.value = defaultCode;
		syncImportButtonState();
	}

	function nameSuggestsFeatured( name ) {
		const base = String( name || '' )
			.replace( /\.[^.]+$/, '' )
			.toLowerCase();
		return /(^|[-_\s])(cover|featured|hero|thumbnail|thumb)([-_\s]|$)/.test(
			base
		);
	}

	/**
	 * Package images available for featured selection.
	 *
	 * @param {Object} doc Preview document.
	 * @return {Array<{id: string, name: string}>}
	 */
	function getPackageImages( doc ) {
		const files = Array.isArray( doc && doc.package_files )
			? doc.package_files
			: [];
		return files
			.filter(
				( file ) =>
					file &&
					file.kind === 'image' &&
					file.id &&
					file.name
			)
			.map( ( file ) => ( {
				id: String( file.id ),
				name: String( file.name ),
			} ) );
	}

	function dockPackageTools() {
		// Package image-pin UI temporarily removed.
	}

	function attachPackageToolsToSelectedCard() {
		// Package image-pin UI temporarily removed.
	}

	/**
	 * @return {string} Selected Drive image file id (or empty).
	 */
	function getSelectedFeaturedImageId( doc ) {
		const select = document.getElementById( 'forwp-drive-import-featured' );
		if ( select && select.value ) {
			return String( select.value );
		}
		if ( doc && doc.image_file_id ) {
			return String( doc.image_file_id );
		}
		return '';
	}

	function setupFeaturedImageUi( doc ) {
		const wrap = document.getElementById( 'forwp-drive-import-featured-wrap' );
		const select = document.getElementById( 'forwp-drive-import-featured' );
		if ( ! wrap || ! select ) {
			return;
		}

		const images = getPackageImages( doc );
		if ( ! images.length ) {
			wrap.hidden = true;
			select.innerHTML = '';
			return;
		}

		const strings = forwpDriveAdmin.strings || {};
		const suggestedLabel = strings.featuredImageSuggested || 'Suggested';
		let featuredId = doc && doc.image_file_id ? String( doc.image_file_id ) : '';
		if ( ! featuredId || ! images.some( ( image ) => image.id === featuredId ) ) {
			const suggested = images.find( ( image ) =>
				nameSuggestsFeatured( image.name )
			);
			featuredId = suggested ? suggested.id : images[ 0 ].id;
		}

		select.innerHTML = images
			.map( ( image ) => {
				const suggested = nameSuggestsFeatured( image.name )
					? ` (${ suggestedLabel })`
					: '';
				return `<option value="${ escapeHtml( image.id ) }">${ escapeHtml(
					image.name
				) }${ escapeHtml( suggested ) }</option>`;
			} )
			.join( '' );
		select.value = featuredId;
		wrap.hidden = false;
	}

	function getPackageDocuments( doc ) {
		const fromMeta = ( Array.isArray( doc && doc.package_files )
			? doc.package_files
			: []
		).filter(
			( file ) => file && file.kind === 'document' && file.id && file.name
		);

		const fromBrowse = browsePackageDocuments( doc );
		const byId = {};
		[ ...fromMeta, ...fromBrowse ].forEach( ( file ) => {
			const id = String( file.id || '' ).trim();
			if ( ! id ) {
				return;
			}
			if ( ! byId[ id ] ) {
				byId[ id ] = {
					id,
					name: file.name,
					kind: 'document',
				};
			}
		} );

		return Object.keys( byId )
			.map( ( id ) => byId[ id ] )
			.sort( ( a, b ) =>
				String( a.name ).localeCompare( String( b.name ), undefined, {
					sensitivity: 'base',
				} )
			);
	}

	/**
	 * Sibling documents from the live browse tree for this package folder.
	 * Keeps "File to import" in sync when package_files from last sync is stale.
	 *
	 * @param {Object} doc Document row.
	 * @return {Array<{id:string,name:string,kind:string}>}
	 */
	function browsePackageDocuments( doc ) {
		const packagePath = String(
			( doc && doc.package_folder_id ) || ''
		).trim();
		if ( ! packagePath ) {
			return [];
		}

		const browse = browseTreeForActiveSource();
		if ( ! browse ) {
			return [];
		}

		const findFolder = ( node, path ) => {
			if ( ! node || ! path ) {
				return null;
			}
			if ( String( node.path || '' ) === path ) {
				return node;
			}
			let found = null;
			Object.keys( node.folders || {} ).some( ( key ) => {
				found = findFolder( node.folders[ key ], path );
				return !! found;
			} );
			return found;
		};

		const folder = findFolder( browse, packagePath );
		if ( ! folder ) {
			return [];
		}

		return ( folder.files || [] )
			.filter( ( file ) => {
				const name = String( ( file && file.name ) || '' );
				const kind = String( ( file && file.kind ) || '' );
				return (
					kind === 'document' ||
					/\.(md|mdx|markdown|docx?)$/i.test( name )
				);
			} )
			.map( ( file ) => ( {
				id: String( file.id || '' ),
				name: String( file.name || '' ),
				kind: 'document',
			} ) )
			.filter( ( file ) => file.id && file.name );
	}

	function setupSourceFileUi( doc ) {
		const wrap = document.getElementById( 'forwp-drive-import-source-wrap' );
		const select = document.getElementById( 'forwp-drive-import-source-file' );
		if ( ! wrap || ! select ) {
			return;
		}

		const docs = getPackageDocuments( doc );
		if ( docs.length < 2 ) {
			wrap.hidden = true;
			select.innerHTML = '';
			return;
		}

		const selectedId = String( doc.selected_file_id || doc.file_id || '' );
		select.innerHTML = docs
			.map( ( file ) => {
				const selected = String( file.id ) === selectedId ? ' selected' : '';
				return `<option value="${ escapeHtml( String( file.id ) ) }"${ selected }>${ escapeHtml(
					file.name
				) }</option>`;
			} )
			.join( '' );
		if ( selectedId && ! [ ...select.options ].some( ( o ) => o.value === selectedId ) ) {
			select.value = docs[ 0 ].id;
		} else if ( selectedId ) {
			select.value = selectedId;
		}
		wrap.hidden = false;
		select.onchange = () => {
			const fileId = select.value;
			if ( ! fileId || ! previewId ) {
				return;
			}
			api( 'documents/' + previewId + '/source', {
				method: 'POST',
				body: JSON.stringify( { file_id: fileId } ),
			} ).then( ( { ok, data } ) => {
				if ( ok ) {
					openPreview( previewId, { mode: getImportMode() } );
				} else {
					const status = document.getElementById( 'forwp-drive-inbox-status' );
					setStatus( status, ( data && data.message ) || 'Could not switch file.', true );
				}
			} );
		};
	}

	function getSelectedImageAlign() {
		const selected = document.querySelector(
			'input[name="forwp-drive-image-align"]:checked'
		);
		return selected ? String( selected.value || 'center' ) : 'center';
	}

	function isImageMarkerText( text ) {
		return /^\[image:\s*[^\]]+\]$/i.test( String( text || '' ).trim() );
	}

	function getPreviewBodyHtml( body ) {
		const clone = body.cloneNode( true );
		clone
			.querySelectorAll(
				'.forwp-drive-image-marker__remove, .forwp-drive-preview__blocks-note'
			)
			.forEach( ( el ) => el.remove() );
		clone.querySelectorAll( '.forwp-drive-image-marker' ).forEach( ( el ) => {
			el.classList.remove( 'forwp-drive-image-marker' );
			if ( ! el.className ) {
				el.removeAttribute( 'class' );
			}
		} );
		return clone.innerHTML;
	}

	function persistPreviewBody() {
		const body = document.getElementById( 'forwp-drive-preview-post-content' );
		if ( ! body || ! previewId ) {
			return;
		}
		const html = getPreviewBodyHtml( body );
		api( 'documents/' + previewId + '/body', {
			method: 'POST',
			body: JSON.stringify( { body_html: html } ),
		} ).then( ( { ok } ) => {
			if ( ok && previewDoc ) {
				previewDoc.body_html = html;
			}
		} );
	}

	function decorateImageMarkers( body ) {
		if ( ! body ) {
			return;
		}
		const strings = forwpDriveAdmin.strings || {};
		const removeLabel = strings.removeImageMarker || 'Remove';
		body.querySelectorAll( 'p, h1, h2, h3, h4, h5, h6, li' ).forEach( ( node ) => {
			if ( node.classList.contains( 'forwp-drive-preview__blocks-note' ) ) {
				return;
			}
			if ( node.classList.contains( 'forwp-drive-image-marker' ) ) {
				return;
			}
			if ( ! isImageMarkerText( node.textContent ) ) {
				return;
			}
			node.classList.add( 'forwp-drive-image-marker' );
			const button = document.createElement( 'button' );
			button.type = 'button';
			button.className =
				'button-link-delete forwp-drive-image-marker__remove';
			button.textContent = removeLabel;
			node.appendChild( button );
		} );
	}

	function bindPreviewBodyActions( body ) {
		if ( ! body || body.dataset.drivePinBound === '1' ) {
			return;
		}
		body.dataset.drivePinBound = '1';
		body.addEventListener( 'click', ( event ) => {
			const removeBtn = event.target.closest(
				'.forwp-drive-image-marker__remove'
			);
			if ( removeBtn && body.contains( removeBtn ) ) {
				event.preventDefault();
				event.stopPropagation();
				const marker = removeBtn.closest( '.forwp-drive-image-marker' );
				if ( marker ) {
					marker.remove();
					persistPreviewBody();
				}
				return;
			}

			if ( ! pinSelectedName || ! previewId ) {
				return;
			}
			if ( ! body.classList.contains( 'is-pinning' ) ) {
				return;
			}
			if ( event.target.closest( '.forwp-drive-image-marker' ) ) {
				return;
			}
			const node = event.target.closest( 'p, h1, h2, h3, h4, h5, h6, li' );
			if (
				! node ||
				! body.contains( node ) ||
				node.classList.contains( 'forwp-drive-preview__blocks-note' )
			) {
				return;
			}
			event.preventDefault();
			const align = getSelectedImageAlign();
			const marker = `[image:${ pinSelectedName } ${ align }]`;
			const p = document.createElement( 'p' );
			p.textContent = marker;
			node.insertAdjacentElement( 'afterend', p );
			decorateImageMarkers( body );
			persistPreviewBody();
		} );
	}

	function setupImagePinUi( doc ) {
		// Image pin / alignment UI temporarily removed from Incoming.
		pinSelectedName = '';
		const body = document.getElementById( 'forwp-drive-preview-post-content' );
		if ( body ) {
			body.classList.remove( 'is-pinning' );
			bindPreviewBodyActions( body );
			decorateImageMarkers( body );
		}
		void doc;
	}

	function resetImportTargetSelect( message ) {
		const select = document.getElementById( 'forwp-drive-import-target' );
		if ( ! select ) {
			return;
		}
		const strings = forwpDriveAdmin.strings || {};
		select.innerHTML =
			'<option value="">' +
			escapeHtml(
				message ||
					strings.selectLanguageFirst ||
					'Select a language to list matching posts.'
			) +
			'</option>';
	}

	function getImportMode() {
		const selected = document.querySelector(
			'input[name="forwp-drive-import-mode"]:checked'
		);
		return selected && selected.value === 'update' ? 'update' : 'create';
	}

	function setImportModeUi() {
		const wrap = document.getElementById( 'forwp-drive-import-target-wrap' );
		const isUpdate = getImportMode() === 'update';
		const strings = forwpDriveAdmin.strings || {};
		if ( wrap ) {
			wrap.hidden = ! isUpdate;
		}
		const button = document.getElementById( 'forwp-drive-preview-import' );
		if ( button ) {
			button.textContent = isUpdate
				? strings.updateExistingPost || 'Update existing post'
				: strings.importAsDraft || 'Import as draft';
		}
		syncImportButtonState();
	}

	function renderImportTargets( data ) {
		const select = document.getElementById( 'forwp-drive-import-target' );
		if ( ! select ) {
			return;
		}

		const targets = data && data.targets ? data.targets : [];
		if ( ! targets.length ) {
			select.innerHTML =
				'<option value="">' +
				escapeHtml( 'No matching posts found' ) +
				'</option>';
			syncImportButtonState();
			return;
		}

		select.innerHTML = targets
			.map( ( target ) => {
				const typeLabel =
					target.post_type_label ||
					target.post_type ||
					getSelectedImportPostType() ||
					'Post';
				const slug = target.slug || '—';
				const title = target.title || '(no title)';
				// Readable order: post type → slug → title.
				const parts = [ typeLabel, slug, title ];
				if ( target.status && target.status !== 'publish' ) {
					parts.push( target.status );
				}
				const langLabel = target.language_name || target.language || '';
				if ( langLabel ) {
					parts.push( langLabel );
				}
				return `<option value="${ target.id }">${ escapeHtml(
					parts.join( ' · ' )
				) }</option>`;
			} )
			.join( '' );

		if ( data.suggested_id ) {
			select.value = String( data.suggested_id );
		}
		syncImportButtonState();
	}

	function getSelectedImportPostType() {
		const select = document.getElementById( 'forwp-drive-inbox-import-post-type' );
		if ( select && select.value ) {
			return String( select.value );
		}
		return forwpDriveAdmin.importPostType || 'post';
	}

	function fillInboxImportPostTypes() {
		const select = document.getElementById( 'forwp-drive-inbox-import-post-type' );
		if ( ! select ) {
			return;
		}
		const types = Array.isArray( forwpDriveAdmin.postTypes )
			? forwpDriveAdmin.postTypes
			: [];
		const current = getSelectedImportPostType();
		const preferred = forwpDriveAdmin.importPostType || 'post';
		select.innerHTML = types
			.map( ( pt ) => {
				const selected =
					pt.slug === current || ( ! current && pt.slug === preferred )
						? ' selected'
						: '';
				return `<option value="${ escapeHtml( pt.slug ) }"${ selected }>${ escapeHtml(
					pt.label
				) } (${ escapeHtml( pt.slug ) })</option>`;
			} )
			.join( '' );
		if ( ! select.value && preferred ) {
			select.value = preferred;
		}
	}

	function loadImportTargets( doc ) {
		if ( ! doc ) {
			return;
		}

		if ( requiresImportLanguage() && ! getSelectedImportLanguage() ) {
			resetImportTargetSelect();
			return;
		}

		const params = new URLSearchParams();
		if ( doc.slug ) {
			params.set( 'slug', doc.slug );
		}
		if ( doc.title ) {
			params.set( 'title', doc.title );
		}
		const lang = getSelectedImportLanguage();
		if ( lang ) {
			params.set( 'lang', lang );
		}
		const postType = getSelectedImportPostType();
		if ( postType ) {
			params.set( 'post_type', postType );
		}
		const query = params.toString();
		api( 'import-targets' + ( query ? '?' + query : '' ) ).then(
			( { ok, data } ) => {
				if ( ok ) {
					if ( data.multilingual ) {
						multilingualConfig = data.multilingual;
					}
					renderImportTargets( data );
				}
			}
		);
	}

	function getImportPayload() {
		const mode = getImportMode();
		const payload = {
			mode,
			post_type: getSelectedImportPostType(),
		};
		const lang = getSelectedImportLanguage();

		if ( requiresImportLanguage() ) {
			if ( ! lang ) {
				showImportValidationError(
					forwpDriveAdmin.strings.languageRequired ||
						'Select a content language for this import.',
					'forwp-drive-import-language'
				);
				return null;
			}
			payload.language = lang;
		} else if ( lang ) {
			payload.language = lang;
		}

		if ( mode === 'update' ) {
			const select = document.getElementById( 'forwp-drive-import-target' );
			const targetId = select ? parseInt( select.value, 10 ) : 0;
			if ( ! targetId ) {
				showImportValidationError(
					forwpDriveAdmin.strings.updateTargetRequired ||
						'Select an existing post to update.',
					'forwp-drive-import-target'
				);
				return null;
			}
			payload.target_post_id = targetId;
		}

		const featuredId = getSelectedFeaturedImageId( previewDoc );
		if ( featuredId ) {
			payload.featured_image_file_id = featuredId;
		}

		return payload;
	}

	function setStatus( el, message, isError, isPending ) {
		if ( ! el ) {
			return;
		}
		const hasMessage = !! message;
		el.textContent = message || '';
		el.hidden = ! hasMessage;

		let stateClass = '';
		if ( hasMessage ) {
			if ( isError ) {
				stateClass = ' forwp-drive-status--error';
			} else if ( isPending ) {
				stateClass = ' forwp-drive-status--pending';
			} else {
				stateClass = ' forwp-drive-status--success';
			}
		}

		const driveHook =
			el.id === 'forwp-drive-drive-actions-status'
				? ' forwp-drive-status--drive-actions'
				: '';
		el.className =
			'forwp-drive-status' +
			driveHook +
			( hasMessage ? ' forwp-drive-status--visible' : '' ) +
			stateClass;

		if ( hasMessage && typeof el.scrollIntoView === 'function' ) {
			el.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
		}
	}

	function setBusyOverlay( message ) {
		const overlay = document.getElementById( 'forwp-drive-busy' );
		const text = document.getElementById( 'forwp-drive-busy-message' );
		const wrap = document.querySelector( '.forwp-drive-inbox-dashboard' );
		const on = !! message;
		if ( text ) {
			text.textContent = message || '';
		}
		if ( overlay ) {
			overlay.hidden = ! on;
			if ( on && typeof overlay.querySelector === 'function' ) {
				const panel = overlay.querySelector( '.forwp-drive-busy__panel' );
				if ( panel && typeof panel.focus === 'function' ) {
					panel.focus();
				}
			}
		}
		document.body.classList.toggle( 'forwp-drive-is-busy', on );
		if ( wrap ) {
			wrap.setAttribute( 'aria-busy', on ? 'true' : 'false' );
		}
	}

	/**
	 * Copy text to clipboard (Clipboard API with execCommand fallback for HTTP local dev).
	 *
	 * @param {string} text Text to copy.
	 * @return {Promise<boolean>} Whether copy succeeded.
	 */
	function copyTextToClipboard( text ) {
		if ( ! text ) {
			return Promise.resolve( false );
		}

		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text ).then(
				() => true,
				() => copyTextToClipboardFallback( text )
			);
		}

		return Promise.resolve( copyTextToClipboardFallback( text ) );
	}

	/**
	 * @param {string} text Text to copy.
	 * @return {boolean}
	 */
	function copyTextToClipboardFallback( text ) {
		const textarea = document.createElement( 'textarea' );
		textarea.value = text;
		textarea.setAttribute( 'readonly', '' );
		textarea.style.position = 'fixed';
		textarea.style.left = '-9999px';
		document.body.appendChild( textarea );
		textarea.select();
		let copied = false;
		try {
			copied = document.execCommand( 'copy' );
		} catch ( err ) {
			copied = false;
		}
		document.body.removeChild( textarea );
		return copied;
	}

	function driveActionsStatus() {
		return document.getElementById( 'forwp-drive-drive-actions-status' );
	}

	function formatSyncTime( timestamp ) {
		if ( ! timestamp ) {
			return '';
		}
		const date = new Date( String( timestamp ).replace( ' ', 'T' ) + 'Z' );
		if ( Number.isNaN( date.getTime() ) ) {
			return String( timestamp );
		}
		return date.toLocaleString( undefined, {
			month: 'short',
			day: 'numeric',
			hour: '2-digit',
			minute: '2-digit',
		} );
	}

	function updateInboxStatusBar( connection, lastSync, documentCount, incomingId ) {
		const srcStatus = getActiveSourceStatus();
		const effectiveConnection =
			( srcStatus && srcStatus.connection ) || connection || null;
		const effectiveLastSync =
			( srcStatus && srcStatus.last_sync ) || lastSync || null;
		const strings = forwpDriveAdmin.strings || {};
		const sourceIcon = sourceIconMarkup( activeSourceSlug );
		const state =
			effectiveConnection && effectiveConnection.state
				? effectiveConnection.state
				: '';
		if ( state === 'ok' ) {
			const connectedLabel =
				activeSourceSlug === 'github' &&
				effectiveConnection &&
				effectiveConnection.message
					? effectiveConnection.message
					: 'Connected';
			setChip( 'connection', connectedLabel, 'ok', sourceIcon );
		} else if ( state === 'not_configured' ) {
			setChip( 'connection', 'Not configured', 'warn', sourceIcon );
		} else if ( state === 'disconnected' ) {
			setChip( 'connection', 'Disconnected', 'warn', sourceIcon );
		} else if ( state === 'expired' || state === 'error' ) {
			setChip( 'connection', 'Connection problem', 'error', sourceIcon );
		} else {
			setChip( 'connection', 'Checking connection…', 'muted', sourceIcon );
		}

		if ( effectiveLastSync && effectiveLastSync.timestamp ) {
			setChip(
				'sync',
				'Last sync: ' + formatSyncTime( effectiveLastSync.timestamp ),
				'muted'
			);
		} else if ( effectiveLastSync && typeof effectiveLastSync.scanned === 'number' ) {
			setChip( 'sync', 'Last sync: done', 'muted' );
		} else {
			setChip( 'sync', 'Last sync: —', 'muted' );
		}

		const ready =
			typeof documentCount === 'number'
				? documentCount
				: effectiveLastSync && typeof effectiveLastSync.ready_total === 'number'
				? effectiveLastSync.ready_total
				: 0;
		setChip( 'ready', 'Ready: ' + ready, ready > 0 ? 'ok' : 'muted' );

		const errorsChip = document.querySelector(
			'#forwp-drive-inbox-chips [data-chip="errors"]'
		);
		const exportErrors =
			effectiveLastSync && typeof effectiveLastSync.export_errors === 'number'
				? effectiveLastSync.export_errors
				: 0;
		if ( errorsChip ) {
			if ( exportErrors > 0 ) {
				setChip( 'errors', 'Export errors: ' + exportErrors, 'error' );
			} else {
				errorsChip.hidden = true;
			}
		}

		const openIncoming = document.getElementById(
			'forwp-drive-inbox-open-incoming'
		);
		if ( openIncoming ) {
			const url =
				( srcStatus && srcStatus.incoming_url ) ||
				( activeSourceSlug === 'google_drive'
					? driveFolderUrl(
							( srcStatus && srcStatus.incoming_id ) || incomingId
					  )
					: '' );
			openIncoming.textContent =
				activeSourceSlug === 'github'
					? strings.openOnGitHub || 'Open on GitHub'
					: strings.openFolder || 'Open folder';
			if ( url ) {
				openIncoming.href = url;
				openIncoming.hidden = false;
			} else {
				openIncoming.hidden = true;
				openIncoming.removeAttribute( 'href' );
			}
		}

		const queueCount = document.getElementById( 'forwp-drive-inbox-queue-count' );
		if ( queueCount ) {
			if ( documentCount > 0 ) {
				queueCount.textContent = String( documentCount );
				queueCount.hidden = false;
			} else {
				queueCount.hidden = true;
			}
		}
	}

	function setSelectedQueueCard( id ) {
		document
			.querySelectorAll(
				'.forwp-drive-tree__row.is-selected, .forwp-drive-card.is-selected'
			)
			.forEach( ( row ) => {
				row.classList.remove( 'is-selected' );
				row.setAttribute( 'aria-selected', 'false' );
			} );
		if ( ! id ) {
			dockPackageTools();
			return;
		}
		document
			.querySelectorAll(
				`.forwp-drive-tree__row[data-id="${ id }"]`
			)
			.forEach( ( row ) => {
				row.classList.add( 'is-selected' );
				row.setAttribute( 'aria-selected', 'true' );
			} );
		attachPackageToolsToSelectedCard();
	}

	function showWorkspacePlaceholder() {
		const placeholder = document.getElementById(
			'forwp-drive-workspace-placeholder'
		);
		const panel = document.getElementById( 'forwp-drive-preview' );
		if ( placeholder ) {
			placeholder.hidden = false;
		}
		if ( panel ) {
			panel.hidden = true;
		}
		previewId = null;
		previewDoc = null;
		const featuredWrap = document.getElementById(
			'forwp-drive-import-featured-wrap'
		);
		const featuredSelect = document.getElementById(
			'forwp-drive-import-featured'
		);
		if ( featuredWrap ) {
			featuredWrap.hidden = true;
		}
		if ( featuredSelect ) {
			featuredSelect.innerHTML = '';
		}
		dockPackageTools();
		setSelectedQueueCard( null );
	}

	function toggleTreeFolder( path, docId ) {
		const folderPath = String( path || '' ).trim();
		if ( ! folderPath ) {
			return;
		}

		const wasExpanded = treeExpandedPaths.has( folderPath );
		const nested =
			! docId && lastInboxTree
				? findFirstImportableUnder( lastInboxTree, folderPath )
				: null;

		if ( docId ) {
			// Package folder: stay expanded and open preview.
			treeExpandedPaths.add( folderPath );
			previewId = docId;
			renderInbox( documentsForActiveSource(), inboxCache.lastSync );
			openPreview( docId );
			return;
		}

		if ( nested && nested.docId ) {
			// Container (e.g. LMS4WP): expand and open the first nested material.
			treeExpandedPaths.add( folderPath );
			expandTreePath( nested.folderPath || folderPath );
			previewId = nested.docId;
			renderInbox( documentsForActiveSource(), inboxCache.lastSync );
			openPreview(
				nested.docId,
				nested.fileId ? { fileId: nested.fileId } : {}
			);
			return;
		}

		if ( wasExpanded ) {
			treeExpandedPaths.delete( folderPath );
		} else {
			treeExpandedPaths.add( folderPath );
		}
		renderInbox( documentsForActiveSource(), inboxCache.lastSync );
	}

	/**
	 * Ensure every segment of a folder path is expanded in the tree.
	 *
	 * @param {string} folderPath Path like LMS4WP/articles.
	 */
	function expandTreePath( folderPath ) {
		const parts = String( folderPath || '' )
			.split( '/' )
			.filter( Boolean );
		let path = '';
		parts.forEach( ( segment ) => {
			path = path ? path + '/' + segment : segment;
			treeExpandedPaths.add( path );
		} );
	}

	/**
	 * First importable document under a folder path (for container folders).
	 *
	 * @param {Object} root Tree root.
	 * @param {string} folderPath Folder path.
	 * @return {{docId:string,fileId:string,folderPath:string}|null}
	 */
	function findFirstImportableUnder( root, folderPath ) {
		const findFolder = ( node, path ) => {
			if ( ! node || ! path ) {
				return null;
			}
			if ( String( node.path || '' ) === path ) {
				return node;
			}
			let found = null;
			Object.keys( node.folders || {} ).some( ( key ) => {
				found = findFolder( node.folders[ key ], path );
				return !! found;
			} );
			return found;
		};

		const walk = ( node ) => {
			if ( ! node ) {
				return null;
			}
			if ( node.doc && node.doc.id ) {
				return {
					docId: String( node.doc.id ),
					fileId: String( node.doc.file_id || node.doc.selected_file_id || '' ),
					folderPath: String( node.path || '' ),
				};
			}
			const files = node.files || [];
			for ( let i = 0; i < files.length; i++ ) {
				const file = files[ i ];
				if ( file && file.doc && file.doc.id ) {
					return {
						docId: String( file.doc.id ),
						fileId: String( file.fileId || file.doc.file_id || '' ),
						folderPath: String( node.path || '' ),
					};
				}
			}
			const names = sortTreeFolderNames( node.folders || {} );
			for ( let i = 0; i < names.length; i++ ) {
				const hit = walk( node.folders[ names[ i ] ] );
				if ( hit ) {
					return hit;
				}
			}
			return null;
		};

		const folder = findFolder( root, folderPath );
		return folder ? walk( folder ) : null;
	}

	function renderInboxEmpty( lastSync ) {
		const list = document.getElementById( 'forwp-drive-inbox-list' );
		if ( ! list ) {
			return;
		}

		const isGithub = activeSourceSlug === 'github';
		let syncHint = '';
		if ( ! isGithub && lastSync && typeof lastSync.scanned === 'number' ) {
			if ( lastSync.scanned === 0 ) {
				syncHint =
					'<p>No articles found in <code>incoming</code>. Use a Google Doc, Markdown (<code>.md</code>), or <code>.docx</code> (not a shortcut).</p>';
			} else if ( ( lastSync.ready_total ?? 0 ) === 0 ) {
				syncHint =
					'<p>Files were seen in Drive but none are ready. Sync again after editing, or check the file is a Google Doc, Markdown, or .docx.</p>';
			}
		}

		const checklist = isGithub
			? `<li>Each article: a subfolder at the <strong>repo root</strong> (or your Incoming path) with a <strong>.md</strong> file plus png/jpg images.</li>
					<li>Click <strong>Sync</strong> after pushing to the repo.</li>
					<li>Already imported? Check the <strong>published</strong> path in the repo.</li>`
			: `<li>Each article: a subfolder inside <strong>incoming/</strong> with a <strong>Google Doc</strong>, <strong>.md</strong>, or <strong>.docx</strong> plus a featured <strong>image</strong>.</li>
					<li>Click <strong>Sync from source</strong> after adding or editing the file.</li>
					<li>Already imported? Check the <strong>published</strong> folder on Drive.</li>`;

		list.innerHTML = `
			<div class="forwp-drive-empty-panel forwp-drive-admin-chrome">
				<p class="forwp-drive-empty-panel__lead"><strong>No documents ready for import.</strong></p>
				${ syncHint }
				<p class="forwp-drive-empty-panel__label">Checklist</p>
				<ul class="forwp-drive-empty-panel__list">
					${ checklist }
				</ul>
			</div>`;
		showWorkspacePlaceholder();
	}

	let treeExpandedPaths = new Set();
	let treeExpandInitialized = false;
	let lastInboxTree = null;

	function packageFolderLabel( doc ) {
		if ( doc.package_folder_name ) {
			return String( doc.package_folder_name );
		}
		const folderId = String( doc.package_folder_id || '' ).trim();
		if ( folderId && ( doc.source === 'github' || folderId.indexOf( '/' ) !== -1 ) ) {
			const parts = folderId.split( '/' ).filter( Boolean );
			return parts.length ? parts[ parts.length - 1 ] : folderId;
		}
		return doc.title || doc.file_name || 'Package';
	}

	/**
	 * Path segments for nesting (GitHub path) or a single folder label (Drive).
	 *
	 * @param {Object} doc Document row.
	 * @return {string[]}
	 */
	function packageFolderSegments( doc ) {
		const folderId = String( doc.package_folder_id || '' ).trim();
		if (
			folderId &&
			( doc.source === 'github' || folderId.indexOf( '/' ) !== -1 )
		) {
			return folderId.split( '/' ).filter( Boolean );
		}
		if ( folderId || doc.package_folder_name || ( doc.package_files || [] ).length ) {
			return [ packageFolderLabel( doc ) ];
		}
		return [];
	}

	/**
	 * Build a nested folder tree from queue documents (fallback when browse is empty).
	 *
	 * @param {Array<Object>} documents Documents.
	 * @return {{folders: Object, files: Array<Object>}}
	 */
	function buildQueueTree( documents ) {
		const root = { folders: {}, files: [], path: '' };

		( documents || [] ).forEach( ( doc ) => {
			const segments = packageFolderSegments( doc );
			if ( ! segments.length ) {
				root.files.push( {
					doc,
					name: doc.file_name || doc.title || 'document',
					kind: 'document',
				} );
				return;
			}

			let node = root;
			let path = '';
			segments.forEach( ( segment, index ) => {
				path = path ? path + '/' + segment : segment;
				if ( ! node.folders[ segment ] ) {
					node.folders[ segment ] = {
						name: segment,
						path,
						folders: {},
						files: [],
						doc: null,
						role: '',
					};
				}
				node = node.folders[ segment ];
				if ( index === segments.length - 1 ) {
					node.doc = doc;
					const packageFiles = Array.isArray( doc.package_files )
						? doc.package_files
						: [];
					if ( packageFiles.length ) {
						node.files = packageFiles.map( ( file ) => ( {
							doc,
							name: file.name || '',
							kind: file.kind === 'image' ? 'image' : 'document',
							fileId: file.id || '',
						} ) );
					} else {
						node.files = [
							{
								doc,
								name: doc.file_name || doc.title || 'document',
								kind: 'document',
							},
						];
					}
				}
			} );
		} );

		return root;
	}

	/**
	 * Deep-clone a browse tree from the API and attach inbox documents for selection.
	 *
	 * @param {Object|null} browse Remote browse tree.
	 * @param {Array<Object>} documents Queue documents.
	 * @return {{folders: Object, files: Array<Object>}}
	 */
	function buildInboxTree( browse, documents ) {
		const docs = documents || [];
		if ( ! browse || ( ! treeHasEntries( browse ) && ! docs.length ) ) {
			return buildQueueTree( docs );
		}

		const cloneNode = ( node ) => {
			const out = {
				name: node.name || '',
				path: node.path || '',
				role: node.role || '',
				id: node.id || '',
				doc: null,
				folders: {},
				files: [],
			};
			Object.keys( node.folders || {} ).forEach( ( key ) => {
				out.folders[ key ] = cloneNode( node.folders[ key ] );
			} );
			( node.files || [] ).forEach( ( file ) => {
				out.files.push( {
					name: file.name || '',
					kind: file.kind || 'file',
					fileId: file.id || '',
					doc: null,
				} );
			} );
			return out;
		};

		const root = {
			folders: {},
			files: [],
			path: '',
		};
		Object.keys( browse.folders || {} ).forEach( ( key ) => {
			root.folders[ key ] = cloneNode( browse.folders[ key ] );
		} );
		( browse.files || [] ).forEach( ( file ) => {
			root.files.push( {
				name: file.name || '',
				kind: file.kind || 'file',
				fileId: file.id || '',
				doc: null,
			} );
		} );

		const findFolderByPath = ( node, path ) => {
			if ( ! path ) {
				return null;
			}
			if ( node.path === path ) {
				return node;
			}
			let found = null;
			Object.keys( node.folders || {} ).some( ( key ) => {
				found = findFolderByPath( node.folders[ key ], path );
				return !! found;
			} );
			return found;
		};

		/**
		 * Match browse-tree file ids to queue file ids.
		 * GitHub browse used bare paths; documents use gh:owner/repo:path.
		 *
		 * @param {string} a First id.
		 * @param {string} b Second id.
		 * @return {boolean}
		 */
		const sameStorageId = ( a, b ) => {
			const left = String( a || '' ).trim();
			const right = String( b || '' ).trim();
			if ( ! left || ! right ) {
				return false;
			}
			if ( left === right ) {
				return true;
			}
			const stripGh = ( id ) => {
				if ( 0 !== id.indexOf( 'gh:' ) ) {
					return id;
				}
				const parts = id.split( ':' );
				return parts.length >= 3 ? parts.slice( 2 ).join( ':' ) : id;
			};
			return stripGh( left ) === stripGh( right );
		};

		const attachFileDoc = ( node, fileId, doc ) => {
			if ( ! fileId ) {
				return false;
			}
			const hit = ( node.files || [] ).find( ( file ) =>
				sameStorageId( file.fileId, fileId )
			);
			if ( hit ) {
				hit.doc = doc;
				return true;
			}
			return Object.keys( node.folders || {} ).some( ( key ) =>
				attachFileDoc( node.folders[ key ], fileId, doc )
			);
		};

		docs.forEach( ( doc ) => {
			const packagePath = String( doc.package_folder_id || '' ).trim();
			const fileId = String( doc.file_id || doc.selected_file_id || '' ).trim();
			let attached = false;

			if ( packagePath ) {
				const folder = findFolderByPath( root, packagePath );
				if ( folder ) {
					folder.doc = doc;
					attached = true;
					const packageFiles = Array.isArray( doc.package_files )
						? doc.package_files
						: [];
					packageFiles.forEach( ( pf ) => {
						const id = pf.id || '';
						const existing = ( folder.files || [] ).find(
							( f ) =>
								sameStorageId( f.fileId, id ) ||
								( pf.name && f.name === pf.name )
						);
						if ( existing && ( pf.kind === 'document' || ! pf.kind ) ) {
							existing.doc = doc;
							if ( id && ! existing.fileId ) {
								existing.fileId = id;
							} else if ( id ) {
								existing.fileId = id;
							}
						}
					} );
					attachFileDoc( folder, fileId, doc );
					// Any remaining markdown/doc files in the package folder share this queue doc.
					( folder.files || [] ).forEach( ( f ) => {
						if (
							! f.doc &&
							( f.kind === 'document' || /\.(md|mdx|markdown)$/i.test( f.name || '' ) )
						) {
							f.doc = doc;
						}
					} );
				}
			}

			if ( ! attached && fileId ) {
				attached = attachFileDoc( root, fileId, doc );
			}

			if ( ! attached ) {
				const fallback = buildQueueTree( [ doc ] );
				Object.keys( fallback.folders || {} ).forEach( ( key ) => {
					if ( ! root.folders[ key ] ) {
						root.folders[ key ] = fallback.folders[ key ];
					}
				} );
				( fallback.files || [] ).forEach( ( file ) => {
					root.files.push( file );
				} );
			}
		} );

		return root;
	}

	function treeFolderIcon() {
		return `<span class="forwp-drive-tree__icon-wrap" aria-hidden="true"><svg class="forwp-drive-tree__icon forwp-drive-tree__icon--folder" viewBox="0 0 16 16" width="16" height="16" focusable="false"><path fill="#54aeff" d="M1.75 2.5a.75.75 0 0 0 0 1.5h3.69l1.03 1.03a.75.75 0 0 0 .53.22h6.25a.75.75 0 0 1 .75.75v6.5a.75.75 0 0 1-.75.75H1.75a.75.75 0 0 1-.75-.75V3.25a.75.75 0 0 1 .75-.75Z"></path></svg></span>`;
	}

	function treeFileIcon( kind ) {
		const fill = kind === 'image' ? '#3fb950' : '#8b949e';
		return `<span class="forwp-drive-tree__icon-wrap" aria-hidden="true"><svg class="forwp-drive-tree__icon forwp-drive-tree__icon--file" viewBox="0 0 16 16" width="16" height="16" focusable="false"><path fill="${ fill }" d="M2 1.75C2 .784 2.784 0 3.75 0h6.586c.464 0 .909.184 1.237.513l2.914 2.914c.329.328.513.773.513 1.237v9.586A1.75 1.75 0 0 1 13.25 16h-9.5A1.75 1.75 0 0 1 2 14.25Zm1.75-.25a.25.25 0 0 0-.25.25v12.5c0 .138.112.25.25.25h9.5a.25.25 0 0 0 .25-.25V6h-2.75A1.75 1.75 0 0 1 9 4.25V1.5Zm6.75.062L14.938 4.5H10.75a.25.25 0 0 1-.25-.25V1.562Z"></path></svg></span>`;
	}

	function treeToggle( expanded ) {
		// SVG only — WP admin can force dashicons on text nodes, which hides ▾/▸ glyphs.
		return `<span class="forwp-drive-tree__toggle${
			expanded ? ' is-expanded' : ''
		}" aria-hidden="true"><svg viewBox="0 0 16 16" width="12" height="12" fill="currentColor" focusable="false"><path d="M6.22 3.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042L9.94 8 6.22 4.28a.75.75 0 0 1 0-1.06Z"></path></svg></span>`;
	}

	function ensureTreeExpandedDefaults( root, documents ) {
		if ( treeExpandInitialized ) {
			return;
		}
		treeExpandInitialized = true;
		const paths = [];
		const walk = ( node ) => {
			Object.keys( node.folders || {} ).forEach( ( key ) => {
				const child = node.folders[ key ];
				paths.push( child.path );
				walk( child );
			} );
		};
		walk( root );
		const folderCount = paths.length;
		const docCount = ( documents || [] ).length;
		// Expand shallow trees; keep deep published/failed collapsed by default past depth 1.
		if ( folderCount <= 40 || docCount <= 20 ) {
			paths.forEach( ( path ) => {
				const depth = String( path ).split( '/' ).filter( Boolean ).length;
				if ( depth <= 1 ) {
					treeExpandedPaths.add( path );
				}
			} );
		} else if ( previewId ) {
			const selected = ( documents || [] ).find(
				( doc ) => String( doc.id ) === String( previewId )
			);
			if ( selected ) {
				const segments = packageFolderSegments( selected );
				let path = '';
				segments.forEach( ( segment ) => {
					path = path ? path + '/' + segment : segment;
					treeExpandedPaths.add( path );
				} );
			}
		}
		// Always expand role roots so published/failed are visible.
		Object.keys( root.folders || {} ).forEach( ( key ) => {
			const folder = root.folders[ key ];
			if ( folder.role || key === 'published' || key === 'failed' || key === 'incoming' ) {
				treeExpandedPaths.add( folder.path );
			}
		} );
	}

	function sortTreeFolderNames( folderMap ) {
		const names = Object.keys( folderMap || {} );
		const weight = ( name ) => {
			const role = ( folderMap[ name ] && folderMap[ name ].role ) || '';
			const key = role || name;
			if ( key === 'incoming' ) {
				return -10;
			}
			if ( key === 'published' ) {
				return 900;
			}
			if ( key === 'failed' ) {
				return 901;
			}
			return 0;
		};
		return names.sort( ( a, b ) => {
			const dw = weight( a ) - weight( b );
			if ( dw !== 0 ) {
				return dw;
			}
			return a.localeCompare( b, undefined, { sensitivity: 'base' } );
		} );
	}

	function renderQueueTreeNode( node, depth ) {
		const folderNames = sortTreeFolderNames( node.folders || {} );
		let html = '';

		folderNames.forEach( ( name ) => {
			const folder = node.folders[ name ];
			const expanded = treeExpandedPaths.has( folder.path );
			const docId = folder.doc ? folder.doc.id : '';
			const selected =
				docId && previewId && String( docId ) === String( previewId );
			const warning =
				folder.doc && folder.doc.scan_error ? ' is-warning' : '';
			const roleClass = folder.role
				? ` is-role is-role--${ escapeHtml( folder.role ) }`
				: '';
			const emptyClass =
				! Object.keys( folder.folders || {} ).length &&
				! ( folder.files || [] ).length
					? ' is-empty'
					: '';
			html += `<div class="forwp-drive-tree__group" data-path="${ escapeHtml(
				folder.path
			) }">
				<div
					class="forwp-drive-tree__row is-folder${ expanded ? ' is-expanded' : '' }${
				selected ? ' is-selected' : ''
			}${ warning }${ roleClass }${ emptyClass }"
					style="--forwp-drive-tree-depth: ${ depth }"
					data-action="tree-folder"
					data-path="${ escapeHtml( folder.path ) }"
					${ docId ? `data-id="${ docId }"` : '' }
					tabindex="0"
					role="treeitem"
					aria-expanded="${ expanded ? 'true' : 'false' }"
					aria-selected="${ selected ? 'true' : 'false' }"
				>
					${ treeToggle( expanded ) }
					${ treeFolderIcon() }
					<span class="forwp-drive-tree__label">${ escapeHtml( folder.name ) }</span>
				</div>`;
			if ( expanded ) {
				html += `<div class="forwp-drive-tree__children" role="group">`;
				const childHtml = renderQueueTreeNode( folder, depth + 1 );
				html +=
					childHtml ||
					`<div class="forwp-drive-tree__empty" style="--forwp-drive-tree-depth: ${
						depth + 1
					}">(empty)</div>`;
				html += `</div>`;
			}
			html += `</div>`;
		} );

		( node.files || [] ).forEach( ( file ) => {
			const fileDocId = file.doc ? file.doc.id : '';
			const fileSelected =
				fileDocId && previewId && String( fileDocId ) === String( previewId );
			const selectable = !! fileDocId;
			const storageFileId = file.fileId || '';
			html += `<div
				class="forwp-drive-tree__row is-file is-file--${ escapeHtml(
					file.kind || 'document'
				) }${ fileSelected ? ' is-selected' : '' }${
				selectable ? '' : ' is-readonly'
			}"
				style="--forwp-drive-tree-depth: ${ depth }"
				${
					selectable
						? `data-action="select" data-id="${ escapeHtml(
								String( fileDocId )
						  ) }" data-file-id="${ escapeHtml( String( storageFileId ) ) }"`
						: ''
				}
				tabindex="${ selectable ? '0' : '-1' }"
				role="treeitem"
				aria-selected="${ fileSelected ? 'true' : 'false' }"
			>
				${ treeFileIcon( file.kind ) }
				<span class="forwp-drive-tree__label">${ escapeHtml( file.name ) }</span>
			</div>`;
		} );

		return html;
	}

	function renderInbox( documents, lastSync ) {
		const list = document.getElementById( 'forwp-drive-inbox-list' );
		if ( ! list ) {
			return;
		}

		const browse = browseTreeForActiveSource();
		const tree = buildInboxTree( browse, documents );
		lastInboxTree = tree;
		const hasTree = treeHasEntries( tree );

		if ( ! hasTree && ( ! documents || ! documents.length ) ) {
			renderInboxEmpty( lastSync );
			return;
		}

		const keepId = previewId;
		dockPackageTools();
		ensureTreeExpandedDefaults( tree, documents );
		const browseError =
			browse && browse.error
				? `<p class="forwp-drive-tree__error">${ escapeHtml( browse.error ) }</p>`
				: '';
		list.innerHTML = `${ browseError }<div class="forwp-drive-tree" role="tree" aria-label="Storage tree">${ renderQueueTreeNode(
			tree,
			0
		) }</div>`;

		const stillThere =
			keepId &&
			documents.some( ( doc ) => String( doc.id ) === String( keepId ) );
		if ( stillThere ) {
			setSelectedQueueCard( keepId );
			if ( previewDoc ) {
				setupImagePinUi( previewDoc );
			}
		} else if ( ! documents || ! documents.length ) {
			showWorkspacePlaceholder();
		} else {
			showWorkspacePlaceholder();
		}
	}

	function inboxStatusMessage( lastSync, documentCount ) {
		if ( ! lastSync ) {
			return documentCount
				? `${ documentCount } document(s) ready.`
				: 'Inbox is empty. Run sync after adding files to incoming.';
		}

		const parts = [
			`Last sync: ${ lastSync.scanned ?? 0 } in incoming`,
			`${ documentCount } shown`,
			`${ lastSync.ready_total ?? 0 } ready total`,
		];
		if ( lastSync.export_errors ) {
			parts.push( `${ lastSync.export_errors } export error(s)` );
		}
		return parts.join( ' · ' );
	}

	function escapeHtml( text ) {
		const div = document.createElement( 'div' );
		div.textContent = text == null ? '' : String( text );
		return div.innerHTML;
	}

	function driveFolderUrl( folderId ) {
		const id = String( folderId || '' ).trim();
		if ( ! id ) {
			return '';
		}
		return (
			'https://drive.google.com/drive/folders/' +
			encodeURIComponent( id )
		);
	}

	function driveFileUrl( fileId ) {
		const id = String( fileId || '' ).trim();
		if ( ! id ) {
			return '';
		}
		// Native Google Doc — open the article in the Docs editor.
		return (
			'https://docs.google.com/document/d/' +
			encodeURIComponent( id ) +
			'/edit'
		);
	}

	function updateRootFolderOpenLink( folderId ) {
		const link = document.getElementById( 'forwp-drive-root-folder-open' );
		if ( ! link ) {
			return;
		}
		const url = driveFolderUrl( folderId );
		if ( url ) {
			link.href = url;
			link.hidden = false;
		} else {
			link.hidden = true;
			link.removeAttribute( 'href' );
		}
	}

	function renderFolderRow( label, folderId ) {
		const id = escapeHtml( folderId || '' );
		const url = driveFolderUrl( folderId );
		const openLabel = escapeHtml(
			forwpDriveAdmin.strings.openInDrive || 'Open in Drive'
		);
		const link = url
			? ` <a href="${ escapeHtml(
					url
			  ) }" class="forwp-drive-drive-folder-link" target="_blank" rel="noopener noreferrer">${ openLabel } <span class="screen-reader-text">${ id }</span></a>`
			: '';
		return `<dt>${ escapeHtml(
			label
		) }</dt><dd><code>${ id }</code>${ link }</dd>`;
	}

	function renderDriveConnectionAlert( connection ) {
		const alert = document.getElementById( 'forwp-drive-inbox-connection-alert' );
		const syncBtn = document.getElementById( 'forwp-drive-inbox-sync' );
		if ( ! alert ) {
			return;
		}

		const show =
			!! connection &&
			connection.state &&
			'ok' !== connection.state &&
			'not_configured' !== connection.state;

		if ( ! show ) {
			alert.hidden = true;
			alert.innerHTML = '';
			if ( syncBtn ) {
				syncBtn.disabled = false;
			}
			return;
		}

		const strings = forwpDriveAdmin.strings || {};
		const settingsUrl = connection.settings_url || 'admin.php?page=forwp-drive-settings';
		const reconnectLink =
			connection.needs_reconnect && connection.auth_url
				? `<p><a class="button button-primary" href="${ escapeHtml(
						connection.auth_url
				  ) }">${ escapeHtml(
						strings.reconnectDrive || 'Reconnect Google Drive'
				  ) }</a> <a class="button" href="${ escapeHtml(
						settingsUrl
				  ) }">${ escapeHtml( strings.openSettings || 'Open Settings' ) }</a></p>`
				: `<p><a class="button button-primary" href="${ escapeHtml(
						settingsUrl
				  ) }">${ escapeHtml( strings.openSettings || 'Open Settings' ) }</a></p>`;
		const staleNote =
			connection.state === 'expired' || connection.state === 'error'
				? `<p>${ escapeHtml(
						strings.inboxStaleNote ||
							'The inbox below may be outdated until Drive access is restored and you sync again.'
				  ) }</p>`
				: '';

		alert.innerHTML = `<div class="forwp-drive-connection-alert__inner forwp-drive-status forwp-drive-status--error forwp-drive-status--visible">
			<p><strong>${ escapeHtml(
				strings.connectionProblemTitle || 'Google Drive connection problem'
			) }</strong></p>
			<p>${ escapeHtml( connection.message || '' ) }</p>
			${ staleNote }
			${ reconnectLink }
		</div>`;
		alert.hidden = false;

		if ( syncBtn ) {
			syncBtn.disabled = !! connection.needs_reconnect;
		}
	}

	function loadInbox() {
		const status = document.getElementById( 'forwp-drive-inbox-status' );
		setStatus( status, 'Loading…', false, true );
		return api( 'documents?status=ready' ).then( ( { ok, data } ) => {
			if ( ! ok ) {
				setStatus(
					status,
					data.message || 'Failed to load inbox. Try refreshing the page.',
					true
				);
				return;
			}
			renderDriveConnectionAlert( data.drive_connection );
			if ( data.multilingual ) {
				multilingualConfig = data.multilingual;
			}
			const docs = data.documents || [];
			inboxCache = {
				documents: docs,
				browseTrees: data.browse_trees || {},
				lastSync: data.last_sync,
				connection: data.drive_connection,
				incomingId: data.incoming_id || '',
				sourceStatus: data.source_status || {},
			};
			updateAdminMenuIncomingCount( docs.length );
			renderInboxSourceTabs();
			applyActiveSourceChrome();
			setStatus( status, '' );
			setActiveInboxSource( activeSourceSlug );
		} );
	}

	function runInboxSync() {
		const status = document.getElementById( 'forwp-drive-inbox-status' );
		setStatus( status, forwpDriveAdmin.strings.syncRunning, false, true );
		return api( 'sync/run', { method: 'POST' } ).then( ( { ok, data } ) => {
			if ( ! ok ) {
				setStatus( status, data.message || 'Sync failed.', true );
				return;
			}
			let message = `Synced ${ data.scanned || 0 } file(s); ${ data.new_ready || 0 } new ready.`;
			if ( data.removed ) {
				message += ` ${ data.removed } removed from inbox (no longer in incoming).`;
			}
			if ( data.export_errors ) {
				message += ` ${ data.export_errors } could not be exported.`;
			}
			setStatus( status, message, false );
			return loadInbox();
		} );
	}

	function openPreview( id, options = {} ) {
		previewId = id;
		previewDoc = null;
		setSelectedQueueCard( id );
		const panel = document.getElementById( 'forwp-drive-preview' );
		const placeholder = document.getElementById(
			'forwp-drive-workspace-placeholder'
		);
		const meta = document.getElementById( 'forwp-drive-preview-meta' );
		const body = document.getElementById( 'forwp-drive-preview-post-content' );
		if ( ! panel || ! body ) {
			return;
		}
		if ( placeholder ) {
			placeholder.hidden = true;
		}
		panel.hidden = false;
		meta.innerHTML = '<p class="forwp-drive-preview__meta">Loading preview…</p>';
		body.innerHTML = '';
		const wantedFileId = String( options.fileId || '' ).trim();

		const finishPreview = ( data ) => {
			if ( String( previewId ) !== String( id ) ) {
				return;
			}
			previewDoc = data;
			if ( data.multilingual ) {
				multilingualConfig = data.multilingual;
			}
			meta.innerHTML = `<p class="forwp-drive-preview__title">${ escapeHtml( data.title ) }</p>
				<p class="forwp-drive-preview__meta">Slug: ${ escapeHtml( data.slug || '—' ) } · Date: ${ escapeHtml( data.date || '—' ) } · Author: ${ escapeHtml( data.author || '—' ) } · Category: ${ escapeHtml( data.category || '—' ) }${ data.has_image ? ' · Featured image: ' + escapeHtml( data.image_name || 'yes' ) : '' }</p>`;
			body.innerHTML = data.body_html || escapeHtml( data.body || '' );
			if ( data.body_html && data.body_html.includes( '<!-- wp:' ) ) {
				body.insertAdjacentHTML(
					'afterbegin',
					'<p class="description forwp-drive-preview__blocks-note">Block markup preview — imported posts store Gutenberg blocks, not raw HTML.</p>'
				);
			}
			const mode = options.mode === 'update' ? 'update' : 'create';
			const modeInput = document.querySelector(
				`input[name="forwp-drive-import-mode"][value="${ mode }"]`
			);
			if ( modeInput instanceof HTMLInputElement ) {
				modeInput.checked = true;
			}
			setImportModeUi();
			fillInboxImportPostTypes();
			setupImportLanguageUi( data.multilingual );
			setupSourceFileUi( data );
			setupFeaturedImageUi( data );
			setupImagePinUi( data );
			if ( requiresImportLanguage() ) {
				resetImportTargetSelect();
			}
			loadImportTargets( data );
		};

		const loadDocument = () =>
			api( 'documents/' + id ).then( ( { ok, data } ) => {
				if ( ! ok ) {
					const detail =
						data && data.message
							? String( data.message )
							: 'Could not load preview.';
					meta.innerHTML =
						'<p class="forwp-drive-preview__meta">' +
						escapeHtml( detail ) +
						'</p>';
					return;
				}
				finishPreview( data );
			} );

		if ( wantedFileId ) {
			api( 'documents/' + id + '/source', {
				method: 'POST',
				body: JSON.stringify( { file_id: wantedFileId } ),
			} ).then( ( { ok } ) => {
				loadDocument();
				if ( ! ok ) {
					// Still show whatever is selected if switch failed.
				}
			} );
			return;
		}

		loadDocument();
	}

	function importDoc( id, payloadOverride ) {
		const payload = payloadOverride || getImportPayload();
		if ( payload === null ) {
			return;
		}
		const strings = forwpDriveAdmin.strings || {};
		const isUpdate = payload.mode === 'update';
		confirmDialog( {
			title: isUpdate
				? strings.dialogUpdateTitle || 'Update post'
				: strings.dialogImportTitle || 'Import document',
			message: isUpdate ? strings.updateConfirm : strings.importConfirm,
			confirmLabel: isUpdate
				? strings.updateExistingPost || 'Update existing post'
				: strings.importAsDraft || 'Import as draft',
		} ).then( ( confirmed ) => {
			if ( ! confirmed ) {
				return;
			}
			const status = document.getElementById( 'forwp-drive-inbox-status' );
			setBusyOverlay( strings.importRunning || 'Importing…' );
			setStatus( status, strings.importRunning, false, true );
			api( 'documents/' + id + '/import', {
				method: 'POST',
				body: JSON.stringify( payload ),
			} ).then( ( { ok, data } ) => {
				if ( ! ok ) {
					setBusyOverlay( '' );
					setStatus( status, data.message || 'Import failed.', true );
					return;
				}
				if ( data.edit_url ) {
					window.location.href = data.edit_url;
					return;
				}
				loadInbox();
			} );
		} );
	}

	function rejectDoc( id ) {
		const strings = forwpDriveAdmin.strings || {};
		confirmDialog( {
			title: strings.dialogRejectTitle || 'Reject document',
			message: strings.rejectConfirm,
			confirmLabel: strings.reject || 'Reject',
			danger: true,
		} ).then( ( confirmed ) => {
			if ( ! confirmed ) {
				return;
			}
			api( 'documents/' + id + '/reject', { method: 'POST' } ).then( () => {
				showWorkspacePlaceholder();
				loadInbox();
			} );
		} );
	}

	function renderFolderIds( folderIds ) {
		const box = document.getElementById( 'forwp-drive-folder-ids' );
		if ( ! box || ! folderIds || ! folderIds.incoming ) {
			if ( box ) {
				box.hidden = true;
			}
			return;
		}
		box.hidden = false;
		box.innerHTML = `
			<p><strong>Configured folders</strong></p>
			<dl class="forwp-drive-folder-ids__list">
				${ renderFolderRow( 'incoming', folderIds.incoming ) }
				${ renderFolderRow( 'published', folderIds.published ) }
				${ renderFolderRow( 'failed', folderIds.failed ) }
			</dl>`;
	}

	let settingsCache = null;
	let templateRows = [];
	let blockMappingRows = [];
	let patternsCustomized = false;
	let selectedSourceSlug = null;
	const GOOGLE_DRIVE_SLUG = 'google_drive';

	function setActiveTab( tabId ) {
		document.querySelectorAll( '.forwp-drive-tab' ).forEach( ( btn ) => {
			const active = btn.getAttribute( 'data-tab' ) === tabId;
			btn.classList.toggle( 'is-active', active );
			btn.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			btn.tabIndex = active ? 0 : -1;
		} );
		document.querySelectorAll( '.forwp-drive-tab-panel [role="tabpanel"]' ).forEach( ( panel ) => {
			const show =
				( tabId === 'sources' && panel.id === 'forwp-drive-panel-sources' ) ||
				( tabId === 'documentation' && panel.id === 'forwp-drive-panel-documentation' );
			panel.hidden = ! show;
		} );
	}

	function showSourceOverview() {
		selectedSourceSlug = null;
		const intro = document.getElementById( 'forwp-drive-sources-intro' );
		const detail = document.getElementById( 'forwp-drive-source-detail-wrap' );
		if ( intro ) {
			intro.hidden = false;
		}
		if ( detail ) {
			detail.hidden = true;
		}
		document.querySelectorAll( '.forwp-drive-provider-card-wrap' ).forEach( ( el ) => {
			el.classList.remove( 'is-selected' );
		} );
	}

	function updateConnectionPreviewHint( data ) {
		const hint = document.getElementById( 'forwp-drive-status-preview-hint' );
		if ( ! hint || ! data ) {
			return;
		}
		const connection = data.drive_connection;
		if ( connection && connection.needs_reconnect ) {
			hint.textContent =
				connection.message ||
				'Reconnect Google Drive in Storage sources, then sync again.';
		} else if ( data.connected && data.source_ready ) {
			hint.textContent = 'Ready to sync incoming documents.';
		} else if ( data.connected ) {
			hint.textContent = 'Connected — set the root folder ID and save subfolders.';
		} else if ( data.has_client_config ) {
			hint.textContent = 'Credentials saved — click Connect your Drive.';
		} else {
			hint.textContent = 'Add API credentials, then connect your Google account.';
		}
	}

	function updateGoogleSetupHint( data ) {
		const hint = document.getElementById( 'forwp-drive-google-setup-hint' );
		if ( ! hint ) {
			return;
		}
		const show =
			!! data &&
			! data.source_ready &&
			selectedSourceSlug === GOOGLE_DRIVE_SLUG;
		hint.hidden = ! show;
	}

	function updateDevRedirectPanel( data ) {
		const panel = document.getElementById( 'forwp-drive-oauth-redirect-panel' );
		const lockedNote = document.getElementById( 'forwp-drive-dev-redirect-locked-note' );
		const oauthRedirect = document.getElementById( 'forwp-drive-oauth-redirect' );
		const useSuggestedBtn = document.getElementById( 'forwp-drive-use-suggested-redirect' );
		const saveOauthBtn = document.getElementById( 'forwp-drive-save-oauth-redirect' );
		const wpconfigNote = document.getElementById( 'forwp-drive-wpconfig-redirect-note' );

		if ( ! panel || ! data ) {
			return;
		}

		const show = !! data.local_dev_redirect_help;
		panel.hidden = ! show;

		const canEdit =
			show &&
			data.has_client_config &&
			! data.connected &&
			! data.oauth_redirect_locked;

		panel.classList.toggle( 'is-inactive', show && ! canEdit );

		if ( lockedNote ) {
			if ( ! show || canEdit ) {
				lockedNote.hidden = true;
			} else if ( ! data.has_client_config ) {
				lockedNote.hidden = false;
				lockedNote.textContent =
					'Save API credentials below first, then set the loopback redirect and register it in Google Cloud before Connect.';
			} else if ( data.connected ) {
				lockedNote.hidden = false;
				lockedNote.textContent =
					'OAuth redirect is locked while your Drive account is connected. Disconnect to change it.';
			} else {
				lockedNote.hidden = true;
			}
		}

		if ( wpconfigNote ) {
			wpconfigNote.hidden = ! data.oauth_redirect_locked;
		}

		const fieldsLocked = !! data.oauth_redirect_locked || ! canEdit;
		if ( oauthRedirect ) {
			oauthRedirect.disabled = fieldsLocked;
		}
		if ( useSuggestedBtn ) {
			useSuggestedBtn.disabled = fieldsLocked;
			useSuggestedBtn.hidden =
				! data.oauth_redirect_uri_suggested || !! data.oauth_redirect_locked;
			useSuggestedBtn.dataset.uri = data.oauth_redirect_uri_suggested || '';
		}
		if ( saveOauthBtn ) {
			saveOauthBtn.disabled = fieldsLocked;
		}
	}

	function renderSourceRegistry( sources ) {
		const grid = document.getElementById( 'forwp-drive-source-registry-grid' );
		if ( ! grid ) {
			return;
		}
		const list = sources && sources.length ? sources : [];
		grid.innerHTML = list
			.map( ( row ) => {
				const live = !! row.implemented;
				const ready = live && !! row.ready;
				const badges = [];
				if ( ready ) {
					badges.push(
						'<span class="forwp-drive-badge forwp-drive-badge--on">On</span>'
					);
				}
				if ( live ) {
					badges.push(
						'<span class="forwp-drive-badge forwp-drive-badge--live">Live</span>'
					);
				} else {
					badges.push(
						'<span class="forwp-drive-badge forwp-drive-badge--planned">Planned</span>'
					);
				}
				const classes = [ 'forwp-drive-provider-card-wrap' ];
				if ( selectedSourceSlug === row.slug ) {
					classes.push( 'is-selected' );
				}
				if ( ready ) {
					classes.push( 'is-enabled' );
				}
				return `
				<div class="${ classes.join( ' ' ) }" role="button" tabindex="0" data-source-slug="${ escapeHtml(
					row.slug
				) }" data-ready="${ ready ? '1' : '0' }" aria-pressed="${
					selectedSourceSlug === row.slug ? 'true' : 'false'
				}">
					<div class="forwp-drive-provider-card-head">
						<div>
							<div class="forwp-drive-provider-label">${ escapeHtml( row.label ) }</div>
							<div class="forwp-drive-provider-slug"><code>${ escapeHtml( row.slug ) }</code></div>
						</div>
						<div class="forwp-drive-provider-card-badges">${ badges.join( '' ) }</div>
					</div>
					<p class="forwp-drive-provider-status">${ escapeHtml( row.status || '' ) }</p>
				</div>`;
			} )
			.join( '' );
	}

	function renderLanguageProviderRegistry( providers ) {
		const grid = document.getElementById( 'forwp-drive-language-provider-grid' );
		if ( ! grid ) {
			return;
		}
		const list = providers && providers.length ? providers : [];
		grid.innerHTML = list
			.map( ( row ) => {
				let badge =
					'<span class="forwp-drive-badge forwp-drive-badge--planned">' +
					escapeHtml( 'Not installed' ) +
					'</span>';
				if ( row.planned ) {
					badge =
						'<span class="forwp-drive-badge forwp-drive-badge--planned">' +
						escapeHtml( 'Planned' ) +
						'</span>';
				} else if ( row.active ) {
					badge =
						'<span class="forwp-drive-badge forwp-drive-badge--live">' +
						escapeHtml( 'Active' ) +
						'</span>';
				} else if ( row.available ) {
					badge =
						'<span class="forwp-drive-badge forwp-drive-badge--standby">' +
						escapeHtml( 'Installed' ) +
						'</span>';
				} else if ( row.installed ) {
					badge =
						'<span class="forwp-drive-badge forwp-drive-badge--inactive">' +
						escapeHtml( 'Inactive' ) +
						'</span>';
				}
				return `
				<article class="forwp-drive-provider-card-wrap forwp-drive-provider-card-wrap--readonly${
					row.active ? ' is-active-provider' : ''
				}">
					<div class="forwp-drive-provider-card-head">
						<div>
							<div class="forwp-drive-provider-label">${ escapeHtml( row.label ) }</div>
							<div class="forwp-drive-provider-slug"><code>${ escapeHtml( row.slug ) }</code></div>
						</div>
						${ badge }
					</div>
					<p class="forwp-drive-provider-status">${ escapeHtml( row.status || '' ) }</p>
				</article>`;
			} )
			.join( '' );
	}

	function fillGitHubSettings( github ) {
		const owner = document.getElementById( 'forwp-drive-github-owner' );
		const repo = document.getElementById( 'forwp-drive-github-repo' );
		const branch = document.getElementById( 'forwp-drive-github-branch' );
		const incoming = document.getElementById( 'forwp-drive-github-incoming' );
		const tokenInput = document.getElementById( 'forwp-drive-github-token' );
		const tokenStatus = document.getElementById( 'forwp-drive-github-token-status' );
		const connected = document.getElementById( 'forwp-drive-github-connected' );
		const connectedLink = document.getElementById( 'forwp-drive-github-connected-link' );
		if ( owner ) {
			owner.value = github.owner || '';
		}
		if ( repo ) {
			repo.value = github.repo || '';
		}
		if ( branch ) {
			branch.value = github.branch || 'main';
		}
		if ( incoming ) {
			incoming.value = github.incoming || '';
		}
		// Never echo the PAT back into the DOM — only show that one is stored.
		if ( tokenInput ) {
			tokenInput.value = '';
			tokenInput.placeholder = github.has_token
				? '••••••••••••  (saved — leave blank to keep)'
				: '';
		}
		if ( tokenStatus ) {
			tokenStatus.textContent = github.has_token
				? 'Token is saved on the server. Leave the field empty to keep it, or paste a new token to replace it.'
				: 'No token saved yet.';
		}
		const repoUrl = github.repo_url || '';
		if ( connected && connectedLink ) {
			if ( repoUrl ) {
				connected.hidden = false;
				connectedLink.href = repoUrl;
				connectedLink.textContent =
					github.owner && github.repo
						? github.owner + '/' + github.repo
						: repoUrl;
			} else {
				connected.hidden = true;
				connectedLink.removeAttribute( 'href' );
				connectedLink.textContent = '';
			}
		}
	}

	function openSourceDetail( slug ) {
		const sources = settingsCache?.sources || [];
		const row = sources.find( ( s ) => s.slug === slug );
		if ( ! row ) {
			return;
		}

		selectedSourceSlug = slug;
		const intro = document.getElementById( 'forwp-drive-sources-intro' );
		const detail = document.getElementById( 'forwp-drive-source-detail-wrap' );
		const title = document.getElementById( 'forwp-drive-source-detail-title' );
		const googleSplit = document.getElementById( 'forwp-drive-google-split' );
		const githubSplit = document.getElementById( 'forwp-drive-github-split' );
		const planned = document.getElementById( 'forwp-drive-planned-detail' );
		const plannedText = document.getElementById( 'forwp-drive-planned-detail-text' );

		if ( intro ) {
			intro.hidden = true;
		}
		if ( detail ) {
			detail.hidden = false;
		}
		if ( title ) {
			title.textContent = row.label;
		}

		const isGoogle = slug === GOOGLE_DRIVE_SLUG && row.implemented;
		const isGithub = slug === 'github' && row.implemented;
		if ( googleSplit ) {
			googleSplit.hidden = ! isGoogle;
		}
		if ( githubSplit ) {
			githubSplit.hidden = ! isGithub;
		}
		if ( planned ) {
			planned.hidden = isGoogle || isGithub;
		}
		if ( plannedText && ! isGoogle && ! isGithub ) {
			plannedText.textContent = row.status;
		}

		if ( isGithub && settingsCache && settingsCache.github ) {
			fillGitHubSettings( settingsCache.github );
		}

		renderSourceRegistry( sources );
		if ( settingsCache ) {
			updateGoogleSetupHint( settingsCache );
		}
		document.getElementById( 'forwp-drive-source-detail-wrap' )?.scrollIntoView( {
			behavior: 'smooth',
			block: 'nearest',
		} );
	}

	function initSettingsChrome() {
		document.querySelectorAll( '.forwp-drive-tab' ).forEach( ( btn ) => {
			btn.addEventListener( 'click', () => {
				const tab = btn.getAttribute( 'data-tab' );
				if ( tab ) {
					setActiveTab( tab );
				}
			} );
		} );

		document.getElementById( 'forwp-drive-source-back' )?.addEventListener( 'click', showSourceOverview );

		document
			.getElementById( 'forwp-drive-root-folder' )
			?.addEventListener( 'input', ( event ) => {
				const input = event.target;
				if ( input instanceof HTMLInputElement ) {
					updateRootFolderOpenLink( input.value.trim() );
				}
			} );

		const grid = document.getElementById( 'forwp-drive-source-registry-grid' );
		if ( grid ) {
			grid.addEventListener( 'click', ( event ) => {
				const card = event.target.closest( '[data-source-slug]' );
				if ( card ) {
					openSourceDetail( card.getAttribute( 'data-source-slug' ) );
				}
			} );
			grid.addEventListener( 'keydown', ( event ) => {
				const card = event.target.closest( '[data-source-slug]' );
				if ( card && ( event.key === 'Enter' || event.key === ' ' ) ) {
					event.preventDefault();
					openSourceDetail( card.getAttribute( 'data-source-slug' ) );
				}
			} );
		}
	}

	function fieldMapValue( field ) {
		if ( field.type === 'core' ) {
			return 'core:' + ( field.field || field.key );
		}
		if ( field.type === 'meta' ) {
			return 'meta:' + ( field.key || '' );
		}
		const multi = field.multi ? ':multi' : '';
		return 'taxonomy:' + ( field.taxonomy || field.key ) + multi;
	}

	function lookupMetaKey( slug ) {
		const list = settingsCache?.meta_fields || [];
		const found = list.find( ( item ) => item.slug === slug );
		return found ? found.meta_key : '';
	}

	function parseFieldMapValue( value ) {
		if ( value.startsWith( 'core:' ) ) {
			const core = value.replace( 'core:', '' );
			return { type: 'core', field: core, key: core };
		}
		if ( value.startsWith( 'meta:' ) ) {
			const slug = value.replace( 'meta:', '' );
			return {
				type: 'meta',
				key: slug,
				meta_key: lookupMetaKey( slug ),
			};
		}
		const multi = value.endsWith( ':multi' );
		const taxonomy = value
			.replace( 'taxonomy:', '' )
			.replace( ':multi', '' );
		return {
			type: 'taxonomy',
			taxonomy,
			key: taxonomy,
			multi,
		};
	}

	function buildMapOptions( taxonomies, metaFields ) {
		const options = [
			{ value: 'core:title', label: 'Post title' },
			{ value: 'core:slug', label: 'Post slug' },
			{ value: 'core:date', label: 'Publication date' },
			{ value: 'core:author', label: 'Post author (display name or nickname)' },
		];
		( taxonomies || [] ).forEach( ( tax ) => {
			options.push( {
				value: 'taxonomy:' + tax.slug,
				label: tax.label + ' (' + tax.slug + ')',
			} );
			if ( tax.slug === 'post_tag' || ! tax.hierarchical ) {
				options.push( {
					value: 'taxonomy:' + tax.slug + ':multi',
					label: tax.label + ' — multiple (comma-separated)',
				} );
			}
		} );
		( metaFields || [] ).forEach( ( meta ) => {
			options.push( {
				value: 'meta:' + meta.slug,
				label: meta.label,
			} );
		} );
		return options;
	}

	function renderTemplateRows() {
		const tbody = document.getElementById( 'forwp-drive-template-rows' );
		const sample = document.getElementById( 'forwp-drive-sample-template' );
		if ( ! tbody || ! settingsCache ) {
			return;
		}

		const mapOptions = buildMapOptions(
			settingsCache.taxonomies || [],
			settingsCache.meta_fields || []
		);

		tbody.innerHTML = templateRows
			.map( ( row, index ) => {
				const mapValue = fieldMapValue( row );
				const options = mapOptions
					.map(
						( opt ) =>
							`<option value="${ escapeHtml( opt.value ) }"${
								opt.value === mapValue ? ' selected' : ''
							}>${ escapeHtml( opt.label ) }</option>`
					)
					.join( '' );
				const isTitle =
					row.type === 'core' && ( row.field === 'title' || row.key === 'title' );
				return `<tr data-index="${ index }">
					<td><input type="text" class="forwp-drive-field-label" value="${ escapeHtml(
						row.label || ''
					) }" ${ isTitle ? 'readonly' : '' } /></td>
					<td><select class="forwp-drive-field-map" ${
						isTitle ? 'disabled' : ''
					}>${ options }</select></td>
					<td>${
						isTitle
							? ''
							: '<button type="button" class="button-link forwp-drive-row-remove" data-remove="' +
							  index +
							  '">Remove</button>'
					}</td>
				</tr>`;
			} )
			.join( '' );

		if ( sample ) {
			sample.textContent = buildSampleFromRows();
		}
	}

	function buildSampleFromRows() {
		const lines = [];
		templateRows.forEach( ( row ) => {
			const label = ( row.label || '' ).trim();
			if ( ! label ) {
				return;
			}
			if ( row.type === 'core' && row.field === 'title' ) {
				lines.push( label + ': My Article' );
			} else if ( row.type === 'core' && row.field === 'slug' ) {
				lines.push( label + ': my-article' );
			} else if ( row.type === 'core' && row.field === 'date' ) {
				lines.push( label + ': 2026-05-26' );
			} else if ( row.type === 'core' && row.field === 'author' ) {
				lines.push( label + ': Jane Editor' );
			} else if ( row.type === 'taxonomy' ) {
				lines.push(
					label + ': ' + ( row.multi ? 'one, two' : 'Example' )
				);
			} else if ( row.type === 'meta' ) {
				lines.push( label + ': Example SEO value' );
			}
		} );
		lines.push( '', '======', '', 'Article body starts here…' );
		return lines.join( '\n' );
	}

	function buildBlockTemplateOptions( selected ) {
		const templates = settingsCache?.block_mapping?.templates || [];
		return templates
			.map( ( template ) => {
				const disabled = ! template.available;
				const suffix = disabled ? ' (coming soon)' : ! template.ready && template.status ? ' (needs setup)' : '';
				return `<option value="${ escapeHtml( template.id ) }"${
					template.id === selected ? ' selected' : ''
				}${ disabled ? ' disabled' : '' }>${ escapeHtml( ( template.label || template.id ) + suffix ) }</option>`;
			} )
			.join( '' );
	}

	function renderBlockMappingRows() {
		if ( ! settingsCache ) {
			return;
		}

		const tbody = document.getElementById( 'forwp-drive-block-mapping-rows' );
		const emptyEl = document.getElementById( 'forwp-drive-patterns-empty' );
		if ( ! tbody ) {
			return;
		}

		if ( emptyEl ) {
			emptyEl.hidden = blockMappingRows.length > 0;
		}

		tbody.innerHTML = blockMappingRows
			.map( ( row, index ) => {
				const status = row.template_status
					? `<p class="description">${ escapeHtml( row.template_status ) }</p>`
					: '';
				const isImage = row.template === 'core-image';
				const headingsValue = isImage
					? ''
					: escapeHtml( row.section_headings || '' );
				const headingsPlaceholder = isImage
					? '— [image:filename] markers —'
					: 'FAQ, Frequently Asked Questions';
				return `<tr data-index="${ index }">
					<td class="forwp-drive-block-mapping-table__on">
						<label class="forwp-drive-block-rule-enabled-label">
							<input type="checkbox" class="forwp-drive-block-rule-enabled" ${
								row.enabled ? 'checked' : ''
							} />
							<span class="screen-reader-text">${ escapeHtml(
								'Enable pattern'
							) }</span>
						</label>
					</td>
					<td>
						<select class="forwp-drive-block-rule-template">${ buildBlockTemplateOptions(
							row.template || '4wp-faq'
						) }</select>
						${ status }
					</td>
					<td><input type="text" class="regular-text forwp-drive-block-rule-headings" value="${ headingsValue }" placeholder="${ escapeHtml(
						headingsPlaceholder
					) }"${ isImage ? ' disabled' : '' } /></td>
					<td class="forwp-drive-block-mapping-table__keep"><input type="checkbox" class="forwp-drive-block-rule-keep-heading" title="Keep H2 in content" ${
						row.keep_section_heading ? 'checked' : ''
					}${ isImage ? ' disabled' : '' } /></td>
					<td class="forwp-drive-block-mapping-table__actions"><button type="button" class="button-link-delete forwp-drive-block-rule-remove" data-block-remove="${ index }">Delete</button></td>
				</tr>`;
			} )
			.join( '' );

		tbody.querySelectorAll( '.forwp-drive-block-rule-template' ).forEach( ( select ) => {
			select.addEventListener( 'change', () => {
				blockMappingRows = collectBlockMappingFromDom().rules;
				renderBlockMappingRows();
			} );
		} );
	}

	function collectBlockMappingFromDom() {
		const tbody = document.getElementById( 'forwp-drive-block-mapping-rows' );
		if ( ! tbody ) {
			return { rules: blockMappingRows };
		}

		const rows = [];
		tbody.querySelectorAll( 'tr' ).forEach( ( tr, index ) => {
			const base = blockMappingRows[ index ] || {};
			const enabledEl = tr.querySelector( '.forwp-drive-block-rule-enabled' );
			const templateEl = tr.querySelector( '.forwp-drive-block-rule-template' );
			const headingsEl = tr.querySelector( '.forwp-drive-block-rule-headings' );
			const keepEl = tr.querySelector( '.forwp-drive-block-rule-keep-heading' );
			rows.push( {
				id: base.id || 'rule_' + Date.now() + '_' + index,
				post_id: base.post_id || 0,
				preset_slug: base.preset_slug || '',
				origin: base.origin || 'custom',
				label: base.label || '',
				enabled: !! ( enabledEl && enabledEl.checked ),
				template: templateEl ? templateEl.value : base.template || '4wp-faq',
				section_headings: headingsEl ? headingsEl.value.trim() : base.section_headings || '',
				keep_section_heading: !! ( keepEl && keepEl.checked ),
			} );
		} );

		return { rules: rows };
	}

	function applyPatternsPayload( data ) {
		if ( ! data ) {
			return;
		}
		patternsCustomized = true;
		blockMappingRows = ( data.rules || [] ).map( ( rule ) => ( { ...rule } ) );
		if ( settingsCache ) {
			settingsCache.block_mapping = data;
		} else {
			settingsCache = { block_mapping: data };
		}
		renderBlockMappingRows();
	}

	function loadPatternsPage() {
		const status = document.getElementById( 'forwp-drive-patterns-status' );
		api( 'patterns' ).then( ( { ok, data } ) => {
			if ( ! ok ) {
				setStatus(
					status,
					( data && data.message ) || 'Could not load patterns.',
					true
				);
				return;
			}
			applyPatternsPayload( data );
		} );
	}

	function collectTemplateRowsFromDom() {
		const tbody = document.getElementById( 'forwp-drive-template-rows' );
		if ( ! tbody ) {
			return templateRows;
		}
		const rows = [];
		tbody.querySelectorAll( 'tr' ).forEach( ( tr, index ) => {
			const labelInput = tr.querySelector( '.forwp-drive-field-label' );
			const mapSelect = tr.querySelector( '.forwp-drive-field-map' );
			const base = templateRows[ index ] || {};
			const label = labelInput ? labelInput.value.trim() : base.label || '';
			const mapVal = mapSelect ? mapSelect.value : fieldMapValue( base );
			const parsed = parseFieldMapValue( mapVal );
			rows.push( {
				label: label || base.label,
				key: parsed.key,
				type: parsed.type,
				field: parsed.field,
				taxonomy: parsed.taxonomy,
				multi: parsed.multi,
				required: parsed.field === 'title',
			} );
		} );
		return rows;
	}

	function loadSettings() {
		const line = document.getElementById( 'forwp-drive-connection-line' );
		const connect = document.getElementById( 'forwp-drive-connect' );
		const disconnect = document.getElementById( 'forwp-drive-disconnect' );
		const rootInput = document.getElementById( 'forwp-drive-root-folder' );
		const clientId = document.getElementById( 'forwp-drive-client-id' );
		const clientSecret = document.getElementById( 'forwp-drive-client-secret' );
		const secretHint = document.getElementById( 'forwp-drive-secret-hint' );
		const locked = document.getElementById( 'forwp-drive-credentials-locked' );
		const saveCreds = document.getElementById( 'forwp-drive-save-credentials' );
		const clearCreds = document.getElementById( 'forwp-drive-clear-credentials' );
		const saveFolders = document.getElementById( 'forwp-drive-save-folders' );
		const runSync = document.getElementById( 'forwp-drive-run-sync' );
		const redirectCodes = document.querySelectorAll( '.forwp-drive-redirect-uri' );
		const localDevNotes = document.querySelectorAll( '.forwp-drive-local-dev-note' );
		const oauthRedirect = document.getElementById( 'forwp-drive-oauth-redirect' );
		const useSuggestedBtn = document.getElementById(
			'forwp-drive-use-suggested-redirect'
		);
		const wpconfigNote = document.getElementById( 'forwp-drive-wpconfig-redirect-note' );
		const suggestedHint = document.getElementById(
			'forwp-drive-oauth-redirect-suggested'
		);
		const postTypeSelect = document.getElementById( 'forwp-drive-import-post-type' );
		const sampleTemplate = document.getElementById( 'forwp-drive-sample-template' );

		api( 'settings' ).then( ( { ok, data } ) => {
			if ( ! ok ) {
				return;
			}

			settingsCache = data;
			templateRows = ( data.template_fields || [] ).map( ( f ) => ( { ...f } ) );
			patternsCustomized = !! data.block_mapping?.customized;
			blockMappingRows = ( data.block_mapping?.rules || [] ).map( ( rule ) => ( { ...rule } ) );
			if ( data.import_post_type ) {
				forwpDriveAdmin.importPostType = data.import_post_type;
			}
			if ( Array.isArray( data.post_types ) ) {
				forwpDriveAdmin.postTypes = data.post_types;
			}

			if ( data.redirect_uri ) {
				redirectCodes.forEach( ( el ) => {
					el.textContent = data.redirect_uri;
				} );
			}
			if ( oauthRedirect ) {
				oauthRedirect.value =
					data.oauth_redirect_uri || data.oauth_redirect_uri_suggested || '';
			}
			localDevNotes.forEach( ( el ) => {
				el.hidden = ! data.local_dev_redirect_help;
			} );
			if ( suggestedHint && data.oauth_redirect_uri_suggested ) {
				suggestedHint.hidden = ! data.local_dev_redirect_help;
				suggestedHint.textContent =
					'Suggested for ' +
					( data.site_host || 'this host' ) +
					': ' +
					data.oauth_redirect_uri_suggested;
			}
			updateDevRedirectPanel( data );
			if ( postTypeSelect && data.post_types ) {
				postTypeSelect.innerHTML = data.post_types
					.map(
						( pt ) =>
							`<option value="${ escapeHtml( pt.slug ) }"${
								pt.slug === data.import_post_type ? ' selected' : ''
							}>${ escapeHtml( pt.label ) } (${ escapeHtml( pt.slug ) })</option>`
					)
					.join( '' );
			}
			renderBlockMappingRows();
			if ( sampleTemplate && data.sample_template ) {
				sampleTemplate.textContent = data.sample_template;
			}
			renderTemplateRows();

			if ( locked ) {
				locked.hidden = ! data.credentials_locked;
			}
			if ( clientId ) {
				clientId.value = data.google_client_id || '';
				clientId.disabled = !! data.credentials_locked;
			}
			if ( clientSecret ) {
				clientSecret.value = '';
				clientSecret.disabled = !! data.credentials_locked;
				clientSecret.placeholder = data.has_client_secret
					? '••••••••  (leave blank to keep current)'
					: '';
			}
			if ( secretHint ) {
				secretHint.textContent = data.has_client_secret
					? 'A client secret is saved. Enter a new value only to replace it.'
					: 'Required on first save.';
			}
			if ( saveCreds ) {
				saveCreds.disabled = !! data.credentials_locked;
			}
			if ( clearCreds ) {
				clearCreds.hidden =
					!! data.credentials_locked || ! data.has_client_config;
				clearCreds.disabled = !! data.credentials_locked;
			}

			if ( line ) {
				line.classList.remove( 'forwp-drive-connection-line--error' );
				const connection = data.drive_connection;
				if ( connection && connection.needs_reconnect ) {
					line.textContent = connection.message || '';
					line.classList.add( 'forwp-drive-connection-line--error' );
				} else if ( data.connected ) {
					line.textContent = 'Google account connected. You can import documents from Drive.';
				} else if ( ! data.has_client_config ) {
					line.textContent =
						'Save API credentials on the left and click Save credentials before connecting.';
				} else if ( data.auth_url_error ) {
					line.textContent = data.auth_url_error;
				} else {
					line.textContent = 'Credentials saved. Click Connect to authorize access.';
				}
			}
			if ( connect ) {
				const connection = data.drive_connection;
				const needsReconnect = !! ( connection && connection.needs_reconnect );
				const authUrl = ( connection && connection.auth_url ) || data.auth_url;
				const canConnect =
					data.has_client_config && authUrl && ( ! data.connected || needsReconnect );

				if ( data.connected && ! needsReconnect ) {
					connect.hidden = true;
				} else {
					connect.hidden = false;
					connect.href = canConnect ? authUrl : '#';
					connect.classList.toggle( 'disabled', ! canConnect );
					connect.setAttribute(
						'aria-disabled',
						canConnect ? 'false' : 'true'
					);
					connect.textContent = needsReconnect
						? forwpDriveAdmin.strings.reconnectDrive || 'Reconnect Google Drive'
						: 'Connect your Drive';
				}
			}
			if ( disconnect ) {
				disconnect.hidden = ! data.connected;
			}

			const foldersReady =
				data.connected &&
				data.folder_ids &&
				data.folder_ids.incoming &&
				! ( data.drive_connection && data.drive_connection.needs_reconnect );
			if ( saveFolders ) {
				saveFolders.disabled = ! data.connected;
			}
			if ( runSync ) {
				runSync.disabled = ! foldersReady;
			}
			if ( rootInput && data.folder_ids && data.folder_ids.root ) {
				rootInput.value = data.folder_ids.root;
			}
			updateRootFolderOpenLink(
				rootInput ? rootInput.value.trim() : data.folder_ids?.root || ''
			);
			renderFolderIds( data.folder_ids );
			updateConnectionPreviewHint( data );
			updateGoogleSetupHint( data );
			renderSourceRegistry( data.sources || [] );
			renderLanguageProviderRegistry( data.language_providers || [] );
			if ( selectedSourceSlug === 'github' && data.github ) {
				fillGitHubSettings( data.github );
			}
		} );
	}

	document.addEventListener( 'change', ( event ) => {
		const target = event.target;
		if (
			target instanceof HTMLInputElement &&
			target.name === 'forwp-drive-import-mode'
		) {
			setImportModeUi();
			if ( previewDoc ) {
				loadImportTargets( previewDoc );
			}
			syncImportButtonState();
		}
		if ( target instanceof HTMLSelectElement && target.id === 'forwp-drive-import-language' ) {
			if ( previewDoc ) {
				loadImportTargets( previewDoc );
			}
			syncImportButtonState();
		}
		if ( target instanceof HTMLSelectElement && target.id === 'forwp-drive-inbox-import-post-type' ) {
			if ( previewDoc ) {
				loadImportTargets( previewDoc );
			}
			syncImportButtonState();
		}
		if ( target instanceof HTMLSelectElement && target.id === 'forwp-drive-import-target' ) {
			syncImportButtonState();
		}
	} );

	document.addEventListener( 'keydown', ( event ) => {
		const target = event.target;
		if ( ! ( target instanceof HTMLElement ) ) {
			return;
		}
		if ( event.key !== 'Enter' && event.key !== ' ' ) {
			return;
		}
		if ( target.matches( '.forwp-drive-tree__row[data-action="select"]' ) ||
			target.matches( '.forwp-drive-tree__row[data-action="tree-folder"]' ) ||
			target.matches( '.forwp-drive-card[data-action="select"]' ) ) {
			event.preventDefault();
			if ( target.getAttribute( 'data-action' ) === 'tree-folder' ) {
				toggleTreeFolder( target.getAttribute( 'data-path' ), target.getAttribute( 'data-id' ) );
				return;
			}
			const id = target.getAttribute( 'data-id' );
			if ( id ) {
				openPreview( id );
			}
		}
	} );

	document.addEventListener( 'click', ( event ) => {
		const target = event.target;
		if ( ! ( target instanceof HTMLElement ) ) {
			return;
		}

		if ( target.closest( '#forwp-drive-image-pin' ) ) {
			return;
		}

		const connectBtn = target.closest( '#forwp-drive-connect' );
		if ( connectBtn && connectBtn.getAttribute( 'aria-disabled' ) === 'true' ) {
			event.preventDefault();
			return;
		}

		const actionEl = target.closest( '[data-action]' );
		const action = actionEl ? actionEl.getAttribute( 'data-action' ) : null;
		const id = actionEl ? actionEl.getAttribute( 'data-id' ) : null;
		if ( action === 'open-external' ) {
			event.stopPropagation();
			return;
		}
		if ( action === 'source-tab' && actionEl ) {
			const slug = actionEl.getAttribute( 'data-source' );
			if ( slug ) {
				setActiveInboxSource( slug );
			}
			return;
		}
		if ( action === 'tree-folder' && actionEl ) {
			toggleTreeFolder(
				actionEl.getAttribute( 'data-path' ),
				actionEl.getAttribute( 'data-id' )
			);
			return;
		}
		if ( action && id ) {
			if ( action === 'preview' || action === 'select' ) {
				const fileId = actionEl
					? actionEl.getAttribute( 'data-file-id' ) || ''
					: '';
				openPreview( id, fileId ? { fileId } : {} );
				return;
			}
			if ( action === 'reject' ) {
				event.preventDefault();
				event.stopPropagation();
				rejectDoc( id );
				return;
			}
		}

		if ( target.id === 'forwp-drive-inbox-sync' ) {
			if ( ! isSourceImplemented( activeSourceSlug ) ) {
				return;
			}
			runInboxSync();
		}
		if ( target.id === 'forwp-drive-preview-import' && previewId ) {
			importDoc( previewId );
		}
		if ( target.id === 'forwp-drive-preview-reject' && previewId ) {
			rejectDoc( previewId );
		}
		if ( target.id === 'forwp-drive-preview-close' ) {
			showWorkspacePlaceholder();
		}
		const disconnectBtn = target.closest( '#forwp-drive-disconnect' );
		if ( disconnectBtn ) {
			const strings = forwpDriveAdmin.strings || {};
			confirmDialog( {
				title: strings.dialogDisconnectTitle || 'Disconnect Drive',
				message:
					strings.disconnectConfirm ||
					'Disconnect Google Drive from this site?',
				confirmLabel: strings.dialogConfirm || 'Confirm',
				danger: true,
			} ).then( ( confirmed ) => {
				if ( ! confirmed ) {
					return;
				}
				const status = document.getElementById(
					'forwp-drive-settings-status'
				);
				setStatus(
					status,
					strings.disconnectRunning || 'Disconnecting…'
				);
				api( 'oauth/disconnect', { method: 'POST' } ).then(
					( { ok, data } ) => {
						setStatus(
							status,
							ok
								? data.message || 'Disconnected.'
								: data.message || 'Could not disconnect.',
							! ok
						);
						if ( ok ) {
							loadSettings();
						}
					}
				);
			} );
			return;
		}
		if ( target.id === 'forwp-drive-save-github' ) {
			const status = document.getElementById( 'forwp-drive-settings-status' );
			const owner = document.getElementById( 'forwp-drive-github-owner' );
			const repo = document.getElementById( 'forwp-drive-github-repo' );
			const branch = document.getElementById( 'forwp-drive-github-branch' );
			const incoming = document.getElementById( 'forwp-drive-github-incoming' );
			const token = document.getElementById( 'forwp-drive-github-token' );
			api( 'settings', {
				method: 'POST',
				body: JSON.stringify( {
					github: {
						owner: owner ? owner.value.trim() : '',
						repo: repo ? repo.value.trim() : '',
						branch: branch ? branch.value.trim() : 'main',
						incoming: incoming ? incoming.value.trim() : '',
						token: token ? token.value : '',
					},
				} ),
			} ).then( ( { ok, data } ) => {
				setStatus(
					status,
					ok ? data.message || 'Saved.' : data.message || 'Error.',
					! ok
				);
				loadSettings();
			} );
			return;
		}
		if ( target.id === 'forwp-drive-save-credentials' ) {
			const status = document.getElementById( 'forwp-drive-settings-status' );
			const idEl = document.getElementById( 'forwp-drive-client-id' );
			const secretEl = document.getElementById( 'forwp-drive-client-secret' );
			api( 'settings', {
				method: 'POST',
				body: JSON.stringify( {
					google_client_id: idEl ? idEl.value.trim() : '',
					google_client_secret: secretEl ? secretEl.value : '',
				} ),
			} ).then( ( { ok, data } ) => {
				setStatus(
					status,
					ok ? data.message || 'Saved.' : data.message || 'Error.',
					! ok
				);
				if ( ok && secretEl ) {
					secretEl.value = '';
				}
				loadSettings();
			} );
		}
		if ( target.id === 'forwp-drive-clear-credentials' ) {
			const strings = forwpDriveAdmin.strings || {};
			confirmDialog( {
				title: strings.dialogClearCredsTitle || 'Clear credentials',
				message:
					strings.clearCredentialsConfirm ||
					'Clear saved Client ID and Client Secret? This also disconnects your Drive account.',
				confirmLabel: strings.dialogConfirm || 'Confirm',
				danger: true,
			} ).then( ( confirmed ) => {
				if ( ! confirmed ) {
					return;
				}
				const status = document.getElementById(
					'forwp-drive-settings-status'
				);
				const clearBtn = document.getElementById(
					'forwp-drive-clear-credentials'
				);
				setStatus(
					status,
					strings.clearCredentialsRunning || 'Clearing…'
				);
				if ( clearBtn ) {
					clearBtn.disabled = true;
				}
				api( 'settings', {
					method: 'POST',
					body: JSON.stringify( { clear_credentials: true } ),
				} ).then( ( { ok, data } ) => {
					setStatus(
						status,
						ok ? data.message || 'Cleared.' : data.message || 'Error.',
						! ok
					);
					if ( ok ) {
						loadSettings();
					}
					if ( clearBtn ) {
						clearBtn.disabled = false;
					}
				} );
			} );
			return;
		}
		const copyRedirectBtn = target.closest( '.forwp-drive-copy-redirect' );
		if ( copyRedirectBtn ) {
			event.preventDefault();
			const row = copyRedirectBtn.closest( 'li' );
			const code = row ? row.querySelector( '.forwp-drive-redirect-uri' ) : null;
			const status = document.getElementById( 'forwp-drive-settings-status' );
			const text = code ? ( code.textContent || '' ).trim() : '';
			copyTextToClipboard( text ).then( ( ok ) => {
				setStatus(
					status,
					ok ? 'Redirect URI copied.' : 'Could not copy. Select the URI and copy manually.',
					! ok
				);
			} );
		}
		if ( target.id === 'forwp-drive-save-folders' ) {
			const root = document.getElementById( 'forwp-drive-root-folder' );
			const status = driveActionsStatus();
			setStatus( status, 'Saving folders…', false, true );
			api( 'settings', {
				method: 'POST',
				body: JSON.stringify( { root_folder_id: root ? root.value.trim() : '' } ),
			} ).then( ( { ok, data } ) => {
				setStatus(
					status,
					ok ? data.message || 'Saved.' : data.message || 'Error.',
					! ok
				);
				if ( ok ) {
					loadSettings();
				}
			} );
		}
		if ( target.id === 'forwp-drive-run-sync' ) {
			const status = driveActionsStatus();
			setStatus( status, forwpDriveAdmin.strings.syncRunning, false, true );
			api( 'sync/run', { method: 'POST' } ).then( ( { ok, data } ) => {
				setStatus(
					status,
					ok
						? ( () => {
								let msg = `Synced ${ data.scanned || 0 } file(s); ${ data.new_ready || 0 } new ready.`;
								if ( data.export_errors ) {
									msg += ` ${ data.export_errors } export error(s).`;
								}
								return msg;
						  } )()
						: data.message || 'Sync failed.',
					! ok
				);
			} );
		}
		if ( target.id === 'forwp-drive-use-suggested-redirect' ) {
			const input = document.getElementById( 'forwp-drive-oauth-redirect' );
			const uri = target.dataset.uri || '';
			if ( input && uri ) {
				input.value = uri;
			}
		}
		if ( target.id === 'forwp-drive-save-oauth-redirect' ) {
			const status = document.getElementById( 'forwp-drive-settings-status' );
			const input = document.getElementById( 'forwp-drive-oauth-redirect' );
			api( 'settings', {
				method: 'POST',
				body: JSON.stringify( {
					oauth_redirect_uri: input ? input.value.trim() : '',
				} ),
			} ).then( ( { ok, data } ) => {
				setStatus(
					status,
					ok ? data.message || 'Saved.' : data.message || 'Error.',
					! ok
				);
				loadSettings();
			} );
		}
		if ( target.id === 'forwp-drive-save-patterns' ) {
			const status =
				document.getElementById( 'forwp-drive-patterns-status' ) ||
				document.getElementById( 'forwp-drive-settings-status' );
			const payload = collectBlockMappingFromDom();
			api( 'patterns', {
				method: 'POST',
				body: JSON.stringify( payload ),
			} ).then( ( { ok, data } ) => {
				setStatus(
					status,
					ok ? data.message || 'Patterns saved.' : data.message || 'Error.',
					! ok
				);
				if ( ok ) {
					applyPatternsPayload( data );
				}
			} );
		}
		if ( target.id === 'forwp-drive-save-import-template' ) {
			const status = document.getElementById( 'forwp-drive-settings-status' );
			const postType = document.getElementById( 'forwp-drive-import-post-type' );
			api( 'settings', {
				method: 'POST',
				body: JSON.stringify( {
					import_post_type: postType ? postType.value : 'post',
					template_fields: collectTemplateRowsFromDom(),
				} ),
			} ).then( ( { ok, data } ) => {
				setStatus(
					status,
					ok ? data.message || 'Saved.' : data.message || 'Error.',
					! ok
				);
				if ( ok ) {
					loadSettings();
				}
			} );
		}
		if ( target.id === 'forwp-drive-block-mapping-add-row' ) {
			blockMappingRows = collectBlockMappingFromDom().rules;
			blockMappingRows.push( {
				id: 'rule_' + Date.now(),
				enabled: true,
				template: '4wp-faq',
				section_headings: 'FAQ, Frequently Asked Questions',
				keep_section_heading: false,
				origin: 'custom',
				label: '',
			} );
			renderBlockMappingRows();
		}
		if ( target.hasAttribute( 'data-block-remove' ) ) {
			const index = parseInt( target.getAttribute( 'data-block-remove' ), 10 );
			blockMappingRows = collectBlockMappingFromDom().rules;
			blockMappingRows.splice( index, 1 );
			renderBlockMappingRows();
		}
		if ( target.id === 'forwp-drive-template-add-row' ) {
			templateRows = collectTemplateRowsFromDom();
			templateRows.push( {
				label: 'Custom',
				key: 'custom_' + Date.now(),
				type: 'taxonomy',
				taxonomy: ( settingsCache.taxonomies || [] )[0]?.slug || 'category',
				multi: false,
			} );
			renderTemplateRows();
		}
		if ( target.hasAttribute( 'data-remove' ) ) {
			const index = parseInt( target.getAttribute( 'data-remove' ), 10 );
			templateRows = collectTemplateRowsFromDom();
			templateRows.splice( index, 1 );
			renderTemplateRows();
		}
	} );

	document.addEventListener( 'input', ( event ) => {
		const target = event.target;
		if (
			target instanceof HTMLElement &&
			( target.classList.contains( 'forwp-drive-field-label' ) ||
				target.classList.contains( 'forwp-drive-field-map' ) )
		) {
			templateRows = collectTemplateRowsFromDom();
			const sample = document.getElementById( 'forwp-drive-sample-template' );
			if ( sample ) {
				sample.textContent = buildSampleFromRows();
			}
		}
	} );

	document.addEventListener( 'change', ( event ) => {
		const target = event.target;
		if ( target && target.id === 'forwp-drive-import-post-type' ) {
			const status = document.getElementById( 'forwp-drive-settings-status' );
			api( 'settings', {
				method: 'POST',
				body: JSON.stringify( {
					import_post_type: target.value,
				} ),
			} ).then( ( { ok, data } ) => {
				if ( ok ) {
					forwpDriveAdmin.importPostType = target.value;
					loadSettings();
					setStatus(
						status,
						'Post type updated. Template fields reset to defaults for this type.'
					);
				}
			} );
		}
	} );

	if ( document.getElementById( 'forwp-drive-inbox-list' ) ) {
		renderInboxSourceTabs();
		applyActiveSourceChrome();
		loadInbox();
	}
	if ( document.getElementById( 'forwp-drive-source-registry-grid' ) ) {
		initSettingsChrome();
		loadSettings();
	} else if (
		document.getElementById( 'forwp-drive-block-mapping-rows' ) ||
		document.getElementById( 'forwp-drive-patterns-preset-list' )
	) {
		loadPatternsPage();
	}
} )();
