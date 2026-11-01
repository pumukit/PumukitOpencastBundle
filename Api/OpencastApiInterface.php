<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Api;

use Pumukit\OpencastBundle\Client\OpencastHttpClient;
use Pumukit\OpencastBundle\MediaPackage\MediaPackage;

interface OpencastApiInterface
{
    public function supports(string $version): bool;

    public function searchMediaPackages(OpencastHttpClient $http, string $query, int $limit, int $offset): SearchResult;

    public function getMediaPackage(OpencastHttpClient $http, string $id): ?MediaPackage;

    public function getArchivedMediaPackage(OpencastHttpClient $http, string $id): ?MediaPackage;

    public function deleteEvent(OpencastHttpClient $http, string $id): void;

    public function getSeries(OpencastHttpClient $http, string $id): ?array;

    public function createSeries(OpencastHttpClient $http, string $title, string $description): string;

    public function updateSeries(OpencastHttpClient $http, string $id, string $title, string $description): void;

    public function deleteSeries(OpencastHttpClient $http, string $id): void;

    public function createUser(OpencastHttpClient $http, OpencastUser $user): void;

    public function updateUser(OpencastHttpClient $http, OpencastUser $user): void;

    public function deleteUser(OpencastHttpClient $http, string $username): void;

    public function getSucceededWorkflowMediaPackageIds(OpencastHttpClient $http, string $workflowDefinition): array;
}
