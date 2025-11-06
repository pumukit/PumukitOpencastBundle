<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Shared\Config;

final readonly class OpencastConfig
{
    /**
     * @param UrlMappingConfig[] $urlMapping
     */
    public function __construct(
        private string $host,
        private ?string $adminHost,
        private string $username,
        private string $password,
        private string $player,
        private bool $useRedirect,
        private bool $batchImportInverted,
        private bool $showImporterTab,
        private bool $deleteArchiveMediaPackage,
        private string $deletionWorkflowName,
        private bool $schedulerOnMenu,
        private string $scheduler,
        private bool $manageOpencastUsers,
        private bool $syncSeriesWithOpencast,
        private bool $insecure,
        private NotificationConfig $notifications,
        private SbsConfig $sbs,
        private bool $errorIfFileNotExist,
        private array $urlMapping,
    ) {}

    public function getHost(): string
    {
        return $this->host;
    }

    public function getAdminHost(): ?string
    {
        return $this->adminHost;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getPlayer(): string
    {
        return $this->player;
    }

    public function useRedirect(): bool
    {
        return $this->useRedirect;
    }

    public function batchImportInverted(): bool
    {
        return $this->batchImportInverted;
    }

    public function showImporterTab(): bool
    {
        return $this->showImporterTab;
    }

    public function deleteArchiveMediaPackage(): bool
    {
        return $this->deleteArchiveMediaPackage;
    }

    public function getDeletionWorkflowName(): string
    {
        return $this->deletionWorkflowName;
    }

    public function schedulerOnMenu(): bool
    {
        return $this->schedulerOnMenu;
    }

    public function getScheduler(): string
    {
        return $this->scheduler;
    }

    public function manageOpencastUsers(): bool
    {
        return $this->manageOpencastUsers;
    }

    public function syncSeriesWithOpencast(): bool
    {
        return $this->syncSeriesWithOpencast;
    }

    public function insecure(): bool
    {
        return $this->insecure;
    }

    public function getNotifications(): NotificationConfig
    {
        return $this->notifications;
    }

    public function getSbs(): SbsConfig
    {
        return $this->sbs;
    }

    public function errorIfFileNotExist(): bool
    {
        return $this->errorIfFileNotExist;
    }

    /**
     * @return UrlMappingConfig[]
     */
    public function getUrlMapping(): array
    {
        return $this->urlMapping;
    }
}
