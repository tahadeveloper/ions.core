<?php

namespace Ions\Bundles;

use Aws\S3\S3Client;
use Exception;
use InvalidArgumentException;
use Ions\Support\Str;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Verot\Upload\Upload;

class IonDisk
{
    private static string $type;
    private static $filesystem;

    public static function init(): void
    {
        // Initialize properties
        self::$type = self::getTypeFromEnv();
        self::$filesystem = self::initializeFilesystem();
    }

    private static function getTypeFromEnv()
    {
        return config('filesystem.disks.default', 'local');
    }

    private static function initializeFilesystem(): Filesystem
    {
        // Initialize the appropriate Flysystem adapter based on the type
        if (self::$type === 'local') {
            $adapter = new LocalFilesystemAdapter(
                config('filesystem.disks.local.root')
            );
            return new Filesystem($adapter);
        }

        if (self::$type === 's3') {
            $options = [
                'region' => config('filesystem.disks.s3.region'),
                'version' => config('filesystem.disks.s3.version', 'latest'),
                'credentials' => [
                    'key' => config('filesystem.disks.s3.key'),
                    'secret' => config('filesystem.disks.s3.secret'),
                ],
            ];
            $adapter = new AwsS3V3Adapter(new S3Client($options), config('filesystem.disks.s3.bucket'),
                config('filesystem.disks.s3.base_path', 'app'));
            return new Filesystem($adapter);
        }

        throw new RuntimeException("Unsupported IonDisk type: " . self::$type);
    }

    public static function putFile($fileContent, $originalFilename, $userProvidedPath, $withOriginal = false): array
    {
        // Generate a random filename
        $randomName = $withOriginal ? pathinfo($originalFilename, PATHINFO_FILENAME) : Str::random(15);
        // get extension
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $randomName .= '.' . $extension;
        $filePath = "{$userProvidedPath}/{$randomName}";

        // Upload the file to the specified path
        try {
            self::$filesystem->write($filePath, $fileContent);
            return [
                'upload_name' => $randomName,
                'original_name' => $originalFilename,
                'file_size' => strlen($fileContent),
            ];
        } catch (Exception $e) {
            // Handle any errors (e.g., disk full, permissions issue)
            return ['error' => $e->getMessage()];
        }
    }

    public static function getFile($filePath): array|string
    {
        // Retrieve file content or secure URL
        try {
            return self::$filesystem->read($filePath);
        } catch (Exception $e) {
            // Handle any errors (e.g., file not found)
            return ['error' => $e->getMessage()];
        }
    }

    public static function deleteFile($filePath): array
    {
        // Delete a file
        try {
            self::$filesystem->delete($filePath);
            return ['success' => true];
        } catch (Exception $e) {
            // Handle any errors (e.g., file not found)
            return ['error' => $e->getMessage()];
        }
    }

    public static function disk($type): static
    {
        // type get from config/filesystem.php
        $defType = config('filesystem.disks.default', 'local');
        if (!empty($type)) {
            self::$type = $type;
        } else {
            self::$type = $defType;
        }
        self::$filesystem = self::initializeFilesystem();
        return new self();
    }

    public static function getType(): string
    {
        return self::$type;
    }

    /**
     * @return S3Client
     */
    public static function getS3Client(): S3Client
    {
        $options = [
            'region' => config('filesystem.disks.s3.region'),
            'version' => config('filesystem.disks.s3.version', 'latest'),
            'credentials' => [
                'key' => config('filesystem.disks.s3.key'),
                'secret' => config('filesystem.disks.s3.secret'),
            ],
        ];
        // Create the S3 client
        return new S3Client($options);
    }

    public static function flySystem(): ?Filesystem
    {
        switch (self::$type) {
            case 'local':
                // No configuration needed
                break;
            case 's3':
                $s3Client = self::getS3Client();
                // Create the Flysystem adapter
                $adapter = new AwsS3V3Adapter($s3Client, config('filesystem.disks.s3.bucket'),
                    config('filesystem.disks.s3.base_path', 'app'));
                return new Filesystem($adapter);
            default:
                throw new InvalidArgumentException("Unsupported disk type: $type");
        }

        return null;
    }

