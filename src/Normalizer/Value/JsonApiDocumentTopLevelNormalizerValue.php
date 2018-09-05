<?php

namespace Drupal\jsonapi\Normalizer\Value;

use Drupal\Component\Assertion\Inspector;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;
use Drupal\jsonapi\JsonApiSpec;

/**
 * Helps normalize the top level document in compliance with the JSON API spec.
 *
 * @internal
 */
class JsonApiDocumentTopLevelNormalizerValue implements ValueExtractorInterface, RefinableCacheableDependencyInterface {

  use RefinableCacheableDependencyTrait;

  const RESOURCE_OBJECT_DOCUMENT = 'resource_object_document';

  const ERROR_DOCUMENT = 'error_document';

  /**
   * The values.
   *
   * @var array
   */
  protected $values;

  /**
   * The type of document that this instance is..
   *
   * The spec says the top-level `data` and `errors` members MUST NOT coexist,
   * therefore, a document can either be a "resource object document" or an
   * "error document".
   *
   * @var string
   *
   * @see http://jsonapi.org/format/#document-top-level
   */
  protected $documentType;

  /**
   * The includes.
   *
   * @var array
   */
  protected $includes;

  /**
   * The links. Keys are link relation types.
   *
   * @var string[]
   */
  protected $links;

  /**
   * The cardinality of the document's primary data.
   *
   * @var int
   */
  protected $cardinality;

  /**
   * The metadata.
   *
   * @var array
   */
  protected $meta;

  /**
   * Instantiates a JsonApiDocumentTopLevelNormalizerValue object.
   *
   * @param string $document_type
   *   The document's type. Use either the self::RESOURCE_OBJECT_DOCUMENT or
   *   self::ERROR_DOCUMENT class constant.
   * @param \Drupal\Core\Entity\EntityInterface[] $values
   *   The data to normalize. It can be either a straight up entity or a
   *   collection of entities.
   * @param string[] $links
   *   The URLs to which to link.
   * @param int|bool $cardinality
   *   The cardinality of the document's primary data. -1 for unlimited
   *   cardinality. For example, an individual resource would have a cardinality
   *   of 1. A related resource would have a cardinality of -1 for a to-many
   *   relationship, but a cardinality of 1 for a to-one relationship. Required
   *   for resource object documents.
   * @param array $meta
   *   (optional) The metadata to normalize.
   */
  public function __construct($document_type, array $values, array $links, $cardinality = FALSE, array $meta = []) {
    assert(in_array($document_type, [static::RESOURCE_OBJECT_DOCUMENT, static::ERROR_DOCUMENT]));
    assert(is_int($cardinality) || $document_type === static::ERROR_DOCUMENT);
    $this->documentType = $document_type;
    $this->values = $values;
    array_walk($values, [$this, 'addCacheableDependency']);

    if (!$this->isErrorDocument()) {
      // @todo Make this unconditional in https://www.drupal.org/project/jsonapi/issues/2965056.
      if (!\Drupal::requestStack()->getCurrentRequest()->get('_on_relationship')) {
        // Make sure that different sparse fieldsets are cached differently.
        $this->addCacheContexts(array_map(function ($query_parameter_name) {
          return sprintf('url.query_args:%s', $query_parameter_name);
        }, JsonApiSpec::getReservedQueryParameters()));
      }
      // Every JSON API document contains absolute URLs.
      $this->addCacheContexts(['url.site']);

      $this->cardinality = $cardinality;

      assert(Inspector::assertAllStrings($links));
      $this->links = $links;

      $this->meta = $meta;

      // Get an array of arrays of includes.
      $this->includes = array_map(function ($value) {
        return $value->getIncludes();
      }, $values);
      // Flatten the includes.
      $this->includes = array_reduce($this->includes, function ($carry, $includes) {
        array_walk($includes, [$this, 'addCacheableDependency']);
        return array_merge($carry, $includes);
      }, []);
      // Filter the empty values.
      $this->includes = array_filter($this->includes);
    }
    $this->documentType = $document_type;
  }

