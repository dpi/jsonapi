<?php

namespace Drupal\jsonapi_test_computed_field;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;

/**
 * Item list class for jsonapi_test_computed_field.
 */
class JsonApiComputedFieldItemList extends FieldItemList implements CacheableDependencyInterface {

  use ComputedItemListTrait;
  use CacheableDependencyTrait;

  /**
   * {@inheritdoc}
   */
  protected function computeValue() {
    $this->cacheContexts[] = 'user';
    $this->cacheTags[] = 'field:jsonapi_test_computed_field';
    $this->cacheMaxAge = 8000;
    $this->list[0] = $this->createItem(0, 'jsonapi_test_computed_field');
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    $this->ensureComputedValue();
    return $this->cacheContexts;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $this->ensureComputedValue();
    return $this->cacheTags;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    $this->ensureComputedValue();
    return $this->cacheMaxAge;
  }

}
