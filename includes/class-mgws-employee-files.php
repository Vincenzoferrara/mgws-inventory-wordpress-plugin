<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Documents of an employee: the signed contract, an ID, anything else HR
 * keeps on file.
 *
 * Files are personal data, so they never go to the media library. They live
 * in a folder of their own under uploads, closed to the web server, with a
 * random name; the only way to read one is the REST route, which checks
 * mgws_manage_employees like the rest of the employee record.
 */
class MGWS_Employee_Files {
    public const KINDS = array('contract', 'id_document', 'other');

    public const MAX_BYTES = 10485760;

    /** Types read from the file content, never from the name the client sent. */
    private const MIME_EXT = array(
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    );

    /**
     * The private folder, created on first use with what keeps Apache and IIS
     * from serving it. On nginx those files do nothing: the random names are
     * what keeps a file from being guessed there.
     *
     * @return string|WP_Error
     */
    public static function directory() {
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error'])) {
            return new WP_Error('mgws_storage_unavailable', 'The uploads folder is not writable', array('status' => 500));
        }
        $dir = trailingslashit($uploads['basedir']) . 'mgws-private/employees';
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return new WP_Error('mgws_storage_unavailable', 'The uploads folder is not writable', array('status' => 500));
        }
        $base = dirname($dir);
        $guards = array(
            '.htaccess' => "Require all denied\nDeny from all\n",
            'index.php' => "<?php\n// Silence is golden.\n",
            'web.config' => "<?xml version=\"1.0\"?" . ">\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
        );
        foreach ($guards as $name => $content) {
            if (!file_exists($base . '/' . $name)) {
                file_put_contents($base . '/' . $name, $content);
            }
        }
        if (!file_exists($dir . '/index.php')) {
            file_put_contents($dir . '/index.php', $guards['index.php']);
        }
        return $dir;
    }

    public static function detect_mime($bytes) {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? (string) finfo_buffer($finfo, $bytes) : '';
            if ($finfo) {
                finfo_close($finfo);
            }
            return $mime;
        }
        // Without fileinfo, the magic numbers of the four accepted types.
        if (strncmp($bytes, '%PDF-', 5) === 0) {
            return 'application/pdf';
        }
        if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
            return 'image/jpeg';
        }
        if (strncmp($bytes, "\x89PNG\r\n\x1A\n", 8) === 0) {
            return 'image/png';
        }
        if (strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        return 'application/octet-stream';
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public static function store($employee_id, $kind, $title, $file_name, $bytes, $user_id) {
        $kind = sanitize_key((string) $kind);
        if (!in_array($kind, self::KINDS, true)) {
            return new WP_Error('mgws_bad_request', 'kind must be one of: ' . implode(', ', self::KINDS), array('status' => 400));
        }
        $size = strlen((string) $bytes);
        if ($size === 0) {
            return new WP_Error('mgws_bad_request', 'The file is empty', array('status' => 400));
        }
        if ($size > self::MAX_BYTES) {
            return new WP_Error('mgws_file_too_large', 'The file is larger than 10 MB', array('status' => 413));
        }
        $mime = self::detect_mime($bytes);
        if (!isset(self::MIME_EXT[$mime])) {
            return new WP_Error('mgws_bad_file_type', 'Only PDF, JPEG, PNG and WebP files are accepted', array('status' => 415));
        }
        $dir = self::directory();
        if (is_wp_error($dir)) {
            return $dir;
        }
        $stored = bin2hex(random_bytes(16)) . '.' . self::MIME_EXT[$mime];
        if (file_put_contents($dir . '/' . $stored, $bytes) !== $size) {
            return new WP_Error('mgws_storage_unavailable', 'The file could not be saved', array('status' => 500));
        }
        $file_name = sanitize_file_name((string) $file_name);
        if ($file_name === '') {
            $file_name = 'document.' . self::MIME_EXT[$mime];
        }
        $title = sanitize_text_field((string) $title);
        global $wpdb;
        $ok = $wpdb->insert(MGWS_DB::table_employee_documents(), array(
            'employee_id' => (int) $employee_id,
            'kind' => $kind,
            'title' => mb_substr($title !== '' ? $title : $file_name, 0, 191),
            'file_name' => mb_substr($file_name, 0, 191),
            'stored_name' => $stored,
            'mime' => $mime,
            'size_bytes' => $size,
            'uploaded_by' => (int) $user_id,
            'created_at_gmt' => current_time('mysql', true),
        ));
        if (!$ok) {
            wp_delete_file($dir . '/' . $stored);
            return new WP_Error('mgws_storage_unavailable', 'The file could not be saved', array('status' => 500));
        }
        return self::get((int) $wpdb->insert_id);
    }

    public static function list_for($employee_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE employee_id = %d ORDER BY created_at_gmt DESC, id DESC',
            MGWS_DB::table_employee_documents(),
            (int) $employee_id
        ), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public static function get($document_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d LIMIT 1',
            MGWS_DB::table_employee_documents(),
            (int) $document_id
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Path of a stored file. The stored name comes from our own table, but it
     * is checked again so a tampered row cannot point outside the folder.
     *
     * @return string|WP_Error
     */
    private static function path(array $row) {
        $stored = (string) $row['stored_name'];
        if (!preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|webp)$/', $stored)) {
            return new WP_Error('mgws_document_missing', 'The document file is missing', array('status' => 404));
        }
        $dir = self::directory();
        return is_wp_error($dir) ? $dir : $dir . '/' . $stored;
    }

    /**
     * @return string|WP_Error the file bytes
     */
    public static function read(array $row) {
        $path = self::path($row);
        if (is_wp_error($path)) {
            return $path;
        }
        $bytes = is_readable($path) ? file_get_contents($path) : false;
        if ($bytes === false) {
            return new WP_Error('mgws_document_missing', 'The document file is missing', array('status' => 404));
        }
        return $bytes;
    }

    public static function delete(array $row) {
        $path = self::path($row);
        if (!is_wp_error($path) && file_exists($path)) {
            wp_delete_file($path);
        }
        global $wpdb;
        return (bool) $wpdb->delete(MGWS_DB::table_employee_documents(), array('id' => (int) $row['id']));
    }

    public static function response(array $row) {
        return array(
            'id' => (int) $row['id'],
            'employee_id' => (int) $row['employee_id'],
            'kind' => (string) $row['kind'],
            'title' => (string) $row['title'],
            'file_name' => (string) $row['file_name'],
            'mime' => (string) $row['mime'],
            'size_bytes' => (int) $row['size_bytes'],
            'created_at_gmt' => (string) $row['created_at_gmt'],
        );
    }
}