  /**
   * {@inheritdoc}
   */
  public function rasterizeValue() {
    // Determine which of the two mutually exclusive top-level document members
    // should be used.
    $mutually_exclusive_member = $this->isErrorDocument() ? 'errors' : 'data';
    $rasterized = [
      $mutually_exclusive_member => [],
      'jsonapi' => [
        'version' => JsonApiSpec::SUPPORTED_SPECIFICATION_VERSION,
        'meta' => [
          'links' => ['self' => JsonApiSpec::SUPPORTED_SPECIFICATION_PERMALINK],
        ],
      ],
    ];
    if (!empty($this->meta)) {
      $rasterized['meta'] = $this->meta;
    }

    if ($this->isErrorDocument()) {
      foreach ($this->values as $normalized_exception) {
        $rasterized['errors'] = array_merge($rasterized['errors'], $normalized_exception->rasterizeValue());
      }
      return $rasterized;
    }

    $rasterized['links'] = $this->links;

    $uuid_generator = \Drupal::service('uuid');
    foreach ($this->values as $normalizer_value) {
      if ($normalizer_value instanceof HttpExceptionNormalizerValue) {
        if (!isset($rasterized['meta']['omitted'])) {
          $rasterized['meta']['omitted'] = [
            'detail' => 'Some resources have been omitted because of insufficient authorization.',
            'links' => [
              'help' => 'https://www.drupal.org/docs/8/modules/json-api/filtering#filters-access-control',
            ],
          ];
        }
        // Add the errors to the pre-existing errors.
        foreach ($normalizer_value->rasterizeValue() as $error) {
          // JSON API links cannot be arrays and the spec generally favors link
          // relation types as keys. 'item' is the right link relation type, but
          // we need multiple values. So, we're just generating a meaningless,
          // random value to use as a unique key. We don't use the UUID directly
          // so as not to imply that it's an identifier for the error.
          $link_key = 'item:' . substr(str_replace('-', '', $uuid_generator->generate()), 0, 7);
          $rasterized['meta']['omitted']['links'][$link_key] = [
            'href' => $error['links']['via'],
            'meta' => [
              'rel' => 'item',
              'detail' => $error['detail'],
            ],
          ];
        }
      }
      else {
        $rasterized_value = $normalizer_value->rasterizeValue();
        if (array_key_exists('data', $rasterized_value) && array_key_exists('links', $rasterized_value)) {
          $rasterized['data'][] = $rasterized_value['data'];
          $rasterized['links'] = NestedArray::mergeDeep($rasterized['links'], $rasterized_value['links']);
        }
        else {
          $rasterized['data'][] = $rasterized_value;
        }
      }
    }
    // Deal with the single entity case.
    if ($this->cardinality !== 1) {
      $rasterized['data'] = array_filter($rasterized['data']);
    }
    else {
      $rasterized['data'] = empty($rasterized['data']) ? NULL : reset($rasterized['data']);
    }

    // This is the top-level JSON API document, therefore the rasterized value
    // must include the rasterized includes: there is no further level to bubble
    // them to!
    $included = array_filter($this->rasterizeIncludes());
    if (!empty($included)) {
      foreach ($included as $included_item) {
        if ($included_item['data'] === FALSE) {
          unset($included_item['data']);
          $rasterized = NestedArray::mergeDeep($rasterized, $included_item);
        }
        else {
          if ($included_item['data']) {
            $rasterized['included'][] = $included_item['data'];
          }
          if (!empty($included_item['meta']['omitted'])) {
            $rasterized['meta']['omitted']['detail'] = 'Some resources have been omitted because of insufficient authorization.';
            foreach ($included_item['meta']['omitted']['links'] as $link_key => $link) {
              $rasterized['meta']['omitted']['links'][$link_key] = $link;
            }
          }
        }
      }
    }

    if (empty($rasterized['links'])) {
      unset($rasterized['links']);
    }

    return $rasterized;
  }

  /**
   * Gets a flattened list of includes in all the chain.
   *
   * @return \Drupal\jsonapi\Normalizer\Value\EntityNormalizerValue[]
   *   The array of included relationships.
   */
  public function getIncludes() {
    $nested_includes = array_map(function ($include) {
      return $include->getIncludes();
    }, $this->includes);
    $includes = array_reduce(array_filter($nested_includes), function ($carry, $item) {
      return array_merge($carry, $item);
    }, $this->includes);
    // Make sure we don't output duplicate includes.
    return array_values(array_reduce($includes, function ($unique_includes, $include) {
      $rasterized_include = $include->rasterizeValue();

      if (empty($rasterized_include['data'])) {
        $unique_includes[] = $include;
      }
      else {
        $unique_key = $rasterized_include['data']['type'] . ':' . $rasterized_include['data']['id'];
        $unique_includes[$unique_key] = $include;
      }
      return $unique_includes;
    }, []));
  }

  /**
   * {@inheritdoc}
   */
  public function rasterizeIncludes() {
    // First gather all the includes in the chain.
    return array_map(function ($include) {
      return $include->rasterizeValue();
    }, $this->getIncludes());
  }

  /**
   * Whether this is an errors document or not.
   *
   * @return bool
   *   TRUE if the document contains top-level errors, FALSE otherwise.
   */
  protected function isErrorDocument() {
    return $this->documentType === static::ERROR_DOCUMENT;
  }

}
