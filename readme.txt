=== Nelx Secure Document Storage ===
Contributors: astariko
Tags: security, file protection, secure uploads, documents, jetformbuilder
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.18
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Native secure uploads, an authorized file library and shortcodes, with optional JetFormBuilder, JetEngine and Elementor integrations.

== Description ==

Manage sensitive documents through Secure Files in WordPress administration. Files use protected physical locations and obfuscated filenames, while original filenames are retained for display and download. Access is restricted to logged-in owners, administrators and configured global or form-level roles.

* Native Add Secure File uploads through WordPress validation and attachment records.
* Searchable, paginated backend library with source filters, view, download and permitted deletion. The admin table defaults to 10 rows and offers 10, 20, 50, 100 or All.
* Dedicated capabilities: nelxsdst_access_secure_files for the library and nelxsdst_access_all_secure_files for access to all secured documents. Configure all-files or own-files backend roles separately.
* Context-aware shortcodes for normal media fields and repeater tables/cards, alongside attachment-ID and authorized-library shortcodes.
* Optional JetFormBuilder secure upload routing, form selection, form-level access and processing feedback.
* Existing JetEngine Secure File Links callback and Elementor styling controls.
* Streamed, nonce-protected view/download delivery.
* Pending recovery using exact attachment paths, with original ownership retained.
* Configurable private filesystem storage, without relocating existing files.

== Requirements ==

WordPress 6.0 or higher and PHP 7.4 or higher. JetFormBuilder, JetEngine and Elementor are optional. Local storage must be writable by the PHP process. Apache/LiteSpeed must honor the generated .htaccess deny rules for protected uploads storage; Nginx requires equivalent server-level protection. Private storage outside all publicly served directories is preferable.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/ and activate it.
2. Open Nelx Studio → Secure Documents Storage to upload or manage documents. Authorized non-administrator library users continue to use the dedicated Nelx Secure Files menu.
3. Use Access to select global file-access roles and backend-library roles separately.
4. Use Integrations to configure JetFormBuilder when available and build reusable styled shortcodes.
5. Review Storage under Access and Pending recovery under Files. Donate contains the original support QR image.

== Frequently Asked Questions ==

= Are JetFormBuilder, JetEngine or Elementor required? =

No. Native uploads and shortcodes work without them. Existing integrations remain available when those plugins are active.

= How do I display secure files? =

Open Secure Files → Integrations → Shortcode Builder and create a reusable shortcode configuration. Choose the source/context, repeater mode and layout, show or hide View/Download actions, then configure button and card/table styling. Copy the generated shortcode from the card header or from inside the expanded configuration. Each saved shortcode has its own preset ID so the same field can be rendered with different styling.

Hand-written shortcodes remain supported for backward compatibility: [nelx_secure_file id="123"] displays one attachment and [nelx_secure_files] lists files the logged-in user may access. [nelx_secure_files per_page="20"] supports page sizes from 1 to 100. Use a Gutenberg Shortcode block, Classic Editor, or any builder's shortcode element. This release does not include a dedicated block.

= How do context-aware shortcodes work? =

Replace field values with your actual WordPress meta keys:

* [nelx_secure_file field="document"] resolves the current listing/loop post's media field. Each listing item uses its own object.
* [nelx_secure_files field="qualifications" repeater="yes" layout="cards"] displays a repeater with its text values and secure file buttons. layout="table" gives the existing repeater table.
* [nelx_secure_files field="qualifications" context="user" layout="cards"] uses a JetEngine user listing or queried WordPress user, falling back to the logged-in user.
* [nelx_secure_file field="passport" context="current_user"] always uses the logged-in user's meta.
* [nelx_secure_file field="passport" context="user" object_id="42"] explicitly selects a profile user.
* [nelx_secure_file field="document" context="post" object_id="42"] explicitly selects a post.

