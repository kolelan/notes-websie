<?php

declare(strict_types=1);

use App\Database\PdoFactory;
use App\Http\Controller\NoteController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/Config/bootstrap.php';

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

$pdo = PdoFactory::createFromEnv();
$noteController = new NoteController($pdo);

$app->get('/health', static function (Request $request, Response $response): Response {
    $response->getBody()->write((string)json_encode(['status' => 'ok'], JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/notes', [$noteController, 'list']);
$app->get('/notes/{id}', [$noteController, 'show']);
$app->post('/notes', [$noteController, 'create']);
$app->put('/notes/{id}', [$noteController, 'update']);
$app->delete('/notes/{id}', [$noteController, 'delete']);

$app->run();
