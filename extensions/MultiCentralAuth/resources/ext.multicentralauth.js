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

		// Farm/Wiki selection logic with OOUI Infusion
		if ( $( '#mca-farm-select' ).length ) {
			const farmSelect = OO.ui.infuse( $( '#mca-farm-select' ) );
			const wikiSelect = OO.ui.infuse( $( '#mca-wiki-select' ) );
			const domainSelect = OO.ui.infuse( $( '#mca-domain-select' ) );
			const subdomainField = OO.ui.infuse( $( '#mca-subdomain-field' ) );

			const farmWikis = mw.config.get( 'mcaFarmWikis' );

			const updateVisibility = () => {
				const farmId = farmSelect.getValue();

				if ( farmId === 'wm' || farmId === 'mh' ) {
					wikiSelect.$element.closest( '.oo-ui-fieldLayout' ).hide();
					subdomainField.$element.closest( '.oo-ui-fieldLayout' ).show();
					domainSelect.$element.closest( '.oo-ui-fieldLayout' ).show();

					const domains = [];
					if ( farmId === 'wm' ) {
						[ '.wikipedia.org', '.wikimedia.org', '.wiktionary.org', '.wikibooks.org', '.wikisource.org', '.wikiquote.org', '.wikinews.org' ].forEach( d => {
							domains.push( { data: d, label: d } );
						} );
						if ( domainSelect.getValue() === '.miraheze.org' ) domainSelect.setValue( '.wikipedia.org' );
					} else {
						domains.push( { data: '.miraheze.org', label: '.miraheze.org' } );
						domainSelect.setValue( '.miraheze.org' );
					}
					domainSelect.setOptions( domains );
				} else {
					wikiSelect.$element.closest( '.oo-ui-fieldLayout' ).show();
					subdomainField.$element.closest( '.oo-ui-fieldLayout' ).hide();
					domainSelect.$element.closest( '.oo-ui-fieldLayout' ).hide();

					const wikis = farmWikis[ farmId ] || {};
					const options = [];
					for ( const wikiId in wikis ) {
						options.push( { data: wikiId, label: wikis[ wikiId ] } );
					}
					wikiSelect.setOptions( options );
				}
			};

			farmSelect.on( 'change', updateVisibility );
			updateVisibility();
		}
	} );
}() );
