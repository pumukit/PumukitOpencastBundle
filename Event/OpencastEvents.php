<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Event;

final class OpencastEvents
{
    /**
     * The import.success event is thrown each time an import is finished successfully in the system.
     *
     * The event listener receives a Pumukit\OpencastBundle\Event\OpencastEvent instance.
     */
    public const IMPORT_SUCCESS = 'import.success';

    /**
     * The import.success event is thrown each time an import fails in the system.
     *
     * The event listener receives a Pumukit\OpencastBundle\Event\OpencastEvent instance.
     */
    public const IMPORT_ERROR = 'import.error';
}