context="auto" is the default: current JetEngine listing object first, then the WordPress loop post or queried user. JetEngine CCT listing fields are supported when present on the current listing object. The shortcode cannot guess a field name, so specify field for contextual output. repeater="auto" detects array rows; repeater="yes" or "no" resolves ambiguous field formats. Normal media fields accept attachment IDs, ID lists, and ID/URL objects or JSON. show_view="no" and show_download="no" hide an action; view_text and download_text customize labels for field output. Repeater labels reuse JetEngine field labels when available and otherwise format the field keys. Unauthorized file buttons are always omitted by shortcodes. No raw public file URLs are emitted.

An empty field preserves the original authorized file library. Secure shortcode content must be excluded from shared full-page caching.

= Can non-administrators use the backend? =

Select their roles under Access → Backend library access. They receive nelxsdst_access_secure_files and see Files only. All-file roles additionally receive nelxsdst_access_all_secure_files, allowing protected viewing and downloading of every secured document. Own-file roles see only documents with their recorded ownership in the backend, even when separate global or form-level rules grant broader frontend access. If a role is in both lists, all-file access wins. Uploading also requires upload_files; deleting also requires the attachment's delete_post permission. Access, Integrations and Donate require manage_options.

= How is access granted? =

Administrators with manage_options and recorded file owners have access. Configured global roles can access all secure files. JetFormBuilder form-level grants apply to files associated with that form. Guest-owned files do not give other guests access.

= Can storage be outside the public web root? =

Define an absolute local path in wp-config.php, before WordPress loads:

define( 'NELXSDST_STORAGE_PATH', '/absolute/private/nelx-secure-files' );

Alternatively use the nelxsdst_storage_path filter in an early-loading plugin. Keep this directory outside the public web root and any web-server aliases. New files use the configured directory; existing files remain at their recorded attachment paths and the original saved storage root remains recognized. Keep the private-path configuration in place while files stored there remain in use. The plugin does not move files or reconfigure the server automatically.

= Does .htaccess protect files on every server? =

No. Apache/LiteSpeed must be configured to honor the generated rules. Nginx does not process .htaccess. A server administrator must deny web access to the secure uploads directory, for example the actual URL path corresponding to wp-content/uploads/nelx-secure-storage/. This must match any custom uploads location. Private storage must not be exposed through an alias. The Storage panel under Access describes the active storage mode; it cannot certify server-level protection.

= What is recovered? =

Recovery processes attachment records whose actual paths lie within recognized pending directories. It preserves recorded ownership and source. Matching is by exact path, not filename. Orphan physical files without attachment records are not adopted or deleted automatically.

= Will v1.2.1 files keep working? =

Existing secure-file flags, owner, original filename, form IDs, stored paths and protected link parameters are retained. No file migration is required. The Debugging section and inert logging settings have been removed.

= Can secure-file pages be cached? =

Exclude them from shared full-page caching. Native shortcodes request private no-cache behaviour and set DONOTCACHEPAGE, but an upstream cache may serve content before WordPress runs. Protected delivery still requires the current user's nonce and file authorization.

= Are large downloads loaded fully into memory? =

No. Delivery reads local files in 1 MiB chunks. Active web content is downloaded rather than displayed inline, and responses use nosniff and private no-store headers. Browser range requests and server-offloaded delivery are not implemented in this release.

== Bundled libraries ==

DataTables 2.3.4 is bundled locally under assets/vendor/datatables, with readable source and its MIT license. No remote DataTables requests are made by the plugin. The existing bundled Select2 library is retained.

== Changelog ==

= 1.3.18 (November 5, 2026) =
* Load bundled DataTables assets on both the default plugin settings route and the Files tab so the file list initializes on first visit.
* Preserve existing file access, filters, upload and AJAX table behavior.


= 1.3.17 (November 3, 2026) =
* Standardize bundled Nelx Studio Hub translation calls to the plugin text domain for WordPress.org Plugin Check compliance.
* Correct plugin and author URLs and the Secure Document Storage product link in the Nelx Studio Hub catalog.
* Align plugin header, internal version and readme stable tag to 1.3.17.

= 1.3.16 (November 3, 2026) =
* Keep Save Integrations visible throughout the entire Integrations screen, including Shortcode Builder and Optional integrations.
* Preserve AJAX saving and the existing Save Access behavior.

