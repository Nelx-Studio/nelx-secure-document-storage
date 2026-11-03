# Nelx Secure Document Storage

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4)](https://www.php.net/)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue)](LICENSE)

**Nelx Secure Document Storage** is a free WordPress plugin for protected file uploads, controlled document access, and secure viewing and downloading. It works without a page builder, with optional JetFormBuilder, JetEngine, and Elementor integrations.

**Current version:** 1.3.17  
**Website:** https://nelxstudio.com/nelx-secure-document-storage/  
**Author:** Astariko Ambuka — https://nelxstudio.com/

## Features

- Native secure file uploads and an administrative file library with search and filters.
- Owner-, administrator-, and role-based access controls, with separate backend-library permissions.
- Protected file delivery with nonce checks and streamed downloads.
- Context-aware shortcodes for individual secure files, authorized file lists, media fields, and repeater tables/cards.
- Reusable shortcode presets for per-instance button, card, and table styling.
- Optional JetFormBuilder integration for securing form uploads.
- Optional JetEngine dynamic-field callback and Elementor styling support.
- Storage status and recovery tools, including a configurable private filesystem storage path.

## Requirements

- WordPress 6.0 or newer.
- PHP 7.4 or newer.
- A writable storage directory.

JetFormBuilder, JetEngine, and Elementor are optional. **For protected uploads storage inside a publicly served directory, configure your web server to deny direct access.** Apache/LiteSpeed can honor the generated `.htaccess` rules; Nginx requires equivalent server configuration. Private storage outside the public web root is preferable.

## Installation

1. Download the release ZIP or copy this repository into `wp-content/plugins/nelx-secure-document-storage/`.
2. Activate **Nelx Secure Document Storage** in WordPress.
3. Open **Nelx Secure Files** to review files, access controls, and integrations.
4. Configure global access and backend library roles under **Access**.
5. If using JetFormBuilder, configure secure uploads under **Integrations**.
6. Use the **Shortcode Builder** to create and save context-aware display presets.

## Basic shortcodes

Display a secure attachment by ID:

```text
[nelx_secure_file id="123"]
```

List files the current user may access:

```text
[nelx_secure_files]
```

Render a media field for the current object:

```text
[nelx_secure_file field="document"]
```

Render a repeater as cards:

```text
[nelx_secure_files field="qualifications" repeater="yes" layout="cards"]
```

For styled or frequently reused configurations, create a preset in the plugin's **Shortcode Builder** and copy the generated shortcode.

## Documentation and support

Read the full usage and technical reference in the WordPress-format [`readme.txt`](readme.txt), or visit the [plugin website](https://nelxstudio.com/nelx-secure-document-storage/).

When reporting a bug, include the plugin version, WordPress and PHP versions, steps to reproduce, and relevant non-sensitive error details. Do not include private documents or credentials in public issues.

## Licence

Copyright © 2026 Astariko Ambuka. Distributed under the **GNU General Public License, version 2 or (at your option) any later version**. See [LICENSE](LICENSE) and [license.txt](license.txt).
