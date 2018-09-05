<?php

namespace Drupal\Tests\jsonapi\Unit\Normalizer\Value;

use Drupal\Component\DependencyInjection\Container;
use Drupal\jsonapi\Normalizer\Value\JsonApiDocumentTopLevelNormalizerValue;
use Drupal\jsonapi\Normalizer\Value\RelationshipNormalizerValue;
use Drupal\jsonapi\Normalizer\Value\FieldNormalizerValueInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @coversDefaultClass \Drupal\jsonapi\Normalizer\Value\JsonApiDocumentTopLevelNormalizerValue
 * @group jsonapi
 *
 * @internal
 */
class JsonApiDocumentTopLevelNormalizerValueTest extends UnitTestCase {

  /**
   * The JsonApiDocumentTopLevelNormalizerValue object.
   *
   * @var \Drupal\jsonapi\Normalizer\Value\JsonApiDocumentTopLevelNormalizerValue
   */
  protected $object;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $cache_contexts_manager = $this->getMockBuilder('Drupal\Core\Cache\Context\CacheContextsManager')
      ->disableOriginalConstructor()
      ->getMock();
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $request_stack = new RequestStack();
    $request_stack->push(Request::create('/'));
    $container = new Container();
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    $container->set('request_stack', $request_stack);
    \Drupal::setContainer($container);

    $field1 = $this->prophesize(FieldNormalizerValueInterface::class);
    $field1->getIncludes()->willReturn([]);
    $field1->getPropertyType()->willReturn('attributes');
    $field1->rasterizeValue()->willReturn('dummy_title');
    $field2 = $this->prophesize(RelationshipNormalizerValue::class);
    $field2->getPropertyType()->willReturn('relationships');
    $field2->rasterizeValue()->willReturn(['data' => ['type' => 'node', 'id' => 2]]);
    $included[] = $this->prophesize(JsonApiDocumentTopLevelNormalizerValue::class);
    $included[0]->getIncludes()->willReturn([]);
    $included[0]->rasterizeValue()->willReturn([
      'data' => [
        'type' => 'node',
        'id' => 3,
        'attributes' => ['body' => 'dummy_body1'],
      ],
    ]);
    $included[0]->getCacheContexts()->willReturn(['lorem:ipsum']);
    // Type & id duplicated in purpose.
    $included[] = $this->prophesize(JsonApiDocumentTopLevelNormalizerValue::class);
    $included[1]->getIncludes()->willReturn([]);
    $included[1]->rasterizeValue()->willReturn([
      'data' => [
        'type' => 'node',
        'id' => 3,
        'attributes' => ['body' => 'dummy_body2'],
      ],
    ]);
    $included[] = $this->prophesize(JsonApiDocumentTopLevelNormalizerValue::class);
    $included[2]->getIncludes()->willReturn([]);
    $included[2]->rasterizeValue()->willReturn([
      'data' => [
        'type' => 'node',
        'id' => 4,
        'attributes' => ['body' => 'dummy_body3'],
      ],
    ]);
    $field2->getIncludes()->willReturn(array_map(function ($included_item) {
      return $included_item->reveal();
    }, $included));
    $this->object = $this->getMockBuilder(JsonApiDocumentTopLevelNormalizerValue::class)
      ->setMethods(['addCacheableDependency'])
      ->setConstructorArgs([
        JsonApiDocumentTopLevelNormalizerValue::RESOURCE_OBJECT_DOCUMENT,
        ['title' => $field1->reveal(), 'field_related' => $field2->reveal()],
        [],
        1,
      ])
      ->getMock();
    $this->object->method('addCacheableDependency');
  }

  /**
   * @covers ::getIncludes
   */
  public function testGetIncludes() {
    $includes = $this->object->getIncludes();
    $includes = array_filter($includes, function ($included) {
      return $included instanceof JsonApiDocumentTopLevelNormalizerValue;
    });
    $this->assertCount(2, $includes);
  }

}
