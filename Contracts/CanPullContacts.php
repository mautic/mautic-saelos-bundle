<?php

namespace MauticPlugin\MauticSaelosBundle\Contracts;

interface CanPullContacts
{
    /**
     * Pull contacts from the integration
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
    public function pullContacts($params = []): array;

    /**
     * Determine whether this is configured
     * to pull contacts from the integration.
     *
     * @return bool
     */
    public function shouldPullContacts(): bool;
}