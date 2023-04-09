<?php declare(strict_types=1);
namespace Imbo\Storage;

use DateTime;
use Imbo\Exception\StorageException;
use Imbo\Storage\Client\Exception as ClientException;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass Imbo\Storage\B2
 */
class B2Test extends TestCase
{
    private string $bucketId   = 'bucket-id';
    private string $bucketName = 'bucket-name';

    private function getAdapter(Client $client): B2
    {
        return new B2('key-id', 'application-key', $this->bucketId, $this->bucketName, $client);
    }

    /**
     * @covers ::__construct
     * @covers ::getImagePath
     * @covers ::store
     */
    public function testCanStoreImage(): void
    {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('uploadFile')
            ->with('user/image-id', 'some image data')
            ->willReturn(true);

        $this->assertTrue(
            $this->getAdapter($client)->store('user', 'image-id', 'some image data'),
            'Expected store() to return true',
        );
    }

    /**
     * @covers ::store
     */
    public function testThrowsExceptionWhenClientIsUnableToUploadImage(): void
    {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('uploadFile')
            ->willThrowException($e = new ClientException('some error'));

        $this->expectExceptionObject(new StorageException('Unable to upload image to B2', 503, $e));
        $this->getAdapter($client)->store('user', 'image-id', 'some image data');
    }

    /**
     * @covers ::delete
     */
    public function testCanDeleteImage(): void
    {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('fileExists')
            ->with('user/image-id')
            ->willReturn(true);

        $client
            ->expects($this->once())
            ->method('deleteFile')
            ->with('user/image-id')
            ->willReturn(true);

        $this->assertTrue(
            $this->getAdapter($client)->delete('user', 'image-id'),
            'Expected delete() to return true',
        );
    }

    /**
     * @covers ::delete
     */
    public function testThrowsExceptionWhenDeletingImageThatDoesNotExist(): void
    {
        $client = $this->createConfiguredMock(Client::class, [
            'fileExists' => false,
        ]);
        $client
            ->expects($this->never())
            ->method('deleteFile');

        $this->expectExceptionObject(new StorageException('File not found', 404));
        $this->getAdapter($client)->delete('user', 'image-id');
    }

    /**
     * @covers ::delete
     */
    public function testThrowsExceptionWhenDeletingImageFails(): void
    {
        $client = $this->createConfiguredMock(Client::class, [
            'fileExists' => true,
        ]);
        $client
            ->expects($this->once())
            ->method('deleteFile')
            ->with('user/image-id')
            ->willThrowException($e = new ClientException('some error', 500));

        $this->expectExceptionObject(new StorageException('Unable to delete image', 503, $e));
        $this->getAdapter($client)->delete('user', 'image-id');
    }

    /**
     * @covers ::getImage
     */
    public function testCanGetImage(): void
    {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('fileExists')
            ->with('user/image-id')
            ->willReturn(true);

        $client
            ->expects($this->once())
            ->method('getFile')
            ->with('user/image-id')
            ->willReturn('image data');

        $this->assertSame(
            'image data',
            $this->getAdapter($client)->getImage('user', 'image-id'),
        );
    }

    /**
     * @covers ::getImage
     */
    public function testThrowsExceptionWhenFetchingImageThatDoesNotExist(): void
    {
        $client = $this->createConfiguredMock(Client::class, [
            'fileExists' => false,
        ]);
        $client
            ->expects($this->never())
            ->method('getFile');

        $this->expectExceptionObject(new StorageException('File not found', 404));
        $this->getAdapter($client)->getImage('user', 'image-id');
    }

    /**
     * @covers ::getImage
     */
    public function testThrowsExceptionWhenFetchingImageFails(): void
    {
        $client = $this->createConfiguredMock(Client::class, [
            'fileExists' => true,
        ]);
        $client
            ->expects($this->once())
            ->method('getFile')
            ->with('user/image-id')
            ->willThrowException($e = new ClientException('some error', 500));

        $this->expectExceptionObject(new StorageException('Unable to get image', 503, $e));
        $this->getAdapter($client)->getImage('user', 'image-id');
    }

