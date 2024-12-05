<?php

namespace Apiato\Core\Foundation\Routing\Versioning;

use Apiato\Core\Foundation\Routing\Versioning\Contracts\RouteVersionExtractorInterface;
use Symfony\Component\Finder\SplFileInfo;

class DefaultRouteVersionExtractor implements RouteVersionExtractorInterface 
{
    public function extractVersion(SplFileInfo $file): string|false 
    {
        $fileNameWithoutExtension = pathinfo($file->getFilename(), PATHINFO_FILENAME);
        $fileNameWithoutExtensionExploded = explode('.', $fileNameWithoutExtension);
        
        end($fileNameWithoutExtensionExploded);
        // get the array before the last one
        return prev($fileNameWithoutExtensionExploded);
    }
}