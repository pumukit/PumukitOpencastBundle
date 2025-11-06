<?php

namespace Pumukit\OpencastBundle\Infrastructure\Http;

use Pumukit\OpencastBundle\Domain\Exception\OpencastHttpException;
use Pumukit\OpencastBundle\Shared\Config\OpencastConfig;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

final class OpencastHttpClient
{
    private const HTTP_TIMEOUT = 10;

    public function __construct(
        private OpencastConfig $config,
        private HttpClientInterface $httpClient,
        private ?LoggerInterface $logger = null
    ) {}

    public function request(string $method, string $path, array $options = []): array
    {
        $url = rtrim($this->config->getHost(), '/') . $path;

        $defaultOptions = [
            'headers' => [
                'X-Requested-Auth' => 'Digest',
                'X-Opencast-Matterhorn-Authorization' => 'true',
            ],
            'auth_basic' => $this->config->getUsername() && $this->config->getPassword()
                ? [$this->config->getUsername(), $this->config->getPassword()]
                : null,
            'verify_peer' => !$this->config->insecure(),
            'timeout' => self::HTTP_TIMEOUT,
        ];

        $mergedOptions = array_merge($defaultOptions, $options);

        $this->logger?->debug(sprintf(
            '%s::request() - URL: %s, Method: %s, Options: %s',
            self::class,
            $url,
            $method,
            json_encode($mergedOptions)
        ));

        try {
            $response = $this->httpClient->request($method, $url, $mergedOptions);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            if ($method === 'GET' && $statusCode !== Response::HTTP_OK) {
                $this->logger?->error(sprintf(
                    '%s::request() - Error %s, Status %d, URL: %s',
                    self::class,
                    $response->getInfo('error') ?? 'N/A',
                    $statusCode,
                    $url
                ));

                throw OpencastHttpException::fromResponse(
                    $url,
                    $statusCode,
                    $response->getInfo('error')
                );
            }

            return [
                'content' => $content,
                'error' => null,
                'status' => $statusCode,
            ];
        } catch (OpencastHttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf(
                '%s::request() - Exception connecting to %s: %s',
                self::class,
                $url,
                $e->getMessage()
            ));

            throw OpencastHttpException::requestFailed($url, $e);
        }
    }
}
