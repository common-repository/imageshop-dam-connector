=== Imageshop DAM Connector ===
Tags: media library, media cdn, DAM
Requires at least: 5.6
Requires PHP: 5.6
Tested up to: 6.2
Stable tag: 1.1.0
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cloud based DAM Solution

== Description ==

Imageshop is a cloud-based [http://www.imageshop.org Digital Asset Management system] (image bank /DAM system) that makes it easier than ever to organize, search, share and use your digital files, internally and with the outside world and partners.

Drag & drop uploading and ultra-efficient image tagging enable your files are always available in the DAM system when and where they are needed, in the right format and the best quality. Read more about Imageshop here: http://www.imageshop.org


== Frequently Asked Questions ==

= Can I use this plugin with multisite? =

The plugin will let you add it to any multisite, but each individual site will need to do their own configuration.

= What if I no longer wish to use Imageshop for my files? =

If you at any point wish to stop using Imageshop for your files, you can export any files that have been used in content on your site to your local media library again, before deactivating the plugin.

= I have an idea for an improvement or enhancement =

We welcome both suggestions, discussions, and code! Check out the project source at https://github.com/DekodeInteraktiv/imageshop

== Changelog ==

= 1.1.0 (2024-02-22) =
* Search: Ignore image mime-types when the image is served by Imageshop, these are always valid and checked by the Imageshop API.
* Attachments: Improve the SQL query for fallback handling to be more performant in the database lookups it performs.
* Attachments: Avoid duplicating the srcset generation if it already exists.
* Attachments: Introduce a new advanced setting allowing site administrators to disable the srcset calculations on media-heavy sites that may not always be compatible with WordPress' media handling.

= 1.0.4 (2023-09-05) =
* Attachments: Fix a race condition where a 0x0 pixel image size could be generated if the image was being processsed by third party code during the upload process.

= 1.0.3 (2023-08-14) =
* Attachments: Improve the identification of original images to generate the appropriate media sizes.

= 1.0.2 (2023-07-20) =
* Imageshpo API: Add secondary declaration for media interfaces to improve support during uthe upload procedure.

= 1.0.1 (2023-07-19) =
* Attachments: Fall back to local images if the Imageshop API response fails for any reason.
* Attachments: Add support for image sizes defined in an array, and not by a pre-registered slug.

= 1.0.0 (2023-06-20) =
* Media filter: Use numeration for categories to avoid filter reordering on CategoryID's.
* Media library: Add a note about image sizes.
* Attachments: Fix an issue where 0x0 pixel image sizes would be needlessly generated.
* Attachments: Avoid repetative fallback actions when the domain for an attachment does not match the site URL.
* Attachments: Add dynamic generation of media captions.
* Attachments: Ensure mime-types match image media before attempting to generate image variations.
