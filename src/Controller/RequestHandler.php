<?php

namespace Drupal\jsonapi\Controller;

use Drupal\jsonapi\ResourceType\ResourceType;
use Symfony\Component\HttpFoundation\Request;

/**
 * Acts as request forwarder for \Drupal\jsonapi\Controller\EntityResource.
 *
 * @internal
 */
class RequestHandler {

  /**
   * The JSON API entity resource controller.
   *
   * @var \Drupal\jsonapi\Controller\EntityResource
   */
  protected $entityResource;

  /**
   * Creates a new RequestHandler instance.
   *
   * @param \Drupal\jsonapi\Controller\EntityResource $entity_resource
   *   The JSON API entity resource controller.
   */
  public function __construct(EntityResource $entity_resource) {
    $this->entityResource = $entity_resource;
  }

  /**
   * Handles a JSON API request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   * @param \Drupal\jsonapi\ResourceType\ResourceType $resource_type
   *   The JSON API resource type for the current request.
   *
   * @return \Drupal\Core\Cache\CacheableResponseInterface
   *   The response object.
   */
  public function handle(Request $request, ResourceType $resource_type) {
    // Determine the request parameters that should be passed to the resource
    // plugin.
    $parameters = ['resource_type' => $resource_type];

    if ($entity = $request->get('entity')) {
      $parameters['entity'] = $entity;
    }

    if ($related = $request->get('related')) {
      $parameters['related'] = $related;
    }

    // Invoke the operation on the resource plugin.
    $action = $this->action($request, $resource_type);

    // Add the deserialized entity or relationship if available.
    if ($request->get('serialization_class', FALSE) && !$request->isMethodCacheable()) {
      $parameters[] = $request->get('deserialized');
    }

    // Add the request as a parameter.
    $parameters[] = $request;

    return call_user_func_array([$this->entityResource, $action], $parameters);
  }

  /**
   * Gets the method to execute in the entity resource.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request being handled.
   * @param \Drupal\jsonapi\ResourceType\ResourceType $resource_type
   *   The JSON API resource type for the current request.
   *
   * @return string
   *   The method to execute in the EntityResource.
   */
  protected function action(Request $request, ResourceType $resource_type) {
    $on_relationship = (bool) $request->get('_on_relationship');
    switch (strtolower($request->getMethod())) {
      case 'head':
      case 'get':
        if ($on_relationship) {
          return 'getRelationship';
        }
        elseif ($request->get('related')) {
          return 'getRelated';
        }
        return $request->get('entity') ? 'getIndividual' : 'getCollection';

      case 'post':
        return ($on_relationship) ? 'addToRelationshipData' : 'createIndividual';

      case 'patch':
        return ($on_relationship) ? 'replaceRelationshipData' : 'patchIndividual';

      case 'delete':
        return ($on_relationship) ? 'removeFromRelationshipData' : 'deleteIndividual';
    }
  }

}
