<?php

namespace Drupal\webform_strawberryfield\Event;
use Drupal\file\FileInterface;
use Drupal\Component\EventDispatcher\Event;

class WebformStrawberryFieldTusUploadedEvent extends Event {

  /**
   * File Entity.
   *
   * @var \Drupal\file\FileInterface
   */
  protected $file;

  public function __construct(FileInterface $file) {
    $this->file = $file;
  }
}