    public static function put(mixed $file, string $path = '', bool $withOriginal = false, array $options = []): array
    {
        $disk = self::flySystem();

        // check if file is valid
        if (!($file instanceof UploadedFile)) {
            throw new InvalidArgumentException('Invalid file, must be an instance of UploadedFile');
        }

        $fileNameWithExt = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $randomFilename = $withOriginal ? pathinfo($fileNameWithExt, PATHINFO_FILENAME) : Str::random(15);

        if ($disk === null) {
            return self::handleLocalUpload($file, $path, $fileNameWithExt, $randomFilename, $extension, $options);
        }

        return self::handleCloudUpload($file, $disk, $path, $fileNameWithExt, $randomFilename, $extension);
    }

    private static function handleLocalUpload(UploadedFile $file, string $path, string $fileNameWithExt, string $randomFilename, string $extension, array $options): array
    {
        $handle = new Upload($file);
        if ($handle->uploaded) {
            $handle->file_new_name_body = $randomFilename;
            $handle->file_new_name_ext = $extension;
            if (!empty($options)) {
                foreach ($options as $key => $option) {
                    $handle->$key = $option;
                }
            }
        }
        $handle->process($path);
        if ($handle->processed) {
            // Return information about uploaded file
            return [
                'error' => false,
                'originalName' => $fileNameWithExt,
                'filename' => $handle->file_dst_name,
                'size' => $file->getSize(),
            ];
        }

        // Handle upload errors
        throw new RuntimeException('Upload failed: ' . $handle->error);
    }

    private static function handleCloudUpload(UploadedFile $file, Filesystem $disk, string $path, string $fileNameWithExt, string $randomFilename, string $extension): array
    {
        $stream = fopen($file->getRealPath(), 'rb+');
        $disk->writeStream($path . '/' . $randomFilename . '.' . $extension, $stream);
        fclose($stream);

        // Return information about uploaded file
        return [
            'error' => false,
            'originalName' => $fileNameWithExt,
            'filename' => $randomFilename . '.' . $extension,
            'size' => $file->getSize(),
        ];
    }

    public static function delete(string $path): void
    {
        $disk = self::flySystem();

        if ($disk === null) {
            // Local storage handling
            if (file_exists($path)) {
                unlink($path);
            } else {
                throw new RuntimeException("File does not exist: $path");
            }
        } else {
            // Cloud storage handling (using Flysystem)
            if ($disk->has($path)) {
                $disk->delete($path);
            } else {
                throw new RuntimeException("File does not exist: $path");
            }
        }
    }

    public static function get($path): bool|string
    {
        $disk = self::flySystem();

        if ($disk === null) {
            // Local storage handling
            $content = file_get_contents($path);
            if ($content === false) {
                throw new RuntimeException('Failed to read file.');
            }
            return $content;
        }

        // Cloud storage handling (using Flysystem)
        try {
            $stream = $disk->readStream($path);
            if (!$stream) {
                throw new RuntimeException('File not found.');
            }
            $content = stream_get_contents($stream);
            fclose($stream);
            return $content;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to retrieve file: ' . $e->getMessage());
        }
    }

    public static function getSignedUrl($path, $expirationTime = 3600): string // 1 hour
    {
        $disk = self::flySystem();

        if ($disk === null) {
            throw new RuntimeException('Signed URLs are not available for local storage.');
        }

        $s3Client = self::getS3Client();
        $command = $s3Client->getCommand('GetObject', [
            'Bucket' => config('filesystem.disks.s3.bucket'),
            'Key' => $path,
        ]);
        $url = $s3Client->getObjectUrl(config('filesystem.disks.s3.bucket'), $path);
        //$request = $s3Client->createPresignedRequest($command, $expirationTime);
        // Get the actual resigned-url
        return $url;
    }
}

IonDisk::init();
