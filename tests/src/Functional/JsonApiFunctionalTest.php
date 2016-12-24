<?php

namespace Drupal\Tests\jsonapi\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Tests\EntityReference\EntityReferenceTestTrait;
use Drupal\file\Entity\File;
use Drupal\jsonapi\Routing\Param\OffsetPage;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

/**
 * Class JsonApiFunctionalTest
 *
 * @package Drupal\Tests\jsonapi\Functional
 *
 * @group jsonapi
 */
class JsonApiFunctionalTest extends BrowserTestBase {

  use EntityReferenceTestTrait;
  use ImageFieldCreationTrait;

  public static $modules = [
    'basic_auth',
    'jsonapi',
    'serialization',
    'node',
    'image',
    'taxonomy',
    'link',
  ];

  /**
   * @var \Drupal\user\Entity\User
   */
  protected $user;

  /**
   * @var \Drupal\user\Entity\User
   */
  protected $userCanViewProfiles;

  /**
   * @var \Drupal\node\Entity\Node[]
   */
  protected $nodes = [];

  /**
   * @var \Drupal\taxonomy\Entity\Term[]
   */
  protected $tags = [];

  /**
   * @var \Drupal\file\Entity\File[]
   */
  protected $files = [];

  /**
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;


  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    // Set up a HTTP client that accepts relative URLs.
    $this->httpClient = $this->container->get('http_client_factory')
      ->fromOptions(['base_uri' => $this->baseUrl]);

    // Create Basic page and Article node types.
    if ($this->profile != 'standard') {
      $this->drupalCreateContentType(array(
        'type' => 'article',
        'name' => 'Article',
      ));

      // Setup vocabulary.
      Vocabulary::create([
        'vid' => 'tags',
        'name' => 'Tags',
      ])->save();

      // Add tags and field_image to the article.
      $this->createEntityReferenceField(
        'node',
        'article',
        'field_tags',
        'Tags',
        'taxonomy_term',
        'default',
        [
          'target_bundles' => [
            'tags' => 'tags',
          ],
          'auto_create' => TRUE,
        ],
        FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED
      );
      $this->createImageField('field_image', 'article');
    }

    FieldStorageConfig::create(array(
      'field_name' => 'field_link',
      'entity_type' => 'node',
      'type' => 'link',
      'settings' => [],
      'cardinality' => 1,
    ))->save();

    $field_config = FieldConfig::create([
      'field_name' => 'field_link',
      'label' => 'Link',
      'entity_type' => 'node',
      'bundle' => 'article',
      'required' => FALSE,
      'settings' => [],
      'description' => '',
    ]);
    $field_config->save();

    $this->user = $this->drupalCreateUser([
      'create article content',
      'edit any article content',
      'delete any article content',
    ]);

    // Create a user that can
    $this->userCanViewProfiles = $this->drupalCreateUser([
      'access user profiles',
    ]);

    $this->grantPermissions(Role::load(RoleInterface::ANONYMOUS_ID), [
      'access user profiles',
      'administer taxonomy',
    ]);

    drupal_flush_all_caches();
  }

  /**
   * {@inheritdoc}
   */
  protected function drupalGet($path, array $options = array(), array $headers = array()) {
    // Make sure we don't forget the format parameter.
    $options += ['query' => []];
    $options['query'] += ['_format' => 'api_json'];

    return parent::drupalGet($path, $options, $headers);
  }

  /**
   * Performs a HTTP request. Wraps the Guzzle HTTP client.
   *
   * Why wrap the Guzzle HTTP client? Because any error response is returned via
   * an exception, which would make the tests unnecessarily complex to read.
   *
   * @see \GuzzleHttp\ClientInterface::request()
   *
   * @param string $method
   *   HTTP method.
   * @param \Drupal\Core\Url $url
   *   URL to request.
   * @param array $request_options
   *   Request options to apply.
   *
   * @return \Psr\Http\Message\ResponseInterface
   */
  protected function request($method, Url $url, array $request_options) {
    $url->setOption('query', ['_format' => 'api_json']);
    try {
      $response = $this->httpClient->request($method, $url->toString(), $request_options);
    }
    catch (ClientException $e) {
      $response = $e->getResponse();
    }
    catch (ServerException $e) {
      $response = $e->getResponse();
    }

    return $response;
  }

