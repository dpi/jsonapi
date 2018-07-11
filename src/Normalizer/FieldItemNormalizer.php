<?php

namespace Drupal\jsonapi\Normalizer;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\TypedData\FieldItemDataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataInternalPropertiesHelper;
use Drupal\jsonapi\Normalizer\Value\FieldItemNormalizerValue;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Converts the Drupal field item object to a JSON API array structure.
 *
 * @internal
 */
class FieldItemNormalizer extends NormalizerBase implements DenormalizerInterface {

  /**
   * The interface or class that this Normalizer supports.
   *
   * @var string
   */
  protected $supportedInterfaceOrClass = FieldItemInterface::class;

  /**
   * The formats that the Normalizer can handle.
   *
   * @var array
   */
  protected $formats = ['api_json'];

  /**
   * {@inheritdoc}
   *
   * This normalizer leaves JSON API normalizer land and enters the land of
   * Drupal core's serialization system. That system was never designed with
   * cacheability in mind, and hence bubbles cacheability out of band. This must
   * catch it, and pass it to the value object that JSON API uses.
   */
  public function normalize($field_item, $format = NULL, array $context = []) {
    /** @var \Drupal\Core\TypedData\TypedDataInterface $property */
    $values = [];
    // We normalize each individual property, so each can do their own casting,
    // if needed.
    $field_item = TypedDataInternalPropertiesHelper::getNonInternalProperties($field_item);

    // @todo Use the constant \Drupal\serialization\Normalizer\CacheableNormalizerInterface::SERIALIZATION_CONTEXT_CACHEABILITY instead of the 'cacheability' string when JSON API requires Drupal 8.5 or newer.
    $context['cacheability'] = new CacheableMetadata();

    foreach ($field_item as $property_name => $property) {
      $values[$property_name] = $this->serializer->normalize($property, $format, $context);
    }

    if (isset($context['langcode'])) {
      $values['lang'] = $context['langcode'];
    }
    // @todo Use the constant \Drupal\serialization\Normalizer\CacheableNormalizerInterface::SERIALIZATION_CONTEXT_CACHEABILITY instead of the 'cacheability' string when JSON API requires Drupal 8.5 or newer.
    $value = new FieldItemNormalizerValue($values, $context['cacheability']);
    unset($context['cacheability']);
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalize($data, $class, $format = NULL, array $context = []) {
    $item_definition = $context['field_definition']->getItemDefinition();
    assert($item_definition instanceof FieldItemDataDefinitionInterface);

    $property_definitions = $item_definition->getPropertyDefinitions();

    // Because e.g. the 'bundle' entity key field requires field values to not
    // be expanded to an array of all properties, we special-case single-value
    // properties.
    if (!is_array($data)) {
      $property_value = $data;
      $property_value_class = $property_definitions[$item_definition->getMainPropertyName()]->getClass();
      return $this->serializer->supportsDenormalization($property_value, $property_value_class, $format, $context)
        ? $this->serializer->denormalize($property_value, $property_value_class, $format, $context)
        : $property_value;
    }

    $data_internal = [];
    foreach ($data as $property_name => $property_value) {
      $property_value_class = $property_definitions[$property_name]->getClass();
      $data_internal[$property_name] = $this->serializer->supportsDenormalization($property_value, $property_value_class, $format, $context)
        ? $this->serializer->denormalize($property_value, $property_value_class, $format, $context)
        : $property_value;
    }

    return $data_internal;
  }

}
