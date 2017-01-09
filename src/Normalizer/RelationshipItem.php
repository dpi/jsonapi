<?php

namespace Drupal\jsonapi\Normalizer;

use Drupal\Core\Entity\EntityInterface;
use Drupal\jsonapi\ResourceType\ResourceTypeRepository;

/**
 * @internal
 */
class RelationshipItem {

  /**
   * The target key name.
   *
   * @param string
   */
  protected $targetKey = 'target_id';

  /**
   * The target entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface
   */
  protected $targetEntity;

  /**
   * The target JSON API resource type.
   *
   * @param \Drupal\jsonapi\ResourceType\ResourceType
   */
  protected $targetResourceType;

  /**
   * The parent relationship.
   *
   * @var \Drupal\jsonapi\Normalizer\Relationship
   */
  protected $parent;

  /**
   * Relationship item constructor.
   *
   * @param \Drupal\jsonapi\ResourceType\ResourceTypeRepository $resource_type_repository
   *   The JSON API resource type repository.
   * @param \Drupal\Core\Entity\EntityInterface $target_entity
   *   The entity this relationship points to.
   * @param \Drupal\jsonapi\Normalizer\Relationship
   *   The parent of this item.
   * @param string $target_key
   *   The key name of the target relationship.
   */
  public function __construct(ResourceTypeRepository $resource_type_repository, EntityInterface $target_entity, Relationship $parent, $target_key = 'target_id') {
    $this->targetResourceType = $resource_type_repository->get(
      $target_entity->getEntityTypeId(),
      $target_entity->bundle()
    );
    $this->targetKey = $target_key;
    $this->targetEntity = $target_entity;
    $this->parent = $parent;
  }

  /**
   * Gets the target entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   */
  public function getTargetEntity() {
    return $this->targetEntity;
  }

  /**
   * Gets the targetResourceConfig.
   *
   * @return mixed
   */
  public function getTargetResourceType() {
    return $this->targetResourceType;
  }

  /**
   * Gets the relationship value.
   *
   * Defaults to the entity ID.
   *
   * @return string
   */
  public function getValue() {
    return [$this->targetKey => $this->getTargetEntity()->uuid()];
  }

  /**
   * Gets the relationship object that contains this relationship item.
   *
   * @return \Drupal\jsonapi\Normalizer\Relationship
   */
  public function getParent() {
    return $this->parent;
  }

}
