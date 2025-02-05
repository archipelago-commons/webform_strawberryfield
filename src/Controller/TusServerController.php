<?php


namespace Drupal\webform_strawberryfield\Controller;


use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\webform\Plugin\WebformElement\WebformManagedFileBase;
use Drupal\webform\WebformInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Serializer;
use Drupal\webform_strawberryfield\WebformStrawberryTusServerService;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\webform\Plugin\WebformElementManagerInterface;

/**
 * Class TusServerController.
 */
class TusServerController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The injected WebformStrawberryTusServerService.
   *
   * @var \Drupal\webform_strawberryfield\WebformStrawberryTusServerService
   */
  protected $tusServerService;


  /**
   * The webform element manager.
   *
   * @var \Drupal\webform\Plugin\WebformElementManagerInterface
   */
  protected $webformElementManager;

  /**
   * The serializer.
   *
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * The available serialization formats.
   *
   * @var array
   */
  protected $serializerFormats = [];

  /**
   * @var \Drupal\Core\File\FileSystemInterface
   */
  private FileSystemInterface $fileSystem;

  /**
   * Constructs a new TusController object.
   */
  public function __construct(WebformStrawberryTusServerService $tus_server, Serializer $serializer, array $serializer_formats, WebformElementManagerInterface $webformManager, FileSystemInterface $filesystem) {
    $this->tusServerService = $tus_server;
    $this->serializer = $serializer;
    $this->serializerFormats = $serializer_formats;
    $this->webformElementManager = $webformManager;
    $this->fileSystem = $filesystem;
  }


  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    if ($container->hasParameter('serializer.formats') && $container->has('serializer')) {
      $serializer = $container->get('serializer');
      $formats = $container->getParameter('serializer.formats');
    }
    else {
      $formats = ['json'];
      $encoders = [new JsonEncoder()];
      $serializer = new Serializer([], $encoders);
    }

    return new static(
      $container->get( 'webform_strawberryfield.tus.server'),
      $serializer,
      $formats,
      $container->get('plugin.manager.webform.element'),
      $container->get('file_system')
    );
  }

  /**
   * Gets the format of the current request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return string
   *   The format of the request.
   */
  protected function getRequestFormat(Request $request): ?string {
    $format = $request->getRequestFormat();
    if (!in_array($format, $this->serializerFormats)) {
      throw new BadRequestHttpException(sprintf('Unrecognized format: %s.', $format));
    }
    return $format;
  }

  /**
   * Upload a file via TUS protocol.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param string $uuid
   *   UUID for the file being uploaded.
   *
   * @return \TusPhp\Tus\Server
   *   Tus server ready to receive file upload.
   */
  public function uploadToWebform(Request $request, WebformInterface $webform, string $key, string $uuid) {
    $meta_values = $this->getMetaValuesFromRequest($request);
    $current_path = $request->getPathInfo();

    // If no upload token (uuid) is provided, verify this request is genuine.
    // POST requests from Tus will not have an uuid.
    if (!$uuid) {
      //$this->verifyRequest($request, $meta_values);
    }

    // UUID is passed on PATCH and other certain calls, or as the
    // header upload-key on others.
    $uuid = $uuid ?? $request->headers->get('upload-key') ?? '';
    $server = $this->tusServerService->getServer($current_path, $uuid, $meta_values);
    // Each webform might have a different upload destination/key based on their settings
    // We can't upload to S3 though, so in this case all will go to private?
    // See \Drupal\webform\Plugin\WebformElement\WebformManagedFileBase::getUploadLocation
    [$destination, $max] = $this->getUploadLocation($webform, $key);
    if ($destination) {
      $server->setUploadDir($destination);
      // Get the file destination. WE NEE TO MOVE THIS TO THE CONTROLLER.
      // THAT IS WHERE WE HAVE INFO ABOUT THE WEBFORM/KEY.
      $server->setMaxUploadSize((int) 100 * 1048576);
      return $server->serve();
    }
    else {
      throw new UnprocessableEntityHttpException(sprintf('Webform element %s can not hold your file', $key));
    }
  }


  protected function getUploadLocation($webform_entity, $key):array {
    $upload_location = NULL;
    /* @var \Drupal\webform\Entity\Webform $webform_entity */
    $element = $webform_entity->getElementInitialized($key);
    if ($element) {
      $element_plugin = $this->webformElementManager->getElementInstance($element);
      if ($element_plugin->getPluginId() == 'webform_tus_file') {
      }
      $upload_location = 'private://webform/' . $webform_entity->id() . '/_sid_/'.$this->currentUser()->getAccountName().'/tus/';
      //  If we use a custom element we can set this directly $upload_location = $element['#upload_location'];
      $this->fileSystem->prepareDirectory($upload_location, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    }
    return [$upload_location, 100];
  }



  /**
   * Attempt to verify this is a genuine request from a user.
   *
   * Will throw an error if there are issues.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The original request.
   * @param array $meta_values
   *   Meta values extracted using ::getMetaValuesFromRequest().
   */
  protected function verifyRequest(Request $request, array $meta_values): void {
    // If any of these are missing we can't verify the upload or save it to a
    // field so there is no point in continuing.
    if (empty($meta_values['entityType']) ||
      empty($meta_values['entityBundle']) ||
      empty($meta_values['fieldName']) ||
      empty($meta_values['filetype'])) {
      throw new UnprocessableEntityHttpException('Required metadata fields not passed in.');
    }

    // Check the uploaded file type is permitted by field.
    // See file_validate_extensions().
    $allowed_extensions = 'any';
    $regex = '/\.(' . preg_replace('/ +/', '|', preg_quote($allowed_extensions)) . ')$/i';
    if (!preg_match($regex, $meta_values['filename'])) {
      throw new UnprocessableEntityHttpException(sprintf('Only files with the following extensions are allowed: %s.', $allowed_extensions));
    }
  }

  /**
   * Get an array of meta values from.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The original request.
   *
   * @return array
   *   An array of metadata values passed in with the request.
   */
  protected function getMetaValuesFromRequest(Request $request): array {
    $result = [];
    if ($metadata = $request->headers->get('upload-metadata')) {
      foreach (explode(',', $metadata) as $piece) {
        [$meta_name, $meta_value] = explode(' ', $piece);
        $result[$meta_name] = base64_decode($meta_value);
      }
    }

    return $result;
  }

  /**
   * Get the file ID of the uploaded file.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The created file details.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function uploadToWebformComplete(Request $request, WebformInterface $webform, string $key): Response {
    $response = [];
    $post_data = $this->serializer->decode($request->getContent(), $this->getRequestFormat($request));

    // @TODO change this to loadByProperty.

    $file_query = $this->entityTypeManager()->getStorage('file')->getQuery();
    $file_query->condition('uri', $request->get('uuid') . '/' . $post_data['fileName'], 'CONTAINS');
    $file_query->accessCheck(TRUE);
    $results = $file_query->execute();

    if (!empty($results)) {
      $response['fid'] = reset($results);
    }

    $jsonResponse = new CacheableJsonResponse();
    $jsonResponse->setMaxAge(10);
    $jsonResponse->setData($response);
    return $jsonResponse;
  }
}

