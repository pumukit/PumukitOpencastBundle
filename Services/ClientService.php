<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Services;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pumukit\OpencastBundle\Api\OpencastApiInterface;
use Pumukit\OpencastBundle\Api\OpencastApiResolver;
use Pumukit\OpencastBundle\Api\OpencastUser;
use Pumukit\OpencastBundle\Api\SearchResult;
use Pumukit\OpencastBundle\Client\OpencastHttpClient;
use Pumukit\OpencastBundle\Exception\OpencastException;
use Pumukit\OpencastBundle\MediaPackage\MediaPackage;
use Pumukit\SchemaBundle\Document\User;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

class ClientService
{
    public function __construct(
        private readonly OpencastHttpClient $http,
        private readonly OpencastApiResolver $resolver,
        private readonly ?RoleHierarchyInterface $roleHierarchy = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $player = '/paella7/ui/watch.html',
        private readonly string $scheduler = '/admin-ui/index.html#/events/events',
        private readonly bool $manageOpencastUsers = false,
    ) {}

    public function getUrl(): string
    {
        return $this->http->getHost();
    }

    public function getAdminUrl(): string
    {
        return $this->http->getAdminUrl();
    }

    public function getPlayerUrl(): string
    {
        return str_starts_with($this->player, '/') ? $this->http->getHost().$this->player : $this->player;
    }

    public function getSchedulerUrl(): string
    {
        return str_starts_with($this->scheduler, '/') ? $this->getAdminUrl().$this->scheduler : $this->scheduler;
    }

    public function getVersion(): string
    {
        return $this->resolver->getVersion($this->http);
    }

    public function checkSupported(): string
    {
        $this->api();

        return $this->getVersion();
    }

    public function getMediaPackages(string $query, int $limit, int $offset): SearchResult
    {
        return $this->api()->searchMediaPackages($this->http, $query, $limit, $offset);
    }

    public function getMediaPackage(string $id): ?MediaPackage
    {
        return $this->api()->getMediaPackage($this->http, $id);
    }

    public function getMasterMediaPackage(string $id): ?MediaPackage
    {
        return $this->api()->getArchivedMediaPackage($this->http, $id);
    }

    public function removeEvent(string $id): void
    {
        $this->api()->deleteEvent($this->http, $id);
    }

    public function getSucceededWorkflowMediaPackageIds(string $workflowDefinition): array
    {
        return $this->api()->getSucceededWorkflowMediaPackageIds($this->http, $workflowDefinition);
    }

    public function getSeries(string $id): ?array
    {
        return $this->api()->getSeries($this->http, $id);
    }

    public function createSeries(string $title, string $description): string
    {
        return $this->api()->createSeries($this->http, $title, $description);
    }

    public function updateSeries(string $id, string $title, string $description): void
    {
        $this->api()->updateSeries($this->http, $id, $title, $description);
    }

    public function deleteSeries(string $id): void
    {
        $this->api()->deleteSeries($this->http, $id);
    }

    public function createUser(User $user): bool
    {
        if (!$this->manageOpencastUsers) {
            return false;
        }
        $this->api()->createUser($this->http, $this->opencastUser($user));

        return true;
    }

    public function updateUser(User $user): bool
    {
        if (!$this->manageOpencastUsers) {
            return false;
        }
        $this->api()->updateUser($this->http, $this->opencastUser($user));

        return true;
    }

    public function deleteUser(User $user): bool
    {
        if (!$this->manageOpencastUsers) {
            return false;
        }
        $this->api()->deleteUser($this->http, $user->getUsername());

        return true;
    }

    public function getGalicasterProperties(MediaPackage $mediaPackage): array
    {
        $url = $mediaPackage->galicasterPropertiesUrl();
        if (null === $url) {
            return [];
        }

        try {
            return $this->http->getUrl($url)->expect(200)->json();
        } catch (OpencastException $exception) {
            $this->logger->warning('Galicaster properties not read', ['mediapackage' => $mediaPackage->id(), 'url' => $url, 'error' => $exception->getMessage()]);

            return [];
        }
    }

    public function getSpatialField(MediaPackage $mediaPackage): ?string
    {
        $url = $mediaPackage->episodeCatalogUrl();
        if (null === $url) {
            return null;
        }

        try {
            $body = $this->http->getUrl($url)->expect(200)->body;
        } catch (OpencastException $exception) {
            $this->logger->warning('Episode catalog not read', ['mediapackage' => $mediaPackage->id(), 'url' => $url, 'error' => $exception->getMessage()]);

            return null;
        }

        if (!preg_match('#<dcterms:spatial[^>]*>(.*?)</dcterms:spatial>#s', $body, $matches)) {
            return null;
        }
        $spatial = trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1));

        return '' === $spatial ? null : $spatial;
    }

    private function api(): OpencastApiInterface
    {
        return $this->resolver->getApi($this->http);
    }

    private function opencastUser(User $user): OpencastUser
    {
        $roles = $this->roleHierarchy ? $this->roleHierarchy->getReachableRoleNames($user->getRoles()) : $user->getRoles();

        return new OpencastUser($user->getUsername(), (string) $user->getFullname(), (string) $user->getEmail(), array_values(array_unique($roles)));
    }
}
