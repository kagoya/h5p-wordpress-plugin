<?php

/**
 * Handles all communication with the database.
 */
class H5PEditorWordPressStorage implements H5peditorStorage {

  /**
   * Load language file(JSON) from database.
   * This is used to translate the editor fields(title, description etc.)
   *
   * @param string $name The machine readable name of the library(content type)
   * @param int $major Major part of version number
   * @param int $minor Minor part of version number
   * @param string $lang Language code
   * @return string Translation in JSON format
   */
  public function getLanguage($name, $majorVersion, $minorVersion, $language) {
    global $wpdb;

    // Load translation field from DB
    return $wpdb->get_var($wpdb->prepare(
        "SELECT hlt.translation
           FROM {$wpdb->prefix}h5p_libraries_languages hlt
           JOIN {$wpdb->prefix}h5p_libraries hl ON hl.id = hlt.library_id
          WHERE hl.name = %s
            AND hl.major_version = %d
            AND hl.minor_version = %d
            AND hlt.language_code = %s",
        $name, $majorVersion, $minorVersion, $language
    ));
  }

  /**
   * "Callback" for mark the given file as a permanent file.
   * Used when saving content that has new uploaded files.
   *
   * @param int $fileid
   */
  public function keepFile($fileId) {
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'h5p_tmpfiles', array('path' => $fileId), array('%s'));
  }

  /**
   * Decides which content types the editor should have.
   *
   * Two usecases:
   * 1. No input, will list all the available content types.
   * 2. Libraries supported are specified, load additional data and verify
   * that the content types are available. Used by e.g. the Presentation Tool
   * Editor that already knows which content types are supported in its
   * slides.
   *
   * @param array $libraries List of library names + version to load info for
   * @return array List of all libraries loaded
   */
  public function getLibraries($libraries = NULL) {
    global $wpdb;

    $super_user = current_user_can('manage_h5p_libraries');

    if ($libraries !== NULL) {
      // Get details for the specified libraries only.
      $librariesWithDetails = array();
      foreach ($libraries as $library) {
        // Look for library
        $details = $wpdb->get_row($wpdb->prepare(
            "SELECT title, runnable, restricted, tutorial_url
              FROM {$wpdb->prefix}h5p_libraries
              WHERE name = %s
              AND major_version = %d
              AND minor_version = %d
              AND semantics IS NOT NULL",
            $library->name, $library->majorVersion, $library->minorVersion
          ));
        if ($details) {
          // Library found, add details to list
          $library->tutorialUrl = $details->tutorial_url;
          $library->title = $details->title;
          $library->runnable = $details->runnable;
          $library->restricted = $super_user ? FALSE : ($details->restricted === '1' ? TRUE : FALSE);
          $librariesWithDetails[] = $library;
        }
      }

      // Done, return list with library details
      return $librariesWithDetails;
    }

    // Load all libraries
    $libraries = array();
    $libraries_result = $wpdb->get_results(
        "SELECT name,
                title,
                major_version AS majorVersion,
                minor_version AS minorVersion,
                tutorial_url AS tutorialUrl,
                restricted
          FROM {$wpdb->prefix}h5p_libraries
          WHERE runnable = 1
          AND semantics IS NOT NULL
          ORDER BY title"
      );
    foreach ($libraries_result as $library) {
      // Make sure we only display the newest version of a library.
      foreach ($libraries as $key => $existingLibrary) {
        if ($library->name === $existingLibrary->name) {

          // Found library with same name, check versions
          if ( ( $library->majorVersion === $existingLibrary->majorVersion &&
                 $library->minorVersion > $existingLibrary->minorVersion ) ||
               ( $library->majorVersion > $existingLibrary->majorVersion ) ) {
            // This is a newer version
            $existingLibrary->isOld = TRUE;
          }
          else {
            // This is an older version
            $library->isOld = TRUE;
          }
        }
      }

      // Check to see if content type should be restricted
      $library->restricted = $super_user ? FALSE : ($library->restricted === '1' ? TRUE : FALSE);

      // Add new library
      $libraries[] = $library;
    }
    return $libraries;
  }

  /**
   * Allow for other plugins to decide which styles and scripts are attached.
   * This is useful for adding and/or modifing the functionality and look of
   * the content types.
   *
   * @param array $files
   *  List of files as objects with path and version as properties
   * @param array $libraries
   *  List of libraries indexed by machineName with objects as values. The objects
   *  have majorVersion and minorVersion as properties.
   */
  public function alterLibraryFiles(&$files, $libraries) {
    $plugin = H5P_Plugin::get_instance();
    $plugin->alter_assets($files, $libraries, 'editor');
  }
}
