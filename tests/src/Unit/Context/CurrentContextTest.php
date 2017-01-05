<?php

namespace Drupal\Tests\jsonapi\Unit\Context;

use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\jsonapi\Context\CurrentContext;
use Drupal\jsonapi\Configuration\ResourceConfig;
use Drupal\jsonapi\Configuration\ResourceManagerInterface;
use Drupal\jsonapi\Routing\Param\Filter;
use Drupal\jsonapi\Routing\Param\Sort;
use Drupal\jsonapi\Routing\Param\OffsetPage;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

use Symfony\Cmf\Component\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Class CurrentContextTest.
 *
 * @package \Drupal\jsonapi\Test\Unit
 *
 * @coversDefaultClass \Drupal\jsonapi\Context\CurrentContext
 *
 * @group jsonapi
 */
class CurrentContextTest extends UnitTestCase {

  /**
   * A mock for the current route.
   *
   * @var \Symfony\Component\Routing\Route
   */
  protected $currentRoute;

  /**
   * A mock for the current route.
   *
   * @var \Drupal\jsonapi\Configuration\ResourceManagerInterface
   */
  protected $resourceManager;

  /**
   * A mock for the entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $fieldManager;

  /**
   * A request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * @var \Drupal\Core\Routing\StackedRouteMatchInterface
   */
  protected $routeMatcher;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    // Create a mock for the entity field manager.
    $this->fieldManager = $this->prophesize(EntityFieldManagerInterface::CLASS)->reveal();

    // Create a mock for the current route match.
    $this->currentRoute = new Route(
      '/jsonapi/articles',
      [],
      ['_entity_type' => 'node', '_bundle' => 'article']
    );

    // Create a mock for the ResourceManager service.
    $resource_prophecy = $this->prophesize(ResourceManagerInterface::CLASS);
    $resource_config = new ResourceConfig('node', 'article', NodeInterface::class);
    $resource_prophecy->get('node', 'article')->willReturn($resource_config);
    $this->resourceManager = $resource_prophecy->reveal();

    $this->requestStack = new RequestStack();
    $this->requestStack->push(new Request([], [], [
      '_json_api_params' => [
        'filter' => new Filter([], 'node', $this->fieldManager),
        'sort' => new Sort([]),
        'page' => new OffsetPage([]),
        // 'include' => new IncludeParam([]),
        // 'fields' => new Fields([]),.
      ],
      RouteObjectInterface::ROUTE_OBJECT => $this->currentRoute,
    ]));

    $this->routeMatcher = new CurrentRouteMatch($this->requestStack);
  }

  /**
   * @covers ::getResourceConfig
   */
  public function testGetResourceConfig() {
    $request_context = new CurrentContext($this->resourceManager, $this->requestStack, $this->routeMatcher);
    $resource_config = $request_context->getResourceConfig();

    $this->assertEquals(
      $this->resourceManager->get('node', 'article'),
      $resource_config
    );
  }

  /**
   * @covers ::getResourceManager
   */
  public function testGetResourceManager() {
    $request_context = new CurrentContext($this->resourceManager, $this->requestStack, $this->routeMatcher);
    $this->assertEquals(
      $this->resourceManager,
      $request_context->getResourceManager()
    );
  }

  /**
   * @covers ::getJsonApiParameter
   */
  public function testGetJsonApiParameter() {
    $request_context = new CurrentContext($this->resourceManager, $this->requestStack, $this->routeMatcher);

    $expected = new Sort([]);
    $actual = $request_context->getJsonApiParameter('sort');

    $this->assertEquals($expected, $actual);
  }

  /**
   * @covers ::hasExtension
   */
  public function testHasExtensionWithExistingExtension() {
    $request = new Request();
    $request->headers->set('Content-Type', 'application/vnd.api+json; ext="ext1,ext2"');
    $this->requestStack->push($request);
    $request_context = new CurrentContext($this->resourceManager, $this->requestStack, $this->routeMatcher);

    $this->assertTrue($request_context->hasExtension('ext1'));
    $this->assertTrue($request_context->hasExtension('ext2'));
  }

  /**
   * @covers ::getExtensions
   */
  public function testGetExtensions() {
    $request = new Request();
    $request->headers->set('Content-Type', 'application/vnd.api+json; ext="ext1,ext2"');
    $this->requestStack->push($request);
    $request_context = new CurrentContext($this->resourceManager, $this->requestStack, $this->routeMatcher);

    $this->assertEquals(['ext1', 'ext2'], $request_context->getExtensions());
  }

  /**
   * @covers ::hasExtension
   */
  public function testHasExtensionWithNotExistingExtension() {
    $request = new Request();
    $request->headers->set('Content-Type', 'application/vnd.api+json;');
    $this->requestStack->push($request);
    $request_context = new CurrentContext($this->resourceManager, $this->requestStack, $this->routeMatcher);
    $this->assertFalse($request_context->hasExtension('ext1'));
    $this->assertFalse($request_context->hasExtension('ext2'));
  }

}