    /**
     * @dataProvider getFileInfoForLastModified
     * @covers ::getLastModified
     * @param array<string, int> $fileInfo
     */
    public function testCanGetLastModified(array $fileInfo, string $expectedTimestamp): void
    {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('fileExists')
            ->with('user/image-id')
            ->willReturn(true);

        $client
            ->expects($this->once())
            ->method('getFileInfo')
            ->with('user/image-id')
            ->willReturn($fileInfo);

        $lastModified = $this->getAdapter($client)->getLastModified('user', 'image-id');
        $this->assertEqualsWithDelta(
            $lastModified->getTimestamp(),
            1,
            (new DateTime($expectedTimestamp))->getTimestamp(),
            'Last modified timestamp does not match',
        );
    }

    /**
     * @covers ::getLastModified
     */
    public function testThrowsExceptionWhenFetchingLastModifiedDateForImageThatDoesNotExist(): void
    {
        $client = $this->createConfiguredMock(Client::class, [
            'fileExists' => false,
        ]);
        $client
            ->expects($this->never())
            ->method('getFileInfo');

        $this->expectExceptionObject(new StorageException('File not found', 404));
        $this->getAdapter($client)->getLastModified('user', 'image-id');
    }

    /**
     * @covers ::getLastModified
     */
    public function testThrowsExceptionWhenFetchingLastModifiedDateForImageFails(): void
    {
        $client = $this->createConfiguredMock(Client::class, [
            'fileExists' => true,
        ]);
        $client
            ->expects($this->once())
            ->method('getFileInfo')
            ->with('user/image-id')
            ->willThrowException($e = new ClientException('some error', 500));

        $this->expectExceptionObject(new StorageException('Unable to get file info', 503, $e));
        $this->getAdapter($client)->getLastModified('user', 'image-id');
    }



    /**
     * @covers ::getStatus
     */
    public function testCanGetStatus(): void
    {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->exactly(3))
            ->method('getStatus')
            ->willReturnOnConsecutiveCalls(true, false, true);

        $this->assertTrue(
            $this->getAdapter($client)->getStatus(),
            'Incorrect status',
        );

        $this->assertFalse(
            $this->getAdapter($client)->getStatus(),
            'Incorrect status',
        );

        $this->assertTrue(
            $this->getAdapter($client)->getStatus(),
            'Incorrect status',
        );
    }

    /**
     * @covers ::imageExists
     */
    public function testCanCheckIfImageExists(): void
    {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->exactly(3))
            ->method('fileExists')
            ->with('user/image-id')
            ->willReturnOnConsecutiveCalls(true, false, true);

        $this->assertTrue(
            $this->getAdapter($client)->imageExists('user', 'image-id'),
            'Incorrect result for imageExists',
        );

        $this->assertFalse(
            $this->getAdapter($client)->imageExists('user', 'image-id'),
            'Incorrect result for imageExists',
        );

        $this->assertTrue(
            $this->getAdapter($client)->imageExists('user', 'image-id'),
            'Incorrect result for imageExists',
        );
    }

    /**
     * @covers ::imageExists
     */
    public function testCheckIfImageExistsThrowsExceptionOnFailure(): void
    {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('fileExists')
            ->with('user/image-id')
            ->willThrowException($e = new ClientException('some error', 500));

        $this->expectExceptionObject(new StorageException('Unable to check if image exists', 503, $e));
        $this->getAdapter($client)->getLastModified('user', 'image-id');
    }

    /**
     * @return array<string,array{fileInfo:array<string,int>,expectedTimestamp:string}>
     */
    public static function getFileInfoForLastModified(): array
    {
        return [
            'with timestamp' => [
                'fileInfo' => ['x-bz-info-src_last_modified_millis' => 1462212185001],
                'expectedTimestamp' => '@' . 1462212185,
            ],

            'missing timestamp' => [
                'fileInfo' => ['Last-Modified' => 1462212185001],
                'expectedTimestamp' => 'now',
            ],
        ];
    }
}