= 1.3.15 (November 3, 2026) =
* Open the Files screen by default from the Nelx Studio Hub and the plugin settings link; Access remains a separate navigation item.
* Clarify the Files page description for administrators and make backend library role labels distinguish browsing privileges from automatic ownership access.
* Keep Save Access and Save Integrations in container-bound sticky action bars with the existing AJAX notifications.
* Align the stable tag and plugin version and remove old standalone release-notes text files from the distributable.


= 1.3.14 (October 11, 2026) =
* Added an opt-in scoped-file access policy for integrations using `_nelxsdst_policy_context` and `nelxsdst_scoped_file_access`.
* Files with a scoped policy require an explicit allow result; normal owner, administrator and role grants cannot bypass the scoped policy.
* Unscoped files retain their existing authorization behavior.

= 1.3.13 (October 8, 2026) =
* Bundled Nelx Studio Hub 0.3.5, including improved late Freemius submenu preservation and auxiliary native menu roots.
* Left secure storage, access controls, shortcodes and integrations unchanged.

= 1.3.12 (October 8, 2026) =
* Updates the bundled Nelx Studio Hub core to 0.3.2 so Secure Documents provides the same latest Hub experience when installed on its own.
* Keeps version, Active/Inactive state and Up to date/Update available status in one metadata row on installed-product cards.
* Reloads the WordPress admin page about two seconds after a successful Hub activation so the activated plugin immediately appears in the admin menu.

= 1.3.11 (October 8, 2026) =
* Updated the bundled Nelx Studio Hub core to 0.3.1.
* Changed installed-product cards to three columns, matching Explore More Nelx Products.
* Added AJAX plugin activation directly from the Hub dashboard.
* Replaced the unreliable Discover New Tools Dashicon with a bundled inline SVG icon.

= 1.3.10 (October 8, 2026) =
* Upgrades the bundled Nelx Studio Hub core to 0.3.0 with a stronger shared product-registration API.
* Preserves each product's real WordPress admin page slug; the Hub no longer creates synthetic `nelx-product-*` product pages.
* Uses `page=nelx-secure-document-storage` as the Secure Documents management target and keeps known real admin slugs for other detected Nelx plugins.
* Adds explicit Active / Inactive state to installed product cards.
* Adds dashboard-native plugin updates: available updates can be installed by AJAX without leaving the Hub.
* Adds an automatic background update check when the Hub dashboard opens, while retaining the manual Check for Updates action.
* Limits Explore More Nelx Products to real Nelx products that are not currently installed; removes fictional placeholder products.
* Corrects View All Products to https://nelxstudio.com/products/ and support actions to https://nelxstudio.com/support/.
* Shows Free / Freemium / Premium badges only where the product model is known; unknown product tiers are left unlabelled rather than guessed.
* Replaces the previous circular sidebar SVG with a tightly cropped monochrome Nelx mark for better visibility at WordPress admin-menu size.

= 1.3.9 (October 8, 2026) =
* Bundles the shared Nelx Studio Hub core so Secure Document Storage can create the Nelx Studio dashboard when installed on its own.
* Registers Secure Documents Storage explicitly with the Hub, including its version, management URL and documentation URL.
* Moves the administrator-facing Secure Documents entry under Nelx Studio while preserving the existing Files / Access / Integrations / Donate interface and old admin URLs.
* Keeps the dedicated Nelx Secure Files top-level menu for authorized non-administrator file-library users so existing role access is not broadened.
* Adds a shared-core candidate loader so future Nelx plugins can bundle the Hub without loading duplicate core instances; the newest bundled candidate is selected when no standalone Hub is active.
* Preserves the existing plugin folder and main plugin file identity so v1.3.9 updates v1.3.8 normally.

= 1.3.8 (October 7, 2026) =
* Removed hard-coded remote DataTables host checks that Plugin Check treated as offloaded content.
* Dequeues conflicting DataTables assets on Nelx Secure Files admin screens using registered WordPress asset paths only.
* Removed the unused Domain Path header because translations are not bundled in a local languages directory.
* Documented the two intentional attachment meta queries with narrowly scoped WordPress Coding Standards performance justifications.

