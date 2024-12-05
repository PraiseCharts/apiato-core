<?php

namespace Apiato\Core\Foundation\Routing\Versioning\Extractors;

use Apiato\Core\Foundation\Routing\Versioning\Contracts\RouteVersionExtractorInterface;
use Symfony\Component\Finder\SplFileInfo;

class MultiSegmentRouteVersionExtractor implements RouteVersionExtractorInterface
{
    public function __construct(
        private string $fileSegmentSeparator = '-',
        private string $routeSegmentSeparator = '.',
        private string $noVersionIdentifier = 'noversion'
    ) {}

    public function extractVersion(SplFileInfo $file): string|false
    {
        $fileNameWithoutExtension = pathinfo($file->getFilename(), PATHINFO_FILENAME);
        $segments = explode('.', $fileNameWithoutExtension);
        
        end($segments);
        $version = prev($segments);
        
        if ($version === $this->noVersionIdentifier) {
            return false;
        }
        
        return str_replace(
            $this->fileSegmentSeparator, 
            $this->routeSegmentSeparator, 
            $version
        );
    }
}