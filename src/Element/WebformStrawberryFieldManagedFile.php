<?php

namespace Drupal\webform_strawberryfield\Element;

use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\AccessResultForbidden;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Attribute\FormElement;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Element\FormElementBase;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Symfony\Component\HttpFoundation\Request;
use Drupal\file\Element\ManagedFile;

// cspell:ignore filefield

/**
 * Provides an override for normal Managed files with better valueCallback.
 */
#[FormElement('webform_strawberryfield_managed_file')]
class WebformStrawberryFieldManagedFile extends ManagedFile {

  /**
   * {@inheritdoc}
   */
  public static function valueCallback(&$element, $input, FormStateInterface $form_state) {
    // Find the current value of this field.
    $fids = !empty($input['fids']) ? explode(' ', $input['fids']) : [];
    foreach ($fids as $key => $fid) {
      $fids[$key] = (int) $fid;
    }
    $force_clean_defaults = FALSE;
    // Here we need to get the defaults upfront. We need to really differentiate
    // Files we need to keep bc they are not temporary/and the user can't delete
    // v/s attempts to re-use files from others that are temp.
    if ($element['#extended']) {
      $default_fids = $element['#default_value']['fids'] ?? [];
    }
    else {
      $default_fids = $element['#default_value'] ?? [];
    }
    $fids_kept_no_access = [];
    $fids_with_access = $fids;

    // Process any input and save new uploads.
    // Super important. Here you will get the list of
    // Left over files after a deletion... not the actual requested to be deleted
    // Even if we have access to file_NUMBER['selected'] == 1 or null to check if the user
    // decided to delete... but nor core nor us will use that?
    if ($input !== FALSE) {
      $input['fids'] = $fids;
      $return = $input;

      // Uploads take priority over all other values.
      if ($files = file_managed_file_save_upload($element, $form_state)) {
        if ($element['#multiple']) {
          $fids = array_merge($fids, array_keys($files));
        }
        else {
          $fids = array_keys($files);
        }
      }
      else {
        // Check for #filefield_value_callback values.
        // Because FAPI does not allow multiple #value_callback values like it
        // does for #element_validate and #process, this fills the missing
        // functionality to allow File fields to be extended through FAPI.
        if (isset($element['#file_value_callbacks'])) {
          foreach ($element['#file_value_callbacks'] as $callback) {
            $callback($element, $input, $form_state);
          }
        }

        // Load remaining files if the FIDs have changed to confirm they exist.
        // Here is where our implementation needs to differ from
        // \Drupal\file\Element\ManagedFile::valueCallback
        // Restoring all defaults if a passed $input list holds still files, e.g.
        // after a request to remove from a field (which is the difference OF default and the passed)
        // Makes no sense. Also, the fact you can remove ALL by sending and empty
        // Input DEFEATS the purpose of the access checks... only works in the "default" is actually/originally
        // empty ... mmmm.
        $fids_with_access = $fids;


        if (!empty($input['fids'])) {
          $fids = [];
          foreach ($input['fids'] as $fid) {
            if ($file = File::load($fid)) {
              $fids[] = $file->id();
              // Only act on explicitly forbidden ones.
              if ($file->access('download', \Drupal::currentUser(), TRUE)->isForbidden()) {
                $force_clean_defaults = TRUE;
                if (!in_array($file->id(), $default_fids)) {
                  // Only Mark as no access if not present already in the default values.
                  $fids_kept_no_access[] = $file->id();
                  continue;
                }
              }
              // Temporary files that belong to other users should never be
              // allowed.
              if ($file->isTemporary()) {
                if ($file->getOwnerId() != \Drupal::currentUser()->id()) {
                  $force_clean_defaults = TRUE;
                  $fids_kept_no_access[] = $file->id();
                }
                // Since file ownership can't be determined for anonymous users,
                // they are not allowed to reuse temporary files at all. But
                // they do need to be able to reuse their own files from earlier
                // submissions of the same form, so to allow that, check for the
                // token added by $this->processManagedFile().
                elseif (\Drupal::currentUser()->isAnonymous()) {
                  $token = NestedArray::getValue($form_state->getUserInput(), array_merge($element['#parents'], ['file_' . $file->id(), 'fid_token']));
                  $file_hmac = Crypt::hmacBase64('file-' . $file->id(), \Drupal::service('private_key')->get() . Settings::getHashSalt());
                  if ($token === NULL || !hash_equals($file_hmac, $token)) {
                    $fids_kept_no_access[] = $file->id();
                    $force_clean_defaults = TRUE;
                  }
                }
              }
            }
          }
          if ($force_clean_defaults) {
            $fids_with_access = array_diff($fids,$fids_kept_no_access);
          }
        }
      }
    }

    // If there is no input or if the default value was requested above, use the
    // default value.
    if ($input === FALSE) {
      // Confirm that the file exists when used as a default value.
      // Keep any file that we have no access to but is in the defaults
      // Keep any file we have access to
      // Remove from the defaults the difference only if we detected a change in the input?
      // So no $input === FALSE
      if (!empty($default_fids)) {
        // This covers the use case of just keeping the Files if they exist
        // But no changes are requested. I don't like it, but it is core
        // And might make sense if in our CASE an ADO user is not touching
        // The existing (even no access) Files setup by a previous user
        // That did have access. So kinda safe?

        $fids = [];
        foreach ($default_fids as $fid) {
          if ($file = File::load($fid)) {
            $fids[] = $file->id();
          }
        }
      }
    }
    elseif ($force_clean_defaults) {
      $fids = $fids_with_access ?? [];
      if (!empty($default_fids)) {
        $pre_existing_fids_not_passed = array_diff($default_fids, $fids_with_access);
        foreach ($pre_existing_fids_not_passed as $fid_to_check) {
          // Only keep the ones we don't have access. I know it reads strange
          // But we should not be able, via a Webform element to change what was set by a previous/authorized user
          if ($file = File::load($fid_to_check)) {
            if ($file->access('download', \Drupal::currentUser(), TRUE)->isForbidden()) {
              // restore the Explicitly forbidden one. No temporary/strangeness allowed to persist though
              $fids[] = $fid_to_check;
            }
          }
        }
      }
      $fids = array_unique($fids);
    }

    $return['fids'] = $fids;
    return $return;
  }


}
