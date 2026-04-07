<?php

declare(strict_types=1);

use App\Database\PdoFactory;
use App\Auth\JwtService;
use App\Http\Controller\AuthController;
use App\Http\Controller\GroupController;
use App\Http\Controller\NoteController;
use App\Http\Middleware\AuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/Config/bootstrap.php';

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

$pdo = PdoFactory::createFromEnv();
$jwtService = new JwtService();
$authController = new AuthController($pdo, $jwtService);
$noteController = new NoteController($pdo);
$groupController = new GroupController($pdo);
$authMiddleware = new AuthMiddleware($jwtService);

$app->get('/health', static function (Request $request, Response $response): Response {
    $response->getBody()->write((string)json_encode(['status' => 'ok'], JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/auth/login', [$authController, 'login']);
$app->post('/auth/refresh', [$authController, 'refresh']);
$app->post('/auth/logout', [$authController, 'logout']);

$app->group('', function ($group) use ($noteController, $groupController): void {
    $group->get('/notes', [$noteController, 'list']);
    $group->get('/notes/{id}', [$noteController, 'show']);
    $group->post('/notes', [$noteController, 'create']);
    $group->put('/notes/{id}', [$noteController, 'update']);
    $group->delete('/notes/{id}', [$noteController, 'delete']);
    $group->post('/notes/{id}/attach-to-group', [$noteController, 'attachToGroup']);
    $group->post('/notes/{id}/copy-to-group', [$noteController, 'copyToGroup']);

    $group->get('/groups', [$groupController, 'list']);
    $group->get('/groups/{id}', [$groupController, 'show']);
    $group->post('/groups', [$groupController, 'create']);
    $group->put('/groups/{id}', [$groupController, 'update']);
    $group->delete('/groups/{id}', [$groupController, 'delete']);
})->add($authMiddleware);

$app->run();
