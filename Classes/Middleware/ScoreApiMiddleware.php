<?php

declare(strict_types=1);

namespace Cysquale\PlaysGamesWidget\Middleware;

use Cysquale\PlaysGamesWidget\Service\ScoreCatalog;
use Cysquale\PlaysGamesWidget\Service\ScoreStorage;
use Cysquale\PlaysGamesWidget\Service\ScoreSettings;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\SecurityAspect;
use TYPO3\CMS\Core\Security\RequestToken;

final class ScoreApiMiddleware implements MiddlewareInterface
{
    private const TOKEN_PATH = '/plays-games-widget/token';
    private const SCORE_PATH = '/plays-games-widget/score';
    private const TOKEN_SCOPE = 'plays-games-widget/score';

    public function __construct(
        private readonly ScoreCatalog $scoreCatalog,
        private readonly ScoreStorage $scoreStorage,
        private readonly ScoreSettings $scoreSettings,
        private readonly Context $context,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $path = rtrim($request->getUri()->getPath(), '/');

        if ($path === '') {
            $path = '/';
        }

        if ($path === self::TOKEN_PATH) {
            if (strtoupper($request->getMethod()) !== 'GET') {
                return $this->json([
                    'ok' => false,
                    'error' => 'method_not_allowed',
                ], 405);
            }

            return $this->provideToken();
        }

        if ($path === self::SCORE_PATH) {
            if (strtoupper($request->getMethod()) !== 'POST') {
                return $this->json([
                    'ok' => false,
                    'error' => 'method_not_allowed',
                ], 405);
            }

            return $this->saveScore($request);
        }

        return $handler->handle($request);
    }

    private function provideToken(): ResponseInterface
    {
        $userId = $this->frontendUserId();

        if ($userId <= 0) {
            return $this->json([
                'ok' => false,
                'error' => 'login_required',
            ], 403);
        }

        $securityAspect = SecurityAspect::provideIn($this->context);
        $nonce = $securityAspect->provideNonce();

        $token = RequestToken::create(self::TOKEN_SCOPE)
            ->toHashSignedJwt($nonce);

        return $this->json([
            'ok' => true,
            'header_name' => RequestToken::HEADER_NAME,
            'request_token' => $token,
        ]);
    }

