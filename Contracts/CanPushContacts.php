<?php

namespace MauticPlugin\MauticSaelosBundle\Contracts;

interface CanPushContacts
{
    /**
     * Push contacts to the integration.
     *
     * The returned array should have 2 values:
     * - array [
     *     0 => newly created,
     *     1 => updated
     *   ]
     *
     * @param array $params
     *
     * @return array
     */
    public function pushContacts($params = []): array;

    /**
     * Determine whether this is configured
     * to push contacts from the integration.
     *
     * @return bool
     */
    public function shouldPushContacts(): bool;
}