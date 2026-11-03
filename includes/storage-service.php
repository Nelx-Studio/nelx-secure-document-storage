<?php
/** Generic local storage service. Callers must authorize their own upload request. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NELXSDST_Storage_Service {
    /** Local storage uses WordPress' direct adapter, independent of plugin-update FTP settings. */
    public static function filesystem() {
        static $filesystem;
        if (!$filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
            $filesystem = new WP_Filesystem_Direct(null);
        }
        return $filesystem;
    }


    /**
     * Secure an existing WordPress attachment using the established storage layout.
     * This PHP API is not a public upload endpoint; adapters validate requests first.
     *
     * @return true|WP_Error
     */
    public static function ingest_attachment( $id, $context = [] ) {
        $id = absint( $id );
        if ( 'attachment' !== get_post_type( $id ) ) {
            return new WP_Error( 'invalid_attachment', __( 'Invalid attachment.', 'nelx-secure-document-storage' ) );
        }
        if ( NELXSDST_SecureStorage::is_file_secured( $id ) ) {
            return true;
        }
        $file = get_attached_file( $id );
        $uploads = wp_upload_dir();
        $roots = array_merge( [ $uploads['basedir'] ], NELXSDST_SecureStorage::pending_roots() );
        $valid = false;
        foreach ( $roots as $root ) {
            if ( $file && NELXSDST_SecureStorage::path_is_within( $file, $root ) ) {
                $valid = true;
                break;
            }
        }
        if ( ! $valid || ! is_file( $file ) || ! is_readable( $file ) || is_link( $file ) ) {
            return new WP_Error( 'invalid_path', __( 'The attachment is outside the permitted upload locations.', 'nelx-secure-document-storage' ) );
        }
        $root = NELXSDST_SecureStorage::get_storage_root();
        if ( ! $root || ! wp_mkdir_p( $root ) || ! NELXSDST_SecureStorage::add_protection_files( $root ) ) {
            return new WP_Error( 'storage_unavailable', __( 'Protected storage is unavailable.', 'nelx-secure-document-storage' ) );
        }
        $name = sanitize_file_name( $context['original_name'] ?? get_post_meta( $id, '_nelxsdst_original_filename', true ) );
        $name = $name ?: sanitize_file_name( wp_basename( $file ) );
        $owner = isset( $context['owner'] ) ? absint( $context['owner'] ) : absint( get_post_meta( $id, '_nelxsdst_secure_owner', true ) );
        if ( ! isset( $context['owner'] ) && ! metadata_exists( 'post', $id, '_nelxsdst_secure_owner' ) ) {
            $owner = absint( get_post_field( 'post_author', $id ) );
        }
        $source = sanitize_key( $context['source'] ?? 'api' );
        $source = in_array( $source, [ 'manual', 'jetformbuilder', 'api', 'legacy' ], true ) ? $source : 'api';
        $target = NELXSDST_SecureStorage::generate_secure_path( NELXSDST_SecureStorage::generate_secure_filename( $owner, $name ) );
        if ( ! NELXSDST_SecureStorage::path_is_within( dirname( $target['full_path'] ), $root ) || ! NELXSDST_SecureStorage::add_protection_files( dirname( $target['full_path'] ) ) ) {
            return new WP_Error( 'storage_unavailable', __( 'Unable to prepare the secure file directory.', 'nelx-secure-document-storage' ) );
        }
        $wp_filesystem = self::filesystem();
        $old_metadata = wp_get_attachment_metadata( $id );
        if ( ! $wp_filesystem->move( $file, $target['full_path'], false ) ) {
            return new WP_Error( 'move_failed', __( 'The file could not be secured. It remains pending for recovery.', 'nelx-secure-document-storage' ) );
        }
        update_attached_file( $id, $target['full_path'] );
        update_post_meta( $id, '_nelxsdst_secure_file', 1 );
        update_post_meta( $id, '_nelxsdst_secure_owner', $owner );
        update_post_meta( $id, '_nelxsdst_original_filename', $name );
        update_post_meta( $id, '_nelxsdst_secure_path', $target['relative_path'] );
        update_post_meta( $id, '_nelxsdst_source', $source );
        update_post_meta( $id, '_nelxsdst_file_size', filesize( $target['full_path'] ) );
        if ( ! empty( $context['form_id'] ) ) {
            update_post_meta( $id, '_nelxsdst_form_id', absint( $context['form_id'] ) );
        }
        // Remove old generated copies so securing a file cannot leave public thumbnails.
        foreach ( self::derived_files( $file, $old_metadata ) as $copy ) {
            if ( NELXSDST_SecureStorage::path_is_within( $copy, dirname( $file ) ) && $copy !== $file ) {
                wp_delete_file( $copy );
            }
        }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata( $id, $target['full_path'] );
        wp_update_attachment_metadata( $id, is_array( $metadata ) ? $metadata : [] );
        $wp_filesystem->chmod( $target['full_path'], NELXSDST_SecureStorage::FILE_PERMISSION );
        return true;
    }

    public static function derived_files( $file, $metadata ) {
        $files = [];
        if ( ! is_array( $metadata ) ) {
            return $files;
        }
        foreach ( (array) ( $metadata['sizes'] ?? [] ) as $size ) {
            if ( ! empty( $size['file'] ) && wp_basename( $size['file'] ) === $size['file'] ) {
                $files[] = path_join( dirname( $file ), $size['file'] );
            }
        }
        if ( ! empty( $metadata['original_image'] ) && wp_basename( $metadata['original_image'] ) === $metadata['original_image'] ) {
            $files[] = path_join( dirname( $file ), $metadata['original_image'] );
        }
        return $files;
    }

    public static function can_upload() {
        return ( current_user_can( 'manage_options' ) || current_user_can( 'nelxsdst_access_secure_files' ) ) && current_user_can( 'upload_files' );
    }

    public static function can_delete( $id ) {
        return NELXSDST_File_Access::user_can_access_file( $id ) && current_user_can( 'delete_post', $id );
    }

    /** Delete only validated secure paths; WordPress metadata must not escape storage. */
    public static function delete_attachment( $id ) {
        if ( ! self::can_delete( $id ) ) {
            return new WP_Error( 'forbidden', __( 'You cannot delete this file.', 'nelx-secure-document-storage' ) );
        }
        $file = get_attached_file( $id );
        if ( ! $file || ( is_file($file) ? ! NELXSDST_SecureStorage::is_protected_path($file) : ! NELXSDST_SecureStorage::is_protected_path(dirname($file)) ) ) {
            return new WP_Error( 'invalid_path', __( 'The secure file path could not be verified.', 'nelx-secure-document-storage' ) );
        }
        $files = array_merge( [ $file ], self::derived_files( $file, wp_get_attachment_metadata( $id ) ) );
        $backups = get_post_meta( $id, '_wp_attachment_backup_sizes', true );
        foreach ( (array) $backups as $size ) {
            if ( ! empty( $size['file'] ) && wp_basename( $size['file'] ) === $size['file'] ) {
                $files[] = path_join( dirname( $file ), $size['file'] );
            }
        }
        $files = array_unique($files);
        // Validate every related path before removing any file.
        foreach ($files as $path) {
            if (is_file($path) && (!NELXSDST_SecureStorage::is_protected_path($path) || !NELXSDST_SecureStorage::path_is_within($path, dirname($file)))) {
                return new WP_Error('invalid_path', __('A related file path could not be verified.', 'nelx-secure-document-storage'));
            }
        }
        foreach ( $files as $path ) {
            if ( is_file( $path ) ) {
                if ( ! NELXSDST_SecureStorage::is_protected_path( $path ) || ! NELXSDST_SecureStorage::path_is_within( $path, dirname( $file ) ) ) {
                    return new WP_Error( 'invalid_path', __( 'A related file path could not be verified.', 'nelx-secure-document-storage' ) );
                }
                wp_delete_file( $path );
                if ( is_file( $path ) ) {
                    return new WP_Error( 'delete_failed', __( 'A physical file could not be deleted. The attachment record was retained.', 'nelx-secure-document-storage' ) );
                }
            }
        }
        // Physical files already removed safely. Prevent core following stale backup paths.
        $original_metadata = wp_get_attachment_metadata($id);
        delete_post_meta( $id, '_wp_attachment_backup_sizes' );
        delete_post_meta( $id, '_wp_attachment_metadata' );
        delete_post_meta( $id, '_wp_attached_file' );
        if (wp_delete_attachment($id, true)) { return true; }
        // A third-party deletion veto must not leave a record without its verified path.
        update_attached_file($id, $file);
        if ($original_metadata) { wp_update_attachment_metadata($id, $original_metadata); }
        if ($backups) { update_post_meta($id, '_wp_attachment_backup_sizes', $backups); }
        return new WP_Error('delete_failed', __('The attachment record could not be deleted.', 'nelx-secure-document-storage'));
    }
}
