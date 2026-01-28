<?php declare(strict_types=1);

namespace Imbo\Storage;

use ImboSDK\Storage\StorageTests;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresEnvironmentVariable;

#[Group('integration')]
#[CoversClass(B2::class)]
#[RequiresEnvironmentVariable('B2_KEY_ID')]
#[RequiresEnvironmentVariable('B2_APPLICATION_KEY')]
#[RequiresEnvironmentVariable('B2_BUCKET_ID')]
#[RequiresEnvironmentVariable('B2_BUCKET_NAME')]
class B2IntegrationTest extends StorageTests
{
    protected int $allowedTimestampDelta = 10;

    protected function getAdapter(): B2
    {
        $keyId = (string) getenv('B2_KEY_ID');
        $applicationKey = (string) getenv('B2_APPLICATION_KEY');
        $bucketId = (string) getenv('B2_BUCKET_ID');
        $bucketName = (string) getenv('B2_BUCKET_NAME');

        $client = new Client($keyId, $applicationKey, $bucketId, $bucketName);
        $client->emptyBucket();

        return new B2($keyId, $applicationKey, $bucketId, $bucketName, $client);
    }
}
