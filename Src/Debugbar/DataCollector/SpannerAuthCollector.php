<?php

namespace KonstantinBudylov\EloquentSpanner\Debugbar\DataCollector;

use KonstantinBudylov\EloquentSpanner\Helper;
use Barryvdh\Debugbar\DataCollector\MultiAuthCollector;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Collector for Laravel's Auth provider
 */
class SpannerAuthCollector extends MultiAuthCollector
{
    /*
     * Get displayed user information
     *
     * @param ?\Illuminate\Contracts\Auth\Authenticatable $user
     * @return array
     */
    protected function getUserInformation($user = null)
    {
        if (is_null($user) || !Helper::isSpanner($user)) {
            return parent::getUserInformation($user);
        }

        // The default auth identifer is the ID number, which isn't all that
        // useful. Try username and email.

        /** @var Authenticatable $user */
        $identifier = $user instanceof Authenticatable ? $user->getAuthIdentifier() : $user->id;
        try {
            if (isset($user->username)) {
                $identifier = $user->username;
            } elseif (isset($user->email)) {
                $identifier = $user->email;
            }
        } catch (\Throwable $e) {
            // ignore error
        }

        return [
            'name' => $identifier,
            'user' => $user instanceof Arrayable ? $user->toArray() : $user,
        ];
    }
}
