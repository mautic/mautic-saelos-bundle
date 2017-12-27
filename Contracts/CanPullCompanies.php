<?php

namespace MauticPlugin\MauticSaelosBundle\Contracts;

interface CanPullCompanies
{
    /**
     * Pull companies from the integration
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
    public function pullCompanies($params = []): array;

    /**
     * Determine whether this is configured
     * to pull companies from the integration.
     *
     * @return bool
     */
    public function shouldPullCompanies(): bool;
}