const imageshopLoadingIndicator = document.getElementsByClassName( 'imageshop__loader' )[0],
	imageshopMessage = document.getElementsByClassName( 'imageshop__message' )[0],
	imageshopTestConnection = document.getElementsByClassName( 'imageshop__test__connection' )[0],
	imageshopSyncToImageshop = document.getElementsByClassName( 'imageshop__sync_wp_to_imageshop' )[0],
	imageshopSyncToLocal = document.getElementsByClassName( 'imageshop__sync_imageshop_to_wp' )[0];

// check connection button
imageshopTestConnection.addEventListener( 'click', function () {
	wp.apiFetch( {
		path: '/imageshop/v1/settings/test-connection',
		method: 'POST',
		data: {
			token: document.querySelector( 'input[name=imageshop_api_key]' ).value
		}
	} )
		.then( function( response ) {
			imageshopMessage.innerHTML = response.message;
		} );
} );

imageshopSyncToImageshop.addEventListener( 'click', function() {
	imageshopLoadingIndicator.style.display = 'block';

	wp.apiFetch( {
		path: '/imageshop/v1/sync/remote',
		method: 'POST'
	} )
		.then( function( response ) {
			imageshopLoadingIndicator.style.display = 'none';

			imageshopMessage.innerHTML = response.data.message;
		} );
} );

imageshopSyncToLocal.addEventListener( 'click', function() {
	imageshopLoadingIndicator.style.display = 'block';

	wp.apiFetch( {
		path: '/imageshop/v1/sync/local',
		method: 'POST'
	} )
		.then( function( response ) {
			imageshopLoadingIndicator.style.display = 'none';

			imageshopMessage.innerHTML = response.data.message;
		} );
} );
