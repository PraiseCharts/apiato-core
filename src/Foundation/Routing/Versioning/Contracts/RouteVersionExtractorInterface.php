<?php

namespace Apiato\Core\Foundation\Routing\Versioning\Contracts;

use Symfony\Component\Finder\SplFileInfo;

interface RouteVersionExtractorInterface
{
    public function extractVersion(SplFileInfo $file): string|false;
}