<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    /**
     * Проверка доступности веб-страницы документации /docs (Scalar API Reference).
     */
    public function test_docs_page_is_accessible(): void
    {
        $response = $this->get('/docs');

        $response->assertStatus(200);
        $response->assertSee('api-reference', false);
        $response->assertSee('scalar/api-reference', false);
        $response->assertSee('/openapi.json', false);
    }

    /**
     * Проверка валидности спецификации OpenAPI 3.1 в формате JSON.
     */
    public function test_openapi_json_is_valid_and_complete(): void
    {
        $response = $this->get('/openapi.json');

        $response->assertStatus(200);

        $json = json_decode($response->getContent(), true);

        $this->assertIsArray($json);
        $this->assertEquals('3.1.0', $json['openapi']);
        $this->assertArrayHasKey('info', $json);
        $this->assertArrayHasKey('servers', $json);
        $this->assertArrayHasKey('tags', $json);
        $this->assertArrayHasKey('components', $json);
        $this->assertArrayHasKey('paths', $json);

        // Проверка наличия схемы Bearer-авторизации Sanctum
        $this->assertArrayHasKey('sanctumAuth', $json['components']['securitySchemes']);
        $this->assertEquals('http', $json['components']['securitySchemes']['sanctumAuth']['type']);
        $this->assertEquals('bearer', $json['components']['securitySchemes']['sanctumAuth']['scheme']);

        // Проверка наличия ключевых тегов (11 групп)
        $tagNames = array_column($json['tags'], 'name');
        $expectedTags = [
            'Auth', 'Dashboard', 'Profiles', 'MedicineBoxes', 'MedicineBoxMembers',
            'Medicines', 'ReferenceMedicines', 'IntakeCourses', 'IntakeLogs',
            'MedicationSafety', 'Notifications'
        ];
        foreach ($expectedTags as $tag) {
            $this->assertContains($tag, $tagNames, "Tag '{$tag}' отсутствует в спецификации OpenAPI.");
        }

        // Проверка ключевых эндпоинтов
        $this->assertArrayHasKey('/auth/register', $json['paths']);
        $this->assertArrayHasKey('/auth/login', $json['paths']);
        $this->assertArrayHasKey('/dashboard', $json['paths']);
        $this->assertArrayHasKey('/profiles', $json['paths']);
        $this->assertArrayHasKey('/medicine-boxes', $json['paths']);
        $this->assertArrayHasKey('/medicines', $json['paths']);
        $this->assertArrayHasKey('/reference-medicines', $json['paths']);
        $this->assertArrayHasKey('/intake-courses', $json['paths']);
        $this->assertArrayHasKey('/intake-logs', $json['paths']);
        $this->assertArrayHasKey('/intake-logs/quick', $json['paths']);
        $this->assertArrayHasKey('/medication-safety/check', $json['paths']);
        $this->assertArrayHasKey('/notifications', $json['paths']);
    }

    /**
     * Проверка доступности спецификации OpenAPI 3.1 в формате YAML.
     */
    public function test_openapi_yaml_is_accessible(): void
    {
        $response = $this->get('/openapi.yaml');

        $response->assertStatus(200);
        $this->assertStringContainsString('openapi: 3.1.0', $response->getContent());
        $this->assertStringContainsString('sanctumAuth:', $response->getContent());
    }

    /**
     * Проверка целостности и корректности файла Postman Collection v2.1.0.
     */
    public function test_postman_collection_is_valid(): void
    {
        $postmanPath = base_path('FamilyApteka.postman_collection.json');

        $this->assertFileExists($postmanPath);

        $content = File::get($postmanPath);
        $json = json_decode($content, true);

        $this->assertIsArray($json);
        $this->assertArrayHasKey('info', $json);
        $this->assertArrayHasKey('variable', $json);
        $this->assertArrayHasKey('item', $json);

        // Проверка схемы коллекции Postman v2.1
        $this->assertEquals('https://schema.getpostman.com/json/collection/v2.1.0/collection.json', $json['info']['schema']);

        // Проверка наличия переменных baseUrl и token
        $varKeys = array_column($json['variable'], 'key');
        $this->assertContains('baseUrl', $varKeys);
        $this->assertContains('token', $varKeys);

        // Проверка 12 папок (11 функциональных модулей + 12. Teardown)
        $this->assertCount(12, $json['item']);
    }

    /**
     * Проверка наличия файла спецификации по ГОСТ 7.32.
     */
    public function test_gost_markdown_specification_exists(): void
    {
        $gostPath = base_path('../../Документация/01_НИР_и_Практики/3_Семестр/Разработка и реализация программных систем защиты информации/API_СПЕЦИФИКАЦИЯ_ГОСТ.md');

        $this->assertFileExists($gostPath);
        $content = File::get($gostPath);

        $this->assertStringContainsString('Спецификация программного интерфейса', $content);
        $this->assertStringContainsString('Таблица 1', $content);
        $this->assertStringContainsString('/medication-safety/check', $content);
        $this->assertStringContainsString('Argon2id', $content);
        $this->assertStringContainsString('AES-256', $content);
    }
}
