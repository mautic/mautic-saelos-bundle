<?php

namespace MauticPlugin\MauticSaelosBundle\Contracts;

interface CanPushCompanies
{
    /**
     * Push companies to the integration.
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
    public function pushCompanies($params = []): array;

    /**
     * Determine whether this is configured
     * to push companies from the integration.
     *
     * @return bool
     */
    public function shouldPushCompanies(): bool;
}