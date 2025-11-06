<?php

namespace Pumukit\OpencastBundle\Shared\Config;

final readonly class OpencastAPIEndpoints
{
    public const HEALTH = '/info/health';
    public const COMPONENTS = '/info/components.json';
    public const SERIES = '/api/series';
    public const EVENTS = '/api/events';
    public const MEDIA_PACKAGE = '/search/episode.json';
    public const ASSETS_MEDIA_PACKAGE = '/assets/episode/';
    public const WORKFLOW_INSTANCES = '/workflow/instances.json';
    public const WORKFLOW_STATS = '/workflow/statistics.json';
    public const WORKFLOW_STOP = '/workflow/stop';
    public const USER = '/user-utils/';

    public function health(): string
    {
        return self::HEALTH;
    }

    public function components(): string
    {
        return self::COMPONENTS;
    }

    public function series(): string
    {
        return self::SERIES;
    }

    public function events(): string
    {
        return self::EVENTS;
    }

    public function mediaPackage(): string
    {
        return self::MEDIA_PACKAGE;
    }

    public function assetsMediaPackage(): string
    {
        return self::ASSETS_MEDIA_PACKAGE;
    }

    public function workflowInstances(): string
    {
        return self::WORKFLOW_INSTANCES;
    }

    public function workflowStats(): string
    {
        return self::WORKFLOW_STATS;
    }

    public function workflowStop(): string
    {
        return self::WORKFLOW_STOP;
    }

    public function user(): string
    {
        return self::USER;
    }
}
