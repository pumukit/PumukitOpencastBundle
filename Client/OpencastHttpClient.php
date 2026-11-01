<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Client;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pumukit\OpencastBundle\Exception\OpencastConnectionException;

class OpencastHttpClient
{
    public const CONNECT_TIMEOUT = 5;
    public const TIMEOUT = 30;
    private const COMPONENTS = '/info/components.json';

    private ?string $adminUrl = null;

    public function __construct(
        private readonly OpencastConnection $connection,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function getConnection(): OpencastConnection
    {
        return $this->connection;
    }

    public function getHost(): string
    {
        return $this->connection->host;
    }

    public function getAdminUrl(): string
    {
        if (null !== $this->adminUrl) {
            return $this->adminUrl;
        }
        if (null !== $this->connection->adminHost) {
            return $this->adminUrl = $this->connection->adminHost;
        }

        $admin = null;
        $response = $this->request('GET', self::COMPONENTS);
        if ($response->isSuccessful()) {
            $admin = $response->json()['admin'] ?? null;
        }

        return $this->adminUrl = is_string($admin) && filter_var($admin, FILTER_VALIDATE_URL) ? rtrim($admin, '/') : $this->connection->host;
    }

    public function get(string $path, array $query = [], bool $admin = false): OpencastResponse
    {
        return $this->request('GET', $this->withQuery($path, $query), [], $admin);
    }

    public function post(string $path, array $fields = [], bool $admin = false): OpencastResponse
    {
        return $this->request('POST', $path, $fields, $admin);
    }

    public function put(string $path, array $fields = [], bool $admin = false): OpencastResponse
    {
        return $this->request('PUT', $path, $fields, $admin);
    }

    public function delete(string $path, bool $admin = false): OpencastResponse
    {
        return $this->request('DELETE', $path, [], $admin);
    }

    public function getUrl(string $url): OpencastResponse
    {
        if (str_starts_with($url, $this->connection->host.'/') || (null !== $this->connection->adminHost && str_starts_with($url, $this->connection->adminHost.'/'))) {
            return $this->send('GET', $url, []);
        }

        return $this->send('GET', $url, [], false);
    }

    public function request(string $method, string $path, array $fields = [], bool $admin = false): OpencastResponse
    {
        $base = $admin ? $this->getAdminUrl() : $this->connection->host;

        return $this->send($method, $base.$path, $fields);
    }

    private function send(string $method, string $url, array $fields, bool $authenticate = true): OpencastResponse
    {
        $handle = curl_init($url);
        if (false === $handle) {
            throw new OpencastConnectionException(sprintf('Invalid Opencast URL "%s"', $url));
        }

        $headers = [];
        $body = http_build_query($fields);
        match ($method) {
            'GET' => null,
            'POST' => curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]),
            'PUT', 'DELETE' => curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => $body]),
            default => throw new \InvalidArgumentException(sprintf('HTTP method "%s" is not supported', $method)),
        };

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => !$this->connection->insecure,
            CURLOPT_SSL_VERIFYHOST => $this->connection->insecure ? 0 : 2,
        ]);

        if ($authenticate && '' !== $this->connection->username) {
            curl_setopt_array($handle, [
                CURLOPT_HTTPAUTH => CURLAUTH_DIGEST,
                CURLOPT_USERPWD => $this->connection->username.':'.$this->connection->password,
            ]);
            $headers = ['X-Requested-Auth: Digest', 'X-Opencast-Matterhorn-Authorization: true'];
        }
        if ($headers) {
            curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        }

        $content = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);

        if (false === $content || 0 === $status) {
            $this->logger->error('Opencast request failed', ['method' => $method, 'url' => $url, 'error' => $error]);

            throw new OpencastConnectionException(sprintf('Unable to connect to Opencast (%s %s): %s', $method, $url, $error ?: 'no response'));
        }

        $this->logger->debug('Opencast request', ['method' => $method, 'url' => $url, 'status' => $status]);
        if ($status >= 500) {
            $this->logger->error('Opencast server error', ['method' => $method, 'url' => $url, 'status' => $status]);
        }

        return new OpencastResponse($url, $status, (string) $content);
    }

    private function withQuery(string $path, array $query): string
    {
        $query = array_filter($query, static fn ($value) => null !== $value && '' !== $value);

        return $query ? $path.(str_contains($path, '?') ? '&' : '?').http_build_query($query) : $path;
    }
}