= 1.3.7 (October 7, 2026) =
* Prevents external DataTables CDN assets from interfering with the bundled DataTables 2.3.4 implementation on Nelx Secure Files admin screens.
* Adds a defensive legacy-DataTables selector fallback for the files-table length control.


= 1.3.6 (October 7, 2026) =

* Restored the v1.3.4 page-length selector width treatment.
* Matched the page-length selector height to the 38px Search files input without changing any DataTables behavior.

= 1.3.5 (October 7, 2026) =

* Removed the experimental mirrored top pagination and retained the stable native bottom DataTables pager.
* Fixed the page-length menu so All renders correctly instead of `[object Object]`.
* Kept the length menu at 10, 20, 50, 100 and All, with All continuing to use the unlimited server-side `length=-1` mode.
* Reduced the page-length selector height while retaining the wider control.
* Added clear spacing between the DataTables controls row and the file table.

= 1.3.4 (October 7, 2026) =

* Fixed the Secure Files DataTable controls so the page-length selector is visibly wide enough in WordPress admin.
* Added a reliable mirrored pagination control above the table while retaining the standard bottom pagination.
* Switched the length menu to DataTables 2 native values: 10, 20, 50, 100 and All.
* Selecting All now requests the existing unlimited server-side mode (`length=-1`) so every matching authorized file can appear on one page.
* Loaded plugin DataTable overrides after the bundled DataTables stylesheet so WordPress/vendor styles no longer override them.

= 1.3.3 (October 7, 2026) =

* Fixed shortcode preset saving so builder values persist and repopulate correctly after reload.
* Re-rendered saved shortcode cards from the canonical database preset after AJAX saves.
* Matched shortcode accordion action buttons and Add New Shortcode placement to Nelx JFB Email Templates styling.
* Added top DataTables pagination in the primary controls row, widened the length selector, and added an unlimited All option while keeping 10 as the default.
* Renamed the WordPress admin menu and plugin administration heading to Nelx Secure Files.

= 1.3.2 (October 7, 2026) =

* Moved Save Access below Storage and restored AJAX slide-in success/error notifications.
* Restored the Donate QR lightbox with close button, backdrop click and Escape-key dismissal.
* Replaced the static shortcode example list with a saved Shortcode Builder using collapsible configurations, copy/delete/duplicate actions and AJAX saving.
* Added per-shortcode View/Download visibility and labels plus independent normal/hover button styling and card/table styling.
* Kept existing hand-written/context-aware shortcodes backward compatible while adding saved preset references for scoped styling.
* Added top DataTables pagination, changed the default page length to 10, and widened the page-length selector.
* Replaced the admin file input with drag-and-drop live secure uploads using XHR progress and the existing secure-storage ingestion service.

= 1.3.1 (October 7, 2026) =

* Added context-aware media and repeater shortcodes for post/user listings and profiles, reusing the existing table/card rendering.
* Restored staged JetFormBuilder controls and disabled processing feedback when Nelx JFB Email Templates already supplies it.
* Reordered Integrations and restored AJAX settings saving.
* Bundled DataTables with authenticated AJAX paging, search, sorting and source filtering.
* Added capability-based all-files and own-files backend role permissions.
* Moved Storage to Access, recovery to Files, removed Settings, and restored the donation QR in Donate.


= 1.3.0 (October 7, 2026) =

* Added the Secure Files administration interface: Files, Access, Integrations and Settings.
* Added native secure uploads and capability-controlled backend library access.
* Added builder-independent single-file and searchable file-table shortcodes.
* Refactored ingestion and link rendering into reusable core services while retaining optional integrations.
* Added a manual/jetformbuilder/api/legacy source indicator with migration-free fallback.
* Added an optional private storage path constant/filter while retaining existing file locations.
* Streamed large downloads, hardened inline responses and secure deletion, and restricted raw media API exposure.
* Improved exact-path pending recovery, preserving ownership and reporting actual failures.
* Loaded frontend assets on use and retained conditional JFB processing feedback.
* Removed the old Debugging interface, inert logging code and donation sidebar.

