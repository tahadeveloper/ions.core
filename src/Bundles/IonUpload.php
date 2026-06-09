<?php

namespace Ions\Bundles;

use Ions\Foundation\Kernel;
use Ions\Foundation\Singleton;
use Ions\Security\UploadValidator;
use Ions\Support\Storage;
use Ions\Support\Str;
use Verot\Upload\Upload;

class IonUpload extends Singleton
{
    private static mixed $output;

    public static function store(mixed $file, string $path, array $options = []): static
    {
        $upload_arr = array();

        // SECURITY: gate the upload by an extension allow-list BEFORE verot
        // processes/moves the file. The client-supplied extension is never
        // trusted to decide the stored extension — a dangerous extension
        // (.php, .phtml, .phar, ...) is rejected outright, closing the RCE
        // path where an attacker-controlled extension lands in a web-served
        // directory. 'allowed' may be passed in $options or configured via
        // app.uploads.allowed; default to a conservative media/document set.
        $allowed = $options['allowed']
            ?? config('app.uploads.allowed', [
                'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg',
                'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
                'txt', 'csv', 'zip', 'rar', 'mp3', 'mp4', 'webm', 'mov',
            ]);
        unset($options['allowed']);
        $validator = new UploadValidator(is_array($allowed) ? $allowed : []);

        $fileNameWithExt = $file->getClientOriginalName();
        if (!$validator->isAllowed($fileNameWithExt)) {
            static::$output = ['error' => 1, 'message' => 'File extension not allowed'];

            return new self();
        }

        $handle = new Upload($file);
        if ($handle->uploaded) {

            // Force the stored extension to the validated, lower-cased one —
            // never trust the raw client extension.
            $extension = $validator->safeExtension($fileNameWithExt);

            $random_name = Str::random(15);
            $handle->file_new_name_body = $random_name;
            $handle->file_new_name_ext = $extension;

            if (!empty($options)) {
                foreach ($options as $key => $option) {
                    $handle->$key = $option;
                }
            }

            $handle->process($path);
            if ($handle->processed) {
                $upload_arr['error'] = 0;
                $upload_arr['message'] = 'file uploaded';
                $upload_arr['original_name'] = $fileNameWithExt;
                $upload_arr['store_name'] = $random_name . '.' . $extension;
            } else {
                $upload_arr['error'] = 1;
                $upload_arr['message'] = $handle->error;
            }
        } else {
            $upload_arr['error'] = 1;
            $upload_arr['message'] = 'No file to upload';
        }
        static::$output = $upload_arr;

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
        if($file){
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
