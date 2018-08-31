<?php

namespace Drupal\jsonapi\JsonApiResource;

use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Entity\EntityInterface;
use Drupal\jsonapi\Exception\EntityAccessDeniedHttpException;
use Drupal\jsonapi\LabelOnlyEntity;

/**
 * Wrapper to normalize collections with multiple entities.
 *
 * @internal
 */
class EntityCollection implements \IteratorAggregate, \Countable {

  /**
   * Entity storage.
   *
   * @var \Drupal\Core\Entity\EntityInterface[]
   */
  protected $entities;

  /**
   * The number of resources permitted in this collection.
   *
   * @var int
   */
  protected $cardinality;

  /**
   * Holds a boolean indicating if there is a next page.
   *
   * @var bool
   */
  protected $hasNextPage;

  /**
   * Holds the total count of entities.
   *
   * @var int
   */
  protected $count;

  /**
   * Instantiates a EntityCollection object.
   *
   * @param \Drupal\Core\Entity\EntityInterface|null[]|false[] $resources
   *   The resources for the collection.
   * @param int $cardinality
   *   The number of resources that this collection may contain. Related
   *   resource collections may handle both to-one or to-many relationships. A
   *   to-one relationship should have a cardinality of 1. Use -1 for unlimited
   *   cardinality.
   */
  public function __construct(array $resources, $cardinality = -1) {
    assert(Inspector::assertAll(function ($entity) {
        return $entity === NULL
        || $entity === FALSE
        || $entity instanceof EntityInterface
        || $entity instanceof LabelOnlyEntity
        || $entity instanceof EntityAccessDeniedHttpException;
    }, $resources));
    assert($cardinality >= -1 && $cardinality !== 0, 'Cardinality must be -1 for unlimited cardinality or a positive integer.');
    assert($cardinality === -1 || count($resources) <= $cardinality, 'If cardinality is not unlimited, the number of given resources must not exceed the cardinality of the collection.');
    $this->entities = $resources;
    $this->cardinality = $cardinality;
  }

  /**
   * Returns an iterator for entities.
   *
   * @return \ArrayIterator
   *   An \ArrayIterator instance
   */
  public function getIterator() {
    return new \ArrayIterator($this->entities);
  }

  /**
   * Returns the number of entities.
   *
   * @return int
   *   The number of parameters
   */
  public function count() {
    return count($this->entities);
  }

  /**
   * {@inheritdoc}
   */
  public function getTotalCount() {
    return $this->count;
  }

  /**
   * {@inheritdoc}
   */
  public function setTotalCount($count) {
    $this->count = $count;
  }

  /**
   * Returns the collection as an array.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   The array of entities.
   */
  public function toArray() {
    return $this->entities;
  }

  /**
   * Checks if there is a next page in the collection.
   *
   * @return bool
   *   TRUE if the collection has a next page.
   */
  public function hasNextPage() {
    return (bool) $this->hasNextPage;
  }

  /**
   * Sets the has next page flag.
   *
   * Once the collection query has been executed and we build the entity
   * collection, we now if there will be a next page with extra entities.
   *
   * @param bool $has_next_page
   *   TRUE if the collection has a next page.
   */
  public function setHasNextPage($has_next_page) {
    $this->hasNextPage = (bool) $has_next_page;
  }

  /**
   * Gets the cardinality of this collection.
   *
   * @return int
   *   The cardinality of the resource collection. -1 for unlimited cardinality.
   */
  public function getCardinality() {
    return $this->cardinality;
  }

}
