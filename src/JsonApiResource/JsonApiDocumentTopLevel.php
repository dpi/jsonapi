<?php

namespace Drupal\jsonapi\JsonApiResource;

use Drupal\Component\Assertion\Inspector;

/**
 * Represents a JSON API document's "top level".
 *
 * @see http://jsonapi.org/format/#document-top-level
 *
 * @internal
 *
 * @todo Add support for the missing optional members: 'jsonapi' and 'included' or document why not.
 */
class JsonApiDocumentTopLevel {

  /**
   * The data to normalize.
   *
   * @var \Drupal\Core\Entity\EntityInterface|\Drupal\jsonapi\JsonApiResource\EntityCollection|\Drupal\jsonapi\LabelOnlyEntity|\Drupal\jsonapi\JsonApiResource\ErrorCollection
   */
  protected $data;

  /**
   * The metadata to normalize.
   *
   * @var array
   */
  protected $meta;

  /**
   * The links.
   *
   * @var string[]
   */
  protected $links;

  /**
   * Instantiates a JsonApiDocumentTopLevel object.
   *
   * @param \Drupal\Core\Entity\EntityInterface|\Drupal\jsonapi\JsonApiResource\EntityCollection|\Drupal\jsonapi\LabelOnlyEntity|\Drupal\jsonapi\JsonApiResource\ErrorCollection $data
   *   The data to normalize. It can be either a straight up entity or a
   *   collection of entities.
   * @param string[] $links
   *   The URLs to which the top-level document should link. Keys are strings.
   *   Values are URLs.
   * @param array $meta
   *   (optional) The metadata to normalize.
   */
  public function __construct($data, array $links, array $meta = []) {
    assert(Inspector::assertAllStrings($links));

    $this->data = $data;
    $this->links = $links;
    $this->meta = $meta;
  }

  /**
   * Gets the data.
   *
   * @return \Drupal\Core\Entity\EntityInterface|\Drupal\jsonapi\JsonApiResource\EntityCollection|\Drupal\jsonapi\LabelOnlyEntity|\Drupal\jsonapi\JsonApiResource\ErrorCollection
   *   The data.
   */
  public function getData() {
    return $this->data;
  }

  /**
   * Gets the links.
   *
   * @return string[]
   *   The top-level links.
   */
  public function getLinks() {
    return $this->links;
  }

  /**
   * Gets the metadata.
   *
   * @return array
   *   The metadata.
   */
  public function getMeta() {
    return $this->meta;
  }

}
