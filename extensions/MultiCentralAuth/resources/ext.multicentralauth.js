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
		const farmWikis = mw.config.get( 'mcaFarmWikis' );

		if ( $farmSelect.length && $wikiSelect.length ) {
			$farmSelect.on( 'change', function () {
				const farmId = $( this ).val();
				$wikiSelect.empty();

				if ( farmId === 'manual' ) {
					$( '.mca-dynamic-wiki-field' ).hide();
					$( '[name="subdomain"], [name="domain"]' ).closest( '.oo-ui-fieldLayout' ).show();
				} else {
					$( '.mca-dynamic-wiki-field' ).show();
					$( '[name="subdomain"], [name="domain"]' ).closest( '.oo-ui-fieldLayout' ).hide();

					const wikis = farmWikis[ farmId ] || {};
					for ( const wikiId in wikis ) {
						$wikiSelect.append( $( '<option>' ).val( wikiId ).text( wikis[ wikiId ] ) );
					}
				}
			} ).trigger( 'change' );
		}
	} );
}() );
