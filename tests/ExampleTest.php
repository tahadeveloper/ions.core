<?php

use Ions\Bundles\Path;

test('path', function () {

    $string = Path::bin('commands/stubs/provider.stub');



    expect($string)->toBeString();
});
