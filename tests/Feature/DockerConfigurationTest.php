<?php

namespace Tests\Feature;

use Tests\TestCase;

class DockerConfigurationTest extends TestCase
{
    /**
     * Проверка наличия всех ключевых файлов конфигурации Docker.
     */
    public function test_docker_files_exist(): void
    {
        $this->assertFileExists(base_path('Dockerfile'));
        $this->assertFileExists(base_path('docker-compose.yml'));
        $this->assertFileExists(base_path('docker/nginx/default.conf'));
        $this->assertFileExists(base_path('.dockerignore'));
    }

    /**
     * Проверка содержимого Dockerfile (PHP 8.4, расширения, Composer, права доступа).
     */
    public function test_dockerfile_uses_php_8_4_and_required_extensions(): void
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));

        $this->assertNotFalse($dockerfile);
        $this->assertStringContainsString('php:8.4-fpm-alpine', $dockerfile);
        $this->assertStringContainsString('pdo_mysql', $dockerfile);
        $this->assertStringContainsString('mbstring', $dockerfile);
        $this->assertStringContainsString('bcmath', $dockerfile);
        $this->assertStringContainsString('opcache', $dockerfile);
        $this->assertStringContainsString('zip', $dockerfile);
        $this->assertStringContainsString('sodium', $dockerfile);
        $this->assertStringContainsString('composer:2', $dockerfile);
        $this->assertStringContainsString('/var/www/html', $dockerfile);
        $this->assertStringContainsString('www-data', $dockerfile);
        $this->assertStringContainsString('storage', $dockerfile);
        $this->assertStringContainsString('bootstrap/cache', $dockerfile);
        $this->assertStringContainsString('EXPOSE 9000', $dockerfile);
        $this->assertStringContainsString('CMD ["php-fpm"]', $dockerfile);
    }

    /**
     * Проверка структуры docker-compose.yml (сервисы app, web, mysql, redis, порты, тома, сеть).
     */
    public function test_docker_compose_services_ports_and_volumes(): void
    {
        $compose = file_get_contents(base_path('docker-compose.yml'));

        $this->assertNotFalse($compose);

        // Проверка сервисов
        $this->assertStringContainsString('app:', $compose);
        $this->assertStringContainsString('web:', $compose);
        $this->assertStringContainsString('mysql:', $compose);
        $this->assertStringContainsString('redis:', $compose);

        // Проверка портов
        $this->assertStringContainsString('8000:80', $compose);
        $this->assertStringContainsString('3306:3306', $compose);
        $this->assertStringContainsString('6379:6379', $compose);

        // Проверка томов и сетей
        $this->assertStringContainsString('mysql_data', $compose);
        $this->assertStringContainsString('redis_data', $compose);
        $this->assertStringContainsString('vkr-network', $compose);

        // Проверка переменных окружения MySQL
        $this->assertStringContainsString('MYSQL_DATABASE', $compose);
        $this->assertStringContainsString('vkr_family_apteka', $compose);
        $this->assertStringContainsString('MYSQL_USER', $compose);
        $this->assertStringContainsString('vkr_user', $compose);
        $this->assertStringContainsString('MYSQL_PASSWORD', $compose);
        $this->assertStringContainsString('vkr_secret', $compose);
        $this->assertStringContainsString('MYSQL_ROOT_PASSWORD', $compose);
        $this->assertStringContainsString('vkr_root_secret', $compose);

        // Проверка зависимостей
        $this->assertStringContainsString('mysql', $compose);
        $this->assertStringContainsString('redis', $compose);
    }

    /**
     * Проверка конфигурации Nginx и заголовков безопасности.
     */
    public function test_nginx_configuration_and_security_headers(): void
    {
        $nginx = file_get_contents(base_path('docker/nginx/default.conf'));

        $this->assertNotFalse($nginx);
        $this->assertStringContainsString('listen 80', $nginx);
        $this->assertStringContainsString('/var/www/html/public', $nginx);
        $this->assertStringContainsString('try_files $uri $uri/ /index.php?$query_string;', $nginx);
        $this->assertStringContainsString('fastcgi_pass app:9000;', $nginx);
        $this->assertStringContainsString('fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;', $nginx);
        $this->assertStringContainsString('X-Content-Type-Options "nosniff"', $nginx);
        $this->assertStringContainsString('X-Frame-Options "SAMEORIGIN"', $nginx);
        $this->assertStringContainsString('X-XSS-Protection "1; mode=block"', $nginx);
    }

    /**
     * Проверка файла .dockerignore на исключение служебных файлов и папок.
     */
    public function test_dockerignore_configuration(): void
    {
        $dockerignore = file_get_contents(base_path('.dockerignore'));

        $this->assertNotFalse($dockerignore);
        $this->assertStringContainsString('.git', $dockerignore);
        $this->assertStringContainsString('vendor', $dockerignore);
        $this->assertStringContainsString('node_modules', $dockerignore);
        $this->assertStringContainsString('storage/logs/*.log', $dockerignore);
        $this->assertStringContainsString('.env.production', $dockerignore);
        $this->assertStringContainsString('.env.testing', $dockerignore);
    }
}