= 1.2.1 (October 3, 2026) =

* Fixed infinite `upload_dir` recursion during companion live uploads, which could exhaust PHP memory.
* `prepare_live_upload_dir()` no longer calls `ensure_secure_dir()` while already executing inside WordPress' `upload_dir` filter.
* Pending live-upload directories are now prepared directly from the filter's supplied base directory and remain protected by the existing protection files.
* No changes to normal JetFormBuilder secure-upload behavior.

= 1.2.0 (October 3, 2026) =

* Added a public live-upload integration API for trusted companion plugins.
* Added settings-only form-security lookup that does not treat configuration as request authentication.
* Added protected pending-directory routing for separately authenticated live uploads.
* Added verified live-attachment finalization through the existing secure storage pipeline.
* Added original-filename retrieval for secured attachments.
* Preserved the existing JetFormBuilder upload hooks and normal secure-storage behavior.

= 1.1.9 (September 21, 2026) =

* Security: Hardened JetFormBuilder request verification for secure uploads.
* Security: Prevented unverified multipart uploads and spoofed request markers from triggering secure attachment processing.
* Security: Removed unused public AJAX registrations for nonexistent file cleanup and secure URL callbacks.
* Maintenance: Confirmed and registered the bundled Select2 4.1.0 release.

= 1.1.8 (September 1, 2026) =

* Fixed Elementor table border control registration by using Elementor's Border Group Control.
* Removed duplicate table border color control registration.
* Improved pending attachment detection and recovery.
* Pending attachment counts no longer treat WordPress-generated image sizes as separate attachments.
* Improved pending recovery status and button behavior.
* Fixed internationalization and output escaping issues.
* Removed direct database queries introduced by pending recovery status detection.
* Consolidated secure storage initialization to a single instance.

= 1.1.7 (August 31, 2026) =

* Improved pending file recovery interface.
* Added pending file availability status to the recovery interface.
* Disabled recovery when no pending files are available.

= 1.1.6 (August 30, 2026) =

* Improved Elementor control registration.
* Fixed Elementor section ordering issues.
* Improved secure storage initialization.

= 1.1.5 (August 30, 2026) =

* Improved Elementor Dynamic Field styling integration.

= 1.1.4 (August 29, 2026) =

* Improved secure-storage initialization.
* Improved Elementor control registration.

= 1.1.3 (August 27, 2026) =

* Removed server configuration instructions and protection diagnostics.
* Removed generated test files and the standalone recovery script.
* Improved WordPress asset enqueueing and Elementor control registration.
* Added human-readable JavaScript source for the feedback module.

= 1.1.2 (May 16, 2026) =

* Fixed minor issues and bugs.

= 1.1.1 (January 23, 2026) =

= 1.1.0 (January 21, 2026) =

* Added optional form processing modal.
* Added visible submission feedback.
* Added animated processing indicator.
* Added smart modal visibility logic.
* Added new settings control.
* Fixed modal positioning.
* Maintained backward compatibility.

= 1.0.4 (November 15, 2025) =

* Added structured log levels.
* Added context-aware tracking.
* Improved error handling.
* Reduced log noise.
* Improved troubleshooting data.

= 1.0.3 (November 13, 2025) =

* Improved file handling.
* Improved repeater styling.
* Added real label detection from JetEngine.
* Fixed preset file loss.
* Enhanced button styling.
* Added security improvements.

= 1.0.2 =

* Added repeater support with styling.

= 1.0.1 =

* Added secure upload processing.
* Added button callbacks.

= 1.0.0 =

* Initial release.

== Development ==

The original document/feedback CSS and feedback JavaScript retain their distributed minified assets and human-readable sources. The new administration and library assets are distributed as readable CSS/JavaScript and require no build step. Select2 4.1.0 retains its bundled distribution and license. Source: https://github.com/select2/select2


== Roadmap after WordPress.org acceptance ==

Future candidates include Contact Form 7, Gravity Forms, Fluent Forms and WPForms adapters; cloud storage; encryption; public upload forms; folders/tags; versioning; and document-sharing workflows. These are not included in this release.
