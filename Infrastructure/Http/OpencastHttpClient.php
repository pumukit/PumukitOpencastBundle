<?php

namespace Pumukit\OpencastBundle\Infrastructure\Http;

use Psr\Log\LoggerInterface;
use Pumukit\OpencastBundle\Domain\Exception\OpencastHttpException;
use Pumukit\OpencastBundle\Shared\Config\OpencastConfig;
use Symfony\Component\HttpFoundation\Response;

final class OpencastHttpClient
{
    private const HTTP_TIMEOUT = 10;

    public function __construct(
        private OpencastConfig $config,
        private ?LoggerInterface $logger = null
    ) {}

    public function request(string $method, string $path, array $options = [], bool $useAdminUrl = false): array
    {
        $baseUrl = $useAdminUrl && $this->config->getAdminUrl()
            ? $this->config->getAdminUrl()
            : $this->config->getHost();

        $url = rtrim($baseUrl, '/').$path;

        $this->logger?->debug(sprintf(
            '%s::%s() - URL: %s, Method: %s',
            self::class,
            __FUNCTION__,
            $url,
            $method
        ));

        $fields = is_array($options) ? http_build_query($options) : $options;
        $header = ['X-Requested-Auth: Digest', 'X-Opencast-Matterhorn-Authorization: true'];

        $curlHandle = curl_init($url);

        if (false === $curlHandle) {
            $this->logger?->error(sprintf(
                '%s::%s() - Unable to create curl handle for URL: %s',
                self::class,
                __FUNCTION__,
                $url
            ));

            throw OpencastHttpException::requestFailed($url, new \RuntimeException('Unable to create curl handle'));
        }

        try {
            $this->configureCurlMethod($curlHandle, $method, $fields, $header);
            $this->configureCurlOptions($curlHandle);

            $content = curl_exec($curlHandle);
            $error = curl_error($curlHandle);
            $statusCode = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);

            if (false === $content) {
                $this->logger?->error(sprintf(
                    '%s::%s() - cURL execution failed: %s, URL: %s',
                    self::class,
                    __FUNCTION__,
                    $error,
                    $url
                ));

                throw OpencastHttpException::requestFailed($url, new \RuntimeException($error ?: 'cURL execution failed'));
            }

            if ($statusCode >= Response::HTTP_BAD_REQUEST) {
                $this->logger?->error(sprintf(
                    '%s::%s() - HTTP Error %d, URL: %s, Error: %s',
                    self::class,
                    __FUNCTION__,
                    $statusCode,
                    $url,
                    $error
                ));

                throw OpencastHttpException::fromResponse($url, $statusCode, $error);
            }

            return [
                'content' => $content,
                'error' => null,
                'status' => $statusCode,
            ];
        } finally {
            curl_close($curlHandle);
        }
    }

    private function configureCurlMethod($curlHandle, string $method, string $fields, array &$header): void
    {
        switch ($method) {
            case 'GET':
                // No additional configuration needed
                break;

            case 'POST':
                curl_setopt($curlHandle, CURLOPT_POST, 1);
                curl_setopt($curlHandle, CURLOPT_POSTFIELDS, $fields);

                break;

            case 'PUT':
                $header[] = 'Content-Length: '.strlen($fields);
                curl_setopt($curlHandle, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($curlHandle, CURLOPT_POSTFIELDS, $fields);

                break;

            case 'DELETE':
                curl_setopt($curlHandle, CURLOPT_CUSTOMREQUEST, 'DELETE');

                break;

            default:
                throw new \InvalidArgumentException(sprintf('HTTP method "%s" is not supported', $method));
        }
    }

    private function configureCurlOptions($curlHandle): void
    {
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($curlHandle, CURLOPT_CONNECTTIMEOUT, self::HTTP_TIMEOUT);
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, self::HTTP_TIMEOUT);

        if ($this->config->insecure()) {
            curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curlHandle, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $username = $this->config->getUsername();
        $password = $this->config->getPassword();

        if (!empty($username)) {
            curl_setopt($curlHandle, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
            curl_setopt($curlHandle, CURLOPT_USERPWD, $username.':'.$password);
            curl_setopt($curlHandle, CURLOPT_HTTPHEADER, ['X-Requested-Auth: Digest', 'X-Opencast-Matterhorn-Authorization: true']);
        }
    }
}
