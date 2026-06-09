( function () {
	let $methodHint;

	function showMethodHint( methodName, e ) {
		if ( !$methodHint ) {
			$methodHint = $( '<div>' )
				.addClass( 'merge-method-help-div' )
				.hide()
				.on( 'click', function () {
					$( this ).fadeOut();
				} );
			mw.util.$content.append( $methodHint );
		}

		$methodHint
			.empty()
			.append(
				$( '<p>' )
					.addClass( 'merge-method-help-name' )
					.text( mw.msg( 'centralauth-merge-method-' + methodName ) ),
				document.createTextNode( mw.msg( 'centralauth-merge-method-' + methodName + '-desc' ) )
			)
			.css( {
				left: e.pageX + 'px',
				top: e.pageY + 'px'
			} )
			.fadeIn();
	}

	$( () => {
		$( '.mw-centralauth-wikislist' ).on( 'click', '.merge-method-help', function ( event ) {
			showMethodHint( $( this ).data( 'centralauth-mergemethod' ), event );
		} );

		// Farm/Wiki selection logic
		const $farmSelect = $( '#mca-farm-select select' );
		const $wikiSelect = $( '#mca-wiki-select select' );
		const $domainSelect = $( '#mca-domain-select select' );
		const $subdomainField = $( '[name="subdomain"]' ).closest( '.oo-ui-fieldLayout' );
		const $domainField = $( '#mca-domain-select' ).closest( '.oo-ui-fieldLayout' );
		const $dynamicWikiField = $( '#mca-wiki-select' ).closest( '.oo-ui-fieldLayout' );
		const farmWikis = mw.config.get( 'mcaFarmWikis' );

		if ( $farmSelect.length ) {
			$farmSelect.on( 'change', function () {
				const farmId = $( this ).val();

				if ( farmId === 'wm' || farmId === 'mh' ) {
					$dynamicWikiField.hide();
					$subdomainField.show();
					$domainField.show();

					// Filter domains
					$domainSelect.find( 'option' ).hide();
					if ( farmId === 'wm' ) {
						$domainSelect.find( 'option' ).each( function () {
							if ( $( this ).val().indexOf( '.wikipedia.org' ) !== -1 ||
								 $( this ).val().indexOf( '.wikimedia.org' ) !== -1 ||
								 $( this ).val().indexOf( '.wiktionary.org' ) !== -1 ||
								 $( this ).val().indexOf( '.wikibooks.org' ) !== -1 ||
								 $( this ).val().indexOf( '.wikisource.org' ) !== -1 ||
								 $( this ).val().indexOf( '.wikiquote.org' ) !== -1 ||
								 $( this ).val().indexOf( '.wikinews.org' ) !== -1 ) {
								$( this ).show();
							}
						} );
						if ( $domainSelect.val() === '.miraheze.org' ) $domainSelect.val( '.wikipedia.org' );
					} else {
						$domainSelect.find( 'option[value=".miraheze.org"]' ).show();
						$domainSelect.val( '.miraheze.org' );
					}
				} else {
					$dynamicWikiField.show();
					$subdomainField.show(); // Subdomain remains optional for custom farms
					$domainField.hide();

					$wikiSelect.empty();
					const wikis = farmWikis[ farmId ] || {};
					for ( const wikiId in wikis ) {
						$wikiSelect.append( $( '<option>' ).val( wikiId ).text( wikis[ wikiId ] ) );
					}
					// Force OOUI to recognize changes if it's an OOUI widget
					$wikiSelect.trigger( 'change' );
				}
			} ).trigger( 'change' );
		}
	} );
}() );
