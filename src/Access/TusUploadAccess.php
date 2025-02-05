<?php


namespace Drupal\webform_strawberryfield\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform_strawberryfield\Plugin\WebformHandler\strawberryFieldharvester;

/**
 * Defines the custom access control handler for the webform submission entities.
 */
class TusUploadAccess {

  /**
   * Check that webform submission has a strawberryfield webform handler and the user can create bundled Nodes
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   A webform submission.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Run access checks for this account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function checkAccess(WebformInterface $webform, string $key, AccountInterface $account) {
    $possible_upload_element = $webform->getElement($key);
    $all_upload_elements = $webform->getElementsManagedFiles();
    $access_result = AccessResult::allowed();
    //allowedIf()
    // $access_result->addCacheTags(['config:webform.settings']);
    return $access_result;
  }

}
