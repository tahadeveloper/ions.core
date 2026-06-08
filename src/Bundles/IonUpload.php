<?php

namespace Ions\Bundles;

use Ions\Foundation\Kernel;
use Ions\Foundation\Singleton;
use Ions\Security\UploadValidator;
use Ions\Support\Storage;
use Ions\Support\Str;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class IonUpload extends Singleton
{
    private static mixed $output;

    public static function store(mixed $file, string $path, array $options = []): static
    {
        if (!($file instanceof UploadedFile) || !$file->isValid()) {
            static::$output = ['error' => 1, 'message' => 'No file to upload'];
            return new self();
        }

        $allowed = $options['allowed'] ?? config('app.uploads.allowed', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip']);
        unset($options['allowed']);
        $validator = new UploadValidator($allowed);

        $originalName = $file->getClientOriginalName();
        if (!$validator->isAllowed($originalName)) {
            static::$output = ['error' => 1, 'message' => 'File extension not allowed'];
            return new self();
        }

        $ext = $validator->safeExtension($originalName);
        $randomName = Str::random(15);
        $storeName = $randomName . '.' . $ext;

        try {
            $file->move($path, $storeName);
            static::$output = [
                'error' => 0,
                'message' => 'file uploaded',
                'original_name' => $originalName,
                'store_name' => $storeName,
            ];
        } catch (FileException $e) {
            static::$output = ['error' => 1, 'message' => $e->getMessage()];
        }

        return new self();
    }

    public static function remove(string $fileName, string $path): static
    {
        if (file_exists($path . '/' . $fileName)) {
            unlink($path . '/' . $fileName);
        }

        return new self();
    }

    public static function moveUrl(string $image_url, string $destination, $old_destination = 'dump'): static
    {
        $image_ext = null;
        if ($image_url) {
            $url_array = explode('/', $image_url);
            $count_url_array = count($url_array);
            $file_name = $url_array[$count_url_array - 1];
            if (str_contains($image_url, $old_destination) && Storage::exists(Path::files($old_destination . '/' . $file_name))) {
                static::moveLocal($old_destination, $destination, $file_name);
                $image_ext = $url_array[$count_url_array - 1];
            }
            if ((str_contains($image_url, $old_destination) || str_contains($image_url, $destination)) && Storage::exists(Path::files($destination . '/' . $file_name))) {
                $image_ext = $url_array[$count_url_array - 1];
            }
        }
        static::$output = $image_ext;

        return new self();
    }

    public static function moveLocal($from, $to, $file_name, $new_name = null): static
    {
        $result = false;
        if (Storage::exists(Path::files($from . '/' . $file_name))) {
            $result = Storage::move(Path::files($from . '/' . $file_name), Path::files($to . '/' . $file_name ?? $new_name));
        }
        static::$output = $result;

        return new self();
    }

    public static function update($file_name, $file_original_name, $file, $path, array $options = []): static
    {
        $request = Kernel::request();
        $image_name = $request->get($file_name);
        $original_name = $request->get($file_original_name);
        static::$output['error'] = 0;
        if ($file) {
            $upload_file = static::store($file, $path, $options);
            $upload_result = $upload_file::$output;
            if ((int)$upload_result['error'] === 0) {
                if ($image_name) {
                    static::remove($image_name, $path);
                }
                $image_name = $upload_result['store_name'];
                $original_name = $upload_result['original_name'];
            }
        }
        static::$output['store_name'] = $image_name;
        static::$output['original_name'] = $original_name;
        return new self();
    }

    public function response()
    {
        return static::$output;
    }

}
