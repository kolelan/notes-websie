<?php

declare(strict_types=1);

use App\Database\PdoFactory;
use App\Auth\JwtService;
use App\Acl\PermissionService;
use App\Http\Controller\AuthController;
use App\Http\Controller\GroupController;
use App\Http\Controller\NoteController;
use App\Http\Controller\PermissionController;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\RateLimitMiddleware;
use App\Http\Middleware\RequestContextMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/Config/bootstrap.php';

$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->add(new RequestContextMiddleware());

$pdo = PdoFactory::createFromEnv();
$permissionService = new PermissionService($pdo);
$jwtService = new JwtService();
$authController = new AuthController($pdo, $jwtService);
$noteController = new NoteController($pdo, $permissionService);
$groupController = new GroupController($pdo, $permissionService);
$permissionController = new PermissionController($pdo, $permissionService);
$authMiddleware = new AuthMiddleware($jwtService);

$app->get('/health', static function (Request $request, Response $response): Response {
    $response->getBody()->write((string)json_encode(['status' => 'ok'], JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/auth/refresh', [$authController, 'refresh']);
$app->post('/auth/logout', [$authController, 'logout']);
$app->post('/auth/logout-all', [$authController, 'logoutAll'])->add($authMiddleware);

$app->post('/auth/login', [$authController, 'login'])->add(new RateLimitMiddleware(20, 60));
$app->post('/auth/register', [$authController, 'register'])->add(new RateLimitMiddleware(10, 60));

$app->get('/public/notes/{id}', [$noteController, 'publicShow']);

$app->group('', function ($group) use ($noteController, $groupController, $permissionController): void {
    $group->get('/notes', [$noteController, 'list']);
    $group->get('/notes/{id}', [$noteController, 'show']);
    $group->post('/notes', [$noteController, 'create'])->add(new RateLimitMiddleware(20, 60));
    $group->put('/notes/{id}', [$noteController, 'update']);
    $group->delete('/notes/{id}', [$noteController, 'delete']);
    $group->post('/notes/{id}/attach-to-group', [$noteController, 'attachToGroup']);
    $group->post('/notes/{id}/copy-to-group', [$noteController, 'copyToGroup']);
    $group->get('/tags', [$noteController, 'listTags']);
    $group->post('/notes/{id}/tags', [$noteController, 'addTag']);
    $group->delete('/notes/{id}/tags/{tagId}', [$noteController, 'removeTag']);

    $group->get('/groups', [$groupController, 'list']);
    $group->get('/groups/{id}', [$groupController, 'show']);
    $group->post('/groups', [$groupController, 'create']);
    $group->put('/groups/{id}', [$groupController, 'update']);
    $group->delete('/groups/{id}', [$groupController, 'delete']);
    $group->post('/groups/{id}/invite', [$groupController, 'invite']);
    $group->post('/groups/{id}/accept-invite', [$groupController, 'acceptInvite']);
    $group->get('/permissions/target/{type}/{id}', [$permissionController, 'listForTarget']);
    $group->post('/permissions', [$permissionController, 'create']);
    $group->delete('/permissions/{id}', [$permissionController, 'delete']);
})->add($authMiddleware);

$app->run();
