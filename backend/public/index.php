<?php

declare(strict_types=1);

use App\Database\PdoFactory;
use App\Auth\JwtService;
use App\Acl\PermissionService;
use App\Cache\RedisFactory;
use App\Http\Controller\AuthController;
use App\Http\Controller\AdminController;
use App\Http\Controller\GroupController;
use App\Http\Controller\NoteController;
use App\Http\Controller\PermissionController;
use App\Http\Controller\ProfileController;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\RateLimitMiddleware;
use App\Http\Middleware\RequestContextMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response as SlimResponse;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/Config/bootstrap.php';

$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->add(new RequestContextMiddleware());

$corsAllowedOriginsRaw = (string)($_ENV['CORS_ALLOWED_ORIGINS'] ?? 'http://localhost:5173,http://127.0.0.1:5173');
$corsAllowedOrigins = array_values(array_filter(array_map('trim', explode(',', $corsAllowedOriginsRaw))));

$app->options('/{routes:.+}', function (Request $request, Response $response): Response {
    return $response;
});

$app->add(function (Request $request, $handler) use ($corsAllowedOrigins): Response {
    $origin = $request->getHeaderLine('Origin');
    $isAllowed = in_array($origin, $corsAllowedOrigins, true);

    if ($request->getMethod() === 'OPTIONS') {
        $response = new SlimResponse(204);
    } else {
        $response = $handler->handle($request);
    }

    if ($isAllowed) {
        $response = $response->withHeader('Access-Control-Allow-Origin', $origin);
    }

    return $response
        ->withHeader('Vary', 'Origin')
        ->withHeader('Access-Control-Allow-Methods', 'GET,POST,PUT,DELETE,OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Request-Id')
        ->withHeader('Access-Control-Allow-Credentials', 'true');
});

$pdo = PdoFactory::createFromEnv();
$redis = RedisFactory::createFromEnv();
$permissionService = new PermissionService($pdo);
$jwtService = new JwtService();
$authController = new AuthController($pdo, $jwtService);
$adminController = new AdminController($pdo);
$noteController = new NoteController($pdo, $permissionService);
$groupController = new GroupController($pdo, $permissionService);
$permissionController = new PermissionController($pdo, $permissionService);
$profileController = new ProfileController($pdo);
$authMiddleware = new AuthMiddleware($jwtService);
$adminMiddleware = new AdminMiddleware();

$app->get('/health', static function (Request $request, Response $response): Response {
    $response->getBody()->write((string)json_encode(['status' => 'ok'], JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/auth/refresh', [$authController, 'refresh']);
$app->post('/auth/logout', [$authController, 'logout']);
$app->post('/auth/logout-all', [$authController, 'logoutAll'])->add($authMiddleware);

$app->post('/auth/login', [$authController, 'login'])->add(new RateLimitMiddleware($redis, 20, 60));
$app->post('/auth/register', [$authController, 'register'])->add(new RateLimitMiddleware($redis, 10, 60));

$app->get('/public/notes/{id}', [$noteController, 'publicShow']);
$app->get('/public/notes', [$noteController, 'publicList']);
$app->get('/public/notes/filters/authors', [$noteController, 'publicFilterAuthors']);
$app->get('/public/notes/filters/tags', [$noteController, 'publicFilterTags']);
$app->get('/public/notes/filters/groups', [$noteController, 'publicFilterGroups']);

$app->group('', function ($group) use ($noteController, $groupController, $permissionController, $profileController, $redis): void {
    $group->get('/notes', [$noteController, 'list']);
    $group->get('/notes/overview', [$noteController, 'overview']);
    $group->get('/notes/{id}', [$noteController, 'show']);
    $group->post('/notes', [$noteController, 'create'])->add(new RateLimitMiddleware($redis, 20, 60));
    $group->put('/notes/{id}', [$noteController, 'update']);
    $group->delete('/notes/{id}', [$noteController, 'delete']);
    $group->put('/notes/{id}/public', [$noteController, 'setPublic']);
    $group->post('/notes/{id}/attach-to-group', [$noteController, 'attachToGroup']);
    $group->delete('/notes/{id}/groups/{groupId}', [$noteController, 'detachFromGroup']);
    $group->post('/notes/{id}/copy-to-group', [$noteController, 'copyToGroup']);
    $group->get('/tags', [$noteController, 'listTags']);
    $group->post('/notes/{id}/tags', [$noteController, 'addTag']);
    $group->delete('/notes/{id}/tags/{tagId}', [$noteController, 'removeTag']);

    $group->get('/groups', [$groupController, 'list']);
    $group->get('/groups/note-counts', [$groupController, 'noteCounts']);
    $group->get('/groups/{id}', [$groupController, 'show']);
    $group->post('/groups', [$groupController, 'create']);
    $group->put('/groups/{id}', [$groupController, 'update']);
    $group->delete('/groups/{id}', [$groupController, 'delete']);
    $group->post('/groups/{id}/invite', [$groupController, 'invite']);
    $group->post('/groups/{id}/accept-invite', [$groupController, 'acceptInvite']);
    $group->get('/permissions/target/{type}/{id}', [$permissionController, 'listForTarget']);
    $group->post('/permissions', [$permissionController, 'create']);
    $group->delete('/permissions/{id}', [$permissionController, 'delete']);
    $group->get('/me', [$profileController, 'me']);
    $group->patch('/me', [$profileController, 'updateMe']);
})->add($authMiddleware);

$app->group('/admin', function ($group) use ($adminController): void {
    $group->get('/users', [$adminController, 'listUsers']);
    $group->patch('/users/{id}', [$adminController, 'updateUser']);
    $group->post('/users/{id}/logout-all', [$adminController, 'revokeUserSessions']);
    $group->get('/settings', [$adminController, 'listSettings']);
    $group->put('/settings/{key}', [$adminController, 'upsertSetting']);
    $group->get('/audit', [$adminController, 'listAudit']);
})->add($adminMiddleware)->add($authMiddleware);

$app->run();
