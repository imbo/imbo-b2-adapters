<?php declare(strict_types=1);
namespace Imbo\Storage;

use ImboSDK\Storage\StorageTests;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
#[CoversClass(B2::class)]
class B2IntegrationTest extends StorageTests
{
    protected int $allowedTimestampDelta = 10;

    private function checkEnv(): void
    {
        $required = [
            'B2_KEY_ID',
            'B2_APPLICATION_KEY',
            'B2_BUCKET_ID',
            'B2_BUCKET_NAME',
        ];
        $missing = [];

        foreach ($required as $var) {
            if (empty(getenv($var))) {
                $missing[] = $var;
            }
        }

        if (count($missing)) {
            $this->markTestSkipped(sprintf('Missing required environment variable(s) for the integration tests: %s', join(', ', $missing)));
        }
    }

    protected function getAdapter(): B2
    {
        $this->checkEnv();

        $keyId = (string) getenv('B2_KEY_ID');
        $applicationKey = (string) getenv('B2_APPLICATION_KEY');
        $bucketId = (string) getenv('B2_BUCKET_ID');
        $bucketName = (string) getenv('B2_BUCKET_NAME');

        $client = new Client($keyId, $applicationKey, $bucketId, $bucketName);
        $client->emptyBucket();

        return new B2($keyId, $applicationKey, $bucketId, $bucketName, $client);
    }
}
