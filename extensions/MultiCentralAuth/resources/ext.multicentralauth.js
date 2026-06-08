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
	} );
}() );