    private function saveScore(
        ServerRequestInterface $request
    ): ResponseInterface {
        $userId = $this->frontendUserId();

        if ($userId <= 0) {
            return $this->json([
                'ok' => false,
                'error' => 'login_required',
            ], 403);
        }

        $securityAspect = SecurityAspect::provideIn($this->context);
        $requestToken = $securityAspect->getReceivedRequestToken();

        if (
            !$requestToken instanceof RequestToken
            || $requestToken->scope !== self::TOKEN_SCOPE
        ) {
            return $this->json([
                'ok' => false,
                'error' => 'invalid_request_token',
            ], 403);
        }

        $rawBody = (string)$request->getBody();

        if ($rawBody === '' || strlen($rawBody) > 65536) {
            return $this->json([
                'ok' => false,
                'error' => 'invalid_payload',
            ], 400);
        }

        try {
            $payload = json_decode(
                $rawBody,
                true,
                64,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            return $this->json([
                'ok' => false,
                'error' => 'invalid_json',
            ], 400);
        }

        if (
            !is_array($payload)
            || (
                isset($payload['type'])
                && $payload['type'] !== 'pgames.score'
            )
        ) {
            return $this->json([
                'ok' => false,
                'error' => 'invalid_payload',
            ], 400);
        }

        $scoreKey = preg_replace(
            '/[^a-z0-9_-]/',
            '',
            strtolower((string)($payload['score_key'] ?? ''))
        ) ?? '';

        if ($scoreKey === '') {
            return $this->json([
                'ok' => false,
                'error' => 'missing_score_key',
            ], 400);
        }

        if (!$this->scoreSettings->isGameEnabled($scoreKey)) {
            return $this->json([
                'ok' => false,
                'error' => 'game_not_enabled',
            ], 403);
        }

        $game = $this->scoreCatalog->getGame(
            $scoreKey,
            false
        );

        if ($game === null) {
            return $this->json([
                'ok' => false,
                'error' => 'unknown_game',
            ], 400);
        }

        $incomingGameId = (int)($payload['game_id'] ?? 0);

        if (
            $incomingGameId > 0
            && $incomingGameId !== (int)$game['plays_game_id']
        ) {
            return $this->json([
                'ok' => false,
                'error' => 'game_id_mismatch',
            ], 400);
        }

        $events = $this->extractMetricEvents(
            $payload,
            $game
        );

        if ($events === []) {
            return $this->json([
                'ok' => false,
                'error' => 'no_valid_metrics',
            ], 400);
        }

        $saved = [];

        foreach ($events as $event) {
            try {
                $saved[] = $this->scoreStorage->saveMetric(
                    $userId,
                    $game,
                    $event['metric'],
                    $event['value']
                );
            } catch (\InvalidArgumentException) {
                // Ignore an invalid metric but allow the others.
            }
        }

        if ($saved === []) {
            return $this->json([
                'ok' => false,
                'error' => 'no_storable_metrics',
            ], 400);
        }

        return $this->json([
            'ok' => true,
            'score_key' => $scoreKey,
            'saved' => $saved,
        ]);
    }

    private function extractMetricEvents(
        array $payload,
        array $game
    ): array {
        $events = [];

        if (
            isset($payload['metrics'])
            && is_array($payload['metrics'])
        ) {
            foreach ($payload['metrics'] as $rawMetric) {
                if (!is_array($rawMetric)) {
                    continue;
                }

                $this->appendMetricEvent(
                    $events,
                    $game,
                    $rawMetric
                );
            }
        }

        if (isset($payload['metric_key'])) {
            $this->appendMetricEvent(
                $events,
                $game,
                $payload
            );
        }

        /*
         * Compatibility with the legacy Plays-Games bridge:
         * score_key + game_id + value.
         */
        if (
            $events === []
            && array_key_exists('value', $payload)
        ) {
            $primary = $this->scoreCatalog
                ->getPrimaryMetric($game);

            if ($primary !== null) {
                $events[(string)$primary['metric_key']] = [
                    'metric' => $primary,
                    'value' => $payload['value'],
                ];
            }
        }

        return array_values($events);
    }

    private function appendMetricEvent(
        array &$events,
        array $game,
        array $rawMetric
    ): void {
        $metricKey = preg_replace(
            '/[^a-zA-Z0-9_.:-]/',
            '',
            (string)(
                $rawMetric['metric_key']
                ?? $rawMetric['key']
                ?? ''
            )
        ) ?? '';

        if ($metricKey === '') {
            return;
        }

        $metric = $this->scoreCatalog->getMetric(
            $game,
            $metricKey
        );

        if ($metric === null) {
            return;
        }

        foreach (
            [
                'value',
                'current_value',
                'value_num',
                'current_text',
                'text',
            ] as $valueKey
        ) {
            if (
                array_key_exists($valueKey, $rawMetric)
                && $rawMetric[$valueKey] !== null
            ) {
                $events[$metricKey] = [
                    'metric' => $metric,
                    'value' => $rawMetric[$valueKey],
                ];

                return;
            }
        }
    }

    private function frontendUserId(): int
    {
        if (
            !(bool)$this->context->getPropertyFromAspect(
                'frontend.user',
                'isLoggedIn',
                false
            )
        ) {
            return 0;
        }

        return (int)$this->context->getPropertyFromAspect(
            'frontend.user',
            'id',
            0
        );
    }

    private function json(
        array $data,
        int $status = 200
    ): ResponseInterface {
        $body = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR
        );

        $response = $this->responseFactory
            ->createResponse($status)
            ->withHeader(
                'Content-Type',
                'application/json; charset=utf-8'
            )
            ->withHeader(
                'Cache-Control',
                'no-store, private'
            );

        $response->getBody()->write($body);

        return $response;
    }
}
