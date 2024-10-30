/**
 * Implement filters to the media library modal.
 *
 * Courtesy of Daniel Bachhuber (danielbachhuber.com)
 */

(function(){
	let ImageshopOriginFilters = wp.media.view.AttachmentFilters.extend({
		id: 'imageshop-media-library-origin',

		createFilters: function() {
			var filters = {};

			filters.all = {
				// Change this: use whatever default label you'd like
				text: ( ImageshopMediaLibrary.labels ? ImageshopMediaLibrary.labels.origins.imageshop : 'Search Imageshop' ),
				props: {
					// Change this: key needs to be the WP_Query var for the taxonomy
					imageshop_origin: 'imageshop'
				},
				priority: 10
			}

			filters.wp = {
				// Change this: use whatever default label you'd like
				text: ( ImageshopMediaLibrary.labels ? ImageshopMediaLibrary.labels.origins.wordpress : 'Search WordPress library' ),
				props: {
					// Change this: key needs to be the WP_Query var for the taxonomy
					imageshop_origin: 'wordpress'
				},
				priority: 10
			}

			this.filters = filters;
		}
	})

	/**
	 * Create a new MediaLibraryTaxonomyFilter we later will instantiate
	 */
	let ImageshopInterfaceFilters = wp.media.view.AttachmentFilters.extend({
		id: 'imageshop-media-library-interface',

		createFilters: function() {
			var filters = {};
			// Formats the 'terms' we've included via wp_localize_script()
			_.each( ImageshopMediaLibrary.interfaces || {}, function( value, index ) {
				filters[ value.Id ] = {
					text: value.Name,
					props: {
						// Change this: key needs to be the WP_Query var for the taxonomy
						imageshop_interface: value.Id,
						dataTest: 'nope',
					},
					selected: ( ImageshopMediaLibrary.default_interface.toString() === value.Id.toString() ),
				};
			});
			filters.all = {
				// Change this: use whatever default label you'd like
				text:  ( ImageshopMediaLibrary.labels ? ImageshopMediaLibrary.labels.interfaces.all : 'All interfaces' ),
				props: {
					// Change this: key needs to be the WP_Query var for the taxonomy
					imageshop_interface: '0'
				},
				priority: 10
			};
			this.filters = filters;
		}
	});

	let ImageshopPostsPerPageFilter = wp.media.view.AttachmentFilters.extend({
		id: 'imageshop-posts-per-page',

		createFilters: function() {
			var filters = {};

			filters.all = {
				// Change this: use whatever default label you'd like
				text: ( ImageshopMediaLibrary.labels ? ImageshopMediaLibrary.labels.pagination.all : '25 results per page' ),
				props: {
					// Change this: key needs to be the WP_Query var for the taxonomy
					posts_per_page: 25
				},
				priority: 10
			};
			this.filters = filters;
		}
	});

	let ImageshopLanguageFilter = wp.media.view.AttachmentFilters.extend({
		id: 'imageshop-media-library-language',

		createFilters: function() {
			var filters = {};
			var defaultFilter = {
				slug: '',
				language: {
					label: ''
				}
			};

			if ( ImageshopMediaLibrary.languages ) {
				for ( const[ slug, language ] of Object.entries( ImageshopMediaLibrary.languages ) ) {
					if ( language.default ) {
						defaultFilter = {
							slug,
							language,
						};
					}

					filters[ slug ] = {
						text: language.label,
						props: {
							imageshop_language: slug,
						}
					};
				}
			}
			filters.all = {
				// Change this: use whatever default label you'd like
				text: ( ImageshopMediaLibrary.labels ? ImageshopMediaLibrary.labels.language.all : 'Language' ),
				props: {
					// Change this: key needs to be the WP_Query var for the taxonomy
					imageshop_language: ''
				},
				priority: 10
			};
			this.filters = filters;
		}
	})

	let ImageshopCategoryFilters = wp.media.view.AttachmentFilters.extend({
		id: 'imageshop-media-library-category',

		createFilters: function() {
			var filters = {};
			var incremental = 0;

			if ( ImageshopMediaLibrary.categories ) {
				ImageshopMediaLibrary.categories.map( ( category ) => {
					incremental++;
					filters[ incremental ] = {
						text: category.CategoryName,
						props: {
							// Change this: key needs to be the WP_Query var for the taxonomy
							imageshop_category: category.CategoryID,
						}
					};

					if ( category.Children ) {
						category.Children.map( ( child ) => {
							incremental++;
							filters[ incremental ] = {
								text: ' - ' + child.CategoryName,
								props: {
									// Change this: key needs to be the WP_Query var for the taxonomy
									imageshop_category: child.CategoryID,
								}
							};
						} );
					}
				} );
			}
			filters.all = {
				// Change this: use whatever default label you'd like
				text: ( ImageshopMediaLibrary.labels ? ImageshopMediaLibrary.labels.categories.all : 'All categories' ),
				props: {
					// Change this: key needs to be the WP_Query var for the taxonomy
					imageshop_category: ''
				},
				priority: 10
			};
			this.filters = filters;
		}
	});

	/**
	 * Extend and override wp.media.view.AttachmentsBrowser to include our new filter
	 */
	let AttachmentsBrowser = wp.media.view.AttachmentsBrowser;
	wp.media.view.AttachmentsBrowser = wp.media.view.AttachmentsBrowser.extend({
		createToolbar: function() {
			// Make sure to load the original toolbar
			AttachmentsBrowser.prototype.createToolbar.call( this );

			this.toolbar.set( 'ImageshopOriginFiltersLabel', new wp.media.view.Label({
				value: ( ImageshopMediaLibrary.labels ? ImageshopMediaLibrary.labels.origins.label : 'Media library source origin' ),
				attributes: {
					'for': 'imageshop-media-library-origin'
				},
				priority: -75
			}).render() );
			this.toolbar.set( 'ImageshopOriginFilters', new ImageshopOriginFilters({
				controller: this.controller,
				model:      this.collection.props,
				priority: -75
			}).render() );

			this.toolbar.set( 'ImageshopInterfaceFiltersLabel', new wp.media.view.Label({
				value: ( ImageshopMediaLibrary.labels ? ImageshopMediaLibrary.labels.interfaces.label : 'Imageshop Interface' ),
				attributes: {
					'for': 'imageshop-media-library-interface'
				},
				priority: -75
			}).render() );
			this.toolbar.set( 'ImageshopInterfaceFilters', new ImageshopInterfaceFilters({
				controller: this.controller,
				model:      this.collection.props,
				priority: -75
			}).render() );

			this.toolbar.set( 'ImageshopLanguageFilterLabel', new wp.media.view.Label({
				value: ( ImageshopMediaLibrary.labels ? ImageshopMediaLibrary.labels.language.label : 'Imageshop Language' ),
				attributes: {
					'for': 'imageshop-media-library-language'
				},
				priority: -75
			}).render() );
			this.toolbar.set( 'ImageshopLanguageFilter', new ImageshopLanguageFilter({
				controller: this.controller,
				model: this.collection.props,
				priority: -75
			}).render() );

			this.toolbar.set( 'ImageshopCategoryFiltersLabel', new wp.media.view.Label({
				value: ( ImageshopMediaLibrary.labels ? ImageshopMediaLibrary.labels.categories.label : 'Imageshop Category' ),
				attributes: {
					'for': 'imageshop-media-library-category'
				},
				priority: -75
			}).render() );
			this.toolbar.set( 'ImageshopCategoryFilters', new ImageshopCategoryFilters({
				controller: this.controller,
				model:      this.collection.props,
				priority: -75
			}).render() );

			this.toolbar.set( 'ImageshopPostsPerPageFilterLabel', new wp.media.view.Label({
				value: ( ImageshopMediaLibrary.labels ? ImageshopMediaLibrary.labels.pagination.label : 'Results per page' ),
				attributes: {
					'for': 'imageshop-posts-per-page'
				},
				priority: -75
			}).render() );
			this.toolbar.set( 'ImageshopPostsPerPageFilter', new ImageshopPostsPerPageFilter({
				controller: this.controller,
				model:      this.collection.props,
				priority: -75
			}).render() );

			let ImageshopInterfaceDOM,
				ImageshopCategoryDOM,
				ImageshopSelectorTimer,
				ImageshopSelectPostsPerPageDOM;

			const setSelectors = () => {
				ImageshopInterfaceDOM = document.getElementById( 'imageshop-media-library-interface' );
				ImageshopCategoryDOM = document.getElementById( 'imageshop-media-library-category' );
				ImageshopSelectPostsPerPageDOM = document.getElementById( 'imageshop-posts-per-page' );
			}

			setSelectors()

			const getImageshopCategories = () => {
				wp.apiFetch( {
					path: '/imageshop/v1/categories/' + ImageshopInterfaceDOM.value,
					method: 'GET'
				} ).then( ( response ) => {
					ImageshopMediaLibrary.categories = response;

					this.toolbar.set( 'ImageshopCategoryFilters', new ImageshopCategoryFilters({
						controller: this.controller,
						model:      this.collection.props,
						priority: -75
					}).render() );
				} );
			}

			if ( ImageshopInterfaceDOM && ImageshopCategoryDOM ) {
				ImageshopInterfaceDOM.value = ImageshopMediaLibrary.default_interface;
				ImageshopSelectPostsPerPageDOM.dispatchEvent( new Event( 'change' ) );
			} else {
				ImageshopSelectorTimer = setInterval( () => {
					setSelectors();

					if ( ImageshopInterfaceDOM && ImageshopCategoryDOM ) {
						clearInterval( ImageshopSelectorTimer );

						ImageshopInterfaceDOM.value = ImageshopMediaLibrary.default_interface;

						ImageshopSelectPostsPerPageDOM.dispatchEvent( new Event( 'change' ) );
					}
				}, 500 );
			}
		}
	});
})();

