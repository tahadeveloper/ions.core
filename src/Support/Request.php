<?php

namespace Ions\Support;

class Request extends \Illuminate\Http\Request
{
    public function inputs(): array
    {
        $input = collect($this->attributes->all())->filter(function ($single,$key){
            if($key === '_controller' || $key === '_route' || $key === '_controller_name' || $key === '_method_name'){
                return null;
            }
            return $single;
        })->toArray();

        return $input ?? [];
    }

}