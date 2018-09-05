<?php

namespace Drupal\jsonapi\EventSubscriber;

use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\jsonapi\Context\FieldResolver;
use Drupal\jsonapi\Normalizer\Value\JsonApiDocumentTopLevelNormalizerValue;
use Drupal\jsonapi\ResourceResponse;
use Drupal\jsonapi\ResourceType\ResourceType;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Response subscriber that serializes and removes ResourceResponses' data.
 *
 * @see \Drupal\rest\EventSubscriber\ResourceResponseSubscriber
 * @internal
 *
 * This is 99% identical to:
 *
 * \Drupal\rest\EventSubscriber\ResourceResponseSubscriber
 *
 * but with a few differences:
 * 1. It has the @jsonapi.serializer_do_not_use_removal_imminent service
 *    injected instead of @serializer
 * 2. It has the @current_route_match service no longer injected
 * 3. It hardcodes the format to 'api_json'
 * 4. It adds the JsonApiDocumentTopLevelNormalizerValue value object returned
 *    by JSON API normalization to the response object.
 * 5. It flattens only to a cacheable response if the HTTP method is cacheable.
 */
class ResourceResponseSubscriber implements EventSubscriberInterface {

  /**
   * The serializer.
   *
   * @var \Symfony\Component\Serializer\SerializerInterface
   */
  protected $serializer;

  /**
   * Constructs a ResourceResponseSubscriber object.
   *
   * @param \Symfony\Component\Serializer\SerializerInterface $serializer
   *   The serializer.
   */
  public function __construct(SerializerInterface $serializer) {
    $this->serializer = $serializer;
  }

  /**
   * {@inheritdoc}
   *
   * @see \Drupal\rest\EventSubscriber\ResourceResponseSubscriber::getSubscribedEvents()
   * @see \Drupal\dynamic_page_cache\EventSubscriber\DynamicPageCacheSubscriber
   */
  public static function getSubscribedEvents() {
    // Run before the dynamic page cache subscriber (priority 100), so that
    // Dynamic Page Cache can cache flattened responses.
    $events[KernelEvents::RESPONSE][] = ['onResponse', 128];
    return $events;
  }

  /**
   * Serializes ResourceResponse responses' data, and removes that data.
   *
   * @param \Symfony\Component\HttpKernel\Event\FilterResponseEvent $event
   *   The event to process.
   */
  public function onResponse(FilterResponseEvent $event) {
    $response = $event->getResponse();
    if (!$response instanceof ResourceResponse) {
      return;
    }

    $request = $event->getRequest();
    $format = 'api_json';
    $this->renderResponseBody($request, $response, $this->serializer, $format);
    $event->setResponse($this->flattenResponse($response, $request));
  }

  /**
   * Renders a resource response body.
   *
   * Serialization can invoke rendering (e.g., generating URLs), but the
   * serialization API does not provide a mechanism to collect the
   * bubbleable metadata associated with that (e.g., language and other
   * contexts), so instead, allow those to "leak" and collect them here in
   * a render context.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param \Drupal\jsonapi\ResourceResponse $response
   *   The response from the JSON API resource.
   * @param \Symfony\Component\Serializer\SerializerInterface $serializer
   *   The serializer to use.
   * @param string|null $format
   *   The response format, or NULL in case the response does not need a format,
   *   for example for the response to a DELETE request.
   *
   * @todo Add test coverage for language negotiation contexts in
   *   https://www.drupal.org/node/2135829.
   */
  protected function renderResponseBody(Request $request, ResourceResponse $response, SerializerInterface $serializer, $format) {
    $data = $response->getResponseData();

    // If there is data to send, serialize and set it as the response body.
    if ($data !== NULL) {
      // First normalize the data. Note that error responses do not need a
      // normalization context, since there are no entities to normalize.
      // @see \Drupal\jsonapi\EventSubscriber\DefaultExceptionSubscriber::isJsonApiExceptionEvent()
      $context = !$response->isSuccessful() ? [] : static::generateContext($request);
      $jsonapi_doc_object = $serializer->normalize($data, $format, $context);
      // Having just normalized the data, we can associate its cacheability with
      // the response object.
      assert($jsonapi_doc_object instanceof JsonApiDocumentTopLevelNormalizerValue);
      $response->addCacheableDependency($jsonapi_doc_object);
      // Finally, encode the normalized data (JSON API's encoder rasterizes it
      // automatically).
      $response->setContent($serializer->encode($jsonapi_doc_object, $format));
      $response->headers->set('Content-Type', $request->getMimeType($format));
    }
  }

  /**
   * Generates a top-level JSON API normalization context.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request from which the context can be derived.
   *
   * @return array
   *   The generated context.
   */
  protected static function generateContext(Request $request) {
    $resource_type = $request->get('resource_type');
    assert($resource_type instanceof ResourceType);

    // Translate ALL the includes from the public field names to the internal.
    $includes = array_filter(explode(',', $request->query->get('include')));
    // The primary resource type for 'related' routes is different than the
    // primary resource type of individual and relationship routes and is
    // determined by the relationship field name.
    $related = $request->get('_on_relationship') ? FALSE : $request->get('related');
    $internal_includes = array_map(function ($include) use ($resource_type, $related) {
      $trimmed = trim($include);
      // If the request is a related route, prefix the path with the related
      // field name so that the path can be resolved from the base resource
      // type. Then, remove it after the path is resolved.
      $path_parts = explode('.', $related ? "{$related}.{$trimmed}" : $trimmed);
      return array_map(function ($resolved) use ($related) {
        return implode('.', $related ? array_slice($resolved, 1) : $resolved);
      }, FieldResolver::resolveInternalIncludePath($resource_type, $path_parts));
    }, $includes);
    // Flatten the resolved possible include paths.
    $internal_includes = array_reduce($internal_includes, 'array_merge', []);
    // Build the expanded context.
    $context = [
      'account' => NULL,
      'sparse_fieldset' => NULL,
      'resource_type' => $resource_type,
      'include' => $internal_includes,
    ];
    if ($request->query->get('fields')) {
      $context['sparse_fieldset'] = array_map(function ($item) {
        return explode(',', $item);
      }, $request->query->get('fields'));
    }
    return $context;
  }

  /**
   * Flattens a fully rendered resource response.
   *
   * Ensures that complex data structures in ResourceResponse::getResponseData()
   * are not serialized. Not doing this means that caching this response object
   * requires deserializing the PHP data when reading this response object from
   * cache, which can be very costly, and is unnecessary.
   *
   * @param \Drupal\jsonapi\ResourceResponse $response
   *   A fully rendered resource response.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request for which this response is generated.
   *
   * @return \Drupal\Core\Cache\CacheableResponse|\Symfony\Component\HttpFoundation\Response
   *   The flattened response.
   */
  protected static function flattenResponse(ResourceResponse $response, Request $request) {
    $final_response = ($response instanceof CacheableResponseInterface && $request->isMethodCacheable()) ? new CacheableResponse() : new Response();
    $final_response->setContent($response->getContent());
    $final_response->setStatusCode($response->getStatusCode());
    $final_response->setProtocolVersion($response->getProtocolVersion());
    $final_response->setCharset($response->getCharset());
    $final_response->headers = clone $response->headers;
    if ($final_response instanceof CacheableResponseInterface) {
      $final_response->addCacheableDependency($response->getCacheableMetadata());
    }
    return $final_response;
  }

}
