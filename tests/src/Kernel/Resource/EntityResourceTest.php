<?php

namespace Drupal\Tests\jsonapi\Kernel\Resource;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\jsonapi\EntityCollection;
use Drupal\jsonapi\Resource\DocumentWrapper;
use Drupal\jsonapi\Resource\EntityResource;
use Drupal\jsonapi\Routing\Param\Filter;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Class EntityResourceTest.
 *
 * @package Drupal\Tests\jsonapi\Kernel\Resource
 *
 * @coversDefaultClass \Drupal\jsonapi\Resource\EntityResource
 *
 * @group jsonapi
 */
class EntityResourceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'node',
    'field',
    'jsonapi',
    'rest',
    'serialization',
    'system',
    'user',
  ];

  /**
   * The entity resource under test.
   *
   * @var \Drupal\jsonapi\Resource\EntityResource
   */
  protected $entityResource;

  /**
   * The user.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $user;

  /**
   * The node.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected $node;

  /**
   * The other node.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected $node2;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    // Add the entity schemas.
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    // Add the additional table schemas.
    $this->installSchema('system', ['sequences']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    NodeType::create([
      'type' => 'lorem',
    ])->save();
    $type = NodeType::create([
      'type' => 'article',
    ]);
    $type->save();
    $this->user = User::create([
      'name' => 'user1',
      'mail' => 'user@localhost',
      'status' => 1,
    ]);
    $this->createEntityReferenceField('node', 'article', 'field_relationships', 'Relationship', 'node', 'default', ['target_bundles' => ['article']], FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $this->user->save();
    $this->node = Node::create([
      'title' => 'dummy_title',
      'type' => 'article',
      'uid' => $this->user->id(),
    ]);
    $this->node->save();

    $this->node2 = Node::create([
      'type' => 'article',
      'title' => 'Another test node',
      'uid' => $this->user->id(),
    ]);
    $this->node2->save();

    // Give anonymous users permission to view user profiles, so that we can
    // verify the cache tags of cached versions of user profile pages.
    Role::create([
      'id' => RoleInterface::ANONYMOUS_ID,
      'permissions' => [
        'access user profiles',
        'access content',
      ],
    ])->save();

    $current_context = $this->container->get('jsonapi.current_context');
    $route = $this->prophesize(Route::class);
    $route->getRequirement('_entity_type')->willReturn('node');
    $route->getRequirement('_bundle')->willReturn('article');
    $current_context->setCurrentRoute($route->reveal());
    $this->entityResource = new EntityResource(
      $this->container->get('jsonapi.resource.manager')->get('node', 'article'),
      $this->container->get('entity_type.manager'),
      $this->container->get('jsonapi.query_builder'),
      $this->container->get('entity_field.manager'),
      $current_context
    );

  }


  /**
   * @covers ::getIndividual
   */
  public function testGetIndividual() {
    $response = $this->entityResource->getIndividual($this->node);
    $this->assertInstanceOf(DocumentWrapper::class, $response->getResponseData());
    $this->assertEquals(1, $response->getResponseData()->getData()->id());
    $this->assertSame('node:1', $response->getCacheableMetadata()->getCacheTags()[0]);
  }

  /**
   * @covers ::getIndividual
   * @expectedException \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   */
  public function testGetIndividualDenied() {
    $role = Role::load(RoleInterface::ANONYMOUS_ID);
    $role->revokePermission('access content');
    $role->save();
    $this->entityResource->getIndividual($this->node);
  }

  /**
   * @covers ::getCollection
   */
  public function testGetCollection() {
    // Fake the request.
    $request = $this->prophesize(Request::class);
    $params = $this->prophesize(ParameterBag::class);
    $params->get('_route_params')->willReturn(['_json_api_params' => []]);
    $request->attributes = $params->reveal();
    $params->get('_json_api_params')->willReturn([]);

    // Get the response.
    $response = $this->entityResource->getCollection($request->reveal());

    // Assertions.
    $this->assertInstanceOf(DocumentWrapper::class, $response->getResponseData());
    $this->assertInstanceOf(EntityCollection::class, $response->getResponseData()->getData());
    $this->assertEquals(1, $response->getResponseData()->getData()->getIterator()->current()->id());
    $this->assertEquals(['node:1', 'node:2', 'node_list'], $response->getCacheableMetadata()->getCacheTags());
  }

  /**
   * @covers ::getCollection
   */
  public function testGetFilteredCollection() {
    // Fake the request.
    $request = $this->prophesize(Request::class);
    $params = $this->prophesize(ParameterBag::class);
    $field_manager = $this->container->get('entity_field.manager');
    $filter = new Filter(['type' => ['value' => 'article']], 'node_type', $field_manager);
    $params->get('_route_params')->willReturn([
      '_json_api_params' => [
        'filter' => $filter,
      ],
    ]);
    $params->get('_json_api_params')->willReturn([
      'filter' => $filter,
    ]);
    $request->attributes = $params->reveal();

    // Get the entity resource.
    $current_context = $this->container->get('jsonapi.current_context');
    $route = $this->prophesize(Route::class);
    $route->getRequirement('_entity_type')->willReturn('node');
    $route->getRequirement('_bundle')->willReturn('article');
    $current_context->setCurrentRoute($route->reveal());
    $entity_resource = new EntityResource(
      $this->container->get('jsonapi.resource.manager')->get('node_type', 'node_type'),
      $this->container->get('entity_type.manager'),
      $this->container->get('jsonapi.query_builder'),
      $field_manager,
      $current_context
    );

    // Get the response.
    $response = $entity_resource->getCollection($request->reveal());

    // Assertions.
    $this->assertInstanceOf(DocumentWrapper::class, $response->getResponseData());
    $this->assertInstanceOf(EntityCollection::class, $response->getResponseData()->getData());
    $this->assertCount(1, $response->getResponseData()->getData());
    $this->assertEquals(['config:node.type.article', 'config:node_type_list'], $response->getCacheableMetadata()->getCacheTags());
  }

  /**
   * @covers ::getCollection
   */
  public function testGetEmptyCollection() {
    // Fake the request.
    $request = $this->prophesize(Request::class);
    $params = $this->prophesize(ParameterBag::class);
    $filter = new Filter(
      ['uuid' => ['value' => 'invalid']],
      'node',
      $this->container->get('entity_field.manager')
    );
    $params->get('_route_params')->willReturn([
      '_json_api_params' => [
        'filter' => $filter,
      ],
    ]);
    $params->get('_json_api_params')->willReturn([
      'filter' => $filter,
    ]);
    $request->attributes = $params->reveal();

    // Get the response.
    $response = $this->entityResource->getCollection($request->reveal());

    // Assertions.
    $this->assertInstanceOf(DocumentWrapper::class, $response->getResponseData());
    $this->assertInstanceOf(EntityCollection::class, $response->getResponseData()->getData());
    $this->assertEquals(0, $response->getResponseData()->getData()->count());
    $this->assertEquals(['node_list'], $response->getCacheableMetadata()->getCacheTags());
  }

  /**
   * @covers ::getRelated
   */
  public function testGetRelated() {
    // to-one relationship.
    $response = $this->entityResource->getRelated($this->node, 'uid');
    $this->assertInstanceOf(DocumentWrapper::class, $response->getResponseData());
    $this->assertInstanceOf(User::class, $response->getResponseData()
      ->getData());
    $this->assertEquals(1, $response->getResponseData()->getData()->id());
    $this->assertSame('user:1', $response->getCacheableMetadata()->getCacheTags()[0]);

    // to-many relationship.
    $response = $this->entityResource->getRelated($this->user, 'roles');
    $this->assertInstanceOf(DocumentWrapper::class, $response
      ->getResponseData());
    $this->assertInstanceOf(EntityCollection::class, $response
      ->getResponseData()
      ->getData());
    $this->assertEquals(['config:user_role_list'], $response
      ->getCacheableMetadata()
      ->getCacheTags());
  }

  /**
   * @covers ::getRelationship
   */
  public function testGetRelationship() {
    // to-one relationship.
    $response = $this->entityResource->getRelationship($this->node, 'uid');
    $this->assertInstanceOf(DocumentWrapper::class, $response->getResponseData());
    $this->assertInstanceOf(
      EntityReferenceFieldItemListInterface::class,
      $response->getResponseData()->getData()
    );
    $this->assertEquals(1, $response
      ->getResponseData()
      ->getData()
      ->getEntity()
      ->id()
    );
    $this->assertEquals('node', $response
      ->getResponseData()
      ->getData()
      ->getEntity()
      ->getEntityTypeId()
    );
    $this->assertSame('node:1', $response->getCacheableMetadata()->getCacheTags()[0]);
  }

  /**
   * @covers ::createIndividual
   */
  public function testCreateIndividual() {
    $node = Node::create([
      'type' => 'article',
      'title' => 'Lorem ipsum',
    ]);
    Role::load(Role::ANONYMOUS_ID)
      ->grantPermission('create article content')
      ->save();
    $response = $this->entityResource->createIndividual($node);
    // As a side effect, the node will also be saved.
    $this->assertNotEmpty($node->id());
    $this->assertInstanceOf(DocumentWrapper::class, $response->getResponseData());
    $this->assertEquals(3, $response->getResponseData()->getData()->id());
    $this->assertEquals(201, $response->getStatusCode());
    // Make sure the POST request is not caching.
    $this->assertEquals(['node:3'], $response->getCacheableMetadata()->getCacheTags());
  }

  /**
   * @covers ::createIndividual
   */
  public function testCreateIndividualConfig() {
    $node_type = NodeType::create([
      'type' => 'test',
      'name' => 'Test Type',
      'description' => 'Lorem ipsum',
    ]);
    Role::load(Role::ANONYMOUS_ID)
      ->grantPermission('administer content types')
      ->save();
    $response = $this->entityResource->createIndividual($node_type);
    // As a side effect, the node type will also be saved.
    $this->assertNotEmpty($node_type->id());
    $this->assertInstanceOf(DocumentWrapper::class, $response->getResponseData());
    $this->assertEquals('test', $response->getResponseData()->getData()->id());
    $this->assertEquals(201, $response->getStatusCode());
    // Make sure the POST request is not caching.
    $this->assertEquals(['config:node.type.test'], $response->getCacheableMetadata()->getCacheTags());
  }

  /**
   * @covers ::deleteIndividual
   */
  public function testDeleteIndividual() {
    $node = Node::create([
      'type' => 'article',
      'title' => 'Lorem ipsum',
    ]);
    $nid = $node->id();
    $node->save();
    Role::load(Role::ANONYMOUS_ID)
      ->grantPermission('delete own article content')
      ->save();
    $response = $this->entityResource->deleteIndividual($node);
    // As a side effect, the node will also be deleted.
    $count = $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->getQuery()
      ->condition('nid', $nid)
      ->count()
      ->execute();
    $this->assertEquals(0, $count);
    $this->assertNull($response->getResponseData());
    $this->assertEquals(204, $response->getStatusCode());
    // Make sure the DELETE request is not caching.
    $this->assertEmpty($response->getCacheableMetadata()->getCacheTags());
  }

  /**
   * @covers ::createRelationship
   */
  public function testCreateRelationship() {
    $parsed_field_list = $this->container
      ->get('plugin.manager.field.field_type')
      ->createFieldItemList($this->node, 'field_relationships', [
        ['target_id' => $this->node->id()],
      ]);
    Role::load(Role::ANONYMOUS_ID)
      ->grantPermission('edit any article content')
      ->save();

    $response = $this->entityResource->createRelationship($this->node, 'field_relationships', $parsed_field_list);

    // As a side effect, the node will also be saved.
    $this->assertNotEmpty($this->node->id());
    $this->assertInstanceOf(DocumentWrapper::class, $response->getResponseData());
    $field_list = $response->getResponseData()->getData();
    $this->assertInstanceOf(EntityReferenceFieldItemListInterface::class, $field_list);
    $this->assertSame('field_relationships', $field_list->getName());
    $this->assertEquals([['target_id' => 1]], $field_list->getValue());
    $this->assertEquals(201, $response->getStatusCode());
    // Make sure the POST request is not caching.
    $this->assertEquals(['node:1'], $response->getCacheableMetadata()->getCacheTags());
  }

  /**
   * @covers ::patchRelationship
   * @dataProvider patchRelationshipProvider
   */
  public function testPatchRelationship($relationships) {
    $this->node->field_relationships->appendItem(['target_id' => $this->node->id()]);
    $this->node->save();
    $parsed_field_list = $this->container
      ->get('plugin.manager.field.field_type')
      ->createFieldItemList($this->node, 'field_relationships', $relationships);
    Role::load(Role::ANONYMOUS_ID)
      ->grantPermission('edit any article content')
      ->save();

    $response = $this->entityResource->patchRelationship($this->node, 'field_relationships', $parsed_field_list);

    // As a side effect, the node will also be saved.
    $this->assertNotEmpty($this->node->id());
    $this->assertInstanceOf(DocumentWrapper::class, $response->getResponseData());
    $field_list = $response->getResponseData()->getData();
    $this->assertInstanceOf(EntityReferenceFieldItemListInterface::class, $field_list);
    $this->assertSame('field_relationships', $field_list->getName());
    $this->assertEquals($relationships, $field_list->getValue());
    $this->assertEquals(201, $response->getStatusCode());
    // Make sure the POST request is not caching.
    $this->assertEquals(['node:1'], $response->getCacheableMetadata()->getCacheTags());
  }

  /**
   * Provides data for the testPatchRelationship.
   *
   * @return array
   *   The input data for the test function.
   */
  public function patchRelationshipProvider() {
    return [
      // Replace relationships.
      [[['target_id' => 2], ['target_id' => 1]]],
      // Remove relationships.
      [[]],
    ];
  }

  /**
   * Creates a field of an entity reference field storage on the specified bundle.
   *
   * @param string $entity_type
   *   The type of entity the field will be attached to.
   * @param string $bundle
   *   The bundle name of the entity the field will be attached to.
   * @param string $field_name
   *   The name of the field; if it already exists, a new instance of the existing
   *   field will be created.
   * @param string $field_label
   *   The label of the field.
   * @param string $target_entity_type
   *   The type of the referenced entity.
   * @param string $selection_handler
   *   The selection handler used by this field.
   * @param array $selection_handler_settings
   *   An array of settings supported by the selection handler specified above.
   *   (e.g. 'target_bundles', 'sort', 'auto_create', etc).
   * @param int $cardinality
   *   The cardinality of the field.
   *
   * @see \Drupal\Core\Entity\Plugin\EntityReferenceSelection\SelectionBase::buildConfigurationForm()
   */
  protected function createEntityReferenceField($entity_type, $bundle, $field_name, $field_label, $target_entity_type, $selection_handler = 'default', $selection_handler_settings = array(), $cardinality = 1) {
    // Look for or add the specified field to the requested entity bundle.
    if (!FieldStorageConfig::loadByName($entity_type, $field_name)) {
      FieldStorageConfig::create(array(
        'field_name' => $field_name,
        'type' => 'entity_reference',
        'entity_type' => $entity_type,
        'cardinality' => $cardinality,
        'settings' => array(
          'target_type' => $target_entity_type,
        ),
      ))->save();
    }
    if (!FieldConfig::loadByName($entity_type, $bundle, $field_name)) {
      FieldConfig::create(array(
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'bundle' => $bundle,
        'label' => $field_label,
        'settings' => array(
          'handler' => $selection_handler,
          'handler_settings' => $selection_handler_settings,
        ),
      ))->save();
    }
  }

}
