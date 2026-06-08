<?php

use Ions\Http\RequestInput;
use Ions\Support\Request;

test('parses a JSON POST body into an object', function () {
    $req = Request::create('/api', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"name":"sam","age":30}');
    $out = RequestInput::parse($req);
    expect($out->name)->toBe('sam')->and($out->age)->toBe(30);
});

test('parses a form-encoded POST', function () {
    $req = Request::create('/api', 'POST', ['name' => 'sam']);
    $out = RequestInput::parse($req);
    expect($out->name)->toBe('sam');
});

test('parses GET query parameters', function () {
    $req = Request::create('/api?q=hello&page=2', 'GET');
    $out = RequestInput::parse($req);
    expect($out->q)->toBe('hello')->and($out->page)->toBe('2');
});

test('parses a JSON PUT body', function () {
    $req = Request::create('/api', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"active":true}');
    $out = RequestInput::parse($req);
    expect($out->active)->toBeTrue();
});

test('returns an empty object for a body-less request', function () {
    $req = Request::create('/api', 'GET');
    $out = RequestInput::parse($req);
    expect($out)->toBeObject();
});

test('exposes uploaded files under ->files', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'ri');
    file_put_contents($tmp, 'x');
    $file = new \Symfony\Component\HttpFoundation\File\UploadedFile($tmp, 'a.jpg', null, null, true);
    $req = Request::create('/api', 'POST', ['caption' => 'hi'], [], ['photo' => $file], ['CONTENT_TYPE' => 'multipart/form-data']);
    $out = RequestInput::parse($req);
    expect($out->caption)->toBe('hi')->and($out->files)->toHaveKey('photo');
});