jQuery( document ).ready( function( $ ) {
	$( document ).on( 'click', '.imageshop-edit-cancel', function( event ) {
		event.preventDefault();

		$( '.imageshop-edit-form' ).hide();
		$( '.imageshop-edit-form-feedback' ).hide();
		$( '.imageshop-edit-toggle-visibility' ).show();
	} );
	$( document ).on( 'click', '.imageshop-edit-toggle-visibility', function( event ) {
		event.preventDefault();

		$( '.imageshop-edit-form' ).show();
		$( '.imageshop-edit-toggle-visibility' ).hide();
	} );
	$( document ).on( 'click', '.imageshop_perform_edit', function( event ) {
		event.preventDefault();

		const form = $( this ).closest( 'form' )[0];
		const data = new FormData( form );
		const editorFormFeedback = $( '.imageshop-edit-form-feedback' );

		editorFormFeedback.hide();

		$.ajax( {
			type: 'POST',
			url: ImageshopMediaLibrary.rest_url.update_metadata,
			data: data,
			processData: false,
			contentType: false,
			dataType: 'json'
		} )
			.done( function ( response ) {
				$( '#attachment-details-alt-text' ).val( response.alt );
				$( '#attachment-details-two-column-alt-text' ).val( response.alt );
				$( '#attachment-details-title' ).val( response.title );
				$( '#attachment-details-two-column-title' ).val( response.title );
				$( '#attachment-details-caption' ).val ( response.caption );
				$( '#attachment-details-two-column-caption' ).val ( response.caption );
				$( '#attachment-details-description' ).val( response.description );
				$( '#attachment-details-two-column-description' ).val( response.description );
			} )
			.fail( function ( response ) {
				editorFormFeedback.show().text( response.responseJSON.message );
			} );
	} );

	$( document ).on( 'click', '.imageshop-flush-cache', function( event ) {
		event.preventDefault();

		const feedbackWrapper = $( '.imageshop-ajax-generic-feedback' );

		// Clear existing AJAX feedbacks and states.
		feedbackWrapper
			.removeClass( 'notice-error' )
			.removeClass( 'notice-success' )
			.removeClass( 'notice-warning' )
			.hide();

		$.ajax( {
			type: 'POST',
			url: ImageshopMediaLibrary.rest_url.flush_cache,
			data: {
				_wpnonce: ImageshopMediaLibrary.nonce.rest,
				edit_nonce: $( this ).data( 'flush-nonce' ),
				id: $( this ).data( 'imageshop-id' )
			},
			dataType: 'json'
		} )
			.done( function ( response ) {
				feedbackWrapper.addClass( 'notice-success' ).text( response.message ).show();
			} )
			.fail( function ( response ) {
				console.log( response );
				feedbackWrapper.addClass( 'notice-error' ).text( response.responseJSON.message ).show();
			} );
	} );
} );