  /**
   * Test the GET method.
   */
  public function testRead() {
    $this->createDefaultContent(60, 5, TRUE, TRUE);
    // 1. Load all articles (1st page).
    $collection_output = Json::decode($this->drupalGet('/jsonapi/node/article'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertEquals(OffsetPage::$maxSize, count($collection_output['data']));
    $this->assertSession()
      ->responseHeaderEquals('Content-Type', 'application/vnd.api+json');
    // 2. Load all articles (Offset 3).
    $collection_output = Json::decode($this->drupalGet('/jsonapi/node/article', [
      'query' => ['page' => ['offset' => 3]],
    ]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertEquals(OffsetPage::$maxSize, count($collection_output['data']));
    $this->assertContains('page[offset]=53', $collection_output['links']['next']);
    // 3. Load all articles (1st page, 2 items)
    $collection_output = Json::decode($this->drupalGet('/jsonapi/node/article', [
      'query' => ['page' => ['limit' => 2]],
    ]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertEquals(2, count($collection_output['data']));
    // 4. Load all articles (2nd page, 2 items).
    $collection_output = Json::decode($this->drupalGet('/jsonapi/node/article', [
      'query' => [
        'page' => [
          'limit' => 2,
          'offset' => 2,
        ],
      ],
    ]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertEquals(2, count($collection_output['data']));
    $this->assertContains('page[offset]=4', $collection_output['links']['next']);
    // 5. Single article.
    $uuid = $this->nodes[0]->uuid();
    $single_output = Json::decode($this->drupalGet('/jsonapi/node/article/' . $uuid));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertArrayHasKey('type', $single_output['data']);
    $this->assertEquals($this->nodes[0]->getTitle(), $single_output['data']['attributes']['title']);
    // 6. Single relationship item.
    $single_output = Json::decode($this->drupalGet('/jsonapi/node/article/' . $uuid . '/relationships/type'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertArrayHasKey('type', $single_output['data']);
    $this->assertArrayNotHasKey('attributes', $single_output['data']);
    $this->assertArrayHasKey('related', $single_output['links']);
    // 7. Single relationship image.
    $single_output = Json::decode($this->drupalGet('/jsonapi/node/article/' . $uuid . '/relationships/field_image'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertArrayHasKey('type', $single_output['data']);
    $this->assertArrayNotHasKey('attributes', $single_output['data']);
    $this->assertArrayHasKey('related', $single_output['links']);
    // 8. Multiple relationship item.
    $single_output = Json::decode($this->drupalGet('/jsonapi/node/article/' . $uuid . '/relationships/field_tags'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertArrayHasKey('type', $single_output['data'][0]);
    $this->assertArrayNotHasKey('attributes', $single_output['data'][0]);
    $this->assertArrayHasKey('related', $single_output['links']);
    // 9. Related tags with includes.
    $single_output = Json::decode($this->drupalGet('/jsonapi/node/article/' . $uuid . '/field_tags', [
      'query' => ['include' => 'vid'],
    ]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertEquals('taxonomy_term--tags', $single_output['data'][0]['type']);
    $this->assertArrayHasKey('tid', $single_output['data'][0]['attributes']);
    $this->assertContains(
      '/taxonomy_term/tags/',
      $single_output['data'][0]['links']['self']
    );
    $this->assertEquals(
      'taxonomy_vocabulary--taxonomy_vocabulary',
      $single_output['included'][0]['type']
    );
    // 10. Single article with includes.
    $single_output = Json::decode($this->drupalGet('/jsonapi/node/article/' . $uuid, [
      'query' => ['include' => 'uid,field_tags'],
    ]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertEquals('node--article', $single_output['data']['type']);
    $first_include = reset($single_output['included']);
    $this->assertEquals(
      'user--user',
      $first_include['type']
    );
    $last_include = end($single_output['included']);
    $this->assertEquals(
      'taxonomy_term--tags',
      $last_include['type']
    );
    // 11. Includes with relationships.
    $single_output = Json::decode($this->drupalGet('/jsonapi/node/article/' . $uuid . '/relationships/uid', [
      'query' => ['include' => 'uid'],
    ]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertEquals('user--user', $single_output['data']['type']);
    $this->assertArrayHasKey('related', $single_output['links']);
    $first_include = reset($single_output['included']);
    $this->assertEquals(
      'user--user',
      $first_include['data']['type']
    );
    $this->assertTrue(empty($first_include['data']['attributes']['mail']));
    $this->assertTrue(empty($first_include['data']['attributes']['pass']));
    // 12. Collection with one access denied
    $this->nodes[1]->set('status', FALSE);
    $this->nodes[1]->save();
    $single_output = Json::decode($this->drupalGet('/jsonapi/node/article', [
      'query' => ['page' => ['limit' => 2]],
    ]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertEquals(1, count($single_output['data']));
    $this->assertEquals(1, count($single_output['meta']['errors']));
    $this->assertEquals(403, $single_output['meta']['errors'][0]['status']);
    $this->nodes[1]->set('status', TRUE);
    $this->nodes[1]->save();
    // 13. Test filtering when using short syntax.
    $filter = [
      'uid.uuid' => ['value' => $this->user->uuid()],
      'field_tags.uuid' => ['value' => $this->tags[0]->uuid()],
    ];
    $single_output = Json::decode($this->drupalGet('/jsonapi/node/article', [
      'query' => ['filter' => $filter, 'include' => 'uid,field_tags'],
    ]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertGreaterThan(0, count($single_output['data']));
    // 14. Test filtering when using long syntax.
    $filter = [
      'and_group' => ['group' => ['conjunction' => 'AND']],
      'filter_user' => [
        'condition' => [
          'path' => 'uid.uuid',
          'value' => $this->user->uuid(),
          'memberOf' => 'and_group',
        ],
      ],
      'filter_tags' => [
        'condition' => [
          'path' => 'field_tags.uuid',
          'value' => $this->tags[0]->uuid(),
          'memberOf' => 'and_group',
        ],
      ],
    ];
    $single_output = Json::decode($this->drupalGet('/jsonapi/node/article', [
      'query' => ['filter' => $filter, 'include' => 'uid,field_tags'],
    ]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertGreaterThan(0, count($single_output['data']));
    // 15. Test filtering when using invalid syntax.
    $filter = [
      'and_group' => ['group' => ['conjunction' => 'AND']],
      'filter_user' => [
        'condition' => [
          'name-with-a-typo' => 'uid.uuid',
          'value' => $this->user->uuid(),
          'memberOf' => 'and_group',
        ],
      ],
    ];
    $this->drupalGet('/jsonapi/node/article', [
      'query' => ['filter' => $filter],
    ]);
    $this->assertSession()->statusCodeEquals(400);
    // 16. Test filtering on the same field.
    $filter = [
      'or_group' => ['group' => ['conjunction' => 'OR']],
      'filter_tags_1' => [
        'condition' => [
          'path' => 'field_tags.uuid',
          'value' => $this->tags[0]->uuid(),
          'memberOf' => 'or_group',
        ],
      ],
      'filter_tags_2' => [
        'condition' => [
          'path' => 'field_tags.uuid',
          'value' => $this->tags[1]->uuid(),
          'memberOf' => 'or_group',
        ],
      ],
    ];
    $single_output = Json::decode($this->drupalGet('/jsonapi/node/article', [
      'query' => ['filter' => $filter, 'include' => 'field_tags'],
    ]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertGreaterThanOrEqual(2, count($single_output['included']));
    // 17. Single user (check fields lacking 'view' access).
    $user_url = Url::fromRoute('jsonapi.user--user.individual', [
      'user' => $this->user->uuid(),
    ]);
    $response = $this->request('GET', $user_url, [
      'auth' => [
        $this->userCanViewProfiles->getUsername(),
        $this->userCanViewProfiles->pass_raw,
      ],
    ]);
    $single_output = Json::decode($response->getBody()->__toString());
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals('user--user', $single_output['data']['type']);
    $this->assertEquals($this->user->get('name')->value, $single_output['data']['attributes']['name']);
    $this->assertTrue(empty($single_output['data']['attributes']['mail']));
    $this->assertTrue(empty($single_output['data']['attributes']['pass']));
    // 18. Test filtering on the column of a link.
    $filter = [
      'linkUri' => [
        'condition' => [
          'path' => 'field_link.uri',
          'value' => 'https://',
          'operator' => 'STARTS_WITH',
        ],
      ],
    ];
    $single_output = Json::decode($this->drupalGet('/jsonapi/node/article', [
      'query' => ['filter' => $filter],
    ]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertGreaterThanOrEqual(1, count($single_output['data']));
  }

  /**
   * Test POST, PATCH and DELETE.
   */
  public function testWrite() {
    $this->createDefaultContent(0, 3, FALSE, FALSE);
    // 1. Successful post.
    $collection_url = Url::fromRoute('jsonapi.node--article.collection');
    $body = [
      'data' => [
        'type' => 'node--article',
        'attributes' => [
          'langcode' => 'en',
          'title' => 'My custom title',
          'status' => '1',
          'promote' => '1',
          'sticky' => '0',
          'default_langcode' => '1',
          'body' => [
            'value' => 'Custom value',
            'format' => 'plain_text',
            'summary' => 'Custom summary',
          ],
        ],
        'relationships' => [
          'type' => [
            'data' => [
              'type' => 'node_type--node_type',
              'id' => 'article',
            ],
          ],
          'uid' => [
            'data' => [
              'type' => 'user--user',
              'id' => '1',
            ],
          ],
          'field_tags' => [
            'data' => [
              [
                'type' => 'taxonomy_term--tags',
                'id' => $this->tags[0]->uuid(),
              ],
              [
                'type' => 'taxonomy_term--tags',
                'id' => $this->tags[1]->uuid(),
              ],
            ],
          ],
        ],
      ],
    ];
    $response = $this->request('POST', $collection_url, [
      'body' => Json::encode($body),
      'auth' => [$this->user->getUsername(), $this->user->pass_raw],
      'headers' => ['Content-Type' => 'application/vnd.api+json'],
    ]);
    $created_response = Json::decode($response->getBody()->__toString());
    $this->assertEquals(201, $response->getStatusCode());
    $this->assertArrayHasKey('uuid', $created_response['data']['attributes']);
    $uuid = $created_response['data']['attributes']['uuid'];
    $this->assertEquals(2, count($created_response['data']['relationships']['field_tags']['data']));
    // 2. Authorization error.
    $response = $this->request('POST', $collection_url, [
      'body' => Json::encode($body),
      'headers' => ['Content-Type' => 'application/vnd.api+json'],
    ]);
    $created_response = Json::decode($response->getBody()->__toString());
    $this->assertEquals(403, $response->getStatusCode());
    $this->assertNotEmpty($created_response['errors']);
    $this->assertEquals('Forbidden', $created_response['errors'][0]['title']);
    // 3. Missing Content-Type error.
    $response = $this->request('POST', $collection_url, [
      'body' => Json::encode($body),
      'auth' => [$this->user->getUsername(), $this->user->pass_raw],
    ]);
    $created_response = Json::decode($response->getBody()->__toString());
    $this->assertEquals(422, $response->getStatusCode());
    $this->assertNotEmpty($created_response['errors']);
    $this->assertEquals('Unprocessable Entity', $created_response['errors'][0]['title']);
    // 4. Article with a duplicate ID
    $invalid_body = $body;
    $invalid_body['data']['attributes']['nid'] = 1;
    $response = $this->request('POST', $collection_url, [
      'body' => Json::encode($invalid_body),
      'auth' => [$this->user->getUsername(), $this->user->pass_raw],
      'headers' => ['Content-Type' => 'application/vnd.api+json'],
    ]);
    $created_response = Json::decode($response->getBody()->__toString());
    $this->assertEquals(500, $response->getStatusCode());
    $this->assertNotEmpty($created_response['errors']);
    $this->assertEquals('Internal Server Error', $created_response['errors'][0]['title']);
    // 5. Article with wrong reference UUIDs for tags.
    $body_invalid_tags = $body;
    $body_invalid_tags['data']['relationships']['field_tags']['data'][0]['id'] = 'lorem';
    $body_invalid_tags['data']['relationships']['field_tags']['data'][1]['id'] = 'ipsum';
    $response = $this->request('POST', $collection_url, [
      'body' => Json::encode($body_invalid_tags),
      'auth' => [$this->user->getUsername(), $this->user->pass_raw],
      'headers' => ['Content-Type' => 'application/vnd.api+json'],
    ]);
    $created_response = Json::decode($response->getBody()->__toString());
    $this->assertEquals(201, $response->getStatusCode());
    $this->assertEquals(0, count($created_response['data']['relationships']['field_tags']['data']));
    // 6. Serialization error.
    $response = $this->request('POST', $collection_url, [
      'body' => '{"bad json",,,}',
      'auth' => [$this->user->getUsername(), $this->user->pass_raw],
      'headers' => ['Content-Type' => 'application/vnd.api+json'],
    ]);
    $created_response = Json::decode($response->getBody()->__toString());
    $this->assertEquals(422, $response->getStatusCode());
    $this->assertNotEmpty($created_response['errors']);
    $this->assertEquals('Unprocessable Entity', $created_response['errors'][0]['title']);
    // 7. Successful PATCH.
    $body = [
      'data' => [
        'id' => $uuid,
        'type' => 'node--article',
        'attributes' => ['title' => 'My updated title'],
      ],
    ];
    $individual_url = Url::fromRoute('jsonapi.node--article.individual', [
      'node' => $uuid,
    ]);
    $response = $this->request('PATCH', $individual_url, [
      'body' => Json::encode($body),
      'auth' => [$this->user->getUsername(), $this->user->pass_raw],
      'headers' => ['Content-Type' => 'application/vnd.api+json'],
    ]);
    $updated_response = Json::decode($response->getBody()->__toString());
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals('My updated title', $updated_response['data']['attributes']['title']);
    // 8. Field access forbidden check.
    $body = [
      'data' => [
        'id' => $uuid,
        'type' => 'node--article',
        'attributes' => [
          'title' => 'My updated title',
          'status' => 0,
        ],
      ],
    ];
    $response = $this->request('PATCH', $individual_url, [
      'body' => Json::encode($body),
      'auth' => [$this->user->getUsername(), $this->user->pass_raw],
      'headers' => ['Content-Type' => 'application/vnd.api+json'],
    ]);
    $updated_response = Json::decode($response->getBody()->__toString());
    $this->assertEquals(403, $response->getStatusCode());
    $this->assertEquals('The current user is not allowed to PATCH the selected field (status).', $updated_response['errors'][0]['detail']);
    $node = \Drupal::entityManager()->loadEntityByUuid('node', $uuid);
    $this->assertEquals(1, $node->get('status')->value, 'Node status was not changed.');
    // 9. Successful POST to related endpoint.
    $body = [
      'data' => [
        [
          'id' => $this->tags[2]->uuid(),
          'type' => 'taxonomy_term--tags',
        ],
      ],
    ];
    $relationship_url = Url::fromRoute('jsonapi.node--article.relationship', [
      'node' => $uuid,
      'related' => 'field_tags',
    ]);
    $response = $this->request('POST', $relationship_url, [
      'body' => Json::encode($body),
      'auth' => [$this->user->getUsername(), $this->user->pass_raw],
      'headers' => ['Content-Type' => 'application/vnd.api+json'],
    ]);
    $updated_response = Json::decode($response->getBody()->__toString());
    $this->assertEquals(201, $response->getStatusCode());
    $this->assertEquals(3, count($updated_response['data']));
    $this->assertEquals('taxonomy_term--tags', $updated_response['data'][2]['type']);
    $this->assertEquals($this->tags[2]->uuid(), $updated_response['data'][2]['id']);
    // 10. Successful PATCH to related endpoint.
    $body = [
      'data' => [
        [
          'id' => $this->tags[1]->uuid(),
          'type' => 'taxonomy_term--tags',
        ],
      ],
    ];
    $response = $this->request('PATCH', $relationship_url, [
      'body' => Json::encode($body),
      'auth' => [$this->user->getUsername(), $this->user->pass_raw],
      'headers' => ['Content-Type' => 'application/vnd.api+json'],
    ]);
    $updated_response = Json::decode($response->getBody()->__toString());
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertCount(1, $updated_response['data']);
    $this->assertEquals('taxonomy_term--tags', $updated_response['data'][0]['type']);
    $this->assertEquals($this->tags[1]->uuid(), $updated_response['data'][0]['id']);
    // TODO: Successful DELETE to related endpoint.
    // 11. PATCH with invalid title and body format.
    $body = [
      'data' => [
        'id' => $uuid,
        'type' => 'node--article',
        'attributes' => [
          'title' => '',
          'body' => [
            'value' => 'Custom value',
            'format' => 'invalid_format',
            'summary' => 'Custom summary',
          ],
        ],
      ],
    ];
    $response = $this->request('PATCH', $individual_url, [
      'body' => Json::encode($body),
      'auth' => [$this->user->getUsername(), $this->user->pass_raw],
      'headers' => ['Content-Type' => 'application/vnd.api+json'],
    ]);
    $updated_response = Json::decode($response->getBody()->__toString());
    $this->assertEquals(422, $response->getStatusCode());
    $this->assertCount(2, $updated_response['errors']);
    for ($i = 0; $i < 2; $i++) {
      $this->assertEquals("Unprocessable Entity", $updated_response['errors'][$i]['title']);
      $this->assertEquals(422, $updated_response['errors'][$i]['status']);
      $this->assertEquals(0, $updated_response['errors'][$i]['code']);
    }
    $this->assertEquals("title: This value should not be null.", $updated_response['errors'][0]['detail']);
    $this->assertEquals("body.0.format: The value you selected is not a valid choice.", $updated_response['errors'][1]['detail']);
    $this->assertEquals("/data/attributes/title", $updated_response['errors'][0]['source']['pointer']);
    $this->assertEquals("/data/attributes/body/format", $updated_response['errors'][1]['source']['pointer']);
    // 12. Successful DELETE.
    $response = $this->request('DELETE', $individual_url, [
      'auth' => [$this->user->getUsername(), $this->user->pass_raw],
    ]);
    $this->assertEquals(204, $response->getStatusCode());
    $response = $this->request('GET', $individual_url, []);
    $this->assertEquals(404, $response->getStatusCode());
  }

  /**
   * Creates default content to test the API.
   *
   * @param int $num_articles
   *   Number of articles to create.
   * @param int $num_tags
   *   Number of tags to create.
   * @param bool $article_has_image
   *   Set to TRUE if you want to add an image to the generated articles.
   * @param bool $article_has_link
   *   Set to TRUE if you want to add a link to the generated articles.
   */
  protected function createDefaultContent($num_articles, $num_tags, $article_has_image, $article_has_link) {
    $random = $this->getRandomGenerator();
    for ($created_tags = 0; $created_tags < $num_tags; $created_tags++) {
      $term = Term::create([
        'vid' => 'tags',
        'name' => $random->name(),
      ]);
      $term->save();
      $this->tags[] = $term;
    }
    for ($created_nodes = 0; $created_nodes < $num_articles; $created_nodes++) {
      // Get N random tags.
      $selected_tags = mt_rand(1, $num_tags);
      $tags = [];
      while (count($tags) < $selected_tags) {
        $tags[] = mt_rand(1, $num_tags);
        $tags = array_unique($tags);
      }
      $values = [
        'uid' => ['target_id' => $this->user->id()],
        'type' => 'article',
        'field_tags' => array_map(function ($tag) {
          return ['target_id' => $tag];
        }, $tags),
      ];
      if ($article_has_image) {
        $file = File::create([
          'uri' => 'vfs://' . $random->name() . '.png',
        ]);
        $file->setPermanent();
        $file->save();
        $this->files[] = $file;
        $values['field_image'] = ['target_id' => $file->id()];
      }
      if ($article_has_link) {
        $values['field_link'] = [
          'title' => $this->getRandomGenerator()->name(),
          'uri' => sprintf(
            '%s://%s.%s',
            'http' . (mt_rand(0, 2) > 1 ? '' : 's'),
            $this->getRandomGenerator()->name(),
            'org'
          ),
        ];
      }
      $this->nodes[] = $this->createNode($values);
    }
    if ($article_has_link) {
      // Make sure that there is at least 1 https link for ::testRead() #19.
      $this->nodes[0]->field_link = [
        'title' => 'Drupal',
        'uri' => 'https://drupal.org'
      ];
      $this->nodes[0]->save();
    }
  }

}
