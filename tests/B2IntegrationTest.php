<?php declare(strict_types=1);
namespace Imbo\Storage;

/**
 * @coversDefaultClass Imbo\Storage\B2
 * @group integration
 */
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

        $client = new Client(
            (string) getenv('B2_KEY_ID'),
            (string) getenv('B2_APPLICATION_KEY'),
            (string) getenv('B2_BUCKET_ID'),
            (string) getenv('B2_BUCKET_NAME')
        );
        $client->emptyBucket();

        return new B2(
            (string) getenv('B2_KEY_ID'),
            (string) getenv('B2_APPLICATION_KEY'),
            (string) getenv('B2_BUCKET_ID'),
            (string) getenv('B2_BUCKET_NAME'),
            $client,
        );
    }
}
