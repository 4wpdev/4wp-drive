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

	let previewId = null;
	let previewDoc = null;
	let multilingualConfig = forwpDriveAdmin.multilingual || null;
	let activeSourceSlug =
		forwpDriveAdmin.activeSource || 'google_drive';
	let inboxCache = {
		documents: [],
		lastSync: null,
		connection: null,
		incomingId: '',
	};

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
				const badge = soon
					? '<span class="forwp-drive-source-tab__badge">Soon</span>'
					: '';
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

		if ( openIncoming && ! implemented ) {
			openIncoming.hidden = true;
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
		activeSourceSlug = slug;
		applyActiveSourceChrome();
		showWorkspacePlaceholder();

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
			( inboxCache.documents || [] ).length,
			inboxCache.incomingId
		);
		renderInbox( inboxCache.documents, inboxCache.lastSync );
	}

	function getMultilingualConfig() {
		return multilingualConfig || forwpDriveAdmin.multilingual || null;
	}

	function requiresImportLanguage() {
		const config = getMultilingualConfig();
		return !!( config && config.requires_selection );
	}

	function getSelectedImportLanguage() {
		const select = document.getElementById( 'forwp-drive-import-language' );
		if ( ! select ) {
			return '';
		}
		return select.value || '';
	}

	function setupImportLanguageUi( configOverride ) {
		const wrap = document.getElementById( 'forwp-drive-import-language-wrap' );
		const select = document.getElementById( 'forwp-drive-import-language' );
		if ( ! wrap || ! select ) {
			return;
		}

		const config = configOverride || getMultilingualConfig();
		const strings = forwpDriveAdmin.strings || {};
		if ( ! config || ! config.requires_selection ) {
			wrap.hidden = true;
			select.innerHTML = '';
			return;
		}

		wrap.hidden = false;
		const placeholder =
			strings.selectLanguagePlaceholder || 'Select language…';
		select.innerHTML =
			`<option value="">${ escapeHtml( placeholder ) }</option>` +
			( config.languages || [] )
				.map(
					( language ) =>
						`<option value="${ escapeHtml( language.code ) }">${ escapeHtml(
							language.name
						) }</option>`
				)
				.join( '' );
		select.value = '';
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
			return;
		}

		select.innerHTML = targets
			.map( ( target ) => {
				const langLabel = target.language_name || target.language || '';
				const langPart = langLabel ? ` · ${ langLabel }` : '';
				const label = `${ target.title } (#${ target.id }) · ${ target.status } · ${ target.slug }${ langPart }`;
				return `<option value="${ target.id }">${ escapeHtml( label ) }</option>`;
			} )
			.join( '' );

		if ( data.suggested_id ) {
			select.value = String( data.suggested_id );
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
		const payload = { mode };
		const lang = getSelectedImportLanguage();

		if ( requiresImportLanguage() ) {
			if ( ! lang ) {
				window.alert(
					forwpDriveAdmin.strings.languageRequired ||
						'Select a content language for this import.'
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
				window.alert(
					forwpDriveAdmin.strings.updateTargetRequired ||
						'Select an existing post to update.'
				);
				return null;
			}
			payload.target_post_id = targetId;
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
		const sourceIcon = sourceIconMarkup( activeSourceSlug );
		const state = connection && connection.state ? connection.state : '';
		if ( state === 'ok' ) {
			setChip( 'connection', 'Connected', 'ok', sourceIcon );
		} else if ( state === 'not_configured' ) {
			setChip( 'connection', 'Not configured', 'warn', sourceIcon );
		} else if ( state === 'disconnected' ) {
			setChip( 'connection', 'Disconnected', 'warn', sourceIcon );
		} else if ( state === 'expired' || state === 'error' ) {
			setChip( 'connection', 'Connection problem', 'error', sourceIcon );
		} else {
			setChip( 'connection', 'Checking connection…', 'muted', sourceIcon );
		}

		if ( lastSync && lastSync.timestamp ) {
			setChip(
				'sync',
				'Last sync: ' + formatSyncTime( lastSync.timestamp ),
				'muted'
			);
		} else if ( lastSync && typeof lastSync.scanned === 'number' ) {
			setChip( 'sync', 'Last sync: done', 'muted' );
		} else {
			setChip( 'sync', 'Last sync: —', 'muted' );
		}

		const ready =
			typeof documentCount === 'number'
				? documentCount
				: lastSync && typeof lastSync.ready_total === 'number'
				? lastSync.ready_total
				: 0;
		setChip( 'ready', 'Ready: ' + ready, ready > 0 ? 'ok' : 'muted' );

		const errorsChip = document.querySelector(
			'#forwp-drive-inbox-chips [data-chip="errors"]'
		);
		const exportErrors =
			lastSync && typeof lastSync.export_errors === 'number'
				? lastSync.export_errors
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
			const url = driveFolderUrl( incomingId );
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
		document.querySelectorAll( '.forwp-drive-card.is-selected' ).forEach( ( card ) => {
			card.classList.remove( 'is-selected' );
			card.setAttribute( 'aria-selected', 'false' );
		} );
		if ( ! id ) {
			return;
		}
		const selected = document.querySelector(
			`.forwp-drive-card[data-id="${ id }"]`
		);
		if ( selected ) {
			selected.classList.add( 'is-selected' );
			selected.setAttribute( 'aria-selected', 'true' );
		}
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
		setSelectedQueueCard( null );
	}

	function renderInboxEmpty( lastSync ) {
		const list = document.getElementById( 'forwp-drive-inbox-list' );
		if ( ! list ) {
			return;
		}

		let syncHint = '';
		if ( lastSync && typeof lastSync.scanned === 'number' ) {
			if ( lastSync.scanned === 0 ) {
				syncHint =
					'<p>No Google Docs found in <code>incoming</code>. Use a native Google Doc (not a shortcut).</p>';
			} else if ( ( lastSync.ready_total ?? 0 ) === 0 ) {
				syncHint =
					'<p>Files were seen in Drive but none are ready. Sync again after editing, or check the file is a native Google Doc.</p>';
			}
		}

		list.innerHTML = `
			<div class="forwp-drive-empty-panel forwp-drive-admin-chrome">
				<p class="forwp-drive-empty-panel__lead"><strong>No documents ready for import.</strong></p>
				${ syncHint }
				<p class="forwp-drive-empty-panel__label">Checklist</p>
				<ul class="forwp-drive-empty-panel__list">
					<li>Each article: a subfolder inside <strong>incoming/</strong> with a <strong>Google Doc</strong> or <strong>.docx</strong> plus a featured <strong>image</strong>.</li>
					<li>Click <strong>Sync from Drive</strong> after adding or editing the file.</li>
					<li>Already imported? Check the <strong>published</strong> folder on Drive.</li>
				</ul>
			</div>`;
		showWorkspacePlaceholder();
	}

	function renderInbox( documents, lastSync ) {
		const list = document.getElementById( 'forwp-drive-inbox-list' );
		if ( ! list ) {
			return;
		}

		if ( ! documents || ! documents.length ) {
			renderInboxEmpty( lastSync );
			return;
		}

		const keepId = previewId;
		list.innerHTML = documents
			.map( ( doc ) => {
				const title = doc.title || doc.file_name || '';
				const tags = ( doc.tags || [] ).join( ', ' );
				const warning = doc.scan_error
					? `<p class="forwp-drive-card__warning">${ escapeHtml( doc.scan_error ) }</p>`
					: '';
				const metaParts = [];
				if ( doc.slug ) {
					metaParts.push( `<span>${ escapeHtml( doc.slug ) }</span>` );
				}
				if ( doc.file_name && doc.file_name !== title ) {
					metaParts.push( escapeHtml( doc.file_name ) );
				}
				if ( doc.category ) {
					metaParts.push( escapeHtml( doc.category ) );
				}
				if ( tags ) {
					metaParts.push( escapeHtml( tags ) );
				}
				if ( doc.has_image ) {
					metaParts.push( 'Featured image' );
				}
				const meta = metaParts.length
					? `<div class="forwp-drive-card__meta">${ metaParts.join( ' · ' ) }</div>`
					: '';
				const previewLabel = escapeHtml(
					forwpDriveAdmin.strings.previewAndImport || 'Preview & import'
				);
				return `
				<article
					class="forwp-drive-card forwp-drive-admin-chrome"
					data-id="${ doc.id }"
					data-action="select"
					tabindex="0"
					role="button"
					aria-selected="false"
				>
					<h3>${ escapeHtml( title ) }</h3>
					${ meta }
					${ warning }
					<div class="forwp-drive-card__actions">
						<button type="button" class="button button-primary" data-action="preview" data-id="${ doc.id }">${ previewLabel }</button>
						<button type="button" class="button" data-action="reject" data-id="${ doc.id }">Reject</button>
					</div>
				</article>`;
			} )
			.join( '' );

		const stillThere =
			keepId &&
			documents.some( ( doc ) => String( doc.id ) === String( keepId ) );
		if ( stillThere ) {
			setSelectedQueueCard( keepId );
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
		div.textContent = text;
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
				lastSync: data.last_sync,
				connection: data.drive_connection,
				incomingId: data.incoming_id || '',
			};
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
		api( 'documents/' + id ).then( ( { ok, data } ) => {
			if ( ! ok ) {
				meta.innerHTML =
					'<p class="forwp-drive-preview__meta">Could not load preview.</p>';
				return;
			}
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
			setupImportLanguageUi( data.multilingual );
			if ( requiresImportLanguage() ) {
				resetImportTargetSelect();
			}
			loadImportTargets( data );
		} );
	}

	function importDoc( id, payloadOverride ) {
		const payload = payloadOverride || getImportPayload();
		if ( payload === null ) {
			return;
		}
		const confirmText =
			payload.mode === 'update'
				? forwpDriveAdmin.strings.updateConfirm
				: forwpDriveAdmin.strings.importConfirm;
		if ( ! window.confirm( confirmText ) ) {
			return;
		}
		const status = document.getElementById( 'forwp-drive-inbox-status' );
		setStatus( status, forwpDriveAdmin.strings.importRunning );
		api( 'documents/' + id + '/import', {
			method: 'POST',
			body: JSON.stringify( payload ),
		} ).then( ( { ok, data } ) => {
			if ( ! ok ) {
				setStatus( status, data.message || 'Import failed.', true );
				return;
			}
			if ( data.edit_url ) {
				window.location.href = data.edit_url;
				return;
			}
			loadInbox();
		} );
	}

	function rejectDoc( id ) {
		if ( ! window.confirm( forwpDriveAdmin.strings.rejectConfirm ) ) {
			return;
		}
		api( 'documents/' + id + '/reject', { method: 'POST' } ).then( () => {
			showWorkspacePlaceholder();
			loadInbox();
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
				const live = row.implemented;
				const badge = live
					? '<span class="forwp-drive-badge forwp-drive-badge--live">Live</span>'
					: '<span class="forwp-drive-badge forwp-drive-badge--planned">Planned</span>';
				return `
				<div class="forwp-drive-provider-card-wrap${
					selectedSourceSlug === row.slug ? ' is-selected' : ''
				}" role="button" tabindex="0" data-source-slug="${ escapeHtml( row.slug ) }" aria-pressed="${
					selectedSourceSlug === row.slug ? 'true' : 'false'
				}">
					<div class="forwp-drive-provider-card-head">
						<div>
							<div class="forwp-drive-provider-label">${ escapeHtml( row.label ) }</div>
							<div class="forwp-drive-provider-slug"><code>${ escapeHtml( row.slug ) }</code></div>
						</div>
						${ badge }
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
		if ( googleSplit ) {
			googleSplit.hidden = ! isGoogle;
		}
		if ( planned ) {
			planned.hidden = isGoogle;
		}
		if ( plannedText && ! isGoogle ) {
			plannedText.textContent = row.status;
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
		const tbody = document.getElementById( 'forwp-drive-block-mapping-rows' );
		if ( ! tbody || ! settingsCache ) {
			return;
		}

		tbody.innerHTML = blockMappingRows
			.map( ( row, index ) => {
				const status = row.template_status
					? `<p class="description">${ escapeHtml( row.template_status ) }</p>`
					: '';
				return `<tr data-index="${ index }">
					<td><input type="checkbox" class="forwp-drive-block-rule-enabled" ${
						row.enabled ? 'checked' : ''
					} /></td>
					<td>
						<select class="forwp-drive-block-rule-template">${ buildBlockTemplateOptions(
							row.template || '4wp-faq'
						) }</select>
						${ status }
					</td>
					<td><input type="text" class="regular-text forwp-drive-block-rule-headings" value="${ escapeHtml(
						row.section_headings || ''
					) }" /></td>
					<td><input type="checkbox" class="forwp-drive-block-rule-keep-heading" ${
						row.keep_section_heading ? 'checked' : ''
					} /></td>
					<td><button type="button" class="button-link forwp-drive-block-rule-remove" data-block-remove="${ index }">Remove</button></td>
				</tr>`;
			} )
			.join( '' );
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
				enabled: !! ( enabledEl && enabledEl.checked ),
				template: templateEl ? templateEl.value : base.template || '4wp-faq',
				section_headings: headingsEl ? headingsEl.value.trim() : '',
				keep_section_heading: !! ( keepEl && keepEl.checked ),
			} );
		} );

		return { rules: rows };
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
			blockMappingRows = ( data.block_mapping?.rules || [] ).map( ( rule ) => ( { ...rule } ) );

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
		}
		if ( target instanceof HTMLSelectElement && target.id === 'forwp-drive-import-language' ) {
			if ( previewDoc ) {
				loadImportTargets( previewDoc );
			}
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
		if ( target.matches( '.forwp-drive-card[data-action="select"]' ) ) {
			event.preventDefault();
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

		const connectBtn = target.closest( '#forwp-drive-connect' );
		if ( connectBtn && connectBtn.getAttribute( 'aria-disabled' ) === 'true' ) {
			event.preventDefault();
			return;
		}

		const actionEl = target.closest( '[data-action]' );
		const action = actionEl ? actionEl.getAttribute( 'data-action' ) : null;
		const id = actionEl ? actionEl.getAttribute( 'data-id' ) : null;
		if ( action === 'source-tab' && actionEl ) {
			const slug = actionEl.getAttribute( 'data-source' );
			if ( slug ) {
				setActiveInboxSource( slug );
			}
			return;
		}
		if ( action && id ) {
			if ( action === 'preview' || action === 'select' ) {
				openPreview( id );
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
			if (
				! window.confirm(
					forwpDriveAdmin.strings.disconnectConfirm ||
						'Disconnect Google Drive from this site?'
				)
			) {
				return;
			}
			const status = document.getElementById( 'forwp-drive-settings-status' );
			setStatus(
				status,
				forwpDriveAdmin.strings.disconnectRunning || 'Disconnecting…'
			);
			api( 'oauth/disconnect', { method: 'POST' } ).then( ( { ok, data } ) => {
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
			} );
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
			if (
				! window.confirm(
					forwpDriveAdmin.strings.clearCredentialsConfirm ||
						'Clear saved Client ID and Client Secret? This also disconnects your Drive account.'
				)
			) {
				return;
			}
			const status = document.getElementById( 'forwp-drive-settings-status' );
			const clearBtn = document.getElementById( 'forwp-drive-clear-credentials' );
			setStatus(
				status,
				forwpDriveAdmin.strings.clearCredentialsRunning || 'Clearing…'
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
				} else if ( clearBtn ) {
					clearBtn.disabled = false;
				}
			} );
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
		if ( target.id === 'forwp-drive-save-import-template' ) {
			const status = document.getElementById( 'forwp-drive-settings-status' );
			const postType = document.getElementById( 'forwp-drive-import-post-type' );
			api( 'settings', {
				method: 'POST',
				body: JSON.stringify( {
					import_post_type: postType ? postType.value : 'post',
					block_mapping: collectBlockMappingFromDom(),
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
				keep_section_heading: true,
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
	}
} )();
