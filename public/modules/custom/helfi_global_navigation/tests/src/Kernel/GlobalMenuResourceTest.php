<?php

declare(strict_types = 1);

namespace Drupal\Tests\helfi_global_navigation\Kernel;

use Drupal\helfi_global_navigation\Entity\GlobalMenu;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Tests the Global menu rest resource.
 *
 * @group helfi_global_navigation
 */
class GlobalMenuResourceTest extends KernelTestBase {

  /**
   * Gets the mocked JSON.
   *
   * @return array[]
   *   The mock data.
   */
  private function getMockJson() : array {
    return [
      'fi' => [
        'site_name' => 'Liikenne fi',
        'status' => TRUE,
        'menu_tree' => [
          'name' => 'Kaupunkiympäristö',
          'id' => 'base:liikenne',
          'external' => FALSE,
          'hasItems' => TRUE,
          'weight' => 0,
          'sub_tree' => [
            [
              'id' => 'menu_link_content:7c9ddcc2-4d07-4785-8940-046b4cb85fb4',
              'name' => 'Pysäköinti fi',
              'parentId' => 'base:liikenne',
              'url' => 'https://localhost',
              'external' => FALSE,
              'hasItems' => FALSE,
              'weight' => 0,
            ],
          ],
        ],
      ],
      'en' => [
        'site_name' => 'Liikenne en',
        'menu_tree' => [
          'name' => 'Kaupunkiympäristö en',
          'id' => 'base:liikenne',
          'external' => FALSE,
          'hasItems' => TRUE,
          'weight' => 0,
          'sub_tree' => [
            [
              'id' => 'menu_link_content:7c9ddcc2-4d07-4785-8940-046b4cb85fb4',
              'name' => 'Pysäköinti en',
              'parentId' => 'base:liikenne',
              'url' => 'https://localhost',
              'external' => FALSE,
              'hasItems' => FALSE,
              'weight' => 0,
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Creates a new global menu entity.
   *
   * @param string $id
   *   The id.
   * @param string $projectName
   *   The project name.
   * @param array $menu
   *   The menu.
   *
   * @return \Drupal\helfi_global_navigation\Entity\GlobalMenu
   *   The global menu entity.
   */
  private function createGlobalMenu(string $id, string $projectName, array $menu = []) : GlobalMenu {
    $entity = $this->entityTypeManager
      ->getStorage('global_menu')
      ->createById($id)
      ->set('langcode', 'en')
      ->setMenuTree($menu)
      ->setLabel($projectName);
    $entity->save();
    return $entity;
  }

  /**
   * Tests GET request without permission.
   */
  public function testGetPermissions() : void {
    $this->drupalSetUpCurrentUser();
    $this->createGlobalMenu('liikenne', 'Liikenne', []);

    // Test individual entity.
    $request = $this->getMockedRequest('/api/v1/global-menu/liikenne');
    $response = $this->processRequest($request);
    $data = \GuzzleHttp\json_decode($response->getContent());

    $this->assertEquals(HttpResponse::HTTP_FORBIDDEN, $response->getStatusCode());
    $this->assertEquals('You are not authorized to view this global_menu entity', $data->message);

    $request = $this->getMockedRequest('/api/v1/global-menu');
    $response = $this->processRequest($request);
    $data = \GuzzleHttp\json_decode($response->getContent());
    $this->assertEquals(HttpResponse::HTTP_FORBIDDEN, $response->getStatusCode());
    $this->assertEquals("The 'restful get helfi_global_menu_collection' permission is required.", $data->message);
  }

  /**
   * Tests GET method.
   */
  public function testGetRoutes() : void {
    $this->createGlobalMenu('liikenne', 'Liikenne en', [])
      ->addTranslation('fi')
      ->setLabel('Liikenne fi')
      ->save();
    $this->createGlobalMenu('terveys', 'Terveys en', [])
      ->addTranslation('fi')
      ->setLabel('Terveys fi')
      ->save();

    $user = $this->createUser(permissions:  [
      'restful get helfi_global_menu_collection',
      'view global_menu',
    ]);
    $this->drupalSetCurrentUser($user);

    foreach (['en', 'fi'] as $langcode) {
      $request = $this->getMockedRequest('/' . $langcode . '/api/v1/global-menu');
      $response = $this->processRequest($request);
      $collectionData = \GuzzleHttp\json_decode($response->getContent());

      $this->assertEquals(HttpResponse::HTTP_OK, $response->getStatusCode());

      foreach (['liikenne', 'terveys'] as $id) {
        $expectedLabel = ucfirst($id) . ' ' . $langcode;
        $this->assertEquals($id, $collectionData->{$id}->project[0]->value);
        $this->assertEquals($expectedLabel, $collectionData->{$id}->name[0]->value);

        $request = $this->getMockedRequest('/' . $langcode . '/api/v1/global-menu/' . $id);
        $response = $this->processRequest($request);
        $data = \GuzzleHttp\json_decode($response->getContent());
        $this->assertEquals(HttpResponse::HTTP_OK, $response->getStatusCode());
        $this->assertEquals($id, $data->project[0]->value);
        $this->assertEquals($expectedLabel, $data->name[0]->value);
      }
    }
  }

  /**
   * Tests GET request with unpublished entities.
   */
  public function testUnpublishedEntity() : void {
    $user = $this->createUser(permissions:  [
      'restful get helfi_global_menu_collection',
      'view global_menu',
    ]);
    $this->drupalSetCurrentUser($user);

    $entity = $this->createGlobalMenu('liikenne', 'Liikenne en', []);
    $entity
      ->setPublished()
      ->addTranslation('fi')
      ->setLabel('Liikenne fi')
      ->setUnpublished()
      ->save();

    // English version is published and should be visible.
    $request = $this->getMockedRequest('/en/api/v1/global-menu/liikenne');
    $response = $this->processRequest($request);
    $this->assertEquals(HttpResponse::HTTP_OK, $response->getStatusCode());

    // Finnish translation is unpublished and should return 403 response.
    $request = $this->getMockedRequest('/fi/api/v1/global-menu/liikenne');
    $response = $this->processRequest($request);
    $this->assertEquals(HttpResponse::HTTP_FORBIDDEN, $response->getStatusCode());

    // Publish finnish translation and make sure it's visible.
    $entity->getTranslation('fi')->setPublished()->save();
    $request = $this->getMockedRequest('/fi/api/v1/global-menu/liikenne');
    $response = $this->processRequest($request);
    $this->assertEquals(HttpResponse::HTTP_OK, $response->getStatusCode());
  }

  /**
   * Tests GET request with non-existent global menu.
   */
  public function testGet404Response() : void {
    $user = $this->createUser(permissions:  [
      'restful get helfi_global_menu_collection',
      'view global_menu',
    ]);
    $this->drupalSetCurrentUser($user);

    $request = $this->getMockedRequest('/api/v1/global-menu/liikenne');
    $response = $this->processRequest($request);
    $this->assertEquals(HttpResponse::HTTP_NOT_FOUND, $response->getStatusCode());
  }

  /**
   * Tests POST request permissions.
   */
  public function testPostPermission() : void {
    $this->drupalSetUpCurrentUser();
    $request = $this->getMockedRequest(
      '/api/v1/global-menu/liikenne',
      'POST',
      document: $this->getMockJson()['fi']
    );
    $response = $this->processRequest($request);
    $data = \GuzzleHttp\json_decode($response->getContent());
    $this->assertEquals(HttpResponse::HTTP_FORBIDDEN, $response->getStatusCode());
    $this->assertEquals('You are not authorized to create this global_menu entity', $data->message);

    // Test update permission.
    $this->createGlobalMenu('liikenne', 'Liikenne', []);
    $request = $this->getMockedRequest(
      '/api/v1/global-menu/liikenne',
      'POST',
      document: $this->getMockJson()['fi']
    );
    $response = $this->processRequest($request);
    $data = \GuzzleHttp\json_decode($response->getContent());
    $this->assertEquals(HttpResponse::HTTP_FORBIDDEN, $response->getStatusCode());
    $this->assertEquals('You are not authorized to update this global_menu entity', $data->message);
  }

  /**
   * Tests POST validation.
   */
  public function testPostValidation() : void {
    $user = $this->createUser(permissions:  [
      'create global_menu',
      'update global_menu',
    ]);
    $this->drupalSetCurrentUser($user);
    // Test invalid json.
    $request = $this->getMockedRequest('/api/v1/global-menu/liikenne', 'POST');
    $response = $this->processRequest($request);
    $this->assertEquals(HttpResponse::HTTP_BAD_REQUEST, $response->getStatusCode());
    $data = \GuzzleHttp\json_decode($response->getContent());
    $this->assertEquals('Invalid JSON.', $data->message);

    // Test required fields.
    $request = $this->getMockedRequest('/api/v1/global-menu/liikenne', 'POST', document: [
      [],
    ]);
    $response = $this->processRequest($request);
    $this->assertEquals(HttpResponse::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $data = \GuzzleHttp\json_decode($response->getContent());
    $this->assertEquals('Missing required: menu_tree, site_name', $data->message);

    // Test entity validation failure.
    $request = $this->getMockedRequest('/api/v1/global-menu/liikenne', 'POST', document: [
      'site_name' => 'liikenne',
      'menu_tree' => [],
    ]);
    $response = $this->processRequest($request);
    $data = \GuzzleHttp\json_decode($response->getContent());
    $this->assertEquals(HttpResponse::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $this->assertStringStartsWith("Unprocessable Entity: validation failed.\nmenu_tree:", $data->message);
  }

  /**
   * Tests POST routes.
   */
  public function testPostRoute() : void {
    $user = $this->createUser(permissions:  [
      'create global_menu',
      'update global_menu',
      'view global_menu',
    ]);
    $this->drupalSetCurrentUser($user);

    $document = $this->getMockJson();

    foreach ($document as $langcode => $content) {
      $request = $this->getMockedRequest('/' . $langcode . '/api/v1/global-menu/liikenne', 'POST', document: $content);
      $response = $this->processRequest($request);
      // Make sure creating a new entity and translation returns a 201 response
      // code.
      $this->assertEquals(HttpResponse::HTTP_CREATED, $response->getStatusCode());
      $content = \GuzzleHttp\json_decode($response->getContent());
      // Finnish translation is explicitly set to published while english
      // should fall back to unpublished.
      $expectedStatus = $langcode === 'fi';
      $this->assertEquals($expectedStatus, $content->status[0]->value);

      // Re-send the same request to make sure updating an entity returns a 200
      // code.
      $response = $this->processRequest($request);
      $this->assertEquals(HttpResponse::HTTP_OK, $response->getStatusCode());

      // Make sure item was properly translated.
      $content = \GuzzleHttp\json_decode($response->getContent());
      $this->assertEquals('Liikenne ' . $langcode, $content->name[0]->value);
      $this->assertEquals($langcode, $content->langcode[0]->value);

      // Make sure item keeps the published status.
      $this->assertEquals($expectedStatus, $content->status[0]->value);
    }

    // Publish english translation and make sure entity won't get unpublished
    // when we update it without value on 'status' field.
    GlobalMenu::load('liikenne')
      ->getTranslation('en')
      ->setPublished()
      ->save();
    $request = $this->getMockedRequest('/en/api/v1/global-menu/liikenne', 'POST', document: $document['en']);
    $response = $this->processRequest($request);
    $content = \GuzzleHttp\json_decode($response->getContent());
    $this->assertEquals(TRUE, $content->status[0]->value);
  }

}
