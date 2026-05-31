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

	function driveActionsStatus() {
		return document.getElementById( 'forwp-drive-drive-actions-status' );
	}

	function renderInboxEmpty( lastSync ) {
		const list = document.getElementById( 'forwp-drive-inbox-list' );
		if ( ! list ) {
			return;
		}

		let syncHint = '';
		if ( lastSync && typeof lastSync.scanned === 'number' ) {
			syncHint = `<p><strong>Last sync:</strong> ${ lastSync.scanned } file(s) found in incoming`;
			if ( lastSync.export_errors ) {
				syncHint += `, ${ lastSync.export_errors } export error(s)`;
			}
			syncHint += `, ${ lastSync.ready_total ?? 0 } ready in inbox.</p>`;
			if ( lastSync.scanned === 0 ) {
				syncHint +=
					'<p>No Google Docs found in the <code>incoming</code> folder. Use a native Google Doc (not a shortcut) inside the incoming subfolder.</p>';
			} else if ( ( lastSync.ready_total ?? 0 ) === 0 ) {
				syncHint +=
					'<p>Files were seen in Drive but none are ready in the inbox. Run sync again after editing the doc, or check that the file is a native Google Doc (not a shortcut).</p>';
			}
		}

		list.innerHTML = `
			<div class="forwp-drive-empty-panel forwp-drive-admin-chrome">
				<p class="forwp-drive-empty-panel__lead"><strong>No documents ready for import.</strong></p>
				${ syncHint }
				<p class="forwp-drive-empty-panel__label">Checklist</p>
				<ul class="forwp-drive-empty-panel__list">
					<li>Each article: a subfolder inside <strong>incoming/</strong> with a <strong>Google Doc</strong> or <strong>.docx</strong> plus a featured <strong>image</strong>.</li>
					<li>Click <strong>Run sync now</strong> above after adding or editing the file.</li>
					<li>If you already imported it, look in the <strong>published</strong> folder on Drive.</li>
				</ul>
			</div>`;
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

		list.innerHTML = documents
			.map( ( doc ) => {
				const tags = ( doc.tags || [] ).join( ', ' );
				const warning = doc.scan_error
					? `<p class="forwp-drive-card__warning">${ escapeHtml( doc.scan_error ) }</p>`
					: '';
				return `
				<article class="forwp-drive-card forwp-drive-admin-chrome" data-id="${ doc.id }">
					<h3>${ escapeHtml( doc.title || doc.file_name ) }</h3>
					<div class="forwp-drive-card__meta">
						${ escapeHtml( doc.file_name ) }
						${ doc.category ? ' · ' + escapeHtml( doc.category ) : '' }
						${ tags ? ' · ' + escapeHtml( tags ) : '' }
					</div>
					${ warning }
					<div class="forwp-drive-card__actions">
						<button type="button" class="button" data-action="preview" data-id="${ doc.id }">Preview</button>
						<button type="button" class="button button-primary" data-action="import" data-id="${ doc.id }">Import as Draft</button>
						<button type="button" class="button" data-action="reject" data-id="${ doc.id }">Reject</button>
					</div>
				</article>`;
			} )
			.join( '' );
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
			const docs = data.documents || [];
			setStatus( status, inboxStatusMessage( data.last_sync, docs.length ) );
			renderInbox( docs, data.last_sync );
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

	function openPreview( id ) {
		previewId = id;
		const panel = document.getElementById( 'forwp-drive-preview' );
		const meta = document.getElementById( 'forwp-drive-preview-meta' );
		const body = document.getElementById( 'forwp-drive-preview-post-content' );
		if ( ! panel || ! body ) {
			return;
		}
		api( 'documents/' + id ).then( ( { ok, data } ) => {
			if ( ! ok ) {
				return;
			}
			panel.hidden = false;
			meta.innerHTML = `<p class="forwp-drive-preview__title">${ escapeHtml( data.title ) }</p>
				<p class="forwp-drive-preview__meta">Slug: ${ escapeHtml( data.slug || '—' ) } · Date: ${ escapeHtml( data.date || '—' ) } · Author: ${ escapeHtml( data.author || '—' ) } · Category: ${ escapeHtml( data.category || '—' ) }${ data.has_image ? ' · Featured image: ' + escapeHtml( data.image_name || 'yes' ) : '' }</p>`;
			body.innerHTML = data.body_html || escapeHtml( data.body || '' );
		} );
	}

	function importDoc( id ) {
		if ( ! window.confirm( forwpDriveAdmin.strings.importConfirm ) ) {
			return;
		}
		const status = document.getElementById( 'forwp-drive-inbox-status' );
		setStatus( status, forwpDriveAdmin.strings.importRunning );
		api( 'documents/' + id + '/import', { method: 'POST' } ).then( ( { ok, data } ) => {
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
			document.getElementById( 'forwp-drive-preview' ).hidden = true;
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
			<dl>
				<dt>incoming</dt><dd><code>${ escapeHtml( folderIds.incoming ) }</code></dd>
				<dt>published</dt><dd><code>${ escapeHtml( folderIds.published ) }</code></dd>
				<dt>failed</dt><dd><code>${ escapeHtml( folderIds.failed ) }</code></dd>
			</dl>`;
	}

	let settingsCache = null;
	let templateRows = [];
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
		if ( data.connected && data.source_ready ) {
			hint.textContent = 'Ready to sync incoming documents.';
		} else if ( data.connected ) {
			hint.textContent = 'Connected — set the root folder ID and save subfolders.';
		} else if ( data.has_client_config ) {
			hint.textContent = 'Credentials saved — click Connect Google Drive.';
		} else {
			hint.textContent = 'Add API credentials, then connect your Google account.';
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
		const saveFolders = document.getElementById( 'forwp-drive-save-folders' );
		const runSync = document.getElementById( 'forwp-drive-run-sync' );
		const redirectCode = document.getElementById( 'forwp-drive-redirect-uri' );
		const oauthRedirect = document.getElementById( 'forwp-drive-oauth-redirect' );
		const localDevNote = document.getElementById( 'forwp-drive-local-dev-note' );
		const suggestedHint = document.getElementById(
			'forwp-drive-oauth-redirect-suggested'
		);
		const useSuggestedBtn = document.getElementById(
			'forwp-drive-use-suggested-redirect'
		);
		const wpconfigNote = document.getElementById( 'forwp-drive-wpconfig-redirect-note' );
		const postTypeSelect = document.getElementById( 'forwp-drive-import-post-type' );
		const sampleTemplate = document.getElementById( 'forwp-drive-sample-template' );

		api( 'settings' ).then( ( { ok, data } ) => {
			if ( ! ok ) {
				return;
			}

			settingsCache = data;
			templateRows = ( data.template_fields || [] ).map( ( f ) => ( { ...f } ) );

			if ( redirectCode && data.redirect_uri ) {
				redirectCode.textContent = data.redirect_uri;
			}
			if ( oauthRedirect ) {
				oauthRedirect.value =
					data.oauth_redirect_uri || data.oauth_redirect_uri_suggested || '';
			}
			if ( localDevNote ) {
				localDevNote.hidden = ! data.local_dev_redirect_help;
			}
			if ( suggestedHint && data.oauth_redirect_uri_suggested ) {
				suggestedHint.hidden = ! data.local_dev_redirect_help;
				suggestedHint.textContent =
					'Suggested for ' +
					( data.site_host || 'this host' ) +
					': ' +
					data.oauth_redirect_uri_suggested;
			}
			if ( useSuggestedBtn ) {
				useSuggestedBtn.hidden =
					! data.oauth_redirect_uri_suggested || !! data.oauth_redirect_locked;
				useSuggestedBtn.dataset.uri = data.oauth_redirect_uri_suggested || '';
			}
			if ( wpconfigNote ) {
				wpconfigNote.hidden = ! data.oauth_redirect_locked;
			}
			if ( oauthRedirect ) {
				oauthRedirect.disabled = !! data.oauth_redirect_locked;
			}
			const saveOauthBtn = document.getElementById(
				'forwp-drive-save-oauth-redirect'
			);
			if ( saveOauthBtn ) {
				saveOauthBtn.disabled = !! data.oauth_redirect_locked;
			}
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

			if ( line ) {
				if ( data.connected ) {
					line.textContent = 'Google account connected. You can import documents from Drive.';
				} else if ( ! data.has_client_config ) {
					line.textContent =
						'Save API credentials below, then connect your Google account.';
				} else {
					line.textContent = 'Credentials saved. Click Connect to authorize access.';
				}
			}
			if ( connect ) {
				const canConnect = data.has_client_config && ! data.connected && data.auth_url;
				connect.hidden = ! canConnect;
				connect.href = data.auth_url || '#';
				if ( ! canConnect && data.auth_url_error && line ) {
					line.textContent = data.auth_url_error;
				}
			}
			if ( disconnect ) {
				disconnect.hidden = ! data.connected;
			}

			const foldersReady = data.connected && data.folder_ids && data.folder_ids.incoming;
			if ( saveFolders ) {
				saveFolders.disabled = ! data.connected;
			}
			if ( runSync ) {
				runSync.disabled = ! foldersReady;
			}
			if ( rootInput && data.folder_ids && data.folder_ids.root ) {
				rootInput.value = data.folder_ids.root;
			}
			renderFolderIds( data.folder_ids );
			updateConnectionPreviewHint( data );
			renderSourceRegistry( data.sources || [] );
		} );
	}

	document.addEventListener( 'click', ( event ) => {
		const target = event.target;
		if ( ! ( target instanceof HTMLElement ) ) {
			return;
		}

		const action = target.getAttribute( 'data-action' );
		const id = target.getAttribute( 'data-id' );
		if ( action && id ) {
			if ( action === 'preview' ) {
				openPreview( id );
			} else if ( action === 'import' ) {
				importDoc( id );
			} else if ( action === 'reject' ) {
				rejectDoc( id );
			}
		}

		if ( target.id === 'forwp-drive-refresh-list' ) {
			runInboxSync();
			return;
		}
		if ( target.id === 'forwp-drive-inbox-sync' ) {
			runInboxSync();
		}
		if ( target.id === 'forwp-drive-preview-import' && previewId ) {
			importDoc( previewId );
		}
		if ( target.id === 'forwp-drive-preview-reject' && previewId ) {
			rejectDoc( previewId );
		}
		if ( target.id === 'forwp-drive-preview-close' ) {
			document.getElementById( 'forwp-drive-preview' ).hidden = true;
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
		if ( target.id === 'forwp-drive-copy-redirect' ) {
			const code = document.getElementById( 'forwp-drive-redirect-uri' );
			const status = document.getElementById( 'forwp-drive-settings-status' );
			if ( code && navigator.clipboard ) {
				navigator.clipboard.writeText( code.textContent || '' ).then( () => {
					setStatus( status, 'Redirect URI copied.' );
				} );
			}
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
		loadInbox();
	}
	if ( document.getElementById( 'forwp-drive-source-registry-grid' ) ) {
		initSettingsChrome();
		loadSettings();
	}
} )();
