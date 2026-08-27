<?php

namespace Comhon\Calendar\Contracts;

interface ContextAuthorizerInterface
{
    /**
     * Determine whether the authenticated user may use the given context
     * to load and export schedulable models.
     */
    public function authorize(string $context, $authUser): bool;
}
