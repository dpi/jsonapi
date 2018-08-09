<?php

namespace Drupal\jsonapi\EventSubscriber;

use Drupal\jsonapi\JsonApiResource\ErrorCollection;
use Drupal\jsonapi\JsonApiResource\JsonApiDocumentTopLevel;
use Drupal\jsonapi\ResourceResponse;
use Drupal\jsonapi\Routing\Routes;
use Drupal\serialization\EventSubscriber\DefaultExceptionSubscriber as SerializationDefaultExceptionSubscriber;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Serializes exceptions in compliance with the  JSON API specification.
 *
 * @internal
 */
class DefaultExceptionSubscriber extends SerializationDefaultExceptionSubscriber {

  /**
   * {@inheritdoc}
   */
  protected static function getPriority() {
    return parent::getPriority() + 25;
  }

  /**
   * {@inheritdoc}
   */
  protected function getHandledFormats() {
    return ['api_json'];
  }

  /**
   * {@inheritdoc}
   */
  public function onException(GetResponseForExceptionEvent $event) {
    if (!$this->isJsonApiExceptionEvent($event)) {
      return;
    }
    if (($exception = $event->getException()) && !$exception instanceof HttpException) {
      $exception = new HttpException(500, $exception->getMessage(), $exception);
      $event->setException($exception);
    }

    $this->setEventResponse($event, $exception->getStatusCode());
  }

  /**
   * {@inheritdoc}
   */
  protected function setEventResponse(GetResponseForExceptionEvent $event, $status) {
    /* @var \Symfony\Component\HttpKernel\Exception\HttpException $exception */
    $exception = $event->getException();
    $response = new ResourceResponse(new JsonApiDocumentTopLevel(new ErrorCollection([$exception])), $exception->getStatusCode(), $exception->getHeaders());
    $response->addCacheableDependency($exception);
    $event->setResponse($response);
  }

  /**
   * Check if the error should be formatted using JSON API.
   *
   * The JSON API format is supported if the format is explicitly set or the
   * request is for a known JSON API route.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent $exception_event
   *   The exception event.
   *
   * @return bool
   *   TRUE if it needs to be formatted using JSON API. FALSE otherwise.
   */
  protected function isJsonApiExceptionEvent(GetResponseForExceptionEvent $exception_event) {
    $request = $exception_event->getRequest();
    $parameters = $request->attributes->all();
    return $request->getRequestFormat() === 'api_json' || (bool) Routes::getResourceTypeNameFromParameters($parameters);
  }

}
