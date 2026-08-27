<?php

namespace App\Services;

use Comhon\Calendar\Contracts\ContextAuthorizerInterface;

class ContextAuthorizerConsumer implements ContextAuthorizerInterface
{
    public function authorize(string $context, $authUser): bool
    {
        return $authUser->has_consumer_ability == true;
    }
}
