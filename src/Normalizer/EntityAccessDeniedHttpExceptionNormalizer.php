<?php

namespace Drupal\jsonapi\Normalizer;

use Drupal\Core\Url;
use Drupal\jsonapi\Exception\EntityAccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Normalizes an EntityAccessDeniedException.
 *
 * Normalizes an EntityAccessDeniedException in compliance with the JSON API
 * specification. A source pointer is added to help client applications report
 * which entity was access denied.
 *
 * @see http://jsonapi.org/format/#error-objects
 *
 * @internal
 */
class EntityAccessDeniedHttpExceptionNormalizer extends HttpExceptionNormalizer {

  /**
   * {@inheritdoc}
   */
  protected $supportedInterfaceOrClass = EntityAccessDeniedHttpException::class;

  /**
   * {@inheritdoc}
   */
  protected function buildErrorObjects(HttpException $exception) {
    $errors = parent::buildErrorObjects($exception);

    if ($exception instanceof EntityAccessDeniedHttpException) {
      $error = $exception->getError();
      /** @var \Drupal\Core\Entity\EntityInterface $entity */
      $entity = $error['entity'];
      $pointer = $error['pointer'];
      $reason = $error['reason'];

      if (isset($entity)) {
        $entity_type_id = $entity->getEntityTypeId();
        $bundle = $entity->bundle();
        $url = Url::fromRoute(
          sprintf('jsonapi.%s.individual', \Drupal::service('jsonapi.resource_type.repository')->get($entity_type_id, $bundle)->getTypeName()),
          [$entity_type_id => $entity->uuid()]
        )->setAbsolute()->toString(TRUE);
        $errors[0]['links']['via'] = $url->getGeneratedUrl();
      }
      $errors[0]['source']['pointer'] = $pointer;

      if ($reason) {
        $errors[0]['detail'] = isset($errors[0]['detail']) ? $errors[0]['detail'] . ' ' . $reason : $reason;
      }
    }

    return $errors;
  }

}
