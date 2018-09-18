<?php

namespace Drupal\jsonapi\Normalizer\Value;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;
use Drupal\Core\Entity\EntityInterface;

/**
 * Helps normalize entities in compliance with the JSON API spec.
 *
 * @internal
 */
class EntityNormalizerValue implements ValueExtractorInterface, CacheableDependencyInterface {

  use CacheableDependencyTrait;
  use CacheableDependenciesMergerTrait;

  /**
   * The values.
   *
   * @var array
   */
  protected $values;

  /**
   * The resource path.
   *
   * @var array
   */
  protected $context;

  /**
   * The resource entity.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $entity;

  /**
   * The link manager.
   *
   * @var \Drupal\jsonapi\LinkManager\LinkManager
   */
  protected $linkManager;

  /**
   * Instantiate a EntityNormalizerValue object.
   *
   * @param FieldNormalizerValueInterface[] $values
   *   The normalized result.
   * @param array $context
   *   The context for the normalizer.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param array $link_context
   *   All the objects and variables needed to generate the links for this
   *   relationship.
   */
  public function __construct(array $values, array $context, EntityInterface $entity, array $link_context) {
    $this->setCacheability(static::mergeCacheableDependencies(array_merge([$entity], $values)));

    $this->values = array_filter($values, function ($value) {
      return !($value instanceof NullFieldNormalizerValue || $value instanceof IncludeOnlyRelationshipNormalizerValue);
    });
    $this->context = $context;
    $this->entity = $entity;
    $this->linkManager = $link_context['link_manager'];
  }

  /**
   * {@inheritdoc}
   */
  public function rasterizeValue() {
    // Create the array of normalized fields, starting with the URI.
    $rasterized = [
      'type' => $this->context['resource_type']->getTypeName(),
      'id' => $this->entity->uuid(),
      'attributes' => [],
      'relationships' => [],
    ];
    $rasterized['links'] = [
      'self' => $this->linkManager->getEntityLink(
        $rasterized['id'],
        $this->context['resource_type'],
        [],
        'individual'
      ),
    ];

    foreach ($this->getValues() as $field_name => $normalizer_value) {
      $rasterized[$normalizer_value->getPropertyType()][$field_name] = $normalizer_value->rasterizeValue();
    }
    return array_filter($rasterized);
  }

  /**
   * Gets the values.
   *
   * @return mixed
   *   The values.
   */
  public function getValues() {
    return $this->values;
  }

}
