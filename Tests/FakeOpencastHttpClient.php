<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Tests;

use Pumukit\OpencastBundle\Client\OpencastConnection;
use Pumukit\OpencastBundle\Client\OpencastHttpClient;
use Pumukit\OpencastBundle\Client\OpencastResponse;

final class FakeOpencastHttpClient extends OpencastHttpClient
{
    public array $requests = [];

    public function __construct(private array $responses = [])
    {
        parent::__construct(new OpencastConnection('https://opencast.example.org', 'https://admin.example.org', 'user', 'password'));
    }

    public function respond(string $method, string $path, int $status, string $body = ''): self
    {
        $this->responses[$method.' '.$path] = [$status, $body];

        return $this;
    }

    public function request(string $method, string $path, array $fields = [], bool $admin = false): OpencastResponse
    {
        $this->requests[] = [$method, $path, $fields, $admin];
        $key = $method.' '.$path;
        foreach ($this->responses as $pattern => [$status, $body]) {
            if ($pattern === $key || (str_ends_with($pattern, '*') && str_starts_with($key, substr($pattern, 0, -1)))) {
                return new OpencastResponse(($admin ? 'https://admin.example.org' : 'https://opencast.example.org').$path, $status, $body);
            }
        }

        return new OpencastResponse($path, 404, '');
    }
}
