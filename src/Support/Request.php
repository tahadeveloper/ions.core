<?php

namespace Ions\Support;

use Illuminate\Support\Collection;

class Request extends \Illuminate\Http\Request
{
    public function inputs(): Collection
    {
        return collect($this->attributes->all())->filter(function ($single,$key){
            if($key === '_controller' || $key === '_route' || $key === '_controller_name' || $key === '_method_name'){
                return null;
            }
            return $single;
        });
    }

}