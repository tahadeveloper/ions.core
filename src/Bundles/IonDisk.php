<?php

namespace Ions\Bundles;

use Aws\S3\S3Client;
use Exception;
use InvalidArgumentException;
use Ions\Support\File;
use Ions\Support\Str;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Verot\Upload\Upload;

class IonDisk
{
    private static string $type;
    private static $filesystem;
    private static $basePath;
    private static $bucket;

    public static function init(): void
    {
        // Initialize properties
        self::$type = self::getTypeFromEnv();
        self::$basePath = config('filesystem.disks.local.root');
        self::$bucket = config('filesystem.disks.s3.bucket');
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
            $adapter = new AwsS3V3Adapter(new S3Client($options), self::$bucket,
                self::$basePath);
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
        $filePath = "$userProvidedPath/$randomName";

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

    public static function download($filePath, $downloadPath): array|string
    {
        // Retrieve file content or secure URL
        try {
            ray($filePath, $downloadPath);
            if (self::$filesystem->has($filePath)) {
                $stream = fopen($downloadPath, 'r');
                self::$filesystem->writeStream($filePath, $stream);
                fclose($stream);
                // Downloaded file is available at $downloadPath
                return ['success' => true];
            } else {
                // Handle the case where the file doesn't exist
                return ['error' => 'File not found'];
            }
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
                $adapter = new AwsS3V3Adapter($s3Client, self::$bucket,
                    self::$basePath);
                return new Filesystem($adapter);
            default:
                throw new InvalidArgumentException("Unsupported disk type: " . self::$type);
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

    public static function getObject($path, $downloadTemp)
    {
        $client = self::getS3Client();
        try {
            $result = $client->getObject([
                'Bucket' => self::$bucket,
                'Key' => $path,
                'SaveAs' => $downloadTemp,
            ]);
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public static function getSignedUrl($path, $expirationTime = 3600, $defaultOptions = null): string // 1 hour
    {
        if ($defaultOptions) {
            if ($defaultOptions->has('bucket')) {
                self::$bucket = $defaultOptions->get('bucket');
            }
            if ($defaultOptions->has('basePath')) {
                self::$basePath = $defaultOptions->get('basePath');
            }
        }
        $disk = self::flySystem();

        if ($disk === null) {
            throw new RuntimeException('Signed URLs are not available for local storage.');
        }

        $s3Client = self::getS3Client();
        $command = $s3Client->getCommand('GetObject', [
            'Bucket' => self::$bucket,
            'Key' => $path,
        ]);
        $url = $s3Client->getObjectUrl(self::$bucket, $path);
        //$request = $s3Client->createPresignedRequest($command, $expirationTime);
        // Get the actual resigned-url
        return $url;
    }

    public static function getUrl(string $path, $defaultOptions = null): string
    {
        if ($defaultOptions) {
            if ($defaultOptions->has('bucket')) {
                self::$bucket = $defaultOptions->get('bucket');
            }
            if ($defaultOptions->has('basePath')) {
                self::$basePath = $defaultOptions->get('basePath');
            }
        }
        $s3Client = self::getS3Client();

        if ($defaultOptions && $defaultOptions->has('cdn_base_url')) {
            return $defaultOptions->get('cdn_base_url') . '/' . $path;
        }

        try {
            $result = $s3Client->getObjectUrl(self::$bucket, $path);
            return $result;
        } catch (AwsException $e) {
            throw new RuntimeException('Failed to get URL: ' . $e->getMessage());
        }
    }

    public static function exists($path, $defaultOptions = null): bool
    {
        if ($defaultOptions) {
            if ($defaultOptions->has('bucket')) {
                self::$bucket = $defaultOptions->get('bucket');
            }
            if ($defaultOptions->has('basePath')) {
                self::$basePath = $defaultOptions->get('basePath');
            }
        }
        $disk = self::flySystem();

        if ($disk === null) {
            if ($defaultOptions && $defaultOptions->has('target')) {
                $path = Path::filesRoot($defaultOptions->get('target') . '/' . $path);
            } else {
                $path = Path::files($path);
            }
            return File::exists($path);
        }

        return $disk->has($path);
    }

    public static function size($path, $defaultOptions = null): int
    {
        if ($defaultOptions) {
            if ($defaultOptions->has('bucket')) {
                self::$bucket = $defaultOptions->get('bucket');
            }
            if ($defaultOptions->has('basePath')) {
                self::$basePath = $defaultOptions->get('basePath');
            }
        }
        $disk = self::flySystem();

        if ($disk === null) {
            if ($defaultOptions && $defaultOptions->has('target')) {
                $path = Path::filesRoot($defaultOptions->get('target') . '/' . $path);
            } else {
                $path = Path::files($path);
            }
            return File::size($path);
        }

        return $disk->fileSize($path);
    }

    public static function mimeType($path, $defaultOptions = null): string
    {
        if ($defaultOptions) {
            if ($defaultOptions->has('bucket')) {
                self::$bucket = $defaultOptions->get('bucket');
            }
            if ($defaultOptions->has('basePath')) {
                self::$basePath = $defaultOptions->get('basePath');
            }
        }
        $disk = self::flySystem();

        if ($disk === null) {
            if ($defaultOptions && $defaultOptions->has('target')) {
                $path = Path::filesRoot($defaultOptions->get('target') . '/' . $path);
            } else {
                $path = Path::files($path);
            }
            return File::mimeType($path);
        }

        return $disk->mimeType($path);
    }

    public static function createDirectory($path, $defaultOptions = null): bool
    {
        if ($defaultOptions) {
            if ($defaultOptions->has('bucket')) {
                self::$bucket = $defaultOptions->get('bucket');
            }
            if ($defaultOptions->has('basePath')) {
                self::$basePath = $defaultOptions->get('basePath');
            }
        }
        $disk = self::flySystem();

        if ($disk === null) {
            if ($defaultOptions && $defaultOptions->has('target')) {
                $path = Path::filesRoot($defaultOptions->get('target') . '/' . $path);
            } else {
                $path = Path::files($path);
            }
            return File::makeDirectory($path, 0777, true);
        }

        try {
            $disk->createDirectory($path);
        } catch (FilesystemException $e) {
            throw new RuntimeException('Failed to create directory: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    public static function deleteDirectory(string $path): void
    {
        $disk = self::flySystem();

        if ($disk === null) {
            // Local storage handling
            if (is_dir($path)) {
                $files = array_diff(scandir($path), ['.', '..']);
                foreach ($files as $file) {
                    $filePath = $path . DIRECTORY_SEPARATOR . $file;
                    if (is_dir($filePath)) {
                        self::deleteDirectory($filePath);
                    } else {
                        unlink($filePath);
                    }
                }
                rmdir($path);
            } else {
                throw new RuntimeException("Directory does not exist: $path");
            }
        } else {
            // Cloud storage handling (using Flysystem)
            if ($disk->has($path)) {
                $disk->deleteDirectory($path);
            }
        }
    }

    public static function copy($sourcePath, $destinationPath, $defaultOptions = null): bool
    {
        if ($defaultOptions) {
            if ($defaultOptions->has('bucket')) {
                self::$bucket = $defaultOptions->get('bucket');
            }
            if ($defaultOptions->has('basePath')) {
                self::$basePath = $defaultOptions->get('basePath');
            }
            if ($defaultOptions->has('removePath')) {
                self::$basePath = '';
                if ($defaultOptions->has('fromPath') && $defaultOptions->has('toPath')) {
                    $sourcePath = $defaultOptions->get('fromPath') . '/' . $sourcePath;
                    $destinationPath = $defaultOptions->get('toPath') . '/' . $destinationPath;
                }
            }
        }

        $disk = self::flySystem();

        if ($disk === null) {
            // remove fromPath from sourcePath
            $sourcePath = str_replace($defaultOptions->get('fromPath') . '/', '', $sourcePath);
            $destinationPath = str_replace($defaultOptions->get('toPath') . '/', '', $destinationPath);

            if ($defaultOptions->has('targetFrom')) {
                $sourcePath = Path::filesRoot($defaultOptions->get('targetFrom') . '/' . $sourcePath);
            } else {
                $sourcePath = Path::files($sourcePath);
            }
            if ($defaultOptions->has('targetTo')) {
                $destinationPath = Path::filesRoot($defaultOptions->get('targetTo') . '/' . $destinationPath);
            } else {
                $destinationPath = Path::files($destinationPath);
            }
            return File::copy($sourcePath, $destinationPath);
        }

        try {
            if ($defaultOptions && $defaultOptions->get('bucket') && $defaultOptions->has('otherBucket')) {
                $client = self::getS3Client();
                $client->copyObject([
                    'Bucket' => $defaultOptions->get('otherBucket'),
                    'CopySource' => $defaultOptions->get('bucket') . '/' . $sourcePath,
                    'Key' => $destinationPath,
                ]);
            } else {
                $disk->copy($sourcePath, $destinationPath);
            }
        } catch (FilesystemException $e) {
            throw new RuntimeException('Failed to copy file: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    public static function move($sourcePath, $destinationPath, $defaultOptions = null): bool
    {
        if ($defaultOptions) {
            if ($defaultOptions->has('bucket')) {
                self::$bucket = $defaultOptions->get('bucket');
            }
            if ($defaultOptions->has('basePath')) {
                self::$basePath = $defaultOptions->get('basePath');
            }
            if ($defaultOptions->has('removePath')) {
                self::$basePath = '';
                if ($defaultOptions->has('fromPath') && $defaultOptions->has('toPath')) {
                    $sourcePath = $defaultOptions->get('fromPath') . '/' . $sourcePath;
                    $destinationPath = $defaultOptions->get('toPath') . '/' . $destinationPath;
                }
            }
        }

        $disk = self::flySystem();

        if ($disk === null) {
            // remove fromPath from sourcePath
            if($defaultOptions && $defaultOptions->has('fromPath')){
                $sourcePath = str_replace($defaultOptions->get('fromPath') . '/', '', $sourcePath);
            }
            if($defaultOptions && $defaultOptions->has('toPath')){
                $destinationPath = str_replace($defaultOptions->get('toPath') . '/', '', $destinationPath);
            }

            if ($defaultOptions && $defaultOptions->has('targetFrom')) {
                $sourcePath = Path::filesRoot($defaultOptions->get('targetFrom') . '/' . $sourcePath);
            } else {
                $sourcePath = Path::files($sourcePath);
            }
            if ($defaultOptions && $defaultOptions->has('targetTo')) {
                $destinationPath = Path::filesRoot($defaultOptions->get('targetTo') . '/' . $destinationPath);
            } else {
                $destinationPath = Path::files($destinationPath);
            }
            return File::move($sourcePath, $destinationPath);
        }

        try {
            if ($defaultOptions && $defaultOptions->get('bucket') && $defaultOptions->has('otherBucket')) {
                $client = self::getS3Client();
                $client->copyObject([
                    'Bucket' => $defaultOptions->get('otherBucket'),
                    'CopySource' => $defaultOptions->get('bucket') . '/' . $sourcePath,
                    'Key' => $destinationPath,
                ]);
            } else {
                $disk->move($sourcePath, $destinationPath);
            }
        } catch (FilesystemException $e) {
            throw new RuntimeException('Failed to move file: ' . $e->getMessage());
            return false;
        }
        return true;
    }

}

IonDisk::init();
